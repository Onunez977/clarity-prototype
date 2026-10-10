<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Models\Scan;
use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Clickhouse\Queries\ScanStatsBatchQuery;
use App\Dto\CardQueryStatement;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * Period-distinct plate count over `clarity.scans_cold` (DEV-2466). Deliberately does NOT
 * carry any UI copy about the two divergences from Scan Count / the affiliate report --
 * both are recorded in the Build Record only (Q2), not shown on the card.
 *
 * DEV-2651: absorbs the raw scan total that `ScanCount` used to show as its own card. This
 * card was the first to be ungated (decision 2); the ENTIRE grid followed -- no Company
 * Metrics card carries a Pro gate any more, and `FeatureEnum::COMPANY_METRICS` was deleted
 * along with them. `RendersDashboardStat::canViewCardData()`'s ADMIN/USER role check is now
 * the only gate on every card. Consequence: the per-viewer-timezone ScanPlateStatsBatchQuery
 * scan is no longer bounded to entitled orgs.
 *
 * RELIABILITY, stated because review 4 raised it and the answer is non-obvious: BOTH
 * current-month legs here -- resolveCurrentMonthFromCold() and
 * resolveTotalCurrentMonthFromHot() -- are deliberately catch-free, and
 * CompanyMetricsOverview::getStats() has no per-card try/catch, so a `scans_cold` outage
 * 500s the whole grid. Ungating THIS card did not widen that exposure: `ScanCount` was itself
 * ungated and sat in the same registry with an equally unguarded current-month read, so every
 * ADMIN/USER org already reached one on every render. What changed is that this path now
 * carries TWO such legs rather than one. Ungating the REST of the grid is a different matter
 * and genuinely does widen it -- see CompanyMetricsOverview's docblock. Do not "fix" either
 * by swallowing -- see each
 * method's docblock for why a fabricated 0 under a read-back key is the worse failure.
 *
 * BOTH series are cut on {@see bucketZone()} -- keys AND server-side grouping. That took a
 * change to `ScanStatsBatchQuery`, which grouped on raw UTC `scan_hour` while the
 * distinct-plate leg grouped in the viewer's zone; co-plotting them is what made the
 * mismatch visible (a raw total could render BELOW the distinct count for the same bar).
 * Ratio numerators on the Company Metrics cards use the viewer zone. `repo_date` does not
 * move. Claims `ts` is deferred, so the claims funnel stays on Brek's Repo Report UTC clock.
 */
class UniqueScanCounts extends DashboardWidget implements ExplainsCardQuery
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Scan Counts';

    protected function getTooltipDescription(): ?string
    {
        return 'Distinct plates your kits read in this period. A plate read many times counts once.';
    }

    /**
     * A genuine ClickHouse failure while filling the 13-month batch is remembered for
     * this long before the NEXT render is allowed to retry -- mirrors
     * StagingLiveHitLeader::LEADER_FETCH_FAILURE_TTL_SECONDS. Short on purpose: long
     * enough that a hard-down ClickHouse is not hammered on every page load, short
     * enough that a transient blip self-heals within a minute rather than requiring a
     * cache flush.
     */
    private const SCAN_PLATE_FETCH_FAILURE_TTL_SECONDS = 60;

    /**
     * Closed shared-month ints use this floor while impersonating. The value lives on
     * {@see ScanPlateStatsBatchQuery}, which writes those keys. It is not a change to
     * DashboardWidget::getCacheTTL().
     */
    public const IMPERSONATED_PAST_MONTH_TTL_MINUTES = ScanPlateStatsBatchQuery::IMPERSONATED_PAST_MONTH_TTL_MINUTES;

    /**
     * DEV-2651 t2: the ported `ScanCount` leg -- the raw (non-deduped) scan total, merged
     * onto this card as a second dataset + description line rather than its own card.
     *
     * Every key here lives under its OWN sub-namespace, via {@see buildWidgetCacheKey()},
     * never {@see ScanPlateStatsBatchQuery::sharedMonthCacheKey()} -- that namespace is
     * shared with {@see UniqueScansLeaderboard}'s sparkline, and this total series is not
     * part of that contract. Reusing it here would let the total silently overwrite the
     * unique-plate window value under the same key.
     */
    private const TOTAL_NAMESPACE = 'scan-total:';

    /**
     * Review-4 R4-E. Same value and same job as
     * {@see Concerns\RatioAgainstUniqueScans::UNAVAILABLE_VALUE} — declared locally because this
     * is not a ratio card and does not use that trait. Decision 8 standardised "could not be
     * computed" across the three ratio series; this is the fourth series of the same shape.
     */
    public const UNAVAILABLE_VALUE = '--';

    /**
     * Set when the ported total's batch failed or was suppressed by its backoff marker.
     *
     * Without it the read loop's `?? 0` renders twelve confident zero bars and "0 total scans"
     * beside a healthy unique-plate series — a raw total BELOW a distinct-plate count, which is
     * structurally impossible. Review-3 stopped that zero being CACHED for a year; this stops it
     * being DISPLAYED as though it were real.
     */
    private bool $totalUnavailable = false;

    /** Static grey for the ported Total series on the two-dataset chart. */
    private const TOTAL_SERIES_FILL = 'rgba(156, 163, 175, 0.45)';

    private const TOTAL_SERIES_BORDER = 'rgba(156, 163, 175, 0.8)';

    public function getDatasetLabel(): string
    {
        return 'Scan Counts';
    }

    /**
     * Review-4 R4-E. $totalUnavailable is a per-request flag with no reset of its own, and the
     * expanded card is a long-lived Livewire component -- the exact trap review-1 M1 found on
     * LprRepossessions. Without this, one failed total fetch renders UNAVAILABLE_VALUE for every
     * later filter on that instance, long after the 60s marker expired.
     *
     * The #[On] attribute MUST be re-declared: PHP does not inherit method attributes onto an
     * override, so the parent's is invisible to Livewire. (The base clears $monthlyDataMemo,
     * which holds this card's total_raw/total_formatted keys too -- only the flag is left.)
     */
    #[On('refresh-dashboard')]
    public function refreshData(string $date): void
    {
        parent::refreshData($date);

        $this->totalUnavailable = false;
    }

    public function getRgbColor(): array
    {
        // Q1: the scan-family green ScanCount used to carry before DEV-2651 t8 folded its
        // raw total into this card's own second series. Not a copy-paste slip -- see
        // getStatColor()'s 'success' arm.
        return [34, 197, 94];
    }

    /**
     * Both real read paths are overridden below (getMonthlyData(), getRangeTotal()), so
     * this base-class seam is never invoked. Mirrors LiveHits' 0-stub.
     */
    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return 0;
    }

    /**
     * Mirrors ScanCount::getMonthlyData(): batches the 13-month series into ONE past-months
     * call plus one current-month call, rather than the base class's 13 separate calls.
     *
     * Reads under {@see ScanPlateStatsBatchQuery::sharedMonthCacheKey()}, not
     * {@see DashboardWidget::buildWidgetCacheKey()} -- DEV-2468's Q1 Option A: this is the
     * ORG-TOTAL series the leaderboard's sparkline also reads, so the class-neutral key is
     * what lets the two cards share the scan.
     */
    protected function getMonthlyData(bool $formatted = true): array
    {
        $memoKey = $formatted ? 'formatted' : 'raw';

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $orgId = auth()->user()->org_id;

        // The BUCKET zone -- see bucketZone(). Used for every month key and window here.
        // handle() groups by the sanitised zone (its grouping expression is the bucket key),
        // so the keys we write and read must be cut the same way or a viewer on a zone
        // ClickHouse cannot parse gets a month key the query never emits: for a positive
        // offset like '+05:00' the local month rolls over first, we ask for a month that is
        // still in the future in UTC, and the short count is cached for a year with no
        // self-heal. Deriving the keys from the same zone the query groups by removes the
        // mismatch by construction rather than compensating for it.
        //
        // NOT used for the date-filter path -- see getRangeTotal(), which uses viewerTimezone().
        $bucketZone = $this->bucketZone();

        $past = $this->resolvePastMonthsFromCold($orgId, $bucketZone);
        $openKey = now(tz: $bucketZone)->format('Y-m');
        $past[$openKey] = $this->resolveCurrentMonthFromCold($orgId, $bucketZone);

        $data = [];

        for ($i = 12; $i >= 0; $i--) {
            $dateType = now(tz: $bucketZone)->startOfMonth()->subMonths($i)->format('Y-m');

            $data[] = (int) ($past[$dateType] ?? 0);
        }

        if ($formatted && method_exists($this, 'formatMonthlyData')) {
            $data = $this->formatMonthlyData($data);
        }

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    /**
     * The one zone this card buckets by, and the ONLY thing that may pair with monthly data.
     *
     * `handle()` groups by the sanitised zone, so the month keys, the bar labels and the
     * highlighted-month labels must all be cut with that same zone or they desynchronise --
     * `DashboardWidget::getData()` zips labels to data POSITIONALLY, and
     * `generateColors()` matches the highlight against the label STRING, so a one-month
     * skew silently mislabels all 13 bars rather than failing.
     *
     * That is not hypothetical: an earlier revision minted the data with the sanitised zone
     * while inheriting both label methods on the raw one. At 2026-08-31 23:00 UTC a `+05:00`
     * viewer got labels ending "Sep '26" over a data series ending 2026-08; at 2026-09-01
     * 02:00 UTC a `CST` viewer got the mirror image. Listed zones were unaffected, which is
     * why it survived review twice.
     */
    private function bucketZone(): string
    {
        return ScanPlateStatsBatchQuery::safeTimezone(auth()->user()->timezone ?? 'UTC');
    }

    /**
     * Overridden to use {@see bucketZone()} instead of the base class's raw viewer zone,
     * so the labels describe the months the data is actually bucketed into.
     *
     * @return array<int, string>
     */
    public function getGraphLabels(): array
    {
        $zone = $this->bucketZone();
        $labels = [];

        for ($i = 12; $i >= 0; $i--) {
            $labels[] = now(tz: $zone)->startOfMonth()->subMonths($i)->format('M \'y');
        }

        return $labels;
    }

    /**
     * Overridden for the same reason as getGraphLabels(): this returns LABEL strings that
     * generateColors() matches against that method's output, so both must be cut with
     * {@see bucketZone()} or the wrong bar is highlighted.
     *
     * @return array<int, string>
     */
    protected function getHighlightedMonthsFromFilter(): array
    {
        $zone = $this->bucketZone();

        return match ($this->date) {
            'last_month' => [now(tz: $zone)->startOfMonth()->subMonth()->format('M \'y')],
            default => [now(tz: $zone)->format('M \'y')],
        };
    }

    /**
     * Verbatim mirror of ScanCount::monthKeyStart() -- anchors a `Y-m` month key to day 01
     * before any month arithmetic runs, so resolving e.g. '2026-06' on the 31st cannot
     * overflow into July.
     */
    protected function monthKeyStart(string $monthKey, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $monthKey.'-01', $timezone);
    }

    /**
     * S5/S10: this card's own cache keys ({@see DashboardWidget::buildWidgetCacheKey()})
     * plus a timezone segment, still used by the base class's currentPeriodTotal()
     * 'today'/'last_7d'/'last_month' keys. DEV-2468 moved the 13-month monthly buckets off
     * this method onto {@see ScanPlateStatsBatchQuery::sharedMonthCacheKey()} -- see
     * getMonthlyData() -- so the leaderboard's sparkline can read the same series; this
     * override remains for the date-filter windows, which stay class-prefixed. Every window
     * this card resolves is built from VIEWER-LOCAL wall-clock time, so without a timezone
     * segment a Chicago and a London user on the same org would read each other's buckets.
     * Mirrors StagingLiveHitLeader::leaderCacheKey()'s `:tz=` segment.
     */
    protected function buildWidgetCacheKey(string $dateType, int $orgId): string
    {
        $timezone = auth()->user()->timezone ?? 'UTC';

        return parent::buildWidgetCacheKey($dateType, $orgId).':tz='.$timezone;
    }

    /**
     * Org-scoped and deliberately NOT timezone-scoped, unlike every other key this card
     * builds. Review flagged the inconsistency across three rounds, so the reasoning is
     * recorded here rather than left to look like an oversight: this marker records "the
     * ClickHouse fetch for this org just failed", which is a property of the connection and
     * the org's data volume, not of the viewer's timezone. Adding `:tz=` would give each
     * zone its own backoff and let N zones hammer a hard-down ClickHouse N times over the
     * same 60 seconds -- the opposite of what the marker is for.
     *
     * Known and accepted: while the marker is set, resolvePastMonthsFromCold() returns
     * having written nothing, so the card renders twelve zero bars -- the same outcome used
     * to justify withdrawing the first lock attempt. The difference is duration and cause: a
     * 60-second marker after a genuine ClickHouse failure, versus a fabricated zero
     * persisted at a one-year TTL after a merely slow query. Neither is good; only one is
     * self-healing.
     */
    private function monthlySeriesFailureMarkerKey(int $orgId): string
    {
        // Delegates to the namespace owner (DEV-2469): a second card now writes these keys and
        // must observe the same backoff, so the key cannot live privately on this card.
        return ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($orgId);
    }

    /**
     * The ported total leg's OWN backoff marker. Deliberately not the shared
     * ScanPlateStatsBatchQuery one: that namespace belongs to the unique-plate series and is
     * read by UniqueScansLeaderboard, so a ScanStatsBatchQuery outage must not suppress a
     * healthy unique-plate read (or vice versa). Two queries, two independent failure states.
     */
    private function totalSeriesFailureMarkerKey(int $orgId): string
    {
        return $this->buildWidgetCacheKey(self::TOTAL_NAMESPACE.'fetch-failed', $orgId);
    }

    /**
     * @return list<string>
     */
    private function pastMonthKeys(string $timezone): array
    {
        $keys = [];

        for ($i = 12; $i >= 1; $i--) {
            $keys[] = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');
        }

        return $keys;
    }

    /**
     * NO LOCK, deliberately. RN2 review asked for single-flight around this scan, and two
     * successive `Cache::lock` attempts were reviewed and withdrawn.
     *
     * MEASURED, `docs/investigations/DEV-2466/findings.md:143-170` -- read it there rather
     * than trusting a restatement here; two earlier revisions of this docblock misquoted it.
     * Solo, the shipped no-`FINAL` shape: 60,738,996 rows / 2.44 GB peak / 1,102 ms. Four
     * concurrent copies of that same query: ~10 GB, slowest 3.818 s, 2.6x degradation
     * (the four-way run used the `FINAL` variant, hence ~9.8 GB for the shipped shape).
     *
     *   - The first computed the uncached set BEFORE `block()` and never re-read it, so
     *     every waiter re-ran the scan the holder had just finished, and a timeout returned
     *     having written nothing -- rendering twelve zeros and a `Last Month: 0` headline.
     *   - The second re-probed and fell through on timeout, but `block(3)` throws at 2,750ms
     *     while the slowest of four genuinely concurrent queries measured 3.818s. The wait
     *     budget sat BELOW the contention it existed for, so under load every waiter timed
     *     out and scanned anyway -- the full envelope restored, plus 2.75s of blocked
     *     render. (3.818 s is this card's OWN four-way figure -- see the measurement block
     *     above -- so the budget was under-sized against precisely the contention it
     *     existed for.) It also left
     *     the batch window computed from the pre-lock probe while the write loop iterated
     *     the post-lock set, which persisted a fabricated 0 at a one-year TTL on a month
     *     rollover.
     *
     * Removing it restores the single-probe shape: one probe, one window, one write set,
     * derived from the same array by construction, so the two cannot diverge. That shape is
     * NOT one a review round cleared -- RN3 reviewed the LOCKED code, and the no-lock shape
     * is the one RN2 flagged in the first place. Do not read this paragraph as reviewer
     * approval; it is a statement of what the code does and why the alternatives were worse.
     *
     * The cold-start stampede is a real and UNMITIGATED residual, deferred to its own ticket
     * rather than attempted a third time here. Three candidates, in the order they should be
     * considered:
     *   - A `Cache::add` N-slot semaphore, as `okf/concepts/scout-check-in-recorder.md`
     *     (:33/:83/:107) already ships it -- "the hard ceiling", explicitly contrasted there
     *     with a TTL-expiry lock. It has no `block()` wait to mis-size, which was the failure
     *     mode of BOTH withdrawn attempts, so it is the cheapest of the three and the least
     *     likely to repeat this ticket's history.
     *   - `Cache::flexible`, which serves stale while one caller refreshes.
     *   - A correctly-sized `Cache::lock`. Sizing it is what failed twice. 3.818 s is the
     *     only anchor available, and it is a weak one: it is the slowest of four client
     *     walls in a single `FINAL`-variant run, not a p99, and four is the spike's
     *     concurrency rather than production's. Enough to say the withdrawn 2.75 s budget
     *     was too low; NOT enough to size a replacement. Measure the shipped shape first.
     *
     * DEPLOY-TIME NOTE (DEV-2468 S-F): this ticket moves the 12 past-month buckets off
     * `unique-scan-counts:<Y-m>:org=<id>:tz=<raw>` (one-year TTL, warm today) onto
     * {@see ScanPlateStatsBatchQuery::sharedMonthCacheKey()}'s namespace. Nothing warms
     * the new namespace ahead of time, so the FIRST render for every entitled org after
     * this ships treats all 12 past months as uncached and runs the full 13-month batch
     * cold -- 4,861 ms measured against a standing 20 s `max_execution_time` on
     * `readonly_user` (`docs/investigations/DEV-2466/findings.md`), simultaneously across
     * however many orgs render in that window, with none of the mitigations above in
     * place. Self-healing (each org pays it once, then the new key is warm for a year) and
     * bounded by the existing 20 s cap, but real -- record it in the deploy notes rather
     * than let the next reader assume this key move is free.
     *
     * The shared Y-m ints are written by {@see ScanPlateStatsBatchQuery::rememberMonthSeries()}.
     * A thrown fetch writes nothing.
     *
     * @return array<string, int>
     */
    private function resolvePastMonthsFromCold(int $orgId, string $timezone): array
    {
        $monthKeys = $this->pastMonthKeys($timezone);

        if (cache()->get($this->monthlySeriesFailureMarkerKey($orgId)) !== null) {
            return ScanPlateStatsBatchQuery::cachedMonthInts($orgId, $timezone, $monthKeys);
        }

        try {
            return ScanPlateStatsBatchQuery::rememberMonthSeries(
                $orgId,
                $timezone,
                $monthKeys,
                null,
                null,
                fn (string $start, string $end): array => $this->fetchMonthlySeries($orgId, $start, $end, $timezone),
            );
        } catch (\Throwable $e) {
            if (in_array(config('app.env'), ['production', 'testing'])) {
                report($e);

                cache()->put(
                    $this->monthlySeriesFailureMarkerKey($orgId),
                    true,
                    self::SCAN_PLATE_FETCH_FAILURE_TTL_SECONDS
                );

                return ScanPlateStatsBatchQuery::cachedMonthInts($orgId, $timezone, $monthKeys);
            }

            throw $e;
        }
    }

    /**
     * Reads `scans_cold`, NOT `scans_hot` -- the DEV-2466 sec 6 correction to
     * ScanCount::resolveCurrentMonthFromHot()'s pattern (`scans_hot` measured 3.3x slower
     * despite fewer rows, because it carries 5x the parts).
     *
     * This bucket's TTL is the ladder's open arm. A failure here must propagate
     * rather than be swallowed into a confident wrong number.
     *
     * The window comes from {@see ScanPlateStatsBatchQuery::currentMonthWindow()}, the SAME
     * call `UniqueScansLeaderboard::resolveRangeAndTimezone()` makes for its `'this_month'`
     * arm -- one formula, two callers, so the resulting `sharedRangeCacheKey()` cannot
     * drift apart (DEV-2468 S-A).
     */
    private function resolveCurrentMonthFromCold(int $orgId, string $timezone): int
    {
        $monthKey = now(tz: $timezone)->format('Y-m');
        $window = ScanPlateStatsBatchQuery::currentMonthWindow($timezone);

        return ScanPlateStatsBatchQuery::rememberOpenMonthTotal(
            $orgId,
            $monthKey,
            $timezone,
            fn (): array => $this->fetchRangeRollup($orgId, $window['start'], $window['end'], $timezone),
        );
    }

    /**
     * The same zone {@see BuildsDateFilterChartSeries::resolveCardRange()} minted
     * `$start`/`$end` in ({@see BuildsDateFilterChartSeries::viewerTimezone()}).
     * `handleRange()` passes that zone to `Carbon::parse` inside `toUtcDatetime()`.
     * A raw `users.dti` such as `NotAReal/Zone` throws there and 500s Company Metrics.
     * `CST` / `PST` / `+05:00` follow `safeTimezone()` to UTC, matching the month buckets.
     *
     * No try/catch here -- DashboardWidget::getRangeTotal()'s documented contract: a
     * bounded-window failure must propagate rather than persist a fabricated 0 under the
     * key getDescription() also reads.
     */
    protected function getRangeTotal(string $start, string $end): int
    {
        $orgId = auth()->user()->org_id;
        $timezone = $this->viewerTimezone();

        return $this->fetchRangeRollup($orgId, $start, $end, $timezone)['totals'][0] ?? 0;
    }

    /**
     * The 13-month series' single round trip, behind its own overridable seam so a test
     * can COUNT invocations -- DB::listen never fires for the ClickHouse driver.
     *
     * No kit dimension: the combined 13-month x per-kit rollup was never measured and
     * would take the group count from 13 to roughly 13 x kits against a 2.18B-row table
     * (DEV-2466 sec 4 vs sec 7).
     *
     * @return array<string, int>
     */
    protected function fetchMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        return ScanPlateStatsBatchQuery::handle([
            'org_id' => $orgId,
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
        ]);
    }

    /**
     * A single window's `handleRange()` rollup verbatim -- `totals[0]` the org total, keyed
     * by kit id, plus the `names` map DEV-2468's leaderboard reads. Cached under
     * {@see ScanPlateStatsBatchQuery::sharedRangeCacheKey()} rather than
     * {@see DashboardWidget::buildWidgetCacheKey()} -- the latter prefixes THIS widget's
     * class basename, which would make the cached rollup unreadable from DEV-2468's
     * leaderboard card. That cross-class read is the entire point of caching here: any
     * card resolving the same org+window+timezone can reuse this exact scan instead of
     * issuing its own (DEV-2466 sec 7).
     *
     * @return array{totals: array<int, int>, names: array<int, string>}
     */
    protected function fetchRangeRollup(int $orgId, string $start, string $end, string $timezone): array
    {
        return ScanPlateStatsBatchQuery::rememberedRange($orgId, $start, $end, $timezone);
    }

    /**
     * AC-2 (render): same Stat chrome as the base class, plus the ported total as a
     * secondary line -- mirrors SharedHitStatsData::toStat()'s `->description()` precedent,
     * so the raw count is not lost when the headline stays the deduped plate count.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(
            Stat::make($this->getCardLabel(), \number_format($this->getCardTotal()))
                ->description($this->totalScanDescription())
                ->chart($this->chartSeries())
                ->color($this->getStatColor()),
            $selectedCard,
        );
    }

    /**
     * AC-3: two grouped datasets over the same 13 shared labels. Deliberately a SINGLE
     * y-axis (no `scales.y1`) -- both series are scan counts, unlike a ratio card, so they
     * share one axis rather than needing a second.
     */
    public function getData(): array
    {
        $label = $this->getDatasetLabel();
        $accent = 'rgba('.\implode(', ', $this->getRgbColor()).', 0.85)';
        $accentBorder = 'rgba('.\implode(', ', $this->getRgbColor()).', 1)';

        $datasets = [
            $this->staticColourDataset($label, $this->getMonthlyData(), $accent, $accentBorder),
        ];

        // Resolve BEFORE the check -- reading the series is what sets $totalUnavailable.
        $totals = $this->getTotalMonthlyData();

        if (! $this->totalUnavailable) {
            $datasets[] = $this->staticColourDataset('Total Scans', $totals, self::TOTAL_SERIES_FILL, self::TOTAL_SERIES_BORDER);
        }

        return [
            'datasets' => $datasets,
            'labels' => $this->getGraphLabels(),
        ];
    }

    /**
     * The legend is only useful once there are two series to distinguish, which only
     * happens in the expanded chart. Copies (never mutates) $this->options -- mount()
     * already wrote tooltip.borderColor into it.
     */
    protected function getOptions(): array|RawJs|null
    {
        $options = $this->options;

        $options['plugins']['legend']['display'] = $this->expanded;
        $options['plugins']['legend']['position'] = 'top';
        $options['plugins']['legend']['labels']['usePointStyle'] = true;

        return $options;
    }

    /**
     * The ported total's headline number for the description line, mirroring
     * DashboardWidget::getCardTotal()'s own date routing verbatim -- 'today'/'last_7d'
     * through the bounded range fetch, 'last_month' the penultimate bucket, everything
     * else the newest bucket.
     */
    /**
     * Review-4 R4-E: the count is resolved FIRST because resolving it is what sets
     * $totalUnavailable, then the flag decides whether the number is trustworthy. Same
     * order, and the same UNAVAILABLE_VALUE, as the three ratio cards' ratioDescription().
     */
    private function totalScanDescription(): string
    {
        $total = $this->getTotalScanCount();

        return ($this->totalUnavailable ? self::UNAVAILABLE_VALUE : \number_format($total)).' total scans';
    }

    public function getTotalScanCount(): int
    {
        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            return $this->currentPeriodScanTotal();
        }

        $monthlyData = $this->getTotalMonthlyData(formatted: false);

        return (int) ($this->date === 'last_month'
            ? ($monthlyData[\count($monthlyData) - 2] ?? 0)
            : ($monthlyData[\count($monthlyData) - 1] ?? 0));
    }

    /**
     * Mirror of DashboardWidget::currentPeriodTotal() for the ported total series, cached
     * under its own TOTAL_NAMESPACE key so it cannot collide with the unique series' key
     * for the same $this->date (which the base currentPeriodTotal() already owns via the
     * overridden getRangeTotal() below).
     */
    protected function currentPeriodScanTotal(): int
    {
        [$start, $end] = $this->resolveCardRange();
        $orgId = auth()->user()->org_id;

        return (int) (cache()->remember(
            $this->buildWidgetCacheKey(self::TOTAL_NAMESPACE.$this->date, $orgId),
            $this->getCacheTTL(),
            fn () => $this->fetchTotalRangeTotal($orgId, $start->toDateTimeString(), $end->toDateTimeString())
        ) ?? 0);
    }

    /**
     * Verbatim ScanCount::getRangeTotal() -- the DEV-2324 `Scan::hot()::query()` bug this
     * ticket preserves rather than fixes. Behind its own seam so a test can stub it without
     * a real ClickHouse round trip.
     */
    protected function fetchTotalRangeTotal(int $orgId, string $start, string $end): int
    {
        return (int) Scan::hot()::query()
            ->forOrg($orgId)
            ->between($start, $end, $this->viewerTimezone())
            ->count();
    }

    /**
     * Verbatim ScanCount::getMonthlyData(), ported onto this card's 13-month series --
     * bucketed by {@see bucketZone()} (decision 9), NOT the raw viewer zone ScanCount
     * itself uses, so both series on the chart share one axis of months.
     */
    protected function getTotalMonthlyData(bool $formatted = true): array
    {
        $memoKey = $formatted ? 'total_formatted' : 'total_raw';

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $orgId = auth()->user()->org_id;
        $bucketZone = $this->bucketZone();

        $this->resolveTotalPastMonthsFromAggregate($orgId, $bucketZone);
        $this->resolveTotalCurrentMonthFromHot($orgId, $bucketZone);

        $data = [];

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $bucketZone)->startOfMonth()->subMonths($i);
            $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');
            $cacheKey = $this->buildWidgetCacheKey(self::TOTAL_NAMESPACE.$dateType, $orgId);

            $data[] = cache()->get($cacheKey) ?? 0;
        }

        if ($formatted && method_exists($this, 'formatMonthlyData')) {
            $data = $this->formatMonthlyData($data);
        }

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    /**
     * Verbatim ScanCount::resolvePastMonthsFromAggregate(), writing under TOTAL_NAMESPACE
     * instead of the class-basename-only key ScanCount uses -- see the class docblock.
     */
    private function resolveTotalPastMonthsFromAggregate(int $orgId, string $timezone): void
    {
        $uncachedMonths = [];

        for ($i = 12; $i >= 1; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $monthKey = $targetMonth->format('Y-m');
            $cacheKey = $this->buildWidgetCacheKey(self::TOTAL_NAMESPACE.$monthKey, $orgId);

            if (cache()->get($cacheKey) === null) {
                $uncachedMonths[$monthKey] = $i;
            }
        }

        if (empty($uncachedMonths)) {
            return;
        }

        // Honour this leg's own backoff before touching ClickHouse, so a hard-down
        // scan_report_hourly is not re-queried on every render inside the marker's window.
        if (cache()->get($this->totalSeriesFailureMarkerKey($orgId)) !== null) {
            $this->totalUnavailable = true;

            return;
        }

        $sortedKeys = array_keys($uncachedMonths);
        sort($sortedKeys);
        $minMonth = $sortedKeys[0];
        $maxMonth = end($sortedKeys);

        $batchStart = $this->monthKeyStart($minMonth, $timezone)->startOfDay()->toDateTimeString();
        $batchEnd = $this->monthKeyStart($maxMonth, $timezone)->endOfMonth()->endOfDay()->toDateTimeString();

        // Review-2 H1: this previously set $batchResult = [] and FELL THROUGH to the write
        // loop, persisting a fabricated 0 for every uncached month at a one-year TTL with no
        // marker to retry behind -- recoverable only by cache:clear. That is the exact hazard
        // resolvePastMonthsFromCold() above documents and refuses. Same four moves here:
        // report, write a short-lived marker, write NO bucket, return.
        try {
            $batchResult = $this->fetchTotalMonthlySeries($orgId, $batchStart, $batchEnd, $timezone);
        } catch (\Throwable $e) {
            if (in_array(config('app.env'), ['production', 'testing'])) {
                report($e);

                cache()->put(
                    $this->totalSeriesFailureMarkerKey($orgId),
                    true,
                    self::SCAN_PLATE_FETCH_FAILURE_TTL_SECONDS
                );

                $this->totalUnavailable = true;

                return;
            }

            throw $e;
        }

        foreach ($uncachedMonths as $monthKey => $index) {
            $cacheKey = $this->buildWidgetCacheKey(self::TOTAL_NAMESPACE.$monthKey, $orgId);
            $value = $batchResult[$monthKey] ?? 0;
            $monthEndedAt = $this->monthKeyStart($monthKey, $timezone)->endOfMonth();

            cache()->put($cacheKey, $value, $this->getCacheTTL(1, $monthEndedAt));
        }
    }

    /**
     * The ported ScanCount leg, behind its own seam so a test can stub or count it without a
     * real round trip.
     *
     * Review-2: `$timezone` IS now passed to the query. It used to be threaded only so a
     * recording test could prove which zone produced the bounds, while
     * `ScanStatsBatchQuery` grouped on raw `scan_hour` (UTC) -- which meant this series and
     * the distinct-plate series it is plotted beside were cut on different calendars. Both
     * now group on the same `bucketZone()`.
     *
     * @return array<string, int>
     */
    protected function fetchTotalMonthlySeries(int $orgId, string $start, string $end, string $timezone): array
    {
        // ::handle(), NOT ::query(). ClickhouseQuery::query() catches Throwable and returns []
        // in production|testing (:29-33), so a failure reaches the caller as an empty result --
        // indistinguishable from a genuinely empty org, and invisible to the guard in
        // resolveTotalPastMonthsFromAggregate(). Review-3 H1: the guard added in review-2 was
        // inert for exactly this reason. fetchMonthlySeries() :492 has always called handle().
        return ScanStatsBatchQuery::handle([
            'org_id' => $orgId,
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
        ]);
    }

    /**
     * Verbatim ScanCount::resolveCurrentMonthFromHot() -- same DEV-2324
     * `Scan::hot()::query()` bug, same no-try/catch contract (a 10-minute bucket must
     * propagate a failure rather than cache a fabricated 0), bucketed here by
     * {@see bucketZone()} instead of the raw viewer zone.
     */
    private function resolveTotalCurrentMonthFromHot(int $orgId, string $timezone): void
    {
        $cacheKey = $this->buildWidgetCacheKey(self::TOTAL_NAMESPACE.'this_month', $orgId);

        if (cache()->get($cacheKey) !== null) {
            return;
        }

        $start = now(tz: $timezone)->startOfMonth()->startOfDay()->toDateTimeString();
        $end = now(tz: $timezone)->endOfDay()->toDateTimeString();

        $count = $this->fetchTotalCurrentMonthCount($orgId, $start, $end, $timezone);

        cache()->put($cacheKey, $count, $this->getCacheTTL(0));
    }

    /**
     * ScanCount::resolveCurrentMonthFromHot()'s query -- the DEV-2324
     * `Scan::hot()::query()` setTable-discard bug preserved rather than fixed (out of scope).
     *
     * Review-3 H2: `$timezone` MUST be passed to `between()`. The bounds are minted in
     * {@see bucketZone()} (sanitised), but `Scan::scopeBetween()` :55 defaults to
     * `auth()->user()?->timezone` -- the RAW zone. `ScanCount` minted raw and parsed raw, so it
     * was self-consistent; moving the minting to bucketZone() without threading it here shifts
     * this bucket by the full UTC offset for any viewer whose `users.dti` zone is not a valid
     * IANA identifier (`+05:00`, `CST`), which safeTimezone() maps to UTC while Carbon accepts
     * verbatim. That would re-open, on the current-month bar, the mixed-calendar defect the
     * ScanStatsBatchQuery change in this same diff closed on the past-month bars.
     */
    protected function fetchTotalCurrentMonthCount(int $orgId, string $start, string $end, string $timezone): int
    {
        return (int) Scan::hot()::query()
            ->forOrg($orgId)
            ->between($start, $end, $timezone)
            ->count();
    }

    public function getCardQueryStatements(): array
    {
        $orgId = auth()->user()->org_id;

        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            [$start, $end] = $this->resolveCardRange();

            return [new CardQueryStatement(
                label: 'Headline total',
                sql: ScanPlateStatsBatchQuery::handleRangeSql([
                    'org_id' => $orgId,
                    'start' => $start->toDateTimeString(),
                    'end' => $end->toDateTimeString(),
                    'timezone' => $this->viewerTimezone(),
                ]),
                note: 'Rolled up per kit -- the card reads the `is_rollup = 1` row (the org total).',
                queryClass: ScanPlateStatsBatchQuery::class,
            )];
        }

        $bucketZone = $this->bucketZone();

        if ($this->date === 'this_month') {
            $window = ScanPlateStatsBatchQuery::currentMonthWindow($bucketZone);

            return [new CardQueryStatement(
                label: 'Headline total',
                sql: ScanPlateStatsBatchQuery::handleRangeSql([
                    'org_id' => $orgId,
                    'start' => $window['start'],
                    'end' => $window['end'],
                    'timezone' => $bucketZone,
                ]),
                note: 'Rolled up per kit -- the card reads the `is_rollup = 1` row (the org total).',
                queryClass: ScanPlateStatsBatchQuery::class,
            )];
        }

        $monthKey = now(tz: $bucketZone)->startOfMonth()->subMonth()->format('Y-m');
        $minMonth = now(tz: $bucketZone)->startOfMonth()->subMonths(12)->format('Y-m');

        return [new CardQueryStatement(
            label: 'Headline total',
            sql: ScanPlateStatsBatchQuery::handleSql([
                'org_id' => $orgId,
                // resolvePastMonthsFromCold()'s span when no past month is cached: months 12..1.
                'start' => $this->monthKeyStart($minMonth, $bucketZone)->startOfDay()->toDateTimeString(),
                'end' => $this->monthKeyStart($monthKey, $bucketZone)->endOfMonth()->endOfDay()->toDateTimeString(),
                'timezone' => $bucketZone,
            ]),
            note: "Bucketed by month -- the card reads the `{$monthKey}` row. This is the "
                .'cold-cache batch; a warm cache narrows the span to whichever months are uncached.',
            queryClass: ScanPlateStatsBatchQuery::class,
        )];
    }
}
