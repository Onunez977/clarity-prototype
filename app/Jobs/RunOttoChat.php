<?php

namespace App\Jobs;

use App\Ai\FabricationGuard;
use App\Ai\PageAction;
use App\Ai\PageContext;
use App\Ai\Surface;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Events\OttoInvestigationUpdated;
use App\Events\OttoReplyDiscarded;
use App\Events\OttoReplyReplaced;
use App\Events\OttoStreamFailed;
use App\Models\AgentInvestigation;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\CodeReplyTurn;
use App\Support\Otto\ConversationTitleGenerator;
use App\Support\Otto\ToolInvocationNarration;
use App\Support\Otto\ZoneInventoryTurn;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\StreamedAgentResponse;

class RunOttoChat extends RunsOttoStream
{
    /**
     * Injected on the re-run that replaces a fabricated reply, and the cap that
     * stops a model that keeps inventing results from looping.
     */
    protected const CORRECTION_NOTE = 'SELF-CORRECTION: Your previous reply to the user\'s last request described '
        .'search results or a page-filter proposal that did not actually exist, so that claim was fabricated and has '
        .'been discarded. Redo it correctly now — actually use the appropriate search or page capability and report '
        .'ONLY the records or proposal it really creates. If a capability refuses, relay that instead. Do NOT mention '
        .'this correction, the discarded reply, or that anything went wrong; just answer the request as if for the '
        .'first time.';

    protected const MAX_FABRICATION_CORRECTIONS = 1;

    /**
     * Evaluated before persistProposedPageActions() pulls the page-action buffer.
     * Null when the correction hook evaluates the guard itself.
     */
    protected ?bool $fabricationDetected = null;

    /**
     * @param  array<string, mixed>|null  $pageContextPayload
     * @param  string|null  $correction  Re-run directive that replaces a fabricated reply.
     * @param  int  $corrections  Fabrication corrections already run for this request.
     * @param  array{impersonator: mixed, clientIp: mixed, userAgent: mixed}|null  $activitySession  Browser session captured at send time.
     */
    public function __construct(
        public User $user,
        public string $prompt,
        public string $conversationId,
        public ?array $pageContextPayload = null,
        public mixed $adminImpersonator = null,
        public ?string $correction = null,
        public int $corrections = 0,
        public ?array $activitySession = null,
    ) {
        $this->queue = '{otto}';
    }

    public static function pendingPromptKey(string $conversationId): string
    {
        return 'otto:pending_prompt:'.$conversationId;
    }

    protected function user(): User
    {
        return $this->user;
    }

    protected function prompt(): string
    {
        return $this->prompt;
    }

    protected function conversationId(): ?string
    {
        return $this->conversationId;
    }

    protected function surface(): Surface
    {
        return Surface::Chat;
    }

    protected function pageContext(): ?PageContext
    {
        return PageContext::fromClient($this->pageContextPayload);
    }

    protected function streamChannel(): Channel
    {
        return new PrivateChannel('otto-thread.'.$this->conversationId);
    }

    protected function redispatch(): PendingDispatch
    {
        return self::dispatch(
            $this->user,
            $this->prompt,
            $this->conversationId,
            $this->pageContextPayload,
            $this->adminImpersonator,
            $this->correction,
            $this->corrections,
            $this->activitySession,
        );
    }

    protected function correctionNote(): ?string
    {
        return $this->correction;
    }

    protected function adminImpersonator(): mixed
    {
        return $this->adminImpersonator;
    }

    protected function activitySession(): ?array
    {
        return $this->activitySession;
    }

    protected function resolveInvestigation(): Investigation
    {
        $existing = AgentInvestigation::query()
            ->where('user_id', $this->user->id)
            ->where('conversation_id', $this->conversationId)
            ->first();

        $investigation = new Investigation;
        $investigation->data = $existing?->properties ?? new AgentInvestigationProperties;

        return $investigation;
    }

    /**
     * Persist everything this turn produced *before* releasing the pending-prompt
     * key. OttoConversation::poll treats a missing key as "turn complete" and immediately
     * re-renders the thread from the database, so a poll landing mid-write would
     * paint the reply with no search widget and no proposal card.
     */
    protected function onStreamComplete(Investigation $investigation, ?StreamedAgentResponse $finalResponse): void
    {
        if ($finalResponse) {
            // Before persistProposedPageActions() pulls the buffer: its presence
            // is the proof that a claimed proposal really exists.
            $this->fabricationDetected = app(FabricationGuard::class)
                ->looksFabricated($finalResponse, $investigation);
        }

        if ($this->correctFabricationIfDetected($investigation, $finalResponse)) {
            return;
        }

        if ($finalResponse !== null) {
            $zoneReply = ZoneInventoryTurn::ensureSearched(
                $this->user,
                $investigation,
                $this->prompt,
                $finalResponse,
            );

            if (is_string($zoneReply)) {
                $this->replaceFinalReply($finalResponse, $zoneReply);
            }

            $visibleReply = is_string($zoneReply) ? $zoneReply : (string) $finalResponse->text;
            $codeReply = CodeReplyTurn::replacement($visibleReply);

            if (is_string($codeReply)) {
                $this->replaceFinalReply($finalResponse, $codeReply);
            }
        }

        $this->persistInvestigation($investigation);
        $this->persistProposedPageActions($investigation);
        $this->generateTitleIfNeeded($finalResponse);

        static::turnCache()->forget(self::pendingPromptKey($this->conversationId));

        broadcast(new OttoInvestigationUpdated($this->user->id, $this->conversationId));
    }

    /**
     * If the reply described results or a filter proposal this run did not create,
     * discard it and re-run with a correction directive. From the user's seat the
     * fabricated bubble vanishes and the corrected reply streams in its place.
     * At the cap, authoritative no-action text replaces the model reply and the
     * turn completes normally.
     *
     * True means a corrective re-run was queued and this turn must not release
     * the pending-prompt key or persist the fabricated exchange.
     */
    protected function correctFabricationIfDetected(Investigation $investigation, ?StreamedAgentResponse $finalResponse): bool
    {
        if ($finalResponse === null) {
            return false;
        }

        $detected = $this->fabricationDetected
            ?? app(FabricationGuard::class)->looksFabricated($finalResponse, $investigation);

        if (! $detected) {
            return false;
        }

        if ($this->corrections >= self::MAX_FABRICATION_CORRECTIONS) {
            $this->replaceFinalReply($finalResponse, FabricationGuard::UNFULFILLED_ACTION_RESPONSE);

            return false;
        }

        $this->deleteLatestExchange();

        static::turnCache()->put(
            self::pendingPromptKey($this->conversationId),
            $this->prompt,
            now()->addMinutes(30),
        );

        broadcast(new OttoReplyDiscarded(
            $this->user->id,
            $this->conversationId,
            $finalResponse->invocationId,
        ));

        self::dispatch(
            $this->user,
            $this->prompt,
            $this->conversationId,
            $this->pageContextPayload,
            $this->adminImpersonator,
            self::CORRECTION_NOTE,
            $this->corrections + 1,
            $this->activitySession,
        );

        $this->deferPendingPromptRelease = true;

        return true;
    }

    /**
     * Delete this turn's user + assistant rows so the re-run does not see the
     * fabrication as conversation history. Message ids are time-ordered UUIDs
     * and the per-conversation lock serializes runs, so the two newest rows are
     * this turn.
     */
    protected function deleteLatestExchange(): void
    {
        $ids = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', $this->user->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->limit(2)
            ->pluck('id');

        DB::table('agent_conversation_messages')->whereIn('id', $ids)->delete();
    }

    protected function replaceFinalReply(StreamedAgentResponse $response, string $content): bool
    {
        $messageId = $this->latestAssistantMessageId($this->conversationId);
        if ($messageId === null) {
            return false;
        }

        DB::table('agent_conversation_messages')
            ->where('id', $messageId)
            ->update(['content' => $content]);

        broadcast(new OttoReplyReplaced($this->conversationId, $response->invocationId, $content));

        return true;
    }

    protected function persistInvestigation(Investigation $investigation): void
    {
        $this->discardToolInvocationNarration();

        $data = $investigation->data ?? new AgentInvestigationProperties;
        $messageId = $this->latestAssistantMessageId($this->conversationId);

        foreach ($data->searches as $search) {
            if ($search->conversationId === '') {
                $search->conversationId = $this->conversationId;
            }

            if ($search->messageId === '' && $messageId !== null) {
                $search->messageId = $messageId;
            }
        }

        if ($messageId !== null) {
            $this->appendSearchMarkersToMessage($messageId, $data);
        }

        AgentInvestigation::query()->updateOrCreate(
            [
                'user_id' => $this->user->id,
                'conversation_id' => $this->conversationId,
            ],
            [
                'properties' => $data,
            ],
        );
    }

    /**
     * Write widget markers onto the producing assistant message so
     * MessageRenderer does not depend on a later injectSearchMarkers pass
     * (poll/refresh after a missed .otto.widget).
     */
    protected function appendSearchMarkersToMessage(string $messageId, AgentInvestigationProperties $data): void
    {
        $markers = [];

        foreach ($data->searches as $search) {
            if ($search->messageId !== $messageId) {
                continue;
            }

            $type = $search->searchType->value;
            if (! in_array($type, ['scan', 'hit', 'hotlist', 'scout_driver', 'zone'], true)) {
                continue;
            }

            $markers[] = "<!--otto_table:{$type}:{$search->searchId}-->";
        }

        if ($markers === []) {
            return;
        }

        $content = (string) DB::table('agent_conversation_messages')
            ->where('id', $messageId)
            ->value('content');

        $toAppend = [];
        foreach ($markers as $marker) {
            if (! str_contains($content, $marker)) {
                $toAppend[] = $marker;
            }
        }

        if ($toAppend === []) {
            return;
        }

        DB::table('agent_conversation_messages')
            ->where('id', $messageId)
            ->update(['content' => rtrim($content)."\n\n".implode("\n\n", $toAppend)]);
    }

    /**
     * Drop leftover assistant rows that are only "[Calling ScanSearch…]" filler
     * so the real reply (and its tool_calls) is what the thread keeps.
     */
    protected function discardToolInvocationNarration(): void
    {
        $latestUser = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', $this->user->id)
            ->where('role', 'user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latestUser === null) {
            return;
        }

        $assistants = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', $this->user->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $latestUser->created_at)
            ->orderBy('id')
            ->get();

        if ($assistants->isEmpty()) {
            return;
        }

        foreach ($assistants as $row) {
            $cleaned = ToolInvocationNarration::withoutNarration((string) $row->content);

            if ($cleaned !== (string) $row->content) {
                DB::table('agent_conversation_messages')
                    ->where('id', $row->id)
                    ->update(['content' => $cleaned]);
                $row->content = $cleaned;
            }
        }

        $narrationIds = $assistants
            ->filter(fn ($row): bool => ToolInvocationNarration::isNarrationOnly((string) $row->content))
            ->pluck('id')
            ->all();

        if ($narrationIds === []) {
            return;
        }

        $hasRealReply = $assistants->contains(
            fn ($row): bool => ! ToolInvocationNarration::isNarrationOnly((string) $row->content)
        );

        if ($hasRealReply) {
            DB::table('agent_conversation_messages')->whereIn('id', $narrationIds)->delete();
        }
    }

    /**
     * Persist any page actions Otto proposed this run onto the assistant message
     * that proposed them (its `meta` JSON), so the proposal card re-renders after
     * a refresh. laravel/ai has just written the message rows.
     */
    protected function persistProposedPageActions(Investigation $investigation): void
    {
        $actions = $investigation->pullProposedPageActions();

        if ($actions === []) {
            return;
        }

        $message = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', $this->user->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first();

        if (! $message) {
            return;
        }

        $meta = json_decode((string) ($message->meta ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $meta['page_actions'] = array_map(fn (PageAction $action): array => $action->toArray(), $actions);

        DB::table('agent_conversation_messages')
            ->where('id', $message->id)
            ->update(['meta' => json_encode($meta)]);
    }

    protected function generateTitleIfNeeded(?StreamedAgentResponse $finalResponse): void
    {
        if ($finalResponse === null) {
            return;
        }

        app(ConversationTitleGenerator::class)->generateFor(
            $this->conversationId,
            $this->prompt,
        );
    }

    protected function onStreamFailed(Investigation $investigation, \Throwable $e): void
    {
        static::turnCache()->forget(self::pendingPromptKey($this->conversationId));

        if ($investigation->hasRecordedSearchThisRun()) {
            $this->persistInvestigation($investigation);
            broadcast(new OttoInvestigationUpdated($this->user->id, $this->conversationId));
        }

        broadcast(new OttoStreamFailed($this->conversationId));
    }
}
