<?php

namespace App\Jobs\Rdn;

use App\Enums\HotlistStatusEnum;
use App\Models\Hotlist;
use App\Models\Org;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use Laravel\Nightwatch\Facades\Nightwatch;

class GetOrgHotlistCases implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The statuses branch-filtered analytics can report on. This cannot be scopeOpen():
     * a hotlist that reaches a terminal status before its first enrichment window - a
     * same-day repossession, the headline metric of the page this feature serves - would
     * never be branch-attributed at all. status is the second column of
     * hotlist_org_id_status_created_at, so an IN list keeps index support that a bare
     * (org_id, created_at) predicate would lose.
     *
     * Invalid(99) is excluded - no surface reports it. Other(0) is included: it is the
     * enum's unmapped bucket, but HotlistStatusAggregate::scopeAccepted counts
     * new_status = Open with old_status IN (Other, NewFromClient), so Other is a SOURCE
     * state of the Accepted metric. A row sitting at Other has to be enriched before it
     * moves to Open, or that transition freezes branch_id = 0 and the Accepted cohort -
     * the cohort this feature exists to fix - never attributes.
     *
     * @var list<int>
     */
    public const ANALYTICS_STATUSES = [
        HotlistStatusEnum::Other->value,
        HotlistStatusEnum::Open->value,
        HotlistStatusEnum::Hold->value,
        HotlistStatusEnum::Repossessed->value,
        HotlistStatusEnum::ClosedPositive->value,
        HotlistStatusEnum::Closed->value,
        HotlistStatusEnum::NewFromClient->value,
    ];

    /**
     * Minutes GetOrgBranches gets to land branch rows before an org's case-info run
     * starts. The ordering is load-bearing: a vendor_branch_name whose Branch row has not
     * synced yet is a miss, and a miss spends one of the row's lookup attempts.
     */
    public const BRANCH_SYNC_HEAD_START_MINUTES = 3;

    /**
     * Minutes the fleet's per-org dispatchers are spread across, on top of the head start.
     * ~3,493 of these used to become available on the same second; each one runs a
     * candidate query and fans out. Kept short so it stays small against the child spread
     * - it only has to break the herd, not carry the volume.
     */
    public const DISPATCH_SPREAD_MINUTES = 15;

    /** Derived in the constructor so the dispatch payload carries the bound. See GetOrgHotlistCaseInfo::timeoutSeconds(). */
    public int $timeout;

    /**
     * $forced is `app:rdn <org> --force`. It travels on the payload rather than in a
     * global or a config mutation because this job is batched and its children are queued
     * behind a spread - an override that does not survive serialization is gone before
     * either one runs. See GetOrgHotlistCaseInfo::isEnabled() for what it does and does
     * not override.
     */
    public function __construct(
        public int $orgId,
        public ?Org $org = null,
        public bool $forced = false,
    ) {
        $this->onQueue('{low}');

        // This dispatcher declared no timeout at all and inherited Horizon supervisor-2's
        // 3600 - far above the connection's retry_after=90. A run that outlives its own
        // reservation is released back to the ready list WHILE STILL EXECUTING, and a
        // second worker fans out the whole run a second time - see GetOrgHotlistCaseInfo::
        // timeoutSeconds() for why. Reused rather than re-derived: a second independent
        // number is exactly how that pair drifted apart in the first place.
        $this->timeout = GetOrgHotlistCaseInfo::timeoutSeconds();

        if (! $org) {
            $this->org = Org::find($this->orgId);
        }
    }

    /**
     * The kill switch is read first, before the org gate and before any query, so a
     * disabled fleet costs one config read per org: no candidate selection, no fan-out, no
     * org_meta or hotlist_json write. Its absence from the run summary below is what an
     * operator sees when it is off. A --force run overrides that flag and only that flag:
     * every gate below still applies, and the summary says the run was forced.
     *
     * The org gate lives here rather than in GenerateRdnCache so that calling handle()
     * directly cannot bypass it. Without an RDN account every row selected is a guaranteed
     * miss that still burns the give-up counter and INSERTs a synthetic hotlist_json row.
     *
     * It gates on the api key, not hasRdnAccount(): RDN::__construct builds the SOAP
     * client from rdn_api_key alone, and the two columns are independent populations here
     * - rdn_id without a key would pass a gate it cannot honor, and a usable key without
     * an rdn_id would be excluded for no reason.
     */
    public function handle(): void
    {
        if (! GetOrgHotlistCaseInfo::isEnabled($this->forced)) {
            return;
        }

        if (! $this->org?->hasRdnApiKey()) {
            return;
        }

        if (config('app.env') === 'production') {
            Nightwatch::sample(rate: 0.3);
        }

        // NEWEST FIRST is load-bearing - do not "fix" it to FIFO. Attribution is
        // forward-only: hotlist_status_aggregate freezes branch_id at each transition and
        // is never rewritten, so oldest-first enriches a row only after its ->Open
        // transition already recorded branch_id = 0 - the contradictory
        // Assignments-0 / Repossessions-N screen this ticket exists to fix.
        //
        // The delay spreads the fan-out evenly across one run interval instead of pushing
        // it as a burst: {low} is a single Horizon process that also carries transactional
        // mail, and a fleet-wide run is ~76k jobs. Offsets are strictly below the window,
        // so the last job of a run is available before the next run supersedes it, and
        // GetOrgHotlistCaseInfo::retryUntil() budgets for the largest of them.
        //
        // Phase lives on the DISPATCHER (dispatchDelaySeconds), not on child offsets: a
        // whole-series rotation delayed the newest row by ~12h and defeated newest-first.
        // Child index 0 is always delay 0.
        //
        // Cap is split: half for fresh arrivals (newest-first), half for prior misses
        // (oldest-first). Without the retry half, orgs over the cap never re-attempt
        // yesterday's misses and the seal never frees budget.

        // Read once so the limit the run applied and the cap the summary reports are the
        // same number rather than two reads of a value the org can change between them.
        $cap = $this->org->rdnCaseInfoMaxPerRun();
        $dispatched = 0;
        $failed = 0;
        $suppressed = 0;
        $candidateCount = 0;

        try {
            $candidates = $this->selectCandidates($cap);
            $candidateCount = $candidates->count();

            $window = GetOrgHotlistCaseInfo::maxSpreadSeconds();
            $total = $candidateCount;
            $uniqueLock = new UniqueLock(app(CacheRepository::class));
            $dispatcher = app(Dispatcher::class);

            foreach ($candidates as $index => $hotlist) {
                // Build the job ourselves so we can acquire the unique lock and count a
                // real push (PendingDispatch::__destruct would swallow UniqueUntilProcessing
                // suppressions after we had already incremented dispatched).
                $job = new GetOrgHotlistCaseInfo(
                    orgId: $this->orgId,
                    org: $this->org,
                    hotlistId: $hotlist->id,
                    hotlist: $hotlist,
                    forced: $this->forced,
                );
                // The override has to ride along: the child re-reads the kill switch, so a
                // canary dispatched without it would fan out and then no-op job by job.
                $job->delay($total > 0 ? intdiv($index * $window, $total) : 0);

                if (! $uniqueLock->acquire($job)) {
                    $suppressed++;

                    continue;
                }

                try {
                    $dispatcher->dispatch($job);
                    $dispatched++;
                } catch (\Throwable $e) {
                    // One bad dispatch must not abort the rest of the org's day; release the
                    // unique lock so a later run can retry this hotlist, and keep counting.
                    $failed++;
                    $uniqueLock->release($job);
                    Log::warning('rdn.case_info.dispatch_failed', [
                        'org_id' => $this->orgId,
                        'hotlist_id' => $hotlist->id,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            // Always emit: an absent line is what the runbook reads as "kill switch stopped
            // it", and a mid-run exception used to leave that false signal.
            $this->recordRun(
                dispatched: $dispatched,
                cap: $cap,
                candidates: $candidateCount,
                failed: $failed,
                suppressed: $suppressed,
            );
        }
    }

    /**
     * Split the per-org cap between fresh arrivals and prior misses so newest-first does
     * not starve retries on orgs that arrive faster than the cap.
     *
     * @return Collection<int, Hotlist>
     */
    private function selectCandidates(int $cap): Collection
    {
        $retryBudget = intdiv($cap, 2);

        $eligible = fn (): HasMany => $this->candidates()
            ->whereDoesntHave('hotlistJson', fn (Builder $query) => $query->whereRaw(
                GetOrgHotlistCaseInfo::sealedPredicate(),
                [GetOrgHotlistCaseInfo::maxLookupAttempts()],
            ));

        $retries = $retryBudget > 0
            ? $eligible()
                ->whereHas('hotlistJson', fn (Builder $query) => $query->whereRaw(
                    'jsonb_typeof(hotlist_json."json" -> \'branch_lookup_attempts\') = \'number\''
                    .' AND (hotlist_json."json" ->> \'branch_lookup_attempts\')::numeric > 0'
                ))
                ->orderBy('hotlist.created_at')
                ->limit($retryBudget)
                ->get()
            : collect();

        $retryIds = $retries->pluck('id')->all();
        // Unused retry slots roll into fresh so a quiet miss pool does not shrink the cap.
        $freshBudget = $cap - $retries->count();

        $fresh = $freshBudget > 0
            ? $eligible()
                ->when($retryIds !== [], fn ($query) => $query->whereNotIn('hotlist.id', $retryIds))
                ->orderByDesc('hotlist.created_at')
                ->limit($freshBudget)
                ->get()
            : collect();

        // Delay slots still newest-first across the merged set so attribution races the
        // INSERT-time transition for the freshest rows.
        return $fresh->concat($retries)
            ->unique('id')
            ->sortByDesc(fn (Hotlist $hotlist): int => $hotlist->created_at?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * The one guaranteed per-org, per-run line this loop emits. Log:: rather than a
     * counter alone because it is the channel that is always on (PG-025's observability
     * corollary) and because the volume is bounded by the RDN org count, not by the ~76k
     * daily calls - the per-call outcomes are counters on GetOrgHotlistCaseInfo.
     *
     * Emitted even at zero candidates: "this org selected nothing" is the first thing to
     * check on a pipeline that has never run in production, and an absent line means the
     * kill switch or the rdn_api_key gate stopped the run instead.
     *
     * `dispatched` counts only real pushes (unique-lock suppressions are `suppressed`).
     * `candidates` is the selected set size; `failed` is per-item dispatch exceptions.
     * `capped` uses candidates vs cap so a run that selected a full budget still flags
     * truncation even when some pushes were suppressed. `forced` separates a deliberate
     * canary from a scheduled run. Ids and counts only, no row content.
     */
    private function recordRun(
        int $dispatched,
        int $cap,
        int $candidates = 0,
        int $failed = 0,
        int $suppressed = 0,
    ): void {
        try {
            Log::info('rdn.case_info.run', [
                'org_id' => $this->orgId,
                'dispatched' => $dispatched,
                'candidates' => $candidates,
                'failed' => $failed,
                'suppressed' => $suppressed,
                'cap' => $cap,
                'capped' => $candidates >= $cap,
                'forced' => $this->forced,
                'lookback_days' => GetOrgHotlistCaseInfo::lookbackDays(),
                'max_lookup_attempts' => GetOrgHotlistCaseInfo::maxLookupAttempts(),
            ]);

            logDatadogCount('rdn_case_info.dispatched', $dispatched, [
                'category' => 'rdn_case_info',
                'item' => 'org_'.$this->orgId,
            ]);
        } catch (\Throwable) {
            // A failed counter must not fail this job: the retry would re-run the whole
            // fan-out and dispatch the run's jobs a second time.
        }
    }

    /**
     * The delay GenerateRdnCache pushes this job with: the branch-sync head start plus a
     * per-org phase, so the fleet's dispatchers trickle in rather than all becoming
     * available on the batch's first second. The head start is a floor, never eroded.
     */
    public static function dispatchDelaySeconds(int $orgId): int
    {
        return self::BRANCH_SYNC_HEAD_START_MINUTES * 60
            + self::orgPhaseSeconds($orgId, self::DISPATCH_SPREAD_MINUTES * 60);
    }

    /**
     * A stable starting point for an org inside a spread window.
     *
     * Used by dispatchDelaySeconds() so fleet dispatchers trickle in. Child job offsets do
     * NOT apply this phase - that rotated the newest row to delay≈phase (~12h) and defeated
     * newest-first. crc32 rather than random(): same org, same phase, every run.
     */
    private static function orgPhaseSeconds(int $orgId, int $windowSeconds): int
    {
        return $windowSeconds > 0 ? crc32((string) $orgId) % $windowSeconds : 0;
    }

    /**
     * RDN orders carrying a remote id, in a status branch analytics reports on, inside the
     * lookback window. GetOrgHotlistCaseInfo::sealedPredicate() bounds rows that never
     * resolve; hotlist_integration_id_status_index supports the integration predicate.
     *
     * The integration filter is correctness, not thrift: remote_order_id is unique only
     * per (org_id, remote_order_id, integration_id) - the unique index proves the id
     * spaces overlap - so asking RDN about another integration's order id can come back
     * with a real case for a DIFFERENT order and write its branch here. RDN is also the
     * only integration that can populate branch_id, and remote_order_id is the argument of
     * the call, so a foreign or null one is a guaranteed miss either way.
     */
    private function candidates(): HasMany
    {
        return $this->org
            ->hotlists()
            ->where('hotlist.integration_id', IntegrationEnum::RDN->value)
            ->whereNotNull('hotlist.remote_order_id')
            ->whereIntegerInRaw('hotlist.status', self::ANALYTICS_STATUSES)
            ->where('hotlist.created_at', '>=', now()->subDays(GetOrgHotlistCaseInfo::lookbackDays()));
    }
}
