<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use App\Clickhouse\Queries\HitPlateStatsBatchQuery;
use App\Clickhouse\Queries\HitStatsBatchQuery;
use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Dto\CardQueryStatement;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

trait SharedHitStatsData
{
    protected static array $sharedMonthlyStats = [];

    protected static array $sharedRangeStats = [];

    protected static array $sharedUniqueMonthlyStats = [];

    protected static array $sharedUniqueRangeStats = [];

    /**
     * Every unique-series cache key carries this segment (e.g.
     * `live-hits:unique:2026-07:org=1`), so it can never alias a total key
     * (`live-hits:2026-07:org=1`). DEV-2324: one method, one cache key, two
     * consumers -- the unique and total series may never write into each
     * other's keys.
     */
    private const UNIQUE_NAMESPACE = 'unique:';

    /**
     * Negative-cache TTL (seconds) for the past-months unique-series marker
     * written when `HitPlateStatsBatchQuery::handle()` genuinely THROWS (a
     * real ClickHouse failure, not merely an empty result). Short on purpose:
     * long enough to stop 4 widgets each re-running the 13-partition
     * `uniqExact ... FINAL` scan on every render while ClickHouse is down,
     * short enough that a real recovery is picked back up quickly.
     */
    private const UNIQUE_FETCH_FAILURE_TTL_SECONDS = 60;

    /** Static grey for the Total series on the two-series hit charts (see staticColourDataset()). */
    private const TOTAL_SERIES_FILL = 'rgba(156, 163, 175, 0.45)';

    private const TOTAL_SERIES_BORDER = 'rgba(156, 163, 175, 0.8)';

    protected static array $hitWidgetMap = [
        'live-hits' => 'live_hit_count',
        'historical-hits' => 'historical_hit_count',
        'staging-live-hits' => 'staging_live_hit_count',
        'staging-historical-hits' => 'staging_historical_hit_count',
    ];

    abstract public function getHitStatsColumn(): string;

    /**
     * Anchors a `Y-m` month key to its first day before any month arithmetic
     * runs. `Carbon::createFromFormat('Y-m', $monthKey, $tz)` inherits TODAY's
     * day-of-month, which overflows a target month with fewer days than today's
     * (e.g. resolving `2026-06` from the 31st lands on 2026-07-01) --
     * `->startOfMonth()` cannot undo it because the overflow already happened
     * during parsing. Anchoring the day to `01` up front avoids the overflow
     * entirely. Used by both the total and unique batch-window resolvers so
     * they keep deriving the SAME window.
     */
    /**
     * `users.dti` is unvalidated. {@see ScanPlateStatsBatchQuery::safeTimezone()}
     * is the allowlist: unlisted ids become UTC and this method does not throw.
     */
    private function sanitizedTimezone(string $timezone): string
    {
        return ScanPlateStatsBatchQuery::safeTimezone($timezone);
    }

    private function monthKeyStart(string $monthKey, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $monthKey.'-01', $this->sanitizedTimezone($timezone));
    }

    /**
     * This widget's cache-key prefix, resolved from the AUTHORITATIVE $hitWidgetMap via the
     * widget's own getHitStatsColumn() rather than from class_basename(static::class).
     *
     * The class-name route only works while every widget's name happens to snake-case into a
     * $hitWidgetMap key (LiveHits -> live-hits, and so on). A widget added or renamed without
     * that coincidence would check a guard key that is never among the keys it writes, so the
     * guard would never fire and the current month would be re-resolved — re-issuing the
     * ClickHouse range query — on every render, silently and with no error.
     */
    private function hitWidgetPrefix(): string
    {
        $prefix = \array_search($this->getHitStatsColumn(), static::$hitWidgetMap, true);

        if ($prefix === false) {
            throw new \LogicException(
                static::class.'::getHitStatsColumn() returned "'.$this->getHitStatsColumn()
                .'", which is not a value in $hitWidgetMap — add it there, or the cache guards silently never fire.'
            );
        }

        return $prefix;
    }

    /**
     * The months in the 13-month window (12..1 back) that are NOT fully cached for every widget
     * prefix, as `['2026-07' => 7, ...]` (month key => months-ago index).
     *
     * Shared by the total and unique resolvers, which differ only in `$namespace` — keeping one
     * copy is what guarantees both series ask about the SAME set of months. A month counts as
     * uncached if ANY of the four widget prefixes is missing it, because one batch query fills
     * all four at once.
     */
    private function collectUncachedMonths(int $orgId, string $timezone, string $namespace = ''): array
    {
        $uncachedMonths = [];
        $timezone = $this->sanitizedTimezone($timezone);

        for ($i = 12; $i >= 1; $i--) {
            $monthKey = now(tz: $timezone)->startOfMonth()->subMonths($i)->format('Y-m');

            foreach (static::$hitWidgetMap as $prefix => $col) {
                if (cache()->get($prefix.':'.$namespace.$monthKey.':org='.$orgId) === null) {
                    $uncachedMonths[$monthKey] = $i;

                    break;
                }
            }
        }

        return $uncachedMonths;
    }

    protected function getMonthlyData(bool $formatted = true): array
    {
        $orgId = auth()->user()->org_id;
        $timezone = auth()->user()->timezone ?? 'UTC';
        $column = $this->getHitStatsColumn();
        $memoKey = $formatted ? 'formatted' : 'raw';

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $this->resolvePastMonths($orgId, $timezone);
        $this->resolveCurrentMonth($orgId, $timezone);

        $data = $this->buildMonthlyDataFromCache($orgId, $timezone, $column, $formatted);

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    private function resolvePastMonths(int $orgId, string $timezone): void
    {
        if (isset(static::$sharedMonthlyStats[$orgId])) {
            return;
        }

        $timezone = $this->sanitizedTimezone($timezone);
        $uncachedMonths = $this->collectUncachedMonths($orgId, $timezone);

        if (! empty($uncachedMonths)) {
            $sortedKeys = array_keys($uncachedMonths);
            sort($sortedKeys);
            $minMonth = $sortedKeys[0];
            $maxMonth = end($sortedKeys);

            $batchStart = $this->monthKeyStart($minMonth, $timezone)->startOfDay()->toDateTimeString();
            $batchEnd = $this->monthKeyStart($maxMonth, $timezone)->endOfMonth()->endOfDay()->toDateTimeString();

            $batchResult = HitStatsBatchQuery::query([
                'org_id' => $orgId,
                'start' => $batchStart,
                'end' => $batchEnd,
            ]);

            foreach ($uncachedMonths as $monthKey => $index) {
                $pastTTL = $this->getCacheTTL(1, $this->monthKeyStart($monthKey, $timezone)->endOfMonth());

                foreach (static::$hitWidgetMap as $prefix => $col) {
                    $cacheKey = $prefix.':'.$monthKey.':org='.$orgId;
                    $value = $batchResult[$monthKey][$col] ?? 0;

                    cache()->put($cacheKey, $value, $pastTTL);
                }
            }
        }

        static::$sharedMonthlyStats[$orgId] = true;
    }

    private function resolveCurrentMonth(int $orgId, string $timezone): void
    {
        $cacheKey = $this->hitWidgetPrefix().':this_month:org='.$orgId;

        if (cache()->get($cacheKey) !== null) {
            return;
        }

        $timezone = $this->sanitizedTimezone($timezone);
        $start = now(tz: $timezone)->startOfMonth()->startOfDay()->toDateTimeString();
        $end = now(tz: $timezone)->endOfDay()->toDateTimeString();

        $rangeResult = $this->getSharedHitStatsForRange($start, $end);

        $currentTTL = $this->getCacheTTL(0);
        foreach (static::$hitWidgetMap as $prefix => $col) {
            $key = $prefix.':this_month:org='.$orgId;
            cache()->put($key, $rangeResult[$col] ?? 0, $currentTTL);
        }
    }

    private function buildMonthlyDataFromCache(int $orgId, string $timezone, string $column, bool $formatted, string $namespace = ''): array
    {
        $data = [];
        $widgetPrefix = $this->hitWidgetPrefix();
        $timezone = $this->sanitizedTimezone($timezone);

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');
            $cacheKey = $widgetPrefix.':'.$namespace.$dateType.':org='.$orgId;

            $data[] = cache()->get($cacheKey) ?? 0;
        }

        if ($formatted && \method_exists($this, 'formatMonthlyData')) {
            $data = $this->formatMonthlyData($data);
        }

        return $data;
    }

    /**
     * Mirrors getMonthlyData() for the deduped-plate series (AC-1/AC-5), on the
     * SEPARATE `sharedUniqueMonthlyStats`/UNIQUE_NAMESPACE cache keys -- never the
     * total's. Same 12..0 month-bucketing loop as the total path (via
     * buildMonthlyDataFromCache()); no parallel loop is introduced.
     */
    protected function getUniqueMonthlyData(bool $formatted = true): array
    {
        $orgId = auth()->user()->org_id;
        $timezone = auth()->user()->timezone ?? 'UTC';
        $column = $this->getHitStatsColumn();
        $memoKey = 'unique-'.($formatted ? 'formatted' : 'raw');

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $this->resolvePastUniqueMonths($orgId, $timezone);
        $this->resolveCurrentUniqueMonth($orgId, $timezone);

        $data = $this->buildMonthlyDataFromCache($orgId, $timezone, $column, $formatted, self::UNIQUE_NAMESPACE);

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    /**
     * `handle()` returning `[]`/missing a month key means the query SUCCEEDED
     * and there is genuinely no data for that month -- that is a real answer
     * and `resolvePastUniqueMonths()` caches `0` for it under the normal long
     * TTL. Only a THROWN exception means the fetch itself failed; that case
     * writes this short negative-cache marker instead, in its own key
     * namespace so `buildMonthlyDataFromCache()` (or any other reader of the
     * `{prefix}:unique:{month}:org={orgId}` data keys) can never mistake it
     * for a data value.
     *
     * Deliberately NOT keyed by widget prefix (unlike buildWidgetCacheKey()).
     * LiveHits, HistoricalHits, StagingLiveHits, and StagingHistoricalHits are
     * four DIFFERENT classes that each `use` this trait, and PHP gives every
     * using class its OWN copy of a trait's static properties -- so a
     * per-widget marker meant each of the four widgets independently paid for
     * (and hammered) a hard-down ClickHouse before setting its own marker,
     * defeating the whole point of a negative cache. `$scope` distinguishes
     * the past-months batch fetch from a specific today/last_7d range fetch
     * (see getSharedUniqueHitStatsForRange()) without reintroducing a
     * per-widget dimension.
     */
    private function uniqueFetchFailureMarkerKey(int $orgId, string $scope): string
    {
        return 'hit-plate-stats:unique-fetch-failed:'.$scope.':org='.$orgId;
    }

    /**
     * Cross-widget-class cache key for the raw `handleRange()` fetch behind
     * getSharedUniqueHitStatsForRange(). Deliberately NOT prefixed by the widget's class
     * basename and NOT the `static::$sharedUniqueRangeStats` trait property (which is a
     * SEPARATE array per using class) — the result depends only on (org, start, end), never on
     * which of the four hit widgets asked for it, so all four must resolve to the SAME key.
     * `cache()` is one Cache Manager instance regardless of caller class, so this is what
     * actually collapses the four widgets' fetches into one.
     *
     * Kept as a method rather than inlined because the regression test that proves the collapse
     * has to seed this exact key — a reader and a writer that must agree on a format should not
     * each spell it out.
     */
    private function sharedUniqueRangeMemoKey(int $orgId, string $start, string $end): string
    {
        return 'hit-plate-stats:unique-range:'.$orgId.':'.$start.':'.$end;
    }

    /**
     * Mirror of resolvePastMonths() for the unique series. Differences: the
     * `sharedUniqueMonthlyStats` guard, UNIQUE_NAMESPACE cache keys,
     * HitPlateStatsBatchQuery::handle() called directly (bypassing
     * ClickhouseQuery::query(), which would swallow a real failure into an
     * indistinguishable-from-empty `[]`) inside our own try/catch, and the
     * negative-cache marker on a genuine failure (see
     * uniqueFetchFailureMarkerKey()). The SAME monthKeyStart() window
     * derivation the total path uses is reused verbatim -- no independent
     * timezone handling, so both series smear the same month boundary
     * identically.
     */
    private function resolvePastUniqueMonths(int $orgId, string $timezone): void
    {
        if (isset(static::$sharedUniqueMonthlyStats[$orgId])) {
            return;
        }

        if (cache()->get($this->uniqueFetchFailureMarkerKey($orgId, 'past-months')) !== null) {
            return;
        }

        $timezone = $this->sanitizedTimezone($timezone);
        $uncachedMonths = $this->collectUncachedMonths($orgId, $timezone, self::UNIQUE_NAMESPACE);

        if (! empty($uncachedMonths)) {
            $sortedKeys = array_keys($uncachedMonths);
            sort($sortedKeys);
            $minMonth = $sortedKeys[0];
            $maxMonth = end($sortedKeys);

            $batchStart = $this->monthKeyStart($minMonth, $timezone)->startOfDay()->toDateTimeString();
            $batchEnd = $this->monthKeyStart($maxMonth, $timezone)->endOfMonth()->endOfDay()->toDateTimeString();

            try {
                $batchResult = HitPlateStatsBatchQuery::handle([
                    'org_id' => $orgId,
                    'start' => $batchStart,
                    'end' => $batchEnd,
                ]);
            } catch (\Throwable $e) {
                if (in_array(config('app.env'), ['production', 'testing'])) {
                    report($e);

                    cache()->put(
                        $this->uniqueFetchFailureMarkerKey($orgId, 'past-months'),
                        true,
                        self::UNIQUE_FETCH_FAILURE_TTL_SECONDS
                    );

                    return;
                }

                throw $e;
            }

            /*
             * The batch query GROUPs BY month, so a month with genuinely zero
             * hits simply has no row in a SUCCESSFUL $batchResult (including a
             * completely empty one) -- that is a real answer, not a failure
             * signal, and caching 0 for it is correct. It is what restores the
             * throttle for a zero-hit org: without it, every render re-runs the
             * full 13-partition `uniqExact ... FINAL` scan.
             */
            foreach ($uncachedMonths as $monthKey => $index) {
                $pastTTL = $this->getCacheTTL(1, $this->monthKeyStart($monthKey, $timezone)->endOfMonth());

                foreach (static::$hitWidgetMap as $prefix => $col) {
                    $cacheKey = $prefix.':'.self::UNIQUE_NAMESPACE.$monthKey.':org='.$orgId;
                    $value = $batchResult[$monthKey][$col] ?? 0;

                    cache()->put($cacheKey, $value, $pastTTL);
                }
            }
        }

        static::$sharedUniqueMonthlyStats[$orgId] = true;
    }

    /**
     * Mirror of resolveCurrentMonth() for the unique series -- UNIQUE_NAMESPACE keys
     * and getSharedUniqueHitStatsForRange() as the source. `null` means the fetch
     * FAILED (see getSharedUniqueHitStatsForRange()): write nothing -- including
     * the cache key that itself acts as this method's own "resolved" guard --
     * so the next call retries instead of persisting a fabricated 0.
     */
    private function resolveCurrentUniqueMonth(int $orgId, string $timezone): void
    {
        $cacheKey = $this->hitWidgetPrefix().':'.self::UNIQUE_NAMESPACE.'this_month:org='.$orgId;

        if (cache()->get($cacheKey) !== null) {
            return;
        }

        $timezone = $this->sanitizedTimezone($timezone);
        $start = now(tz: $timezone)->startOfMonth()->startOfDay()->toDateTimeString();
        $end = now(tz: $timezone)->endOfDay()->toDateTimeString();

        $rangeResult = $this->getSharedUniqueHitStatsForRange($start, $end);

        if ($rangeResult === null) {
            return;
        }

        $currentTTL = $this->getCacheTTL(0);
        foreach (static::$hitWidgetMap as $prefix => $col) {
            $key = $prefix.':'.self::UNIQUE_NAMESPACE.'this_month:org='.$orgId;
            cache()->put($key, $rangeResult[$col] ?? 0, $currentTTL);
        }
    }

    public function getDescription(): string|Htmlable|null
    {
        if ($this->date === 'today') {
            return new HtmlString('Today: '.\number_format($this->currentPeriodUnique()));
        }

        if ($this->date === 'last_7d') {
            return new HtmlString('Last 7 Days: '.\number_format($this->currentPeriodUnique()));
        }

        if ($this->date === 'last_month') {
            return new HtmlString('Last Month: '.\number_format($this->currentPeriodUnique()));
        }

        $monthlyData = $this->getUniqueMonthlyData();

        return new HtmlString(
            'Current Month: '.\number_format($monthlyData[count($monthlyData) - 1] ?? 0).' | Last Month: '.\number_format($monthlyData[count($monthlyData) - 2] ?? 0)
        );
    }

    /**
     * Bounded today/last_7d total through the batched hit_stats_daily query rather than
     * the getModelQueryForDates() 0-stub. Same expression getDescription() used before
     * DEV-2324, so the card and the expanded chart cannot disagree.
     */
    protected function getRangeTotal(string $start, string $end): int
    {
        return (int) ($this->getSharedHitStatsForRange($start, $end)[$this->getHitStatsColumn()] ?? 0);
    }

    protected function getSharedHitStatsForRange(string $start, string $end): array
    {
        $orgId = auth()->user()->org_id;
        $cacheKey = "{$orgId}:{$start}:{$end}";

        if (isset(static::$sharedRangeStats[$cacheKey])) {
            return static::$sharedRangeStats[$cacheKey];
        }

        try {
            $result = HitStatsBatchQuery::handleRange([
                'org_id' => $orgId,
                'start' => $start,
                'end' => $end,
            ]);
        } catch (\Throwable $e) {
            if (in_array(config('app.env'), ['production', 'testing'])) {
                report($e);

                $result = [
                    'live_hit_count' => 0,
                    'historical_hit_count' => 0,
                    'staging_live_hit_count' => 0,
                    'staging_historical_hit_count' => 0,
                ];
            } else {
                throw $e;
            }
        }

        static::$sharedRangeStats[$cacheKey] = $result;

        return $result;
    }

    /**
     * Mirror of getSharedHitStatsForRange() for the deduped-plate series, keyed into
     * the SEPARATE `sharedUniqueRangeStats` memo and backed by
     * HitPlateStatsBatchQuery::handleRange().
     *
     * try/catch REQUIRED -- this calls handleRange() directly, bypassing
     * ClickhouseQuery::query() entirely, so nothing else swallows a Throwable
     * here in ANY env. Unlike the TOTAL sibling, a failure here returns `null`
     * rather than a zeroed four-key shape: callers cache the total sibling's
     * zeroed return value, which is exactly the "fabricated value under a
     * shared key" this series must not repeat. `null` is a sentinel every
     * caller must treat as "do not cache" -- see currentPeriodUnique() and
     * resolveCurrentUniqueMonth().
     *
     * DEV-2406 review fix: `static::$sharedUniqueRangeStats` alone does NOT
     * dedupe this fetch across the four hit widgets one dashboard render
     * resolves -- it is a trait property, so LiveHits, HistoricalHits,
     * StagingLiveHits, and StagingHistoricalHits each get their OWN copy (see
     * sharedUniqueRangeMemoKey()'s docblock). `sharedUniqueRangeMemoKey()`
     * layers a genuinely cross-class cache read/write in front of the query
     * for that reason: the first widget to miss both this class's own memo
     * AND the shared cache resolves the fetch once; the other three read it
     * back without ever reaching ClickHouse. The negative-cache marker
     * (uniqueFetchFailureMarkerKey()) mirrors resolvePastUniqueMonths()'s
     * protection, which this range path previously had none of at all.
     */
    protected function getSharedUniqueHitStatsForRange(string $start, string $end): ?array
    {
        $orgId = auth()->user()->org_id;
        $cacheKey = "{$orgId}:{$start}:{$end}";

        if (isset(static::$sharedUniqueRangeStats[$cacheKey])) {
            return static::$sharedUniqueRangeStats[$cacheKey];
        }

        $sharedMemoKey = $this->sharedUniqueRangeMemoKey($orgId, $start, $end);
        $sharedResult = cache()->get($sharedMemoKey);

        if ($sharedResult !== null) {
            return static::$sharedUniqueRangeStats[$cacheKey] = $sharedResult;
        }

        $markerKey = $this->uniqueFetchFailureMarkerKey($orgId, "range:{$start}:{$end}");

        if (cache()->get($markerKey) !== null) {
            return null;
        }

        try {
            $result = HitPlateStatsBatchQuery::handleRange([
                'org_id' => $orgId,
                'start' => $start,
                'end' => $end,
            ]);
        } catch (\Throwable $e) {
            if (in_array(config('app.env'), ['production', 'testing'])) {
                report($e);

                cache()->put($markerKey, true, self::UNIQUE_FETCH_FAILURE_TTL_SECONDS);

                return null;
            }

            throw $e;
        }

        static::$sharedUniqueRangeStats[$cacheKey] = $result;
        cache()->put($sharedMemoKey, $result, $this->getCacheTTL());

        return $result;
    }

    /**
     * Mirror of DashboardWidget::currentPeriodTotal() for the unique series, but
     * deliberately NOT built on cache()->remember(): a failed fetch must never
     * be persisted under the shared `{prefix}:unique:{date}:org={orgId}` key that
     * getCardTotal() and getDescription() both read -- that is precisely the
     * "fabricated value under a shared key" DashboardWidget's docblock forbids.
     * Read the cache; on a miss, fetch; only write the cache when the fetch
     * SUCCEEDED (getSharedUniqueHitStatsForRange() returned non-null). On
     * failure, return 0 for display only, uncached, so the next render retries.
     */
    protected function currentPeriodUnique(): int
    {
        [$start, $end] = $this->resolveCardRange();

        $cacheKey = $this->buildWidgetCacheKey(self::UNIQUE_NAMESPACE.$this->date, auth()->user()->org_id);

        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (int) $cached;
        }

        $result = $this->getSharedUniqueHitStatsForRange($start->toDateTimeString(), $end->toDateTimeString());

        if ($result === null) {
            return 0;
        }

        $value = (int) ($result[$this->getHitStatsColumn()] ?? 0);

        $ttl = $this->getCacheTTL();

        if ($this->date === 'last_month') {
            $timezone = $this->sanitizedTimezone((string) (auth()->user()->timezone ?? 'UTC'));
            $monthEndedAt = now(tz: $timezone)->startOfMonth()->subMonth()->endOfMonth();
            $ttl = $this->getCacheTTL(1, $monthEndedAt);
        }

        cache()->put($cacheKey, $value, $ttl);

        return $value;
    }

    /**
     * AC-1: the compact card headline is the DEDUPED plate count, not the raw total.
     * today / last_7d / last_month use the same resolveCardRange() window as
     * StagingLiveHitLeader / RecoveryLeader so Last Month cannot keep publishing
     * the current-month unique series. this_month still reads the cached month bucket.
     */
    public function getCardTotal(): int
    {
        if (\in_array($this->date, ['today', 'last_7d', 'last_month'], true)) {
            return $this->currentPeriodUnique();
        }

        $monthlyData = $this->getUniqueMonthlyData(formatted: false);

        return (int) ($monthlyData[\count($monthlyData) - 1] ?? 0);
    }

    /**
     * AC-2 (data half): the raw hit count, demoted to the Stat description by
     * DEV-2406 -- the headline is now the deduped plate count. Verbatim the previous
     * getCardTotal() behaviour, reading the (unchanged) total series.
     */
    public function getTotalHitCount(): int
    {
        if (\in_array($this->date, ['today', 'last_7d', 'last_month'], true)) {
            return $this->currentPeriodTotal();
        }

        $monthlyData = $this->getMonthlyData(formatted: false);

        return (int) ($monthlyData[\count($monthlyData) - 1] ?? 0);
    }

    /**
     * AC-2 (render): same Stat chrome as DashboardWidget::toStat(), plus a
     * ->description() secondary line so the raw total isn't lost when the
     * headline became the deduped plate count (AC-1). Precedent:
     * KitUsers.php's ->description('... inactive kits').
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(
            Stat::make($this->getCardLabel(), \number_format($this->getCardTotal()))
                ->description(\number_format($this->getTotalHitCount()).' total hits')
                ->chart($this->chartSeries())
                ->color($this->getStatColor()),
            $selectedCard,
        );
    }

    /**
     * Overrides BuildsDateFilterChartSeries::chartSeries() so the compact
     * sparkline follows the headline onto the unique series (AC-1's deduped
     * count next to a total-shaped sparkline would be incoherent). Same
     * shape/logic as the base, sourced from getUniqueMonthlyData() instead.
     *
     * @return array<int, float>
     */
    protected function chartSeries(): array
    {
        $points = [];

        foreach ($this->getUniqueMonthlyData(formatted: false) as $bucket) {
            $points[] = \is_array($bucket) ? (float) \array_sum($bucket) : (float) $bucket;
        }

        if (\count($points) < 2) {
            \array_unshift($points, 0.0);
        }

        return $points;
    }

    /**
     * AC-3: two grouped (not stacked) datasets over the same 13-month labels.
     * Deliberately omits makeDataset()'s $stack argument -- that is the entire
     * mechanism that keeps Chart.js from stacking the bars (RecoveryLeader.php
     * passes stack: 'recoveries' for the opposite effect).
     */
    public function getData(): array
    {
        $label = 'Total';

        if (\method_exists($this, 'getDatasetLabel')) {
            $label = $this->getDatasetLabel();
        }

        $accent = 'rgba('.\implode(', ', $this->getRgbColor()).', 0.85)';
        $accentBorder = 'rgba('.\implode(', ', $this->getRgbColor()).', 1)';

        return [
            'datasets' => [
                $this->staticColourDataset($label.' (Total)', $this->getMonthlyData(), self::TOTAL_SERIES_FILL, self::TOTAL_SERIES_BORDER),
                $this->staticColourDataset($label.' (Unique Plates)', $this->getUniqueMonthlyData(), $accent, $accentBorder),
            ],
            'labels' => $this->getGraphLabels(),
        ];
    }

    /**
     * AC-4: the legend is only useful once there are two series to distinguish,
     * which only happens in the expanded chart. Copies (never mutates)
     * $this->options -- mount() already wrote tooltip.borderColor into it -- so
     * RecoveryCounts, which doesn't use this trait, keeps the base
     * legend.display => false in both states.
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
     * The headline is the DEDUPED plate count (HitPlateStatsBatchQuery), not the raw hit total
     * that HitStatsBatchQuery feeds into the description line. All four modes resolve through
     * the same range query -- this_month via resolveCurrentUniqueMonth()'s month-to-date window.
     */
    public function getCardQueryStatements(): array
    {
        $orgId = auth()->user()->org_id;

        if (\in_array($this->date, ['today', 'last_7d', 'last_month'], true)) {
            [$start, $end] = $this->resolveCardRange();
        } else {
            $timezone = $this->sanitizedTimezone((string) (auth()->user()->timezone ?? 'UTC'));
            $start = now(tz: $timezone)->startOfMonth()->startOfDay();
            $end = now(tz: $timezone)->endOfDay();
        }

        return [new CardQueryStatement(
            label: 'Headline total (deduped plates)',
            sql: HitPlateStatsBatchQuery::handleRangeSql([
                'org_id' => $orgId,
                'start' => $start->toDateTimeString(),
                'end' => $end->toDateTimeString(),
            ]),
            note: "The card reads the `{$this->getHitStatsColumn()}` column.",
            queryClass: HitPlateStatsBatchQuery::class,
        )];
    }
}
