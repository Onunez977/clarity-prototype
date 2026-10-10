<?php

declare(strict_types=1);

namespace App\Services\Otto;

use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Enums\ScoutAnalyticsPage;
use App\Events\OttoInvestigationUpdated;
use App\Events\OttoStreamStopped;
use App\Events\OttoUserMessagePosted;
use App\Events\OttoWidgetCreated;
use App\Filament\Pages\ManageZones;
use App\Jobs\RunOttoChat;
use App\Jobs\RunsOttoStream;
use App\Models\AgentConversation;
use App\Models\AgentInvestigation;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zones\PersistZone;
use App\Services\Zones\ResolveZoneCreateInput;
use App\Services\Zones\ResolveZoneUpdateInput;
use App\Services\Zones\ZoneGeometryRejected;
use App\Support\Otto\ConversationTitleGenerator;
use App\Support\Otto\InChatSearchIntent;
use App\Support\Otto\OttoAccess;
use App\Support\Otto\OttoMetrics;
use App\Support\Otto\OttoScoutAccess;
use App\Support\Otto\SearchActivityLog;
use App\Support\UserColor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Session-owned Otto chat: send / poll / stop / history / zone accept.
 *
 * Used by the session HTTP API (`ConversationController`) and the Vue island
 * bootstrap. Poll treats a missing pending-prompt key as complete only once
 * the assistant-message count grows past the send-time baseline.
 */
class OttoConversation
{
    /**
     * Internal continuation sent when the user clicks "Make Otto search instead".
     * Never shown as a user bubble — live UI and history render a divider instead.
     */
    public const SEARCH_INSTEAD_PROMPT_PREFIX = 'Run this search yourself and show the results here in the chat instead of changing the page';

    public const SEARCH_INSTEAD_PROMPT_FALLBACK = 'Run that search yourself and show the results here in the chat instead of changing the page.';

    public static function turnBaselineKey(string $conversationId): string
    {
        return 'otto:turn_baseline:'.$conversationId;
    }

    /**
     * Whether $content is the auto-generated "Make Otto search instead" prompt
     * (prefix + filter summary, or the no-summary fallback). Used to hide that
     * internal continuation from the thread UI.
     */
    public static function isSearchInsteadPrompt(string $content): bool
    {
        return $content === self::SEARCH_INSTEAD_PROMPT_FALLBACK
            || str_starts_with($content, self::SEARCH_INSTEAD_PROMPT_PREFIX);
    }

    /**
     * @return list<array{id: string, title: ?string}>
     */
    public function list(User $user, string $search = ''): array
    {
        abort_unless(OttoAccess::userCanUseOtto($user), 403);

        $query = AgentConversation::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(40);

        if ($search !== '') {
            $query->where('title', 'ilike', '%'.$this->escapeIlike($search).'%');
        }

        return $query->get(['id', 'title'])
            ->map(fn (AgentConversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{conversationId: string, title: ?string, messages: list<array<string, mixed>>}
     */
    public function show(User $user, string $conversationId): array
    {
        $conversation = $this->requireOwnedConversation($user, $conversationId);
        session(['otto_active_conversation' => $conversation->id]);

        return [
            'conversationId' => $conversation->id,
            'title' => $conversation->title,
            'messages' => $this->structuredMessages($user, $conversation->id),
        ];
    }

    /**
     * Queue a turn. Returns the conversation id (existing or newly created).
     * No-ops — still returning that id — while a pending-prompt key is present.
     *
     * @param  array<string, mixed>|null  $pageContext
     */
    public function send(
        User $user,
        ?string $conversationId,
        string $prompt,
        ?array $pageContext,
        mixed $adminImpersonator,
    ): string {
        abort_unless(OttoAccess::userCanUseOtto($user), 403);
        abort_unless($this->conversationOwnedBy($user, $conversationId), 403);

        $prompt = trim($prompt);

        if ($prompt === '') {
            return (string) $conversationId;
        }

        $this->forgetStalePendingTurn($user, $conversationId);

        if ($conversationId && RunsOttoStream::turnCache()->has(RunOttoChat::pendingPromptKey($conversationId))) {
            return $conversationId;
        }

        if (! $conversationId) {
            $conversationId = (string) Str::uuid();
            AgentConversation::query()->create([
                'id' => $conversationId,
                'user_id' => $user->id,
                'title' => app(ConversationTitleGenerator::class)->placeholder($prompt),
            ]);
        }

        session(['otto_active_conversation' => $conversationId]);

        $baseline = $this->assistantMessageCount($user, $conversationId);
        RunsOttoStream::turnCache()->put(self::turnBaselineKey($conversationId), $baseline, now()->addMinutes(30));
        RunsOttoStream::turnCache()->put(RunOttoChat::pendingPromptKey($conversationId), $prompt, now()->addMinutes(30));

        if (self::isSearchInsteadPrompt($prompt)
            || InChatSearchIntent::shouldStripPageContext($prompt, $pageContext)) {
            $pageContext = null;
        }

        RunOttoChat::dispatch(
            $user,
            $prompt,
            $conversationId,
            $pageContext ?: null,
            $adminImpersonator,
            activitySession: SearchActivityLog::captureSession(),
        );

        $this->broadcastToOthers(new OttoUserMessagePosted(
            $conversationId,
            $prompt,
            $user->name,
            UserColor::initials($user->name ?? '?'),
            UserColor::for($user->id),
        ), $conversationId);

        return $conversationId;
    }

    /**
     * Hang safety net when Echo never delivers stream_end. Pending-key last:
     * absence means the job finished writing. Completion counts raw assistant
     * rows (the same count stored at send time), not the filtered thread the
     * client renders — an empty historical row must not freeze later turns at
     * idle. The client keeps polling while status is idle.
     *
     * @return array{status: string, conversationId: ?string, hasMessages: bool, messages: list<array<string, mixed>>}
     */
    public function poll(User $user, ?string $conversationId, ?int $turnBaselineAssistantCount = null): array
    {
        abort_unless(OttoAccess::userCanUseOtto($user), 403);
        abort_unless($this->conversationOwnedBy($user, $conversationId), 403);

        if (! $conversationId) {
            return [
                'status' => 'idle',
                'conversationId' => null,
                'hasMessages' => false,
                'messages' => [],
            ];
        }

        $this->forgetStalePendingTurn($user, $conversationId);

        if (RunsOttoStream::turnCache()->has(RunOttoChat::pendingPromptKey($conversationId))) {
            return [
                'status' => 'pending',
                'conversationId' => $conversationId,
                'hasMessages' => $this->hasAnyThreadMessages($user, $conversationId),
                'messages' => [],
            ];
        }

        $messages = $this->structuredMessages($user, $conversationId);
        $assistantCount = $this->assistantMessageCount($user, $conversationId);

        $baseline = $turnBaselineAssistantCount ?? $this->cachedTurnBaseline($conversationId);

        $complete = $baseline === null
            ? $assistantCount > 0
            : $assistantCount > $baseline;

        return [
            'status' => $complete ? 'complete' : 'idle',
            'conversationId' => $conversationId,
            'hasMessages' => $messages !== [],
            'messages' => $messages,
        ];
    }

    public function stop(User $user, ?string $conversationId): void
    {
        abort_unless(OttoAccess::userCanUseOtto($user), 403);
        abort_unless($this->conversationOwnedBy($user, $conversationId), 403);

        if (! $conversationId) {
            return;
        }

        RunsOttoStream::turnCache()->put(
            RunsOttoStream::stopCacheKey($conversationId),
            true,
            now()->addMinutes(5),
        );
        $this->broadcastToOthers(new OttoStreamStopped($conversationId), $conversationId);
    }

    public function forgetActiveConversation(User $user, ?string $conversationId = null): void
    {
        abort_unless(OttoAccess::userCanUseOtto($user), 403);

        $active = session('otto_active_conversation');
        if ($conversationId !== null && $active !== $conversationId) {
            return;
        }

        session()->forget('otto_active_conversation');
    }

    /**
     * Store or clear thumbs on an assistant row. Rating null removes feedback.
     * Merges into `meta` so page-action cards on the same row stay put.
     *
     * @return array{ok: bool, feedback: ?array{rating: bool, comment: ?string, surface: string}}
     */
    public function recordFeedback(
        User $user,
        string $conversationId,
        string $messageId,
        ?bool $rating,
        ?string $comment,
        string $surface,
    ): array {
        $this->requireOwnedConversation($user, $conversationId);

        $result = DB::transaction(function () use ($user, $conversationId, $messageId, $rating, $comment, $surface): array {
            $row = DB::table('agent_conversation_messages')
                ->where('id', $messageId)
                ->where('conversation_id', $conversationId)
                ->where('user_id', $user->id)
                ->where('role', 'assistant')
                ->lockForUpdate()
                ->first();

            abort_unless($row !== null, 404);

            $meta = $this->decodeMeta($row->meta ?? null);

            if ($rating === null) {
                unset($meta['feedback']);
            } else {
                $meta['feedback'] = [
                    'rating' => $rating,
                    'comment' => $rating === false && is_string($comment) && trim($comment) !== ''
                        ? trim($comment)
                        : null,
                    'surface' => $surface,
                ];
            }

            DB::table('agent_conversation_messages')
                ->where('id', $messageId)
                ->update([
                    'meta' => json_encode($meta),
                    'updated_at' => now(),
                ]);

            return [
                'ok' => true,
                'feedback' => $this->feedbackFromMeta($meta),
            ];
        });

        if ($rating !== null) {
            OttoMetrics::recordFeedback($rating);
        }

        return $result;
    }

    public function acceptDisclaimer(): void
    {
        session(['otto_disclaimer_accepted' => true]);
    }

    public function showDisclaimer(): bool
    {
        return ! session('otto_disclaimer_accepted', false);
    }

    /**
     * Blade/JS bootstrap for the Vue island (`window.__OTTO_CONFIG__`).
     *
     * @return array{
     *     userId: int,
     *     userInitials: string,
     *     userColor: string,
     *     ottoAvatar: string,
     *     logo: string,
     *     logoDark: string,
     *     conversationId: string|null,
     *     hasMessages: bool,
     *     showDisclaimer: bool
     * }
     */
    public function frontendConfig(User $user): array
    {
        abort_unless(OttoAccess::userCanUseOtto($user), 403);

        $conversationId = session('otto_active_conversation');
        if (! is_string($conversationId) || $conversationId === '' || ! $this->conversationOwnedBy($user, $conversationId)) {
            $conversationId = null;
        }

        return [
            'userId' => $user->id,
            'userInitials' => UserColor::initials($user->name ?? '?'),
            'userColor' => UserColor::for($user->id),
            'ottoAvatar' => asset('assets/otto.jpg'),
            'logo' => asset('img/logo.png'),
            'logoDark' => asset('img/logo-dark.png'),
            'conversationId' => $conversationId,
            'hasMessages' => $conversationId !== null && $this->hasAnyThreadMessages($user, $conversationId),
            'showDisclaimer' => $this->showDisclaimer(),
        ];
    }

    /**
     * Persist a ZIP-based zone from an accepted Otto confirmation card.
     *
     * A second Accept for the same org zone name updates that row instead of
     * inserting a duplicate (same guard as ProposeCreateZoneTool).
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, zone_id?: int, error?: string}
     */
    public function applyZoneCreate(User $user, string $conversationId, array $payload): array
    {
        $this->authorizeOttoZoneWrite($user, $conversationId);

        $existing = app(ResolveZoneUpdateInput::class)->findByName(
            $user,
            (string) ($payload['name'] ?? ''),
        );

        if ($existing !== null) {
            return $this->applyZoneUpdate($user, $conversationId, [
                ...$payload,
                'zone_id' => $existing->id,
            ]);
        }

        $resolved = app(ResolveZoneCreateInput::class)->resolve($user, $payload);

        if ($resolved['ok'] === false) {
            return ['ok' => false, 'error' => $resolved['error']];
        }

        if ($resolved['zip_codes'] === []) {
            return ['ok' => false, 'error' => 'ZIP codes are required to save a zone from chat. Open the Zones map to draw a shape instead.'];
        }

        try {
            $zone = app(PersistZone::class)->create($user, [
                'name' => $resolved['name'],
                'description' => $resolved['description'],
                'color' => $resolved['color'],
                'is_no_scan_zone' => $resolved['is_no_scan_zone'],
                'definition' => [
                    'polygons' => [],
                    'zip_codes' => $resolved['zip_codes'],
                ],
                'user_ids' => $resolved['user_ids'],
            ], ScoutAnalyticsPage::Otto);
        } catch (ZoneGeometryRejected $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        $this->recordCreatedZoneOnInvestigation($user, $conversationId, $zone);

        return ['ok' => true, 'zone_id' => $zone->id];
    }

    /**
     * Update an existing zone from an accepted Otto confirmation card.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, zone_id?: int, error?: string}
     */
    public function applyZoneUpdate(User $user, string $conversationId, array $payload): array
    {
        $this->authorizeOttoZoneWrite($user, $conversationId);

        $resolved = app(ResolveZoneUpdateInput::class)->resolve($user, $payload);

        if ($resolved['ok'] === false) {
            return ['ok' => false, 'error' => $resolved['error']];
        }

        $zone = $resolved['zone'];
        abort_unless($user->can('update', $zone), 403);

        if ($resolved['zip_codes'] === [] && $resolved['polygons'] === []) {
            return ['ok' => false, 'error' => 'This zone has no mappable boundary to save. Add ZIP codes or draw a shape on the Zones map.'];
        }

        try {
            app(PersistZone::class)->update($zone, $user, [
                'name' => $resolved['name'],
                'description' => $resolved['description'],
                'color' => $resolved['color'],
                'is_no_scan_zone' => $resolved['is_no_scan_zone'],
                'definition' => [
                    'polygons' => $resolved['polygons'],
                    'zip_codes' => $resolved['zip_codes'],
                ],
                'user_ids' => $resolved['user_ids'],
            ], ScoutAnalyticsPage::Otto);
        } catch (ZoneGeometryRejected $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        $this->recordCreatedZoneOnInvestigation($user, $conversationId, $zone->fresh() ?? $zone);

        return ['ok' => true, 'zone_id' => $zone->id];
    }

    /**
     * Return a ManageZones draft URL so the user can draw a polygon.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, url?: string, error?: string}
     */
    public function openZoneEditor(User $user, string $conversationId, array $payload): array
    {
        $this->authorizeOttoZoneWrite($user, $conversationId);

        $resolved = app(ResolveZoneCreateInput::class)->resolve($user, [
            ...$payload,
            'open_map_editor' => true,
        ]);

        if ($resolved['ok'] === false) {
            return ['ok' => false, 'error' => $resolved['error']];
        }

        $query = array_filter([
            'name' => $resolved['name'],
            'description' => $resolved['description'],
            'color' => $resolved['color'],
            'no_scan' => $resolved['is_no_scan_zone'] ? '1' : null,
            'zips' => $resolved['zip_codes'] !== [] ? implode(',', $resolved['zip_codes']) : null,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            'ok' => true,
            'url' => ManageZones::getUrl($query),
        ];
    }

    /**
     * Raw thread for JSON (and for Livewire to render HTML from). Widget
     * markers are injected onto the assistant row that produced each search.
     * Ordered by uuid7 `id`, never `created_at`.
     *
     * @return list<array<string, mixed>>
     */
    public function structuredMessages(User $user, string $conversationId): array
    {
        $rows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get();

        $messages = $rows->map(function ($row): array {
            if ($row->role === 'user' && self::isSearchInsteadPrompt((string) $row->content)) {
                return [
                    'role' => 'event',
                    'event' => 'search_instead',
                ];
            }

            return [
                'id' => $row->id,
                'role' => $row->role,
                'content' => (string) $row->content,
                'page_actions' => $this->pageActionsFromMeta($row->meta ?? null),
                'feedback' => $row->role === 'assistant' ? $this->feedbackFromMeta($row->meta ?? null) : null,
            ];
        })->values()->all();

        $messages = $this->injectSearchMarkers($user, $conversationId, $messages);

        return array_values(array_filter(
            $messages,
            function (array $message): bool {
                if (($message['role'] ?? '') !== 'assistant') {
                    return true;
                }

                $content = (string) ($message['content'] ?? '');

                if (str_contains($content, '<!--otto_table:')) {
                    return true;
                }

                return trim(strip_tags($content)) !== '';
            },
        ));
    }

    public function cachedTurnBaseline(string $conversationId): ?int
    {
        $cached = RunsOttoStream::turnCache()->get(self::turnBaselineKey($conversationId));

        if (is_int($cached)) {
            return $cached;
        }

        if (is_numeric($cached)) {
            return (int) $cached;
        }

        return null;
    }

    public function conversationOwnedBy(?User $user, ?string $conversationId): bool
    {
        if (! $conversationId) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return AgentConversation::query()
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function requireOwnedConversation(User $user, string $conversationId): AgentConversation
    {
        abort_unless(OttoAccess::userCanUseOtto($user), 403);
        abort_unless($this->conversationOwnedBy($user, $conversationId), 403);

        $conversation = AgentConversation::query()
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->first();

        abort_unless($conversation !== null, 403);

        return $conversation;
    }

    /**
     * Page-action proposals persisted on the assistant message's meta so the
     * proposal card re-renders on refresh. Tolerates null/garbage meta.
     *
     * @return list<array<string, mixed>>
     */
    public function pageActionsFromMeta(mixed $meta): array
    {
        $decoded = $this->decodeMeta($meta);

        if (! is_array($decoded['page_actions'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $decoded['page_actions'],
            fn ($action): bool => is_array($action),
        ));
    }

    /**
     * @return ?array{rating: bool, comment: ?string, surface: string}
     */
    public function feedbackFromMeta(mixed $meta): ?array
    {
        $decoded = $this->decodeMeta($meta);
        $feedback = $decoded['feedback'] ?? null;

        if (! is_array($feedback) || ! is_bool($feedback['rating'] ?? null)) {
            return null;
        }

        $comment = $feedback['comment'] ?? null;

        return [
            'rating' => $feedback['rating'],
            'comment' => is_string($comment) && $comment !== '' ? $comment : null,
            'surface' => is_string($feedback['surface'] ?? null) && $feedback['surface'] !== ''
                ? $feedback['surface']
                : 'chat',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        $decoded = is_array($meta) ? $meta : json_decode((string) ($meta ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function escapeIlike(string $search): string
    {
        return addcslashes($search, '\\%_');
    }

    private function assistantMessageCount(User $user, string $conversationId): int
    {
        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->id)
            ->where('role', 'assistant')
            ->count();
    }

    private function hasAnyThreadMessages(User $user, string $conversationId): bool
    {
        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->id)
            ->whereIn('role', ['user', 'assistant'])
            ->exists();
    }

    /**
     * Drop leftover pending keys after a turn that already persisted an assistant
     * reply. Horizon on another checkout used to miss the file-cache forget, which
     * left send() and poll() treating the thread as in-flight forever.
     */
    private function forgetStalePendingTurn(?User $user, ?string $conversationId): void
    {
        if (! $conversationId || $user === null) {
            return;
        }

        $key = RunOttoChat::pendingPromptKey($conversationId);
        $pending = RunsOttoStream::turnCache()->get($key);
        if (! is_string($pending) || $pending === '') {
            return;
        }

        if ($this->pendingPromptHasAssistantReply($user->id, $conversationId, $pending)) {
            RunsOttoStream::turnCache()->forget($key);
        }
    }

    private function pendingPromptHasAssistantReply(int|string $userId, string $conversationId, string $pending): bool
    {
        $pending = trim($pending);

        $lastMatchingUserAt = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('role', 'user')
            ->get(['content', 'created_at'])
            ->filter(fn ($row): bool => trim((string) $row->content) === $pending)
            ->max('created_at');

        if ($lastMatchingUserAt === null) {
            return false;
        }

        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $lastMatchingUserAt)
            ->exists();
    }

    /**
     * Attach each search's widget marker to the assistant message that produced
     * it so MessageRenderer / Vue can swap in the results table on reload/poll.
     * Searches whose messageId is missing or does not match a thread message
     * are skipped — never attached to the latest reply (that leaked prior hit
     * tables onto a later driver follow-up).
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function injectSearchMarkers(User $user, string $conversationId, array $messages): array
    {
        $investigation = AgentInvestigation::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->id)
            ->first();

        if (! $investigation || empty($investigation->properties->searches)) {
            return $messages;
        }

        $assistantIndexById = [];

        foreach ($messages as $i => $message) {
            if (($message['role'] ?? null) === 'assistant' && ! empty($message['id'])) {
                $assistantIndexById[$message['id']] = $i;
            }
        }

        if ($assistantIndexById === []) {
            return $messages;
        }

        foreach ($investigation->properties->searches as $search) {
            $type = $search->searchType->value;
            if (! in_array($type, ['scan', 'hit', 'hotlist', 'scout_driver', 'zone'], true)) {
                continue;
            }

            $messageIdx = $assistantIndexById[$search->messageId] ?? null;
            if ($messageIdx === null) {
                continue;
            }

            $marker = "<!--otto_table:{$type}:{$search->searchId}-->";
            if (str_contains((string) $messages[$messageIdx]['content'], $marker)) {
                continue;
            }

            $messages[$messageIdx]['content'] .= "\n\n".$marker;
        }

        return $messages;
    }

    private function authorizeOttoZoneWrite(?User $user, string $conversationId): void
    {
        abort_unless(OttoAccess::userCanUseOtto($user), 403);
        abort_unless($conversationId !== '' && $this->conversationOwnedBy($user, $conversationId), 403);
        abort_unless(OttoScoutAccess::adminCanUseScout($user), 403);
        abort_unless($user?->can('create', Zone::class), 403);
    }

    private function recordCreatedZoneOnInvestigation(User $user, string $conversationId, Zone $zone): void
    {
        $investigation = AgentInvestigation::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
            ],
            [
                'properties' => new AgentInvestigationProperties,
            ],
        );

        $props = $investigation->properties ?? new AgentInvestigationProperties;
        $searchId = Str::lower(Str::random(12));
        $title = 'Created zone: '.$zone->name;

        $searches = $props->searches;
        $searches[] = new Search(
            searchId: $searchId,
            messageId: '',
            parameters: ['created' => true],
            searchType: SearchType::Zone,
            title: $title,
            createdBy: $user->id,
            conversationId: $conversationId,
        );

        $results = $props->searchResults;
        $results[] = new SearchResult(searchId: $searchId, resultId: $zone->id);

        $investigation->properties = new AgentInvestigationProperties(
            searches: $searches,
            searchResults: $results,
        );
        $investigation->save();

        broadcast(new OttoInvestigationUpdated($user->id, $conversationId));
        broadcast(new OttoWidgetCreated(
            $conversationId,
            '',
            SearchType::Zone->value,
            $searchId,
            $title,
        ));
    }

    /**
     * Fan-out to other tabs. Failures must not roll back send/stop — Echo being
     * down used to throw `Invalid socket ID undefined` before RunOttoChat queued.
     */
    private function broadcastToOthers(OttoUserMessagePosted|OttoStreamStopped $event, string $conversationId): void
    {
        try {
            broadcast($event)->toOthers();
        } catch (\Throwable $e) {
            Log::channel('otto')->warning('Otto realtime broadcast failed', [
                'event' => $event::class,
                'conversation_id' => $conversationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
