<?php

namespace App\Console\Commands;

use App\Clickhouse\Models\ZodPublicHotlist;
use App\Enums\HotlistStatusEnum;
use App\Jobs\Rdn\GetOrgHotlistCaseInfo;
use App\Jobs\Rdn\GetOrgHotlistCases;
use App\Models\Hotlist;
use App\Models\Org;
use App\Services\RDN;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;

/**
 * Operator backfill of hotlist branch_id for one org, run inline: walks the eligible
 * hotlists newest first and runs GetOrgHotlistCaseInfo::handle() for each in-process
 * (same RDN call, Branch match, writes and outcome counters), admitted one call at a time
 * on the job's own rdn-case-info limiter bucket (shared with the scheduled case-info jobs).
 * Nothing is queued.
 *
 * Candidates come from the ClickHouse CDC replica (zod.public_hotlist), read once per run,
 * and each chunk's ids are re-checked on Postgres before any RDN call. The replica runs a
 * few minutes behind Postgres at most, so a hotlist created or updated just before or during
 * a run can wait for a later run. A ClickHouse failure is not caught: the run stops before
 * any RDN call, write or cursor save, and never falls back to Postgres.
 *
 * Resume: the cursor is saved after each chunk, so re-running the same --since/--until
 * resumes (at most one chunk is re-walked). Not scheduled - run by hand, dry run first.
 */
class RdnBackfillBranches extends Command
{
    /** Seconds the dry run backs off once when the service-level RDN limiter throttles it. */
    public const THROTTLE_BACKOFF_SECONDS = 60;

    protected $signature = 'app:rdn-backfill-branches
        {org : Org id}
        {--since= : Activity window start (Y-m-d, UTC midnight, inclusive)}
        {--until= : Activity window end (Y-m-d, UTC midnight, exclusive; default now)}
        {--chunk=500 : Hotlists per page; the resume cursor is saved after each page}
        {--max= : Stop after walking this many hotlists this run, incl. failed (re-run to resume from the cursor)}
        {--dry-run= : Call RDN for N sample hotlists across age buckets and write nothing (bare flag: 30; never runs live)}';

    protected $description = 'Backfill hotlist branch_id from RDN for one org\'s hotlists with activity in a window (newest first, throttled, resumable).';

    public function handle(): int
    {
        $org = Org::find($this->argument('org'));

        if (! $org) {
            $this->error('Org not found.');

            return self::FAILURE;
        }

        if (! $org->hasRdnApiKey()) {
            $this->error("Org {$org->id} has no RDN api key.");

            return self::FAILURE;
        }

        // Every lookup would no-op with the switch off, and the cursor would walk
        // past rows that were never enriched.
        if (! GetOrgHotlistCaseInfo::isEnabled()) {
            $this->error('services.rdn.case_info_enabled is off; refusing to run.');

            return self::FAILURE;
        }

        try {
            $since = $this->dateOption('since');
            $until = $this->dateOption('until');
        } catch (\Throwable) {
            $this->error('--since / --until must be dates in Y-m-d format (e.g. 2025-01-01).');

            return self::FAILURE;
        }

        if (! $since) {
            $this->error('--since is required.');

            return self::FAILURE;
        }

        if ($until && $until->lte($since)) {
            $this->error('--until must be after --since.');

            return self::FAILURE;
        }

        $chunk = $this->positiveIntOption('chunk');
        $max = $this->option('max') === null ? PHP_INT_MAX : $this->positiveIntOption('max');

        // A bare --dry-run reads as null, so detect the flag by presence: it samples 30 and
        // never falls through to the live backfill.
        $dryRun = $this->input->hasParameterOption('--dry-run');
        $sample = match (true) {
            ! $dryRun => null,
            $this->option('dry-run') === null => 30,
            default => $this->positiveIntOption('dry-run'),
        };

        if ($chunk === null || $max === null || ($dryRun && $sample === null)) {
            $this->error('--chunk, --max and --dry-run must be positive integers.');

            return self::FAILURE;
        }

        if ($dryRun) {
            return $this->dryRun($org, $since, $until ?? CarbonImmutable::now('UTC'), $sample);
        }

        return $this->backfill($org, $since, $until, $chunk, $max);
    }

    private function backfill(Org $org, CarbonImmutable $since, ?CarbonImmutable $until, int $chunk, int $max): int
    {
        $cursorKey = self::cursorKey($org->id, $since, $until);
        $until ??= CarbonImmutable::now('UTC');
        $cursor = Cache::get($cursorKey);

        $walked = 0;
        $processed = 0;
        $failed = 0;
        $complete = false;

        // One ClickHouse read per run, ahead of the RDN client and the try: a ClickHouse
        // failure propagates from here, before any RDN call, write, cursor save or summary.
        // Flat ints only (no models), paged below with array_slice.
        $ids = array_map('intval', $this->candidates($org, $since, $until, $cursor)->pluck('id')->all());
        $offset = 0;

        $this->line('ClickHouse candidates: '.count($ids).'.');

        $client = app(RDN::class, ['org' => $org, 'client' => null])->client;

        try {
            while ($walked < $max) {
                $page = array_slice($ids, $offset, min($chunk, $max - $walked));

                // Done only when the ClickHouse list runs out, never on a page Postgres emptied.
                if ($page === []) {
                    $complete = true;
                    Cache::forget($cursorKey);

                    break;
                }

                $offset += count($page);

                $hotlists = $this->eligible($org, $page)
                    ->orderByDesc('hotlist.created_at')
                    ->orderByDesc('hotlist.id')
                    ->get();

                foreach ($hotlists as $hotlist) {
                    $walked++;

                    $job = new GetOrgHotlistCaseInfo(
                        orgId: $org->id,
                        org: $org,
                        hotlistId: $hotlist->id,
                        hotlist: $hotlist,
                        client: $client,
                    );

                    // Run inline, this loop stands in for the job's RateLimited('rdn-case-info')
                    // middleware - same limiter and md5(name.key) bucket - so the backfill and the
                    // scheduled case-info jobs share one per-org budget.
                    $limit = RateLimiter::limiter('rdn-case-info')($job);
                    $limiterKey = md5('rdn-case-info'.$limit->key);

                    while (RateLimiter::tooManyAttempts($limiterKey, $limit->maxAttempts)) {
                        Sleep::for(max(1, RateLimiter::availableIn($limiterKey)))->seconds();
                    }

                    RateLimiter::hit($limiterKey, $limit->decaySeconds);

                    try {
                        $job->handle();
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('rdn.backfill_branches.failed', [
                            'org_id' => $org->id,
                            'hotlist_id' => $hotlist->id,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                // Raw value keeps microseconds so the keyset never skips a same-second row.
                // A page Postgres emptied (sealed, changed or missing rows) leaves the cursor
                // where it was; on resume those ids are re-checked and drop again.
                if ($hotlists->isNotEmpty()) {
                    $last = $hotlists->last();
                    $cursor = ['created_at' => $last->getRawOriginal('created_at'), 'id' => $last->id];
                    Cache::forever($cursorKey, $cursor);
                }

                $this->line("Chunk: {$hotlists->count()} walked, {$processed} processed so far.");
            }
        } finally {
            $summary = [
                'org_id' => $org->id,
                'since' => $since->toDateTimeString(),
                'until' => $until->toDateTimeString(),
                'chunk' => $chunk,
                'max' => $max === PHP_INT_MAX ? null : $max,
                'walked' => $walked,
                'processed' => $processed,
                'failed' => $failed,
                'cursor' => $cursor['id'] ?? null,
                'complete' => $complete,
            ];

            try {
                Log::info('rdn.backfill_branches.run', $summary);
            } catch (\Throwable) {
            }
        }

        $this->info(sprintf(
            '%s: walked %d, processed %d, failed %d.',
            $complete ? 'Complete' : 'Stopped at --max (re-run to resume)',
            $walked,
            $processed,
            $failed,
        ));

        return self::SUCCESS;
    }

    /**
     * Samples RDN across age buckets and classifies each answer exactly as
     * GetOrgHotlistCaseInfo::handle() would, without any of its writes: no hotlist_json
     * write or attempt bump, no outcome counter, no cursor.
     *
     * The candidates are read once from ClickHouse and bucketed by created_at in PHP; each
     * bucket's sample is re-checked on Postgres, so a sampled row that is already sealed or
     * changed drops out and that bucket reports the smaller real count (no refill).
     */
    private function dryRun(Org $org, CarbonImmutable $since, CarbonImmutable $until, int $sample): int
    {
        $now = CarbonImmutable::now('UTC');
        $per = intdiv($sample, 3);
        $limits = ['<30d' => $per + $sample % 3, '30-180d' => $per, '>180d' => $per];

        $candidates = $this->candidates($org, $since, $until)->toBase()->get();

        $this->line('ClickHouse candidates: '.$candidates->count().'.');

        $recent = $now->subDays(30);
        $old = $now->subDays(180);
        $buckets = array_fill_keys(array_keys($limits), []);

        foreach ($candidates as $row) {
            /** @var array{id: int|string, created_at: string} $row */
            $createdAt = CarbonImmutable::parse($row['created_at'], 'UTC');

            $bucket = match (true) {
                $createdAt->gte($recent) => '<30d',
                $createdAt->gte($old) => '30-180d',
                default => '>180d',
            };

            $buckets[$bucket][] = (int) $row['id'];
        }

        $outcomes = [
            GetOrgHotlistCaseInfo::OUTCOME_RESOLVED,
            GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_NAME,
            GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_MATCH,
            GetOrgHotlistCaseInfo::OUTCOME_TRANSPORT_FAILED,
        ];

        $rows = [];
        $unmatched = [];

        foreach ($limits as $label => $limit) {
            $counts = array_fill_keys($outcomes, 0);
            $ids = $limit > 0 ? collect($buckets[$label])->shuffle()->take($limit)->all() : [];
            $hotlists = $ids === [] ? collect() : $this->eligible($org, $ids)->get();

            foreach ($hotlists as $hotlist) {
                $counts[$this->classify($org, $hotlist, $unmatched)]++;
            }

            $rows[] = [$label, $hotlists->count(), ...array_values($counts)];
        }

        $this->table(['bucket', 'sampled', ...$outcomes], $rows);

        arsort($unmatched);
        $this->table(['unmatched vendor_branch_name', 'count'], collect($unmatched)
            ->map(fn (int $count, string $name): array => [$name, $count])
            ->values()
            ->all());

        $this->table(['org branch_name'], $org->branches()
            ->whereNotNull('branch_name')
            ->orderBy('branch_name')
            ->pluck('branch_name')
            ->map(fn (string $name): array => [$name])
            ->all());

        return self::SUCCESS;
    }

    /**
     * The same three-way rule as GetOrgHotlistCaseInfo::handle(): resolved, else a call that
     * never completed, else a named branch nothing matched vs no branch named at all.
     * A per-case refusal (isCaseRefusal()) is a no-branch-name miss.
     * The non-gated RDN client may refresh org_meta.properties.rdn_rate_limit when RDN
     * reports a changed limit: client metadata, not branch data (Q2 PM-accepted 2026-10-07;
     * human confirm at HITL).
     *
     * @param  array<string, int>  $unmatched
     */
    private function classify(Org $org, Hotlist $hotlist, array &$unmatched): string
    {
        // Not gatedByQueue(): this caller has no RateLimited middleware admitting it.
        $rdn = app(RDN::class, ['org' => $org, 'client' => null]);
        $args = ['case' => $hotlist->remote_order_id];

        try {
            $result = $rdn->call('getRdnCaseInfo', $args);
        } catch (ThrottleRequestsException) {
            Sleep::for(self::THROTTLE_BACKOFF_SECONDS)->seconds();

            try {
                $result = $rdn->call('getRdnCaseInfo', $args);
            } catch (ThrottleRequestsException) {
                return GetOrgHotlistCaseInfo::OUTCOME_TRANSPORT_FAILED;
            }
        }

        $vendorBranchName = null;

        if (($caseInfo = ($result['items'][0] ?? false)) && isset($caseInfo->vendor_branch_name)) {
            $vendorBranchName = (string) $caseInfo->vendor_branch_name;

            if (GetOrgHotlistCaseInfo::findBranch($org->id, $vendorBranchName)?->branch_id !== null) {
                return GetOrgHotlistCaseInfo::OUTCOME_RESOLVED;
            }
        }

        if (GetOrgHotlistCaseInfo::isCaseRefusal($rdn->lastCallException())) {
            return GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_NAME;
        }

        if (! $rdn->lastCallCompleted()) {
            return GetOrgHotlistCaseInfo::OUTCOME_TRANSPORT_FAILED;
        }

        if ($vendorBranchName === null) {
            return GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_NAME;
        }

        $unmatched[$vendorBranchName] = ($unmatched[$vendorBranchName] ?? 0) + 1;

        return GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_MATCH;
    }

    /**
     * GetOrgHotlistCases::candidates() eligibility and its sealed filter, with the
     * created_at lookback replaced by activity in the window: an update to the hotlist row
     * (updated_at) in it, a repo date in it, or currently Open/Hold.
     *
     * Read from the ClickHouse CDC replica (FINAL = latest version per id, tombstones
     * dropped), newest first, ids and created_at only. The replica lags Postgres, so this is
     * a nomination: eligible() re-checks the ids on Postgres, which stays the source of truth.
     * $cursor is the last walked row (raw created_at with microseconds, id); the keyset
     * compares both on DateTime64(6), so a same-second row is never skipped.
     *
     * @param  array{created_at: string, id: int}|null  $cursor
     * @return Builder<ZodPublicHotlist>
     */
    private function candidates(Org $org, CarbonImmutable $since, CarbonImmutable $until, ?array $cursor = null): Builder
    {
        return ZodPublicHotlist::query()
            ->final()
            ->forOrg($org->id)
            ->where('integration_id', IntegrationEnum::RDN->value)
            ->whereNotNull('remote_order_id')
            ->whereIntegerInRaw('status', GetOrgHotlistCases::ANALYTICS_STATUSES)
            ->where(fn (Builder $query) => $query
                ->whereIntegerInRaw('status', [HotlistStatusEnum::Open->value, HotlistStatusEnum::Hold->value])
                ->orWhere(fn (Builder $updated) => $updated
                    ->where('updated_at', '>=', $since->toDateTimeString())
                    ->where('updated_at', '<', $until->toDateTimeString()))
                ->orWhereRaw(
                    'id IN (SELECT hotlist_id FROM public_hotlist_meta FINAL WHERE _peerdb_is_deleted = 0 AND repo_date >= ? AND repo_date <= ?)',
                    [$since->toDateString(), $until->subSecond()->toDateString()],
                ))
            ->when($cursor, fn (Builder $query) => $query->whereRaw(
                '(created_at, id) < (toDateTime64(?, 6), ?)',
                [$cursor['created_at'], $cursor['id']],
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->select(['id', 'created_at']);
    }

    /**
     * Postgres re-check for ClickHouse candidates: same org, RDN, case id, analytics status,
     * not sealed. Activity is decided in candidates().
     *
     * @param  array<int, int>  $ids
     * @return HasMany<Hotlist, Org>
     */
    private function eligible(Org $org, array $ids): HasMany
    {
        return $org->hotlists()
            ->whereIntegerInRaw('hotlist.id', $ids)
            ->where('hotlist.integration_id', IntegrationEnum::RDN->value)
            ->whereNotNull('hotlist.remote_order_id')
            ->whereIntegerInRaw('hotlist.status', GetOrgHotlistCases::ANALYTICS_STATUSES)
            ->whereDoesntHave('hotlistJson', fn (Builder $query) => $query->whereRaw(
                GetOrgHotlistCaseInfo::sealedPredicate(),
                [GetOrgHotlistCaseInfo::maxLookupAttempts()],
            ));
    }

    /** Keyed by window so a different window starts fresh. Losing it only re-walks. */
    public static function cursorKey(int $orgId, CarbonImmutable $since, ?CarbonImmutable $until): string
    {
        return 'rdn-backfill-branches:'.$orgId.':'.$since->utc()->format('Y-m-d\\THis\\Z')
            .':'.($until?->utc()->format('Y-m-d\\THis\\Z') ?? 'open');
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    /** A Y-m-d option as UTC midnight, or null when not given; throws on anything else. */
    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if (! $value) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value, 'UTC');

        // createFromFormat() also takes 2026-9-1 and rolls 2026-02-31 into March.
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("--{$name} must be Y-m-d.");
        }

        return $date;
    }
}
