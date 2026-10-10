<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Queries\RecoveryTotalsBatchQuery;
use App\Dto\CardQueryStatement;
use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\RatioAgainstUniqueScans;
use Carbon\CarbonInterface;
use Filament\Support\RawJs;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

/**
 * DEV-2651. Absorbs unique-scans-to-repo's headline and ratio (t8 removes that card). The
 * headline now batches through {@see RecoveryTotalsBatchQuery} instead of thirteen Eloquent
 * round trips (DEV-2555), and since both the headline and the ratio's denominator now sit on
 * one card, {@see RecoveryTotalsBatchQuery}'s own docblock warning that the Eloquent and
 * batched paths can disagree within a TTL is structurally impossible here, not just narrowed.
 *
 * Month labels stay on UTC (SERIES_ZONE) with the headline bars. The label axis MUST
 * co-vary with the headline data axis -- getData() zips labels to data POSITIONALLY and
 * generateColors() matches the highlight against the label STRING, so a one-month skew
 * would mislabel all 13 bars silently. The unique-scans numerator is a separate clock.
 *
 * DEV-2652. The unique-scans numerator is viewer-local. `repo_date` is a Date and cannot
 * move; the ratio is a documented mismatch, not a faked shift. sharedMonthCacheKey() carries
 * no `:tz=` because the value does not depend on zone. The positional zip disagrees near a
 * boundary.
 *
 * Total Repossessions stays visible to every org -- canViewCardData() is untouched. Only the
 * ratio is Pro-gated, via {@see canViewRatioFigure()}; on that ungated path the numerator query
 * must never execute, which is why every ratio accessor below checks the gate BEFORE reading
 * anything from {@see RatioAgainstUniqueScans}.
 */
class RecoveryCounts extends DashboardWidget implements ExplainsCardQuery
{
    use RatioAgainstUniqueScans;

    private const SERIES_ZONE = 'UTC';

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Total Repossessions';

    private ?array $ratioMonthlyMemo = null;

    private ?float $windowRatioMemo = null;

    protected function getTooltipDescription(): ?string
    {
        return 'All repossessions recorded for your company in this period, LPR-assisted or not.';
    }

    public function getDatasetLabel(): string
    {
        return 'Total Repossessions';
    }

    public function getRgbColor(): array
    {
        return [127, 172, 255];
    }

    #[On('refresh-dashboard')]
    public function refreshData(string $date): void
    {
        parent::refreshData($date);

        $this->ratioMonthlyMemo = null;
        $this->windowRatioMemo = null;
        $this->numeratorUnavailable = false;
    }

    /**
     * AC-2: the description is the ratio, gated, and never computed when the gate is closed.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        $stat = Stat::make($this->getCardLabel(), \number_format($this->getCardTotal()))
            ->chart($this->chartSeries())
            ->color($this->getStatColor());

        if (static::canViewRatioFigure()) {
            $stat->description($this->ratioDescription());
        }

        return $this->dashboardStat($stat, $selectedCard);
    }

    private function ratioDescription(): string
    {
        // Computed FIRST because computing it is what sets $numeratorUnavailable.
        $ratio = $this->currentRatio();

        $value = $this->numeratorUnavailable ? self::UNAVAILABLE_VALUE : \number_format($ratio, 1);

        return $value.' scans per repo';
    }

    private function currentRatio(): float
    {
        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            return $this->windowRatio();
        }

        $series = $this->ratioMonthlySeries();

        return (float) ($this->date === 'last_month'
            ? ($series[\count($series) - 2] ?? 0.0)
            : ($series[\count($series) - 1] ?? 0.0));
    }

    /**
     * Memoised: getDescription() and toStat() both resolve the window arm per request.
     *
     * Numerator bounds use resolveCardRange()'s day expressions in numeratorZone().
     * Do not pass resolveCardRange()'s raw-zone Carbon into the scans leg.
     */
    private function windowRatio(): float
    {
        if ($this->windowRatioMemo !== null) {
            return $this->windowRatioMemo;
        }

        $orgId = auth()->user()->org_id;
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

        // currentPeriodTotal() is the SAME headline total the compact card renders -- the
        // window-arm equivalent of decision 9, never a second denominator fetch.
        $repos = $this->currentPeriodTotal();
        $scans = $this->windowUniqueScans($orgId, $start, $end, $zone);

        return $this->windowRatioMemo = self::ratio($scans, $repos);
    }

    /**
     * Decision 9: zipped against THIS card's own headline month array
     * ({@see getMonthlyData()}), never a second independently-bucketed denominator fetch.
     *
     * Repos stay on now(self::SERIES_ZONE) inside getMonthlyData(). Scans use a separate
     * through, minted in numeratorZone(). The positional zip disagrees near a boundary.
     *
     * @return array<int, float>
     */
    private function ratioMonthlySeries(): array
    {
        if ($this->ratioMonthlyMemo !== null) {
            return $this->ratioMonthlyMemo;
        }

        $orgId = auth()->user()->org_id;
        $zone = $this->numeratorZone();
        $scanThrough = now(tz: $zone);

        $repos = $this->getMonthlyData(formatted: false);
        $scans = $this->fetchUniqueScansSeries($orgId, $scanThrough, $zone);

        $data = [];

        for ($i = 12; $i >= 0; $i--) {
            $monthKey = $scanThrough->clone()->startOfMonth()->subMonths($i)->format('Y-m');
            $data[] = self::ratio($scans[$monthKey] ?? 0, $repos[12 - $i] ?? 0);
        }

        return $this->ratioMonthlyMemo = $data;
    }

    /**
     * Gate-then-compute, so an ungated or numerator-failed request returns null without either
     * touching the numerator query or handing getData()/getOptions() a series to draw.
     *
     * @return array<int, float>|null
     */
    private function ratioSeriesForChart(): ?array
    {
        if (! static::canViewRatioFigure()) {
            return null;
        }

        $series = $this->ratioMonthlySeries();

        return $this->numeratorUnavailable ? null : $series;
    }

    /**
     * AC-3/AC-4: a second "unique scans per repo" line, on its own axis, added only when the
     * ratio is both entitled and available -- see toStat() and ratioSeriesForChart() for why it
     * is never computed on the ungated path.
     */
    public function getData(): array
    {
        $data = parent::getData();
        $ratio = $this->ratioSeriesForChart();

        if ($ratio === null) {
            return $data;
        }

        // Human-QA finding: parent::getData() colours this series through makeDataset()'s
        // per-month array, so the legend the ratio turns on showed the leftmost month's grey
        // against green bars. Restated once the second series exists, not before.
        // Agent-QA finding on the label: this read 'Unique Scans to Repo' -- the TITLE OF THE
        // DELETED CARD, inconsistent with its own description line ("N scans per repo") and
        // with its two sibling ratio cards.
        $data['datasets'][0] = $this->countSeriesDataset(
            $data['datasets'][0]['label'],
            $data['datasets'][0]['data'],
        );

        $data['datasets'][] = $this->ratioLineDataset('Scans per Repo', $ratio);

        return $data;
    }

    /** AC-3/AC-4: the legend and the right-hand axis only exist alongside the second dataset. */
    protected function getOptions(): array|RawJs|null
    {
        $options = $this->options;
        $ratio = $this->ratioSeriesForChart();

        $options['plugins']['legend']['display'] = $ratio !== null && $this->expanded;

        if ($ratio === null) {
            return $options;
        }

        $options['scales']['y']['title'] = ['display' => true, 'text' => 'Total Repossessions'];
        $options['scales']['y1'] = [
            'position' => 'right',
            'beginAtZero' => true,
            'grid' => ['drawOnChartArea' => false],
            'title' => ['display' => true, 'text' => 'Scans per Repo'],
        ];

        return $options;
    }

    public function getGraphLabels(): array
    {
        $labels = [];

        for ($i = 12; $i >= 0; $i--) {
            $labels[] = now(self::SERIES_ZONE)->startOfMonth()->subMonthsNoOverflow($i)->format('M \'y');
        }

        return $labels;
    }

    protected function getHighlightedMonthsFromFilter(): array
    {
        return match ($this->date) {
            'last_month' => [now(self::SERIES_ZONE)->startOfMonth()->subMonthNoOverflow()->format('M \'y')],
            default => [now(self::SERIES_ZONE)->format('M \'y')],
        };
    }

    /**
     * The headline's 13-month leg (DEV-2555): probed per month against
     * {@see RecoveryTotalsBatchQuery::sharedMonthCacheKey()}, gap-filled with ONE batched call.
     * This card now OWNS that namespace -- unique-scans-to-repo's copy of this shape reads the
     * same keys this writes.
     */
    protected function getMonthlyData(bool $formatted = true): array
    {
        $memoKey = $formatted ? 'formatted' : 'raw';

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $orgId = auth()->user()->org_id;
        $through = now(self::SERIES_ZONE);

        $repos = $this->fetchRepoSeries($orgId, $through);

        $data = [];

        for ($i = 12; $i >= 0; $i--) {
            $monthKey = $through->clone()->startOfMonth()->subMonths($i)->format('Y-m');
            $data[] = (int) ($repos[$monthKey] ?? 0);
        }

        if ($formatted && \method_exists($this, 'formatMonthlyData')) {
            $data = $this->formatMonthlyData($data);
        }

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    /**
     * NO try/catch: a swallowed failure here is indistinguishable from a genuine zero-repo
     * month, and this card read the same table through Eloquent with no catch anywhere before
     * this ticket -- propagating keeps that contract.
     *
     * NOT clamped to $through: `repo_date` is a business date that can legitimately sit in the
     * future, and the current month must cover its whole span the way the pre-batch Eloquent
     * path (`endOfMonth()`) did.
     *
     * @return array<string, int> keyed 'Y-m'
     */
    protected function fetchRepoSeries(?int $orgId, CarbonInterface $through): array
    {
        if ($orgId === null) {
            return [];
        }

        $result = [];
        $uncached = [];

        for ($i = 12; $i >= 0; $i--) {
            $month = $through->clone()->startOfMonth()->subMonths($i);
            $monthKey = $month->format('Y-m');
            $cached = cache()->get(RecoveryTotalsBatchQuery::sharedMonthCacheKey($orgId, $monthKey));

            if ($cached === null) {
                $uncached[$monthKey] = $month;
            } else {
                $result[$monthKey] = (int) $cached;
            }
        }

        if ($uncached === []) {
            return $result;
        }

        $monthKeys = \array_keys($uncached);
        \sort($monthKeys);

        $spanStart = $uncached[$monthKeys[0]]->clone()->startOfMonth();
        $spanEnd = $uncached[$monthKeys[\count($monthKeys) - 1]]->clone()->endOfMonth();

        $batch = $this->runRepoSeriesBatch($orgId, $spanStart->toDateString(), $spanEnd->toDateString());

        $currentMonthKey = $through->format('Y-m');

        foreach ($uncached as $monthKey => $month) {
            $value = (int) ($batch[$monthKey] ?? 0);
            $result[$monthKey] = $value;

            cache()->put(
                RecoveryTotalsBatchQuery::sharedMonthCacheKey($orgId, $monthKey),
                $value,
                $monthKey === $currentMonthKey
                    ? $this->getCacheTTL()
                    : $this->getCacheTTL(1, $month->clone()->endOfMonth()),
            );
        }

        return $result;
    }

    /**
     * The headline's one batched round trip. A seam so a test can COUNT invocations -- DB::listen
     * never fires for the ClickHouse driver.
     *
     * @return array<string, int>
     */
    protected function runRepoSeriesBatch(int $orgId, string $start, string $end): array
    {
        return RecoveryTotalsBatchQuery::handle([
            'org_id' => $orgId,
            'start' => $start,
            'end' => $end,
        ]);
    }

    /**
     * The today/last_7d headline, batched the same way as the series leg: a class-neutral
     * cache read/write so a second card resolving the same org+window+date reuses this scan.
     */
    protected function getRangeTotal(string $start, string $end): int
    {
        $orgId = auth()->user()->org_id;
        $timezone = auth()->user()->timezone ?? 'UTC';

        return (int) cache()->remember(
            RecoveryTotalsBatchQuery::sharedWindowCacheKey($orgId, $this->date, Carbon::parse($end)->toDateString(), $timezone),
            $this->getCacheTTL(),
            fn (): int => $this->runRepoWindowQuery($orgId, $start, $end)
        );
    }

    /**
     * The headline's window round trip, symmetric with runRepoSeriesBatch().
     */
    protected function runRepoWindowQuery(int $orgId, string $start, string $end): int
    {
        return RecoveryTotalsBatchQuery::handleRange([
            'org_id' => $orgId,
            'start' => Carbon::parse($start)->toDateString(),
            'end' => Carbon::parse($end)->toDateString(),
        ]);
    }

    /**
     * Both real read paths are overridden above (getMonthlyData(), getRangeTotal()), so this
     * base-class seam is never invoked. Mirrors UniqueScanCounts' 0-stub.
     */
    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return 0;
    }

    public function getCardQueryStatements(): array
    {
        $orgId = auth()->user()->org_id;

        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            [$start, $end] = $this->resolveCardRange();

            return [new CardQueryStatement(
                label: 'Headline total',
                sql: RecoveryTotalsBatchQuery::handleRangeSql([
                    'org_id' => $orgId,
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ]),
                queryClass: RecoveryTotalsBatchQuery::class,
            )];
        }

        $through = now(self::SERIES_ZONE);
        $offset = $this->date === 'last_month' ? 1 : 0;
        $monthKey = $through->clone()->startOfMonth()->subMonths($offset)->format('Y-m');

        $cold = self::coldSeriesMonths($through);
        [$spanStart, $spanEnd] = self::spanOf($cold);

        return [new CardQueryStatement(
            label: 'Headline total',
            sql: RecoveryTotalsBatchQuery::handleSql([
                'org_id' => $orgId,
                'start' => $spanStart->toDateString(),
                'end' => $spanEnd->toDateString(),
            ]),
            note: "Bucketed by month -- read the `{$monthKey}` row. This is the cold-cache batch; "
                .'a warm cache narrows the span to whichever months are uncached.',
            queryClass: RecoveryTotalsBatchQuery::class,
        )];
    }

    /**
     * @return array<string, CarbonInterface>
     */
    private static function coldSeriesMonths(CarbonInterface $through): array
    {
        $months = [];

        for ($i = 12; $i >= 0; $i--) {
            $month = $through->clone()->startOfMonth()->subMonths($i);
            $months[$month->format('Y-m')] = $month;
        }

        return $months;
    }

    /**
     * @param  array<string, CarbonInterface>  $months
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private static function spanOf(array $months): array
    {
        $monthKeys = \array_keys($months);
        \sort($monthKeys);

        return [
            $months[$monthKeys[0]]->clone()->startOfMonth(),
            $months[$monthKeys[\count($monthKeys) - 1]]->clone()->endOfMonth(),
        ];
    }
}
