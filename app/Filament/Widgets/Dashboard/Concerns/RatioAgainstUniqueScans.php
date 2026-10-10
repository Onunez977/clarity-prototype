<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use Carbon\CarbonInterface;

/**
 * DEV-2651. The NUMERATOR every "unique scans per X" ratio card shares, lifted out of
 * UniqueScansToCaseClaims, UniqueScansToRepo and UniqueScansToLprRepossession.
 *
 * Carries NO denominator on purpose: a card's headline series already builds a `Y-m`-keyed
 * month array, and the ratio dataset must zip this numerator against THAT SAME array. Two
 * arrays from two independently-bucketed queries can drift apart; one array zipped against
 * itself cannot.
 *
 * `canViewRatioFigure()` is the partial-gate seam this ticket adds — a card whose headline is
 * ungated but whose ratio stays Pro-only overrides this alone.
 */
trait RatioAgainstUniqueScans
{
    /**
     * Deliberately not DashboardMetricCard::LOCKED_VALUE: "could not be computed" is a
     * different statement from "you are not entitled to this".
     */
    public const UNAVAILABLE_VALUE = '--';

    /** Without this, a swallowed numerator failure renders an indistinguishable, confident 0.0. */
    private bool $numeratorUnavailable = false;

    /**
     * Defaults to the card-level gate, so a fully-gated card needs no override.
     *
     * `auth()->check()` first is NOT redundant. This is a DISCLOSURE gate, and
     * `RendersDashboardStat::canViewCardData()` answers `true` for a guest by design (it asks
     * "may the viewing ORG see this", which is meaningless with no viewer). Delegating bare
     * would make the ratio open by default on any card whose own gate is the ungated
     * default — the exact shape this seam exists for.
     */
    public static function canViewRatioFigure(): bool
    {
        return auth()->check() && static::canViewCardData();
    }

    /**
     * Full-alpha amber. Review-2 M2 chose this rgb for separation: it is neither card accent
     * (green / blue) nor any grey makeDataset() reaches for (0.3 / 0.5 / 0.6 / 0.8).
     */
    private const RATIO_SERIES_COLOUR = 'rgba(245, 158, 11, 1)';

    /** Lower than COUNT_SERIES_DRAW_ORDER: see ratioLineDataset(). */
    private const RATIO_SERIES_DRAW_ORDER = 0;

    private const COUNT_SERIES_DRAW_ORDER = 1;

    /**
     * The ratio rides a LINE, not a second bar. Two bars of the same height on two different
     * axes read as one unit -- a viewer has to map bar to axis by colour to learn that 5 and
     * 8 are a count and a rate. Different geometry carries that distinction on its own, and
     * the point style makes the legend swatch say so too.
     *
     * ORDER IS LOAD-BEARING. Chart.js draws datasets in REVERSE index order, so dataset 0 --
     * the bars -- paints LAST and buries the line behind them. It sorts by `order` ascending
     * first, so the lower `order` here is what puts the line in front. Ties are broken by
     * index, which is why leaving both at the default lost the line.
     *
     * @param  array<int, float>  $ratios
     * @return array<string, mixed>
     */
    protected function ratioLineDataset(string $label, array $ratios): array
    {
        return [
            'type' => 'line',
            'label' => $label,
            'data' => $ratios,
            'yAxisID' => 'y1',
            'order' => self::RATIO_SERIES_DRAW_ORDER,
            'borderColor' => self::RATIO_SERIES_COLOUR,
            'backgroundColor' => self::RATIO_SERIES_COLOUR,
            'pointBackgroundColor' => self::RATIO_SERIES_COLOUR,
            'pointBorderColor' => self::RATIO_SERIES_COLOUR,
            'borderWidth' => 2,
            'pointRadius' => 2,
            'tension' => 0.3,
            'fill' => false,
        ];
    }

    /**
     * The count series whenever the ratio is co-plotted: ONE colour, because the legend that
     * second series turns on reads index 0 of makeDataset()'s per-month array. Cards keep
     * calling makeDataset() on the single-series path, where the month highlight still earns
     * its place. See DashboardWidget::staticColourDataset().
     *
     * @param  array<int, float|int>  $data
     * @return array<string, mixed>
     */
    protected function countSeriesDataset(string $label, array $data): array
    {
        $rgb = \implode(', ', $this->getRgbColor());

        return \array_merge(
            $this->staticColourDataset($label, $data, "rgba({$rgb}, 0.85)", "rgba({$rgb}, 1)"),
            ['yAxisID' => 'y', 'order' => self::COUNT_SERIES_DRAW_ORDER],
        );
    }

    /** A zero denominator is normal (an org with no activity), so 0.0 rather than an error. */
    protected static function ratio(int|float $numerator, int|float $denominator): float
    {
        if ((float) $denominator === 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /**
     * Zone for the ratio numerator. Delegates to {@see ScanPlateStatsBatchQuery::safeTimezone()}
     * before any `now(tz:)`. Unlisted ids (CST, PST, +05:00) become UTC. A legacy alias is
     * kept when PHP can construct it and otherwise becomes its canonical IANA id, so
     * `now(tz:)` receives a constructable zone. This method must not throw.
     *
     * Callers mint `now(tz:)` from the return value. `fetchUniqueScansSeries()` keeps its
     * default series zone of UTC; pass this in when the numerator should follow the viewer.
     */
    protected function numeratorZone(): string
    {
        return ScanPlateStatsBatchQuery::safeTimezone((string) (auth()->user()->timezone ?? 'UTC'));
    }

    /**
     * The 13-month leg, read per month through the class-neutral
     * {@see ScanPlateStatsBatchQuery::sharedMonthCacheKey()} namespace that UniqueScanCounts
     * and UniqueScansLeaderboard also read. NEVER `buildWidgetCacheKey()`, which prefixes the
     * calling widget's class basename and would hide this write from every other reader.
     *
     * On failure: report, set the shared backoff marker, return what was already cached.
     * {@see ScanPlateStatsBatchQuery::rememberMonthSeries()} writes the Y-m ints. The open
     * month uses the ladder's index-0 arm. Closed months use the query's closed-month TTL,
     * including UniqueScanCounts' impersonation floor. A card getCacheTTL() override does
     * not reach these keys.
     *
     * @return array<string, int> keyed 'Y-m'
     */
    protected function fetchUniqueScansSeries(?int $orgId, CarbonInterface $through, string $seriesZone = 'UTC'): array
    {
        if ($orgId === null) {
            return [];
        }

        $monthKeys = [];

        for ($i = 12; $i >= 0; $i--) {
            $monthKeys[] = $through->clone()->startOfMonth()->subMonths($i)->format('Y-m');
        }

        if (cache()->get(ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($orgId)) !== null) {
            $this->numeratorUnavailable = true;

            return [];
        }

        try {
            return ScanPlateStatsBatchQuery::rememberMonthSeries(
                $orgId,
                $seriesZone,
                $monthKeys,
                $through->format('Y-m'),
                $through,
                fn (string $start, string $end): array => $this->runUniqueScansBatch($orgId, $start, $end, $seriesZone),
            );
        } catch (\Throwable $e) {
            if (\in_array(config('app.env'), ['production', 'testing'], true)) {
                report($e);

                cache()->put(
                    ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($orgId),
                    true,
                    ScanPlateStatsBatchQuery::FETCH_FAILURE_TTL_SECONDS
                );

                $this->numeratorUnavailable = true;

                return ScanPlateStatsBatchQuery::cachedMonthInts($orgId, $seriesZone, $monthKeys);
            }

            throw $e;
        }
    }

    /**
     * The window leg (today/last_7d), degraded the same way as the monthly leg.
     * CompanyMetricsOverview::getStats() has no per-card try/catch, so nothing may propagate.
     */
    protected function windowUniqueScans(?int $orgId, CarbonInterface $start, CarbonInterface $end, string $timezone): int
    {
        if ($orgId === null) {
            return 0;
        }

        if (cache()->get(ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($orgId)) !== null) {
            $this->numeratorUnavailable = true;

            return 0;
        }

        try {
            $scans = $this->fetchUniqueScansRange(
                $orgId,
                $start->toDateTimeString(),
                $end->toDateTimeString(),
                $timezone,
            );
        } catch (\Throwable $e) {
            if (\in_array(config('app.env'), ['production', 'testing'], true)) {
                report($e);

                cache()->put(
                    ScanPlateStatsBatchQuery::sharedFetchFailureMarkerKey($orgId),
                    true,
                    ScanPlateStatsBatchQuery::FETCH_FAILURE_TTL_SECONDS
                );

                $this->numeratorUnavailable = true;

                return 0;
            }

            throw $e;
        }

        return (int) ($scans['totals'][0] ?? 0);
    }

    /**
     * A seam so a test can count invocations and record the zone reaching the query;
     * DB::listen never fires for the ClickHouse driver.
     *
     * @return array<string, int>
     */
    protected function runUniqueScansBatch(int $orgId, string $start, string $end, string $timezone): array
    {
        return ScanPlateStatsBatchQuery::handle([
            'org_id' => $orgId,
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
        ]);
    }

    /**
     * Keyed identically to handleRange()'s own arguments so this rides whatever another reader
     * of the same window already cached.
     *
     * @return array{totals: array<int, int>, names: array<int, string>}
     */
    protected function fetchUniqueScansRange(int $orgId, string $start, string $end, string $timezone): array
    {
        return ScanPlateStatsBatchQuery::rememberedRange($orgId, $start, $end, $timezone);
    }

    /** @return array<int, int> */
    abstract public function getRgbColor(): array;
}
