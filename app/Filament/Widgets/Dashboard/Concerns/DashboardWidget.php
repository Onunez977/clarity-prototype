<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

abstract class DashboardWidget extends ChartWidget implements DashboardMetricCard
{
    use BuildsDateFilterChartSeries;
    use RendersDashboardStat;
    use ResolvesDashboardCacheTtl;
    use StatHelper;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public ?string $date = 'this_month';

    public bool $expanded = false;

    /**
     * @var array<string, array>
     */
    protected array $monthlyDataMemo = [];

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 1,
    ];

    #[Url]
    public mixed $filters;

    protected ?array $options = [
        'plugins' => [
            'legend' => [
                'display' => false,
            ],
            'tooltip' => [
                'enabled' => true,
                'backgroundColor' => 'rgba(0, 0, 0, 0.8)',
                'titleColor' => '#ffffff',
                'bodyColor' => '#ffffff',
                'borderWidth' => 1,
                'cornerRadius' => 6,
                'padding' => 8,
                'displayColors' => false,
                'position' => 'nearest',
                'yAlign' => 'top',
                'xAlign' => 'center',
            ],
        ],
        'interaction' => [
            'mode' => 'index',
            'intersect' => false,
        ],
        'hover' => [
            'mode' => 'index',
            'intersect' => false,
            'animationDuration' => 200,
        ],
        'animation' => [
            'duration' => 750,
        ],
        'scales' => [
            'y' => [
                'beginAtZero' => true,
                'grid' => [
                    'color' => 'rgba(0, 0, 0, 0.1)',
                ],
                'ticks' => [
                    'maxTicksLimit' => 8,
                ],
            ],
            'x' => [
                'grid' => [
                    'display' => false,
                ],
            ],
        ],
        'responsive' => true,
        'maintainAspectRatio' => false,
    ];

    public function mount(): void
    {
        abort_unless(static::canView(), 403);

        $this->date = $this->filters['date_toggles'] ?? 'this_month';

        $this->options['plugins']['tooltip']['borderColor'] = 'rgba('.\implode(', ', $this->getRgbColor()).', 1)';
    }

    protected function getMaxHeight(): ?string
    {
        return $this->expanded ? '600px' : '12rem';
    }

    #[On('refresh-dashboard')]
    public function refreshData(string $date): void
    {
        $this->date = $date;
        $this->monthlyDataMemo = [];
    }

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(RoleEnum::ADMIN->value, RoleEnum::USER->value);
    }

    protected function getColumns(): int
    {
        return 1;
    }

    public function getData(): array
    {
        $label = 'Total';

        if (\method_exists($this, 'getDatasetLabel')) {
            $label = $this->getDatasetLabel();
        }

        return [
            'datasets' => [
                $this->makeDataset($label, $this->getMonthlyData()),
            ],
            'labels' => $this->getGraphLabels(),
        ];
    }

    public function getDescription(): string|Htmlable|null
    {
        if ($this->date === 'today') {
            return new HtmlString('Today: '.\number_format($this->currentPeriodTotal()));
        }

        if ($this->date === 'last_7d') {
            return new HtmlString('Last 7 Days: '.\number_format($this->currentPeriodTotal()));
        }

        $monthlyData = $this->getMonthlyData();

        return new HtmlString(
            'Current Month: '.\number_format($monthlyData[count($monthlyData) - 1] ?? 0).' | Last Month: '.\number_format($monthlyData[count($monthlyData) - 2] ?? 0)
        );
    }

    public function getGraphLabels(): array
    {
        $labels = [];

        $timezone = $this->viewerTimezone();

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $labels[] = $targetMonth->format('M \'y');
        }

        return $labels;
    }

    protected function getHighlightedMonthsFromFilter(): array
    {
        $timezone = $this->viewerTimezone();

        return match ($this->date) {
            'last_month' => [now(tz: $timezone)->startOfMonth()->subMonth()->format('M \'y')],
            default => [now(tz: $timezone)->format('M \'y')],
        };
    }

    protected function generateColors(string $fallback): array
    {
        $colors = [];

        foreach ($this->getGraphLabels() as $label) {
            $colors[] = \in_array($label, $this->getHighlightedMonthsFromFilter())
                ? 'rgba('.\implode(', ', $this->getRgbColor()).', 0.8)'
                : $fallback;
        }

        return $colors;
    }

    protected function makeDataset(string $label, array $data, ?string $backgroundColor = null, ?string $stack = null): array
    {
        return \array_merge([
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $backgroundColor ?? $this->generateColors('rgba(156, 163, 175, 0.3)'),
            'borderColor' => $this->generateColors('rgba(156, 163, 175, 0.5)'),
            'hoverBackgroundColor' => $backgroundColor ?? $this->generateColors('rgba(156, 163, 175, 0.6)'),
            'hoverBorderColor' => $this->generateColors('rgba(156, 163, 175, 0.8)'),
            'borderWidth' => 1,
            'hoverBorderWidth' => 2,
        ], $stack ? ['stack' => $stack] : []);
    }

    /**
     * makeDataset() paints every colour key with generateColors(), a PER-MONTH array that
     * highlights the current month. Chart.js builds a legend swatch from index 0 of that
     * array -- the LEFTMOST month, which is never the highlighted one -- so on any charted
     * series the legend shows grey while the bar that carries data shows the accent. Right
     * for a single-series chart with no legend; wrong the moment a second series turns one on.
     */
    protected function staticColourDataset(string $label, array $data, string $fill, string $border): array
    {
        return \array_merge($this->makeDataset($label, $data), [
            'backgroundColor' => $fill,
            'hoverBackgroundColor' => $fill,
            'borderColor' => $border,
            'hoverBorderColor' => $border,
        ]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getMonthlyData(bool $formatted = true): array
    {
        $memoKey = $formatted ? 'formatted' : 'raw';

        if (isset($this->monthlyDataMemo[$memoKey])) {
            return $this->monthlyDataMemo[$memoKey];
        }

        $data = [];
        $orgId = auth()->user()->org_id;
        $timezone = $this->viewerTimezone();

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $startDate = $targetMonth->clone()->startOfDay()->toDateTimeString();
            $endDate = $targetMonth->clone()->endOfMonth()->endOfDay()->toDateTimeString();
            $dateType = $i === 0 ? 'this_month' : $targetMonth->format('Y-m');
            $cacheTTL = $this->getCacheTTL($i, $targetMonth->clone()->endOfMonth());

            $data[] = cache()->remember(
                $this->buildWidgetCacheKey($dateType, $orgId),
                $cacheTTL,
                fn () => $this->getModelQueryForDates($startDate, $endDate)
            ) ?? 0;
        }

        if ($formatted && \method_exists($this, 'formatMonthlyData')) {
            $data = $this->formatMonthlyData($data);
        }

        return $this->monthlyDataMemo[$memoKey] = $data;
    }

    /**
     * The [start, end] the headline value covers for $this->date -- the inverse of
     * getCardTotal()'s routing. Duplicates that method's month arithmetic; the card-query
     * fidelity test is what keeps the two agreeing.
     *
     * @return array{0: string, 1: string}
     */
    protected function headlineWindow(): array
    {
        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            [$start, $end] = $this->resolveCardRange();

            return [$start->toDateTimeString(), $end->toDateTimeString()];
        }

        $month = now(tz: $this->viewerTimezone())->startOfMonth();

        if ($this->date === 'last_month') {
            $month = $month->subMonth();
        }

        return [
            $month->clone()->startOfDay()->toDateTimeString(),
            $month->clone()->endOfMonth()->endOfDay()->toDateTimeString(),
        ];
    }

    protected function buildWidgetCacheKey(string $dateType, int $orgId): string
    {
        $class = Str::of(\class_basename(static::class))
            ->snake('-')
            ->toString();

        return $class.':'.$dateType.':org='.$orgId;
    }

    /**
     * The compact-card identifier for this widget, e.g. 'scan-count', 'live-hits'.
     *
     * CANONICAL NOTE for every getCardKey() in this namespace: the key registry is
     * CompanyMetricsOverview::cards() (the dashboard rebuild), NOT Dashboard::cardWidgets() --
     * since the Company Metrics foundation that returns the entitlement-filtered expandableCards(), which omits
     * a locked card. Other implementations point here rather than restating this.
     */
    public function getCardKey(): string
    {
        return Str::of(\class_basename(static::class))
            ->snake('-')
            ->toString();
    }

    /**
     * Filament Stat for the Company Metrics grid: headline total + Stat::chart()
     * sparkline from the existing 13-month getMonthlyData() series.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(
            Stat::make($this->getCardLabel(), \number_format($this->getCardTotal()))
                ->chart($this->chartSeries())
                ->color($this->getStatColor()),
            $selectedCard,
        );
    }

    /**
     * Named Filament color for Stat::color() / the sparkline. Mapped from the existing
     * getRgbColor() values so the Company Metrics cards stay color-coordinated.
     *
     * Every card that reaches this method has an explicit arm, so `default => 'gray'` is
     * currently unreachable; it exists for future mockup cards. Note it does NOT explain
     * RecoveryLeader's colour: that class extends ChartWidget directly, never inherits this
     * method, and hardcodes ->color('info') in its own toStat().
     */
    public function getStatColor(): string
    {
        return match ($this->getCardKey()) {
            'unique-scan-counts' => 'success',
            'recovery-counts' => 'info',
            'unique-lpr-case-claims' => 'success',
            'missed-opportunities' => 'primary',
            'live-hits', 'historical-hits' => 'danger',
            'staging-live-hits', 'staging-historical-hits' => 'warning',
            'lpr-repossessions' => 'info',
            default => 'gray',
        };
    }

    /**
     * The compact card's headline total for $this->date. 'this_month'/'last_month' are
     * free (already-cached getMonthlyData() buckets); 'today'/'last_7d' cache under the
     * same keys getDescription() already uses, so no duplicate query is introduced.
     */
    public function getCardTotal(): int
    {
        if (\in_array($this->date, ['today', 'last_7d'], true)) {
            return $this->currentPeriodTotal();
        }

        $monthlyData = $this->getMonthlyData(formatted: false);

        return (int) ($this->date === 'last_month'
            ? ($monthlyData[\count($monthlyData) - 2] ?? 0)
            : ($monthlyData[\count($monthlyData) - 1] ?? 0));
    }

    /**
     * The ONE computation of the current period's total for 'today'/'last_7d', shared by the
     * compact card headline and getDescription().
     *
     * This is deliberately not a second query path. This number already existed and was
     * already cached under this exact key by getDescription() before the dashboard rebuild; the card
     * computing it separately is what let a swallowed failure in one path write a fabricated
     * value into the other's key, and what let ScanCount's two paths read different tables.
     * One method, one cache key, two consumers.
     */
    protected function currentPeriodTotal(): int
    {
        [$start, $end] = $this->resolveCardRange();

        $ttl = $this->getCacheTTL();

        if ($this->date === 'last_month') {
            $monthEndedAt = now(tz: $this->viewerTimezone())->startOfMonth()->subMonth()->endOfMonth();
            $ttl = $this->getCacheTTL(1, $monthEndedAt);
        }

        return (int) (cache()->remember(
            $this->buildWidgetCacheKey($this->date, auth()->user()->org_id),
            $ttl,
            fn () => $this->getRangeTotal($start->toDateTimeString(), $end->toDateTimeString())
        ) ?? 0);
    }

    /**
     * The single seam the bounded 'today'/'last_7d' read goes through. Defaults to the
     * widget's own getModelQueryForDates(); SharedHitStatsData overrides it to resolve
     * through the batched hit_stats_daily query instead of its getModelQueryForDates()
     * 0-stub.
     *
     * Deliberately NO try/catch. The pre-existing base-class path had none, and adding one
     * inside currentPeriodTotal()'s cache()->remember() would persist a fabricated 0 under
     * the key getDescription() also reads — turning a transient ClickHouse blip into a
     * confident wrong number for the whole TTL, indistinguishable from a real zero. A
     * failure propagates exactly as it did before the dashboard rebuild.
     */
    protected function getRangeTotal(string $start, string $end): int
    {
        return (int) $this->getModelQueryForDates($start, $end);
    }

    /**
     * Normalizes the existing 13-month getMonthlyData() series (zero new queries) into an
     * SVG polyline point string over a `0 0 100 24` viewBox, matching Backyard's
     * InsightPulse sparkline idiom. Returns '' for a flat (no variation) or empty series,
     * so the Blade partial omits the <svg> rather than drawing an uninformative flat line.
     */
    public function getSparklinePoints(): string
    {
        $series = \array_values($this->getMonthlyData(formatted: false));

        if (\count($series) < 2) {
            return '';
        }

        $min = \min($series);
        $max = \max($series);

        if ($min === $max) {
            return '';
        }

        $range = $max - $min;
        $count = \count($series);
        $points = [];

        foreach ($series as $i => $value) {
            $x = \round(($i / ($count - 1)) * 100, 2);
            $y = \round(23 - (($value - $min) / $range) * 22, 2);
            $points[] = "{$x},{$y}";
        }

        return \implode(' ', $points);
    }

    abstract protected function getModelQueryForDates(string $startDate, string $endDate): mixed;

    abstract public function getRgbColor(): array;
}
