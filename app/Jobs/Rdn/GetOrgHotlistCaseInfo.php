<?php

namespace App\Jobs\Rdn;

use App\Models\Branch;
use App\Models\Hotlist;
use App\Models\HotlistJson;
use App\Models\Org;
use App\Services\RDN;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Laravel\Nightwatch\Facades\Nightwatch;
use SoapClient;

class GetOrgHotlistCaseInfo implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A hotlist counts as enriched only once branch_id resolves to a non-null value; a
     * null result means "not resolved this time" and must stay eligible for the next run.
     */
    public const ENRICHED_PREDICATE = '(hotlist_json."json" ->> \'branch_id\') IS NOT NULL';

    /**
     * The bounded miss counter this job keeps in the sidecar blob. Spelled literally in
     * the SQL below; the tests read it back through this constant, so the two cannot
     * drift apart silently.
     */
    public const ATTEMPTS_KEY = 'branch_lookup_attempts';

    /** A branch resolved and was written to the sidecar blob. */
    public const OUTCOME_RESOLVED = 'resolved';

    /** RDN answered, but with no case or no vendor_branch_name to match on. */
    public const OUTCOME_MISS_NO_BRANCH_NAME = 'miss_no_branch_name';

    /** RDN named a branch and no Branch row for this org carries that name, exactly or normalized. */
    public const OUTCOME_MISS_NO_BRANCH_MATCH = 'miss_no_branch_match';

    /** The call never completed, so nothing was answered and no attempt was spent. */
    public const OUTCOME_TRANSPORT_FAILED = 'transport_failed';

    /** Already enriched or at the attempt ceiling by the time this job ran. */
    public const OUTCOME_SEALED = 'sealed';

    /**
     * Runtime bound for a connection that never expires a reservation - sync and array,
     * which run a job to completion in-process. There is no reservation to fit under and
     * no second worker to duplicate the call, so this stands in for the derivation rather
     * than feeding it: nothing on redis reads it, and it cannot drift against retry_after
     * because the two are never compared. One SOAP call is the whole job, so PHP's own
     * default_socket_timeout is the honest ceiling for one.
     */
    public const UNRESERVED_TIMEOUT_SECONDS = 60;

    /** Derived in the constructor so the dispatch payload carries the bound. See timeoutSeconds(). */
    public int $timeout;

    public $backoff = 10;

    public $maxExceptions = 3;

    /**
     * Seconds the unique lock is held while this job sits delayed on the queue. Must cover
     * the full spread: a shorter TTL would let a re-run of app:rdn push a duplicate into
     * the same slot before the first job becomes available.
     */
    public int $uniqueFor;

    /**
     * A hotlist stops being selected once it is enriched OR has missed too many times.
     *
     * Enrichment alone is not enough to close the loop: the non-null predicate keeps a
     * transient miss eligible, which is correct, but a hotlist whose vendor_branch_name
     * matches no Branch row misses on every run it survives - and newest-first puts a
     * fresh arrival at the front of every one of them, displacing rows that would have
     * resolved. The counter bounds that: maxLookupAttempts() misses and the row gives up
     * its remaining slots in the window.
     *
     * jsonb_typeof() guards the cast because the blob is externally owned - anything that
     * is not a JSON number reads as "not exhausted" rather than raising. Callers bind
     * maxLookupAttempts() as the single parameter. GetOrgHotlistCases filters dispatch
     * with this same predicate - they are a pair.
     */
    public static function sealedPredicate(): string
    {
        return '('.self::ENRICHED_PREDICATE.' OR (jsonb_typeof(hotlist_json."json" -> \'branch_lookup_attempts\') = \'number\''
            .' AND (hotlist_json."json" ->> \'branch_lookup_attempts\')::numeric >= ?))';
    }

    /**
     * Misses a hotlist may accumulate before sealedPredicate() stops selecting it, bounded
     * by what the lookback window can actually deliver.
     *
     * A row is selectable on selectionsPerWindow() runs and then ages out, so a ceiling
     * that is not strictly below that count can never be reached, and one derived BELOW
     * the configured value silently seals rows early: a 2-day window with daily runs gave
     * a row two selections and derived a ceiling of one, so the first miss was permanent
     * and the self-healing retry the window exists for never happened. The shipped 4-day
     * window is what makes all three configured attempts reachable. Deriving the bound
     * here is what stops the two settings from drifting apart again; the config value can
     * only make the ceiling stricter, never the window's arithmetic.
     *
     * Zero or a non-numeric override would seal every row on its first miss, so anything
     * but a positive integer falls back to one attempt.
     */
    public static function maxLookupAttempts(): int
    {
        return max(1, min(
            (int) config('services.rdn.case_info_max_lookup_attempts'),
            self::selectionsPerWindow() - 1,
        ));
    }

    /**
     * How many runs a hotlist can be selected by before it ages out of the window.
     */
    public static function selectionsPerWindow(): int
    {
        return max(1, intdiv(self::lookbackDays() * 1440, self::runIntervalMinutes()));
    }

    /**
     * Minutes between scheduled runs. Floored at one: a zero or negative override would
     * make every derivation below collapse or stamp a deadline in the past.
     */
    public static function runIntervalMinutes(): int
    {
        return max(1, (int) config('services.rdn.case_info_run_interval_minutes'));
    }

    /**
     * The largest dispatch delay GetOrgHotlistCases may attach to one of these jobs.
     *
     * A run used to become available all at once - ~76k jobs onto a single-process queue
     * that also carries transactional mail. GetOrgHotlistCases now spreads its fan-out
     * across a whole interval instead, so the last job of a run becomes available just as
     * the next run starts and supersedes it. This is the spread half of retryUntil().
     */
    public static function maxSpreadSeconds(): int
    {
        return self::runIntervalMinutes() * 60;
    }

    /**
     * Minutes the queue gets to drain after the most-delayed job of a run becomes
     * available. One interval: the next run re-selects anything still un-enriched, so a
     * job that has not started by then is superseded rather than owed more runway.
     */
    public static function drainMarginMinutes(): int
    {
        return self::runIntervalMinutes();
    }

    /**
     * The lookback window GetOrgHotlistCases selects on, read here because the attempt
     * ceiling is derived from it.
     */
    public static function lookbackDays(): int
    {
        return max(1, (int) config('services.rdn.case_info_lookback_days'));
    }

    /**
     * Seconds this job may run before the worker kills it - derived from the reservation
     * window it has to fit inside, never stated next to it.
     *
     * A worker holds a job's reservation for the connection's retry_after. Outlive that
     * and the connection releases the job back to the ready list WHILE THIS PROCESS IS
     * STILL EXECUTING IT, and a second worker picks it up: a duplicate RDN SOAP call and a
     * second branch_lookup_attempts increment for one answer, which against a derived
     * ceiling of three seals a hotlist a run or two early and throws away the retry budget
     * the window exists to provide. The shipped bound was 600 against a retry_after of 90.
     * {low} is not single-process in production - HORIZON_SUPERVISOR_2_PROCESSES is 2 and
     * config/deploy.yml runs `php artisan horizon` on every web host - so that window was
     * open, not theoretical.
     *
     * Three quarters of the reservation, not all of it. Above it sits PHP's per-read
     * default_socket_timeout (60 unless the image raises it), so a single hung call throws
     * inside this bound and RDN::call() flattens it into the transport failure that costs
     * the row no attempt - the graceful path is only reachable while this bound is the
     * OUTER of the two. The remaining quarter is the worker's runway to finish the write
     * and delete the reservation. A cold WSDL fetch plus a hung call still cannot fit
     * under 90; that case now ends in a worker kill bounded by retryUntil(), which is what
     * 600 was trading for a duplicated call.
     *
     * Deriving it is the point. retry_after is a shared connection setting every queued
     * job in the app depends on and this must not change it, but a second independent
     * number is exactly how the pair drifted - the same failure as a lookback window and
     * an attempt ceiling that could no longer reach each other. Move retry_after and this
     * follows; GetOrgHotlistCaseInfoTest fails if the pair is ever incoherent.
     */
    public static function timeoutSeconds(): int
    {
        $reservation = self::reservationSeconds();

        return $reservation > 0
            ? max(1, intdiv($reservation * 3, 4))
            : self::UNRESERVED_TIMEOUT_SECONDS;
    }

    /**
     * How long the queue connection this job runs on holds its reservation, or zero when
     * that connection has no reservation to expire.
     *
     * Read from the connection QUEUE_CONNECTION resolves - redis in production, sync under
     * phpunit - rather than from the queue name, because retry_after is a connection
     * setting and Horizon's supervisor timeout is only the fallback for a job that
     * declares none (supervisor-2 carries 3600, so declaring one is not optional here).
     */
    public static function reservationSeconds(): int
    {
        return max(0, (int) config('queue.connections.'.config('queue.default').'.retry_after'));
    }

    /**
     * The kill switch for case-info enrichment. Default on, so the shipped behaviour needs
     * nothing set; flipping RDN_CASE_INFO_ENABLED stops the loop with no deploy.
     *
     * It is read HERE as well as at the top of GetOrgHotlistCases::handle() because the
     * fan-out is spread across a whole run interval - at the shipped 1440 minutes, up to a
     * day of these jobs can already be sitting on {low} when the flag flips, and a switch
     * that leaves a day of SOAP calls running is not a switch. Both checks come before any
     * query, so a disabled run costs one config read per job.
     *
     * $forced is `app:rdn <org> --force`, the deliberate canary override: it lets an
     * operator enrich a couple of orgs while the fleet stays dark, so the flag can ship
     * off and be turned on after the results are checked - no second deploy. It overrides
     * this flag and NOTHING else. The rdn_api_key gate, the RDN-only candidate filters,
     * the per-org cap, the lookback window, the attempt ceiling, sealedPredicate() and
     * both rate limiters all still apply to a forced run.
     */
    public static function isEnabled(bool $forced = false): bool
    {
        return $forced || (bool) config('services.rdn.case_info_enabled');
    }

    /**
     * The Branch row RDN's vendor_branch_name names for this org: exact match first, then
     * a normalized fallback (case, whitespace and punctuation insensitive). Exact always
     * wins when present. branch_name is not unique per org, so both steps take the lowest
     * id, which keeps attribution deterministic for the append-only aggregate that freezes
     * the first write. app:rdn-backfill-branches --dry-run calls this too, without writes.
     */
    public static function findBranch(int $orgId, string $vendorBranchName): ?Branch
    {
        $exact = Branch::where('org_id', $orgId)
            ->where('branch_name', $vendorBranchName)
            ->orderBy('id')
            ->first();

        if ($exact) {
            return $exact;
        }

        $needle = self::normalizeBranchName($vendorBranchName);

        if ($needle === '') {
            return null;
        }

        return Branch::where('org_id', $orgId)
            ->whereNotNull('branch_name')
            ->orderBy('id')
            ->get(['id', 'branch_id', 'branch_name'])
            ->first(fn (Branch $branch): bool => self::normalizeBranchName($branch->branch_name) === $needle);
    }

    /**
     * Lowercase, collapse every run of non-letter/non-digit characters to one space, trim.
     */
    public static function normalizeBranchName(string $name): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($name)));
    }

    /**
     * Whether RDN refused this ONE case for this key (HTTP 500 SOAP fault "This API key is
     * not authorized to access this case"). A permanent per-case answer: the row is sealed
     * as a miss. Deliberately narrow - a key-wide refusal, any other fault or a transport
     * error must stay transport_failed, because a seal has no un-seal path and no TTL.
     * app:rdn-backfill-branches --dry-run calls this too.
     */
    public static function isCaseRefusal(?\Throwable $exception): bool
    {
        return $exception instanceof \SoapFault
            && Str::contains($exception->getMessage(), 'not authorized to access this case', ignoreCase: true);
    }

    /**
     * $forced is carried on the payload, not read from a global: this job is queued behind
     * a spread of up to a whole run interval, so a canary's override has to survive
     * serialization and a worker restart to still be true when the job is picked up.
     */
    public function __construct(
        public int $orgId,
        public ?Org $org,
        public int $hotlistId,
        public ?Hotlist $hotlist = null,
        public ?SoapClient $client = null,
        public bool $forced = false,
    ) {
        $this->onQueue('{low}');

        // The worker reads timeout off the dispatch payload (Queue::createObjectPayload),
        // so it is resolved here, at push, alongside retryUntil().
        $this->timeout = self::timeoutSeconds();
        $this->uniqueFor = self::maxSpreadSeconds() + (self::drainMarginMinutes() * 60);

        if (! $org) {
            $this->org = Org::find($this->orgId);
        }

        if (! $hotlist) {
            $this->hotlist = Hotlist::find($this->hotlistId);
        }
    }

    /**
     * The single admission gate for this job's one SOAP call, on its own limiter key
     * rather than the shared 'rdn-api' one: the per-org default this job needs would
     * otherwise start throttling GetOrgBranches, GetOrgRdnClients, GetOrgAgents and
     * GetOrgBranchZips, which still carry $tries = 3 and would burn it on releases.
     *
     * RateLimited charges once per JOB, and this job is exactly one SOAP call, so per-job
     * and per-call coincide here - which is why handle() calls RDN::gatedByQueue(). The
     * four RdnJob siblings paginate, so they do NOT coincide there and those jobs keep the
     * service-level limiter; do not copy the call over. The two layers were never sharing
     * a bucket either way - RateLimited keys on md5(limiterName.key).
     */
    public function middleware(): array
    {
        return [new RateLimited('rdn-case-info')];
    }

    /**
     * One in-flight enrichment per hotlist. Re-running app:rdn (the canary) used to
     * re-select the same rows into the same delay slots, burning two attempts for one
     * answer. UniqueUntilProcessing holds the lock while the job is delayed and releases
     * it when the worker starts, so a later scheduled run can still retry a miss.
     */
    public function uniqueId(): string
    {
        return (string) $this->hotlistId;
    }

    /**
     * A limiter release consumes an attempt, so the retry budget has to be a time window
     * rather than a try count. The worker applies this deadline in
     * markJobAsFailedIfAlreadyExceedsMaxAttempts(), before handle() or any middleware
     * runs, so it must outlast the worst-case QUEUE WAIT - not this org's RDN limit.
     *
     * The deadline is stamped at PUSH, but the job is not available until its dispatch
     * spread elapses, so the spread is spent before draining even begins. Adding the two
     * keeps that dependence in the code rather than in a constant that happened to be
     * large enough: widening maxSpreadSeconds() widens the deadline with it, and
     * GetOrgHotlistCaseInfoTest pins the relationship.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(
            intdiv(self::maxSpreadSeconds(), 60) + self::drainMarginMinutes()
        );
    }

    public function handle(): void
    {
        if (! self::isEnabled($this->forced)) {
            return;
        }

        if ($this->isSealed()) {
            $this->recordOutcome(self::OUTCOME_SEALED);

            return;
        }

        if (config('app.env') === 'production') {
            Nightwatch::sample(rate: 0.3);
        }

        $rdn = RDN::make(org: $this->org, client: $this->client)->gatedByQueue();

        $result = $rdn->call(
            method: 'getRdnCaseInfo',
            args: [
                'case' => $this->hotlist->remote_order_id,
            ]
        );

        $branchId = null;
        $rdnNamedABranch = false;

        if ($caseInfo = ($result['items'][0] ?? false)) {
            if (isset($caseInfo->vendor_branch_name)) {
                $rdnNamedABranch = true;

                $branch = self::findBranch($this->org->id, (string) $caseInfo->vendor_branch_name);

                $branchId = $branch?->branch_id;
            }
        }

        if ($branchId !== null) {
            $this->writeBranchId($branchId);
            $this->recordOutcome(self::OUTCOME_RESOLVED);

            return;
        }

        // Only an ANSWER costs this hotlist one of its attempts: a case RDN has no record
        // of, or a vendor_branch_name no Branch row carries. A call that never reached RDN
        // is not an answer - RDN::call() flattens a SOAP fault, a TLS or DNS failure, a
        // connect timeout and a rotated key into the same EMPTY_RESULT an empty response
        // gives - and at a derived ceiling of one attempt, counting it would seal the row
        // on a blip with no un-seal path and no TTL. Leave it un-enriched and
        // un-incremented; the next scheduled run re-selects it and retries. The one fault
        // that is an answer is RDN refusing this key for this case (isCaseRefusal()): it
        // never changes, so it is a miss that seals the row on first sight.
        if (self::isCaseRefusal($rdn->lastCallException())) {
            $this->recordUnresolvedLookup(seal: true);
            $this->recordOutcome(self::OUTCOME_MISS_NO_BRANCH_NAME);

            return;
        }

        if (! $rdn->lastCallCompleted()) {
            $this->recordOutcome(self::OUTCOME_TRANSPORT_FAILED);

            return;
        }

        $this->recordUnresolvedLookup();

        $this->recordOutcome($rdnNamedABranch
            ? self::OUTCOME_MISS_NO_BRANCH_MATCH
            : self::OUTCOME_MISS_NO_BRANCH_NAME);
    }

    /**
     * The per-job half of this loop's visibility - a counter, not a log line, because a
     * fleet run is ~76k of these. The durable record of every outcome is already in
     * hotlist_json (branch_id set, or the attempt counter incremented); this only makes
     * the shape of a run readable without querying for it.
     *
     * The two miss buckets are the point. The branch match is exact string equality
     * between RDN's vendor_branch_name and Branch.branch_name, then a normalized fallback
     * (see findBranch()), so a 0% resolve rate has two completely different causes: RDN
     * answering with no case at all (NO_BRANCH_NAME - credentials, ids, or genuinely unknown cases)
     * versus RDN naming a branch this org has no row for (NO_BRANCH_MATCH - a string
     * format or a GetOrgBranches sync problem). One counter cannot tell them apart.
     *
     * Counts, org ids and outcome names only - no plate, VIN, case number, remote order id
     * or vendor branch name is emitted. Failures are swallowed for the same reason
     * RdnMetricsPublisher swallows them: a thrown counter would fail the job and retry it,
     * and on the miss path that re-runs recordUnresolvedLookup() and burns a second
     * attempt for one answer.
     */
    private function recordOutcome(string $outcome): void
    {
        try {
            logDatadogCount('rdn_case_info.'.$outcome, 1, [
                'category' => 'rdn_case_info',
                'item' => 'org_'.$this->orgId,
            ]);
        } catch (\Throwable) {
        }
    }

    private function isSealed(): bool
    {
        return HotlistJson::query()
            ->where('hotlist_id', $this->hotlist->id)
            ->whereRaw(self::sealedPredicate(), [self::maxLookupAttempts()])
            ->exists();
    }

    /**
     * hotlist_json.json is externally owned; jsonb_set touches only branch_id instead of
     * round-tripping the whole blob through the DTO and wiping unknown keys. A single
     * upsert keeps concurrent runs from colliding on the hotlist_id primary key.
     *
     * A resolved lookup writes branch_id and nothing else: branch_id seals the row on its
     * own, so an attempt counter would be dead weight in someone else's blob.
     */
    private function writeBranchId(int $branchId): void
    {
        $value = json_encode($branchId);
        $now = now();

        (new HotlistJson)->getConnection()->statement(
            <<<'SQL'
            insert into public.hotlist_json (hotlist_id, "json", created_at, updated_at)
            values (?, jsonb_set('{}'::jsonb, '{branch_id}', ?::jsonb, true), ?, ?)
            on conflict (hotlist_id) do update set
                "json" = jsonb_set(coalesce(hotlist_json."json", '{}'::jsonb), '{branch_id}', ?::jsonb, true),
                updated_at = ?
            SQL,
            [$this->hotlist->id, $value, $now, $now, $value, $now],
        );
    }

    /**
     * Records a miss: branch_id stays null so the row is still un-enriched, and the miss
     * counter goes up by one in the same statement so the increment cannot be lost to a
     * concurrent run. Reaching maxLookupAttempts() is what stops sealedPredicate() from
     * selecting this row again - see the note there for why a null alone is not enough.
     *
     * The upsert protects the primary key, not the value, so branch_id is only nulled
     * when it is not already resolved: a concurrent run - or the blob's external producer
     * - can write one between this job's guard and this statement, and blanking it would
     * un-enrich a row that is done. ENRICHED_PREDICATE is the same test sealedPredicate()
     * applies, so "already resolved" cannot mean two different things.
     *
     * The counter is cast ::numeric under the same jsonb_typeof guard the read path uses.
     * A ::int here would raise on a fractional or oversized number that read as valid,
     * after the SOAP call had already been spent, leaving the row permanently unsealable.
     *
     * $seal writes the counter to at least the ceiling, for an answer that will never
     * change (isCaseRefusal()).
     */
    private function recordUnresolvedLookup(bool $seal = false): void
    {
        $now = now();
        $enriched = self::ENRICHED_PREDICATE;
        $floor = $seal ? self::maxLookupAttempts() : 0;

        (new HotlistJson)->getConnection()->statement(
            <<<SQL
            insert into public.hotlist_json (hotlist_id, "json", created_at, updated_at)
            values (
                ?,
                jsonb_set(
                    jsonb_set('{}'::jsonb, '{branch_id}', 'null'::jsonb, true),
                    '{branch_lookup_attempts}',
                    to_jsonb(greatest(1, ?::numeric)),
                    true
                ),
                ?,
                ?
            )
            on conflict (hotlist_id) do update set
                "json" = jsonb_set(
                    case
                        when {$enriched}
                            then coalesce(hotlist_json."json", '{}'::jsonb)
                        else jsonb_set(coalesce(hotlist_json."json", '{}'::jsonb), '{branch_id}', 'null'::jsonb, true)
                    end,
                    '{branch_lookup_attempts}',
                    to_jsonb(
                        greatest((case
                            when jsonb_typeof(hotlist_json."json" -> 'branch_lookup_attempts') = 'number'
                                then (hotlist_json."json" ->> 'branch_lookup_attempts')::numeric
                            else 0
                        end) + 1, ?::numeric)
                    ),
                    true
                ),
                updated_at = ?
            SQL,
            [$this->hotlist->id, $floor, $now, $now, $floor, $now],
        );
    }
}
