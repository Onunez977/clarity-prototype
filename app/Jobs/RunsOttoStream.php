<?php

namespace App\Jobs;

use App\Ai\Agents\Otto;
use App\Ai\PageContext;
use App\Ai\Surface;
use App\Models\AgentConversation;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\OttoAccess;
use App\Support\Otto\OttoMetrics;
use App\Support\Otto\SearchActivityLog;
use App\Support\Otto\ToolInvocationNarration;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Pennant\Feature;

abstract class RunsOttoStream implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 180;

    public int $tries = 1;

    /**
     * A fabrication correction re-run is still the same user turn. Keep
     * otto:pending_prompt set so poll does not treat the discarded reply as done.
     */
    protected bool $deferPendingPromptRelease = false;

    public const LOCK_TTL_SECONDS = 210;

    public const LOCK_RETRY_SECONDS = 3;

    /**
     * Horizon reuses this process across jobs. Flush Pennant's in-memory store
     * so each turn re-reads Scout (and other) flags from the database, and drop
     * Spatie role/permission relations after setting the team id so ViewAny_*
     * is not answered from a previous job's cache.
     *
     * Staff impersonation is stamped on the job at dispatch (HTTP session is
     * not available on Horizon), restored for tool soft-gates, then cleared in
     * finally so process reuse cannot leak the flag.
     */
    public function handle(): void
    {
        $impersonator = $this->adminImpersonator();
        if ($impersonator !== null) {
            session()->put('admin_impersonator', $impersonator);
        }

        $activitySession = $this->activitySession();
        if (is_array($activitySession)) {
            session()->put(SearchActivityLog::SESSION_KEY, $activitySession);
        }

        try {
            if ($this->user()->org_id !== null) {
                setPermissionsTeamId($this->user()->org_id);
            }

            $this->user()->unsetRelation('roles');
            $this->user()->unsetRelation('permissions');

            Feature::flushCache();

            $conversationId = $this->conversationId();

            if (! OttoAccess::orgHasOttoEnabled($this->user()->org)) {
                if ($conversationId) {
                    static::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));
                }

                return;
            }

            if ($conversationId && ! AgentConversation::query()
                ->where('id', $conversationId)
                ->where('user_id', $this->user()->id)
                ->exists()) {
                static::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));

                return;
            }

            $lock = $conversationId
                ? static::turnCache()->lock(self::lockKey($conversationId), self::LOCK_TTL_SECONDS)
                : null;

            if ($lock && ! $lock->get()) {
                $this->redispatch()->delay(now()->addSeconds(self::LOCK_RETRY_SECONDS));

                return;
            }

            $channel = $this->streamChannel();
            $investigation = $this->resolveInvestigation();

            try {
                $agent = Otto::make(
                    user: $this->user(),
                    investigation: $investigation,
                    surface: $this->surface(),
                    pageContext: $this->pageContext(),
                    correctionNote: $this->correctionNote(),
                );

                if ($conversationId) {
                    $agent->continue($conversationId, as: $this->user());
                } else {
                    $agent->forUser($this->user());
                }

                $investigation->resetToolCalls();
                $investigation->beginToolOutcomeTracking();
                $investigation->setActiveConversation($conversationId);
                $investigation->setActivePrompt($this->prompt());

                $finalResponse = null;
                $agent->stream($this->prompt())
                    ->each(function (StreamEvent $event) use ($channel, $conversationId): bool {
                        if ($conversationId && static::turnCache()->get(self::stopCacheKey($conversationId))) {
                            return false;
                        }

                        if ($event instanceof ReasoningStart
                            || $event instanceof ReasoningDelta
                            || $event instanceof ReasoningEnd) {
                            return true;
                        }

                        $event->broadcastNow($channel);

                        return true;
                    })
                    ->then(function (StreamedAgentResponse $response) use (&$finalResponse) {
                        $finalResponse = $response;
                    });

                if ($conversationId) {
                    static::turnCache()->forget(self::stopCacheKey($conversationId));
                }

                $this->trimNarrationFromStoredReply($finalResponse);
                $this->onStreamComplete($investigation, $finalResponse);

                if ($finalResponse !== null) {
                    OttoMetrics::recordTurn(
                        $this->surface(),
                        $this->user(),
                        $finalResponse->usage,
                        $finalResponse->toolCalls,
                    );
                }
            } catch (\Throwable $e) {
                Log::channel('otto')->error(class_basename(static::class).' failed', [
                    'user_id' => $this->user()->id,
                    'conversation_id' => $conversationId,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);

                $this->onStreamFailed($investigation, $e);

                throw $e;
            } finally {
                if ($conversationId && ! $this->deferPendingPromptRelease) {
                    static::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));
                }
                $lock?->release();
            }
        } finally {
            session()->forget(SearchActivityLog::SESSION_KEY);

            if ($impersonator !== null) {
                session()->forget('admin_impersonator');
            }
        }
    }

    /**
     * HTTP and Horizon must share pending/lock/stop keys. Local CACHE_STORE=file
     * is per-checkout, so a worktree request and the main Horizon worker never
     * see each other's keys and follow-up sends no-op for 30 minutes.
     */
    public static function turnCache(): Repository
    {
        $store = (string) config('cache.default');

        return Cache::store($store === 'file' ? 'redis' : $store);
    }

    public static function lockKey(string $conversationId): string
    {
        return 'otto:run:'.$conversationId;
    }

    public static function stopCacheKey(string $conversationId): string
    {
        return 'otto:stop:'.$conversationId;
    }

    /**
     * Rewrite the stored reply to the final round's text only. Runs before
     * onStreamComplete so the pending-prompt key — which OttoConversation::poll reads as
     * "this turn is done" — is not released over a half-written thread.
     */
    protected function trimNarrationFromStoredReply(?StreamedAgentResponse $response): void
    {
        if (! $response) {
            return;
        }

        $conversationId = $response->conversationId ?? $this->conversationId();
        if (! $conversationId) {
            return;
        }

        $finalAnswer = static::finalRoundText($response->events);
        $messageId = $this->latestAssistantMessageId($conversationId);

        if ($messageId === null) {
            return;
        }

        $current = (string) DB::table('agent_conversation_messages')
            ->where('id', $messageId)
            ->value('content');

        if ($finalAnswer === '') {
            $cleaned = ToolInvocationNarration::withoutNarration($current);
        } else {
            $cleaned = ToolInvocationNarration::withoutNarration(
                $finalAnswer ?? (string) ($response->text ?? '')
            );
        }

        if ($cleaned === $current) {
            return;
        }

        DB::table('agent_conversation_messages')
            ->where('id', $messageId)
            ->update(['content' => $cleaned]);
    }

    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    protected static function finalRoundText(Collection $events): ?string
    {
        $deltas = $events->whereInstanceOf(TextDelta::class)->values();

        if ($deltas->isEmpty()) {
            return null;
        }

        $finalMessageId = $deltas->last()->messageId;

        return $deltas
            ->filter(fn (TextDelta $delta): bool => $delta->messageId === $finalMessageId)
            ->map->delta
            ->join('');
    }

    /**
     * Newest assistant message id for this conversation. onStreamComplete runs
     * after laravel/ai persists the reply, so this turn's searches can be
     * attributed to it. Message ids are time-ordered UUIDs; created_at is only
     * second-granularity.
     */
    protected function latestAssistantMessageId(string $conversationId): ?string
    {
        $id = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $this->user()->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (string) $id : null;
    }

    abstract protected function user(): User;

    abstract protected function prompt(): string;

    abstract protected function conversationId(): ?string;

    abstract protected function surface(): Surface;

    abstract protected function pageContext(): ?PageContext;

    abstract protected function streamChannel(): Channel;

    abstract protected function resolveInvestigation(): Investigation;

    abstract protected function redispatch(): PendingDispatch;

    abstract protected function onStreamComplete(Investigation $investigation, ?StreamedAgentResponse $finalResponse): void;

    abstract protected function onStreamFailed(Investigation $investigation, \Throwable $e): void;

    protected function adminImpersonator(): mixed
    {
        return null;
    }

    /**
     * @return array{impersonator: mixed, clientIp: mixed, userAgent: mixed}|null
     */
    protected function activitySession(): ?array
    {
        return null;
    }

    /**
     * One-off system-prompt directive for a re-run that replaces a fabricated
     * reply. Null on a normal turn.
     */
    protected function correctionNote(): ?string
    {
        return null;
    }
}
