<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\LprRepossessionsQuery;
use App\Dto\CardQueryStatement;
use App\Facades\Analytics;
use App\Filament\Exports\LprRepossessionExporter;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\HeldFlexibleCache;
use App\Filament\Widgets\Dashboard\Concerns\RatioAgainstUniqueScans;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\ExportAction;
use Filament\Support\RawJs;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

/**
 * DEV-2464. The `db_repos` funnel stage from LprRepossessionsQuery -- billed claims that
 * went on to a "recovered" hotlist transition within the backyard repo window, NOT the
 * `deduped` (billed-claims-only) stage its sibling UniqueLprCaseClaims reads.
 *
 * Near-twin of UniqueLprCaseClaims (DEV-2463): same guard ordering, same two-calendars
 * seam (UTC month axis for the series, viewer-local day for today/last_7d), same
 * propagate-don't-swallow stance. See that class's docblock for the fuller rationale;
 * this one only records what differs.
 *
 * MEASURED ENVELOPE (t5-measurement.md, run 2026-09-09 against production on SQL rendered
 * out of LprRepossessionsQuery by reflection, so there is no drift between what ships and
 * what was measured): the 13-month series this card renders costs 584,044,321 read_rows /
 * 13.18 GB / 2.79 GB peak server-side memory, 6.00s median wall. One month costs the same
 * rows and 2.22 GB, so widening 1 -> 13 months is 1.25x peak memory -- not the ~13x two
 * review lanes predicted. The org filter buys nothing: read_rows is byte-identical for a
 * single org and platform-wide, because every source table is ORDER BY id.
 *
 * The guard constants below are derived from two FIXED production ceilings -- the client
 * query timeout and the proxy's request budget -- not from those latencies.
 *
 * What stays unmeasured is the CONTENDED p99 (every t5 figure is one query at a time) and the
 * all-cold cross-org ceiling. State that ceiling with BOTH legs counted, because the two can be
 * cold in the same render, and with the window leg's key-space counted properly:
 *
 *   series leg   29 entitled orgs x 2.79 GB                 ~= 81 GB
 *   window leg   29 orgs x V viewer timezones x 2.22 GB     (V = distinct zones per org)
 *
 * CORRECTION (review round 14, lane 1): this docblock previously counted the window leg ONCE and
 * landed on ~145 GB. That was wrong in the unsafe direction, and wrong against this class's own
 * reasoning -- currentPeriodTotal()'s docblock explains that the window key carries `:tz=` and
 * that the cold mutex is derived from the key, so the window leg forks per zone and cross-zone
 * viewers of one org do NOT serialise on it. The `:tz=` segment is correctness-required; the
 * arithmetic was the error. Note also the window leg only computes when a viewer selects
 * today/last_7d -- the default filter is this_month.
 *
 * Cross-org requests never serialise (the mutex is per cache key), and t5 read the server as
 * max_memory_usage = 0, max_memory_usage_for_user = 0, max_bytes_in_join = 0 and
 * max_execution_time = 0 -- so the 60s timeout_query is CLIENT-side only and an abandoned query
 * keeps consuming. The instance's total RAM is unreadable from readonly_user, so this can be
 * neither shown fatal nor shown safe. Recorded deferral, not a solved problem; the batch shape
 * (plan node dc1) is its mitigation.
 *
 * DEV-2651 t4. Absorbs unique-scans-to-lpr-repossession's ratio (unique scans / db_repos) as
 * a description line + second chart series via RatioAgainstUniqueScans. The trait supplies
 * the numerator and its own degrade path; the ratio is zipped against getMonthlyData()'s
 * OWN 13-bucket array rather than a second, independently-bucketed denominator fetch (see
 * getRatioMonthlyData()).
 *
 * DEV-2652. The unique-scans numerator is viewer-local. The denominator is UTC be.ts
 * (claim_date/bucket), not repo_date. AC5 defer keeps it there so the claims funnel stays
 * reconcilable to Brek's Repo Report. A :tz= segment on the monthly funnel key would rescan
 * a UTC-identical series once per viewer.
 *
 * The flexible TTL is the ladder's index-0 arm (10 minutes, or 10 seconds while
 * impersonating), passed as [seconds, seconds] so the hard TTL cannot outlive the
 * ladder. The 33.7s tail can outlast that impersonation TTL; the cold lock still
 * single-flights a total miss. Do not floor the pair back up to an hour.
 */
class LprRepossessions extends DashboardWidget implements ExplainsCardQuery, HasActions
{
    use HeldFlexibleCache;
    use InteractsWithActions;
    use RatioAgainstUniqueScans;

    /** Headline clock. The numerator reads numeratorZone(); it must not read this. */
    private const SERIES_ZONE = 'UTC';

    /**
     * ExportCsv page size. Chosen on its own: maxRows is omitted, so a headline
     * larger than this still exports, across more than one chunk.
     */
    private const EXPORT_CHUNK_SIZE = 10000;

    /**
     * The largest single-run wall clock observed for this shape: 33.65s, one of two maxima
     * (16.5s and 33.7s) across 16 samples ON SHARED CLOUD (perf-measurement.md section 4). Each
     * sample was one query at a time, so no CONCURRENCY OF OURS is modelled -- but "uncontended"
     * would be the wrong word, since the artifact attributes the tail to landing in a contention
     * window on shared infrastructure. That distinction matters: it is why the contended p99 is
     * still described as unmeasured while this figure is treated as a real observed tail.
     *
     * t5 later measured a 6.00s median for the shipped SQL, so this is the tail rather than the
     * typical case, and it is NOT what the windows below are derived from. It stays because
     * COLD_LOCK_SECONDS is checked against it: the lock has to outlast the worst run actually
     * seen, not just the median.
     */
    public const OBSERVED_MAX_LATENCY_SECONDS = 33.7;

    /**
     * The production ceiling on how long a holder can occupy the lock: config/database.php
     * defaults clarity_clickhouse's `timeout_query` to 60s. The local .env sets 20; production
     * takes the default, so every window here is sized against 60.
     *
     * clarity_clickhouse carries `timeout_query` but NOT `max_execution_time` (unlike
     * public_clickhouse), and t5 read `max_execution_time = 0` server-side -- so the timeout is
     * CLIENT-side only, and an abandoned query keeps consuming ClickHouse resources after the
     * waiter that started it has gone.
     */
    private const QUERY_TIMEOUT_SECONDS = 60;

    /**
     * The total time ONE request may spend blocked on cold locks, across every guarded leg.
     *
     * config/deploy.yml sets no `proxy.response_timeout`, so kamal-proxy's 30s default applies:
     * past 30s the client is already gone and a waiter is blocking for nobody. Review round 11
     * flagged that the then-45s block sat below QUERY_TIMEOUT_SECONDS and it was raised to 75 --
     * which round 12 found made a two-leg cold render block up to 150s, five times the request
     * budget, converting "maybe a second scan" into "a certain 504". Budget UNDER the proxy
     * window instead, leaving headroom for the rest of the render.
     */
    private const COLD_BLOCK_BUDGET_SECONDS = 24;

    /**
     * Both guarded legs -- the 13-month series and the today/last_7d window -- can be cold in
     * the SAME render, so their block windows add up. The budget therefore divides between them
     * rather than being handed to each. Must divide evenly; the sizing test asserts that.
     */
    private const GUARDED_COLD_LEGS = 2;

    /**
     * Per-leg block window. ACCEPTED WEAKNESS, recorded here because round 11 identified it
     * correctly and round 12 identified, also correctly, that the obvious remedy is worse: 12s
     * is well below QUERY_TIMEOUT_SECONDS, so against a slow holder a waiter WILL time out and
     * compute its own 2.79 GB scan. Blocking longer does not prevent that; it only moves the
     * loss from a second scan to a guaranteed timeout, because the proxy has already hung up.
     * The real fix is to not compute synchronously on a total miss at all (out-of-band warming,
     * plan node dc1). Until then this is the lesser of two documented evils -- and it is the
     * value DEV-2463 arrived at, for this same reason rather than by inheritance.
     */
    private const COLD_BLOCK_SECONDS = self::COLD_BLOCK_BUDGET_SECONDS / self::GUARDED_COLD_LEGS;

    /**
     * The cold lock's own TTL. Must outlast a holder that runs all the way to the client timeout
     * AND the waiters queued behind it, or the holder loses the mutex mid-query and a waiter
     * starts a second copy. Deliberately NOT equal to REFRESH_LOCK_SECONDS: the two accessors
     * the trait calls are near-identical one-liners, and equal values would make swapping them
     * undetectable.
     */
    private const COLD_LOCK_SECONDS = self::QUERY_TIMEOUT_SECONDS + self::COLD_BLOCK_SECONDS + 18;

    /**
     * The expiry handed to Cache::flexible for its OWN deferred refresh lock, which guards the
     * stale-serve branch, not the total miss. Must exceed the query timeout so a worker killed
     * mid-refresh cannot wedge the key until the hard TTL. It needs no block-window headroom
     * because nothing blocks on it -- which is why it is a smaller, distinct number.
     */
    public const REFRESH_LOCK_SECONDS = self::QUERY_TIMEOUT_SECONDS + 15;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'LPR Repossessions';

    /**
     * The stock chart view has no header-action slot. The expanded card carries the
     * export control in the heading so the vendor blade stays untouched. Compact stats
     * keep the plain string: they are built with expanded = false.
     */
    public function getHeading(): string|Htmlable|null
    {
        if (! $this->expanded) {
            return $this->heading;
        }

        $action = $this->cacheAction($this->exportAction());

        if (! $action->isVisible()) {
            return $this->heading;
        }

        return new HtmlString(view('filament.widgets.dashboard.lpr-repossession-export-heading', [
            'heading' => $this->heading,
            'action' => $action,
        ])->render());
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Billed case claims that went on to a recovered repossession in this period.';
    }

    public function getDatasetLabel(): string
    {
        return 'LPR Repossessions';
    }

    /**
     * Review-2 M2: the first attempt at this used grey 0.45, which sits between
     * makeDataset()'s own non-highlighted fallback (grey 0.3) and its hover grey 0.6 -- it
     * swapped an accent collision for a grey one. Amber is off both palettes: neither card's
     * accent (green / blue) nor any grey makeDataset() reaches for.
     */
    public function getRgbColor(): array
    {
        return [0, 45, 211];
    }

    protected function coldBlockSeconds(): int
    {
        return self::COLD_BLOCK_SECONDS;
    }

    protected function coldLockSeconds(): int
    {
        return self::COLD_LOCK_SECONDS;
    }

    protected function refreshLockSeconds(): int
    {
        return self::REFRESH_LOCK_SECONDS;
    }

    /**
     * Index 0 of the TTL ladder, as a flexible [fresh, hard] pair in seconds.
     * Both bounds match so the hard TTL cannot outlive the ladder. The series
     * includes the open month, and the today/last_7d window is open too, so
     * neither call site uses the year arm.
     *
     * @return array{0: int, 1: int}
     */
    private function ladderFlexibleTtl(): array
    {
        $seconds = $this->getCacheTTL(0)->getTimestamp() - now()->getTimestamp();

        return [$seconds, $seconds];
    }

    /**
     * ONE heldFlexible() call around the 13-month UTC series, replacing the base's
     * up-to-13-query per-month fan-out. The series includes the open month, so the TTL
     * is the ladder's index-0 arm, not the year arm. Preserves the $monthlyDataMemo and
     * formatMonthlyData() contracts. Reads the 'db_repos' stage, not 'deduped' -- that is
     * the funnel stage this card reports. A month with no rows is 0, never missing.
     *
     * @return array<int, int|float>
     */
    protected function getMonthlyData(bool $formatted = true): array
    {
        $memoKey = $formatted ? 'formatted' : 'raw';

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $orgId = auth()->user()->org_id;
        $through = now(self::SERIES_ZONE);

        // The key MUST carry the window's identity. Keyed only on the org, a series
        // computed at 23:55 on the last day of a UTC month is still "fresh" minutes into
        // the next one, the new month's bucket is absent, and the ?? 0 below fabricates a
        // zero -- indistinguishable from a legitimate zero, which this card treats as a
        // normal reading: 4 of 29 entitled orgs read 0 in Jul 2026 (25/29 non-zero). The figure
        // is month-specific -- Aug 2026 measured 29/29 non-zero -- so a zero is uncommon rather
        // than impossible, which is exactly why it must not be fabricated.
        // Shared, class-neutral key (DEV-2469). UniqueScansToLprRepossession, its original
        // co-reader, was absorbed into this card by DEV-2651 t8 -- this card is now the only
        // reader of this exact key, but it stays class-neutral rather than reverting to a
        // class_basename prefix: RatioAgainstUniqueScans and UniqueScansLeaderboard already
        // establish the same class-neutral shared-cache convention for this grid's other
        // heavy scans, so a future reader of this 584M-row leg can join without a key migration.
        $series = $this->heldFlexible(
            LprRepossessionsQuery::sharedMonthlySeriesCacheKey($orgId ?? 0, $through->format('Y-m')),
            $this->ladderFlexibleTtl(),
            fn () => $this->fetchMonthlySeries($orgId, $through)
        );

        $data = [];

        for ($i = 12; $i >= 0; $i--) {
            $bucketKey = $through->clone()->startOfMonth()->subMonths($i)->format('Y-m-01');
            $data[] = $series[$bucketKey]['db_repos'] ?? 0;
        }

        if ($formatted && \method_exists($this, 'formatMonthlyData')) {
            $data = $this->formatMonthlyData($data);
        }

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    /**
     * The label axis MUST co-vary with the data axis (UTC), for the same reason as
     * UniqueLprCaseClaims -- otherwise a Chicago viewer between 00:00 and 05:00/06:00 UTC
     * on the 1st sees every bar labelled a month early.
     *
     * @return array<int, string>
     */
    public function getGraphLabels(): array
    {
        $labels = [];

        for ($i = 12; $i >= 0; $i--) {
            $labels[] = now('UTC')->startOfMonth()->subMonthsNoOverflow($i)->format('M \'y');
        }

        return $labels;
    }

    /**
     * @return array<int, string>
     */
    protected function getHighlightedMonthsFromFilter(): array
    {
        return match ($this->date) {
            'last_month' => [now('UTC')->startOfMonth()->subMonthNoOverflow()->format('M \'y')],
            default => [now('UTC')->format('M \'y')],
        };
    }

    /**
     * Same as the base, except the month names are UTC to match the buckets.
     */
    public function getDescription(): string|Htmlable|null
    {
        if ($this->date === 'today') {
            return new HtmlString('Today: '.\number_format($this->currentPeriodTotal()));
        }

        if ($this->date === 'last_7d') {
            return new HtmlString('Last 7 Days: '.\number_format($this->currentPeriodTotal()));
        }

        $monthlyData = $this->getMonthlyData();
        $current = now('UTC')->format('M \'y');
        $previous = now('UTC')->startOfMonth()->subMonthNoOverflow()->format('M \'y');

        return new HtmlString(
            $current.': '.\number_format($monthlyData[\count($monthlyData) - 1] ?? 0)
            .' | '.$previous.': '.\number_format($monthlyData[\count($monthlyData) - 2] ?? 0)
        );
    }

    /**
     * The window leg needs the SAME herd guard as the series leg, and the base does not give it
     * one. Review round 11 (lanes 2 and 3) found this: today/last_7d resolve through the base
     * currentPeriodTotal(), a bare cache()->remember() with no lock and no stale-serve -- and
     * q8-recoveries-leg.md establishes that the window filter reduces the scan by nothing, so a
     * miss here costs the SAME ~584M rows as the 13-month series. Guarding one of the two legs
     * left half the card's query surface exposed to the very stampede the trait exists for.
     *
     * The key carries the resolved DAY and the viewer TIMEZONE -- but only for the day-scoped
     * filters, and only here rather than by overriding buildWidgetCacheKey() for every key this
     * card builds. Both segments exist for the same reason: this leg's window comes from
     * viewer-local wall clock. The base keys on `$this->date` alone, so a "Today" value computed
     * at 23:58 local was served, still labelled Today, into the following day; and without the
     * timezone, two viewers who share a local calendar day at one instant -- Chicago at 12:00 and
     * London at 18:00 -- still have different 24h windows and would read each other's totals. A
     * fabricated-looking figure is especially bad on this card, where a real 0 is a normal
     * reading -- 4 of 29 entitled orgs read 0 in Jul 2026, though Aug 2026 measured 29/29
     * non-zero, so the figure is month-specific.
     *
     * The monthly series key deliberately gets NEITHER segment. Its value is
     * monthlySeries($orgId, now('UTC')) -- identical for every viewer of an org, whatever their
     * zone. Round 13 first added `:tz=` to every key by overriding buildWidgetCacheKey(), which
     * all three review lanes flagged: the cold mutex is derived from the key, so forking the
     * series key per zone stops cross-timezone viewers serialising and multiplies this card's
     * most expensive leg (584M rows / 2.79 GB) by the org's distinct-zone count, for no
     * correctness gain. UniqueScanCounts::monthlySeriesFailureMarkerKey() had already recorded
     * that exact decision for a viewer-independent value, and this class cited it as precedent
     * while inverting its reasoning.
     *
     * KNOWN COST of the day segment, since it is not free: the key rolls at viewer-local
     * midnight, so each entitled org takes one guarded cold miss per day on this leg (29/day),
     * plus one per org on the series leg when the UTC month rolls. The guard is what keeps that
     * bounded -- one query per org rather than one per tab -- and it is the price of not
     * labelling yesterday's total "Today". Out-of-band warming (plan node dc1) is what would
     * remove the blocking part.
     */
    protected function currentPeriodTotal(): int
    {
        [$start, $end] = $this->resolveCardRange();

        // Shared, class-neutral key (DEV-2469 RN4) carrying the INT db_repos stage.
        // UniqueScansToLprRepossession, its original co-reader, was absorbed into this card by
        // DEV-2651 t8; the key stays class-neutral rather than a class_basename prefix for the
        // same reason as the monthly series key above -- see that key's comment. See the key's
        // PAYLOAD CONTRACT before changing what is stored here.
        $key = LprRepossessionsQuery::sharedWindowCacheKey(
            auth()->user()->org_id ?? 0,
            $this->date,
            \in_array($this->date, ['today', 'last_7d'], true) ? $end->format('Y-m-d') : '-',
            \in_array($this->date, ['today', 'last_7d'], true) ? (auth()->user()->timezone ?? 'UTC') : '-',
        );

        return (int) $this->heldFlexible(
            $key,
            $this->ladderFlexibleTtl(),
            fn (): int => $this->getRangeTotal($start->toDateTimeString(), $end->toDateTimeString())
        );
    }

    /**
     * $startDate/$endDate arrive as resolveCardRange()'s NAIVE strings in the viewer's timezone,
     * so they are re-anchored to that timezone here before the query converts them to UTC.
     * Parsing them without the timezone would read them as UTC and shift the window by the
     * viewer's offset.
     */
    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        $timezone = $this->viewerTimezone();

        return $this->fetchWindowTotal(
            auth()->user()->org_id,
            Carbon::parse($startDate, $timezone),
            Carbon::parse($endDate, $timezone),
        );
    }

    /**
     * The single seam getMonthlyData() resolves through -- overridden by a counting or
     * stubbing subclass in the test suite.
     *
     * @return array<string, array{deduped: int, db_repos: int}>
     */
    protected function fetchMonthlySeries(?int $orgId, CarbonInterface $through): array
    {
        return LprRepossessionsQuery::monthlySeries($orgId, $through);
    }

    /**
     * The single seam getModelQueryForDates() resolves through. Selects the 'db_repos'
     * stage here, split from the query call itself (runWindowQuery()) so the stage choice
     * is independently testable -- a ['db_repos'] -> ['deduped'] mutant must not survive.
     */
    protected function fetchWindowTotal(?int $orgId, CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->runWindowQuery($orgId, $start, $end)['db_repos'];
    }

    /**
     * @return array{deduped: int, db_repos: int}
     */
    protected function runWindowQuery(?int $orgId, CarbonInterface $start, CarbonInterface $end): array
    {
        return LprRepossessionsQuery::window($orgId, $start, $end);
    }

    /**
     * AC-2: unchanged headline + a new description line reporting the ratio.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(
            Stat::make($this->getCardLabel(), \number_format($this->getCardTotal()))
                ->description($this->ratioDescription())
                ->chart($this->chartSeries())
                ->color($this->getStatColor()),
            $selectedCard,
        );
    }

    private function ratioDescription(): string
    {
        $ratio = $this->currentRatio();

        $value = $this->numeratorUnavailable ? self::UNAVAILABLE_VALUE : \number_format($ratio, 1);

        return $value.' scans per LPR repo';
    }

    /**
     * Same today/last_7d vs this_month/last_month routing as getCardTotal(), reading
     * numeratorUnavailable only AFTER the fetch that would have set it.
     */
    private function currentRatio(): float
    {
        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            return $this->windowRatio();
        }

        $ratios = $this->getRatioMonthlyData();

        return (float) ($this->date === 'last_month'
            ? ($ratios[\count($ratios) - 2] ?? 0.0)
            : ($ratios[\count($ratios) - 1] ?? 0.0));
    }

    private ?float $windowRatioMemo = null;

    /**
     * Review-1 M1: this was the one merged card missing the override its two siblings got.
     * The expanded card is a long-lived Livewire component, so without this a numerator
     * failure left $numeratorUnavailable stuck true for the component's whole life -- the
     * ratio series stayed dropped under an empty right-hand axis long after the 60s backoff
     * marker expired, recoverable only by a full page reload. Livewire does not inherit
     * #[On] onto an override, so the attribute must be redeclared.
     */
    #[On('refresh-dashboard')]
    public function refreshData(string $date): void
    {
        parent::refreshData($date);

        $this->windowRatioMemo = null;
        $this->ratioMonthlyDataMemo = null;
        $this->numeratorUnavailable = false;
    }

    private function windowRatio(): float
    {
        if ($this->windowRatioMemo !== null) {
            return $this->windowRatioMemo;
        }

        $zone = $this->numeratorZone();
        [$start, $end] = match ($this->date) {
            'today' => [
                now(tz: $zone)->startOfDay(),
                now(tz: $zone)->endOfDay(),
            ],
            'last_7d' => [
                now(tz: $zone)->subDays(7)->startOfDay(),
                now(tz: $zone)->endOfDay(),
            ],
        };

        return $this->windowRatioMemo = self::ratio(
            $this->windowUniqueScans(auth()->user()->org_id, $start, $end, $zone),
            $this->currentPeriodTotal(),
        );
    }

    private ?array $ratioMonthlyDataMemo = null;

    /**
     * Decision 9: zips the numerator against $repos -- getMonthlyData()'s OWN 13-bucket
     * array, the exact one the count dataset renders -- rather than a second,
     * independently-bucketed LprRepossessionsQuery fetch. Only the calendar month each
     * index corresponds to is re-derived, to look up the matching numerator bucket.
     *
     * The zip is positional across two clocks. $through is the viewer zone; $repos is the
     * UTC be.ts headline. Index i is "i months before now" on each clock.
     *
     * @return array<int, float>
     */
    private function getRatioMonthlyData(): array
    {
        if ($this->ratioMonthlyDataMemo !== null) {
            return $this->ratioMonthlyDataMemo;
        }

        $orgId = auth()->user()->org_id;
        $zone = $this->numeratorZone();
        $through = now(tz: $zone);
        $repos = $this->getMonthlyData(formatted: false);
        $scans = $this->fetchUniqueScansSeries($orgId, $through, $zone);

        $ratios = [];
        $months = \count($repos);

        foreach (\array_values($repos) as $index => $dbRepos) {
            $month = $through->clone()->startOfMonth()->subMonths($months - 1 - $index);
            $ratios[] = self::ratio((int) ($scans[$month->format('Y-m')] ?? 0), (int) $dbRepos);
        }

        return $this->ratioMonthlyDataMemo = $ratios;
    }

    /**
     * AC-3/AC-4: a second "Scans per LPR Repo" dataset on its own axis, dropped entirely
     * (decision 8) when the numerator degraded -- plotting it against UNAVAILABLE_VALUE's
     * fabricated 0s would be worse than not drawing it.
     */
    public function getData(): array
    {
        $repos = $this->getMonthlyData();
        $ratios = $this->getRatioMonthlyData();

        if ($this->numeratorUnavailable) {
            // Single series, no legend: makeDataset()'s per-month highlight is still the
            // right call here, and only here.
            $count = $this->makeDataset($this->getDatasetLabel(), $repos);
            $count['yAxisID'] = 'y';

            return ['datasets' => [$count], 'labels' => $this->getGraphLabels()];
        }

        return [
            'datasets' => [
                $this->countSeriesDataset($this->getDatasetLabel(), $repos),
                $this->ratioLineDataset('Scans per LPR Repo', $ratios),
            ],
            'labels' => $this->getGraphLabels(),
        ];
    }

    /**
     * AC-4: the ratio rides a second, right-hand axis (`y1`) that shares no gridlines with
     * the count axis, so the two series don't compete for the same scale. Legend follows
     * SharedHitStatsData's precedent -- useful only once expanded, where both series show.
     */
    protected function getOptions(): array|RawJs|null
    {
        $options = $this->options;

        // Review-4 R4-B: see UniqueLprCaseClaims::getOptions(). getData() drops the ratio
        // dataset when the numerator is unavailable; the axis and legend must drop with it.
        // Gate BEFORE the fetch — getRatioMonthlyData() dereferences auth()->user().
        $ratioAvailable = static::canViewRatioFigure();

        if ($ratioAvailable) {
            $this->getRatioMonthlyData();
            $ratioAvailable = ! $this->numeratorUnavailable;
        }

        $options['plugins']['legend']['display'] = $ratioAvailable && $this->expanded;
        $options['plugins']['legend']['position'] = 'top';

        $options['scales']['y']['title'] = ['display' => true, 'text' => $this->getDatasetLabel()];

        if (! $ratioAvailable) {
            return $options;
        }

        $options['scales']['y1'] = [
            'beginAtZero' => true,
            'position' => 'right',
            'grid' => ['drawOnChartArea' => false],
            'title' => ['display' => true, 'text' => 'Scans per LPR Repo'],
        ];

        return $options;
    }

    public function exportAction(): ExportAction
    {
        return ExportAction::make('export')
            ->hiddenLabel()
            ->icon('heroicon-m-arrow-down-tray')
            ->tooltip(function (): string {
                [$start, $end] = $this->exportWindow();

                return match ($this->date) {
                    'today' => 'Export today ('.$start->format('M j').')',
                    'last_7d' => 'Export last 7 days ('.$start->format('M j')."\u{2013}".$end->format($start->format('Y-m') === $end->format('Y-m') ? 'j' : 'M j').')',
                    default => 'Export '.$start->format('M \'y').' LPR repos (cases claimed '.$start->format('M j')."\u{2013}".$end->format('j').') as CSV',
                };
            })
            ->exporter(LprRepossessionExporter::class)
            ->visible(fn (): bool => $this->expanded)
            ->fileName(function (): string {
                [$start, $end] = $this->exportWindow();
                $from = $start->format('Y-m-d');
                $to = $end->format('Y-m-d');

                return 'lpr-repossessions-claimed-'.($from === $to ? $from : $from.'-to-'.$to);
            })
            ->chunkSize(self::EXPORT_CHUNK_SIZE)
            ->columnMapping(false)
            ->modal(false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->fromRaw('('.$this->exportRowsSql().') AS lpr_repo_export'))
            ->after(fn () => Analytics::track('export', auth()->user(), ['action' => 'export'], 'lpr-repossessions'));
    }

    /**
     * Month filters use the UTC series bucket. today/last_7d use the viewer-local card range.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    protected function exportWindow(): array
    {
        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            $timezone = $this->viewerTimezone();
            [$start, $end] = $this->resolveCardRange();

            return [
                Carbon::parse($start->toDateTimeString(), $timezone),
                Carbon::parse($end->toDateTimeString(), $timezone),
            ];
        }

        $month = now(self::SERIES_ZONE)->startOfMonth();

        if ($this->date === 'last_month') {
            $month = $month->subMonthNoOverflow();
        }

        return [
            $month->clone()->startOfMonth(),
            $month->clone()->endOfMonth(),
        ];
    }

    protected function exportRowsSql(): string
    {
        [$start, $end] = $this->exportWindow();

        return LprRepossessionsQuery::rowsSql(auth()->user()->org_id, $start, $end);
    }

    public function getCardQueryStatements(): array
    {
        $orgId = auth()->user()->org_id;

        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            $timezone = $this->viewerTimezone();
            [$start, $end] = $this->resolveCardRange();

            return [new CardQueryStatement(
                label: 'Headline total',
                sql: LprRepossessionsQuery::windowSql(
                    $orgId,
                    Carbon::parse($start->toDateTimeString(), $timezone),
                    Carbon::parse($end->toDateTimeString(), $timezone),
                ),
                note: 'The card reads the `db_repos` column.',
                queryClass: LprRepossessionsQuery::class,
            )];
        }

        $through = now('UTC');
        $offset = $this->date === 'last_month' ? 1 : 0;
        $bucketKey = $through->clone()->startOfMonth()->subMonths($offset)->format('Y-m-01');

        return [new CardQueryStatement(
            label: 'Headline total',
            sql: LprRepossessionsQuery::monthlySeriesSql($orgId, $through),
            note: "Bucketed by month -- the card reads the `db_repos` column of the `{$bucketKey}` row.",
            queryClass: LprRepossessionsQuery::class,
        )];
    }
}
