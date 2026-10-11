<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Throwable;

abstract class AdminClickhouseChartWidget extends ChartWidget
{
    use StatHelper;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    public ?string $date = 'this_month';

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 2,
    ];

    #[Url]
    public mixed $filters;

    protected ?array $options = [
        'plugins' => [
            'legend' => [
                'display' => true,
                'position' => 'top',
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

    /**
     * Return the ClickHouse query class to use for data retrieval.
     */
    abstract protected function getQueryClass(): string;

    /**
     * Return the PostgreSQL job class for fallback (optional).
     */
    protected function getJobClass(): ?string
    {
        return null;
    }

    /**
     * Return the dataset label for the chart.
     */
    public function getDatasetLabel(): string
    {
        return 'Total';
    }

    /**
     * Return the RGB color for the chart bars.
     */
    abstract public function getRgbColor(): array;

    public function mount(): void
    {
        abort_unless(static::canView(), 403);

        $this->date = $this->filters['date_toggles'] ?? 'this_month';

        $this->options['plugins']['tooltip']['borderColor'] = 'rgba('.\implode(', ', $this->getRgbColor()).', 1)';
    }

    #[On('refresh-dashboard')]
    public function refreshData(string $date): void
    {
        $this->date = $date;
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
        $label = $this->getDatasetLabel();
        $clickhouseData = $this->getMonthlyData();
        $postgresData = $this->getPostgresMonthlyData();

        return [
            'datasets' => [
                $this->makeDataset($label.' (ClickHouse)', $clickhouseData),
                $this->makeDataset(
                    $label.' (PostgreSQL)',
                    $postgresData,
                    'rgba(100, 150, 200, 0.5)'
                ),
            ],
            'labels' => $this->getGraphLabels(),
        ];
    }

    public function getDescription(): string|Htmlable|null
    {
        $queryClass = $this->getQueryClass();
        $orgId = auth()->user()->org_id;
        $timezone = auth()->user()->timezone ?? 'UTC';

        if ($this->date === 'today') {
            $dates = [
                'start' => now(tz: $timezone)->startOfDay()->toDateString(),
                'end' => now(tz: $timezone)->endOfDay()->toDateString(),
            ];

            $fullCacheKey = $this->cacheKey(key: 'today', orgId: $orgId, class: $queryClass);
            $count = cache()->remember($fullCacheKey, now()->addMinutes(5), function () use ($queryClass, $orgId, $dates) {
                return $this->executeQuery($queryClass, $orgId, $dates);
            });

            return new HtmlString('Today: '.\number_format($count));
        }

        if ($this->date === 'last_7d') {
            $dates = [
                'start' => now(tz: $timezone)->subDays(7)->startOfDay()->toDateString(),
                'end' => now(tz: $timezone)->endOfDay()->toDateString(),
            ];

            $fullCacheKey = $this->cacheKey(key: 'last_7d', orgId: $orgId, class: $queryClass);
            $count = cache()->remember($fullCacheKey, now()->addMinutes(5), function () use ($queryClass, $orgId, $dates) {
                return $this->executeQuery($queryClass, $orgId, $dates);
            });

            return new HtmlString('Last 7 Days: '.\number_format($count));
        }

        $monthlyData = $this->getMonthlyData();

        return new HtmlString(
            'Current Month: '.\number_format($monthlyData[count($monthlyData) - 1] ?? 0).' | Last Month: '.\number_format($monthlyData[count($monthlyData) - 2] ?? 0)
        );
    }

    public function getGraphLabels(): array
    {
        $labels = [];

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: auth()->user()->timezone)->startOfMonth()->subMonths($i);
            $labels[] = $targetMonth->format('M \'y');
        }

        return $labels;
    }

    protected function getHighlightedMonthsFromFilter(): array
    {
        return match ($this->date) {
            'last_month' => [now(tz: auth()->user()->timezone)->startOfMonth()->subMonth()->format('M \'y')],
            default => [now(tz: auth()->user()->timezone)->format('M \'y')],
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

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Get monthly data using ClickHouse query with tiered caching.
     * - This month: 5 minute TTL (actively changing)
     * - Older months: 1 year TTL (finalized data)
     */
    protected function getMonthlyData(bool $formatted = true): array
    {
        $data = [];
        $queryClass = $this->getQueryClass();
        $orgId = auth()->user()->org_id;
        $timezone = auth()->user()->timezone ?? 'UTC';

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);
            $dates = [
                'start' => $targetMonth->clone()->startOfDay()->toDateString(),
                'end' => $targetMonth->clone()->endOfMonth()->endOfDay()->toDateString(),
            ];

            if ($i === 0) {
                $cacheKey = 'this_month';
                $cacheTTL = now()->addMinutes(10);
            } else {
                $cacheKey = $targetMonth->format('Y-m');
                $cacheTTL = now()->addYear();
            }

            $fullCacheKey = $this->cacheKey(
                key: $cacheKey,
                orgId: $orgId,
                class: $queryClass,
            );

            $value = cache()->remember($fullCacheKey, $cacheTTL, function () use ($queryClass, $orgId, $dates) {
                return $this->executeQuery($queryClass, $orgId, $dates);
            });

            $data[] = $value ?? 0;
        }

        if ($formatted && \method_exists($this, 'formatMonthlyData')) {
            $data = $this->formatMonthlyData($data);
        }

        return $data;
    }

    /**
     * Get monthly data from PostgreSQL job cache for comparison.
     */
    protected function getPostgresMonthlyData(): array
    {
        $data = [];
        $jobClass = $this->getJobClass();

        if (! $jobClass) {
            return \array_fill(0, 13, 0);
        }

        $orgId = auth()->user()->org_id;
        $timezone = auth()->user()->timezone ?? 'UTC';

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);

            if ($i === 0) {
                $cacheKey = 'this_month';
            } elseif ($i === 1) {
                $cacheKey = 'last_month';
            } else {
                $cacheKey = $targetMonth->format('Y-m');
            }

            $fullCacheKey = $this->cacheKey(
                key: $cacheKey,
                orgId: $orgId,
                class: $jobClass,
            );

            $data[] = cache()->get($fullCacheKey, 0);
        }

        return $data;
    }

    /**
     * Execute ClickHouse query without fallback.
     */
    protected function executeQuery(string $queryClass, int $orgId, array $dates): mixed
    {
        try {
            return $queryClass::query([
                'org_id' => $orgId,
                'start' => $dates['start'],
                'end' => $dates['end'],
            ], cache: false);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
