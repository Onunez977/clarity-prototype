<?php

namespace App\Filament\Widgets\Client\Concerns;

use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

abstract class ClientClickhouseChartWidget extends ChartWidget
{
    use ClientStatHelper;

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    public ?int $org_id = null;

    public ?int $branch_id = null;

    public ?int $client_org_id = null;

    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?string $date_type = 'this_month';

    protected ?array $options = [
        'plugins' => [
            'tooltip' => [
                'enabled' => true,
                'backgroundColor' => 'rgba(0, 0, 0, 0.8)',
                'titleColor' => '#ffffff',
                'bodyColor' => '#ffffff',
                'borderWidth' => 1,
                'cornerRadius' => 6,
                'padding' => 8,
                'displayColors' => true,
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
                'stacked' => true,
                'grid' => [
                    'color' => 'rgba(0, 0, 0, 0.1)',
                ],
                'ticks' => [
                    'maxTicksLimit' => 8,
                ],
            ],
            'x' => [
                'stacked' => true,
                'grid' => [
                    'display' => false,
                ],
            ],
        ],
        'responsive' => true,
        'maintainAspectRatio' => false,
    ];

    /**
     * Define datasets to display on the chart
     * Each dataset should have:
     * - label: Display name
     * - query: Closure that returns a Collection keyed by month
     * - color: Optional color for the dataset
     */
    abstract protected function getDatasetConfigs(): array;

    /**
     * Get monthly data by executing closures for each dataset
     * Each closure should return a Collection keyed by month (Y-m-d format)
     */
    protected function getMonthlyData(): array
    {
        $datasets = [];

        foreach ($this->getDatasetConfigs() as $config) {
            $data = cache()->remember(
                $this->getCacheKey($config['label']),
                $this->getCacheTTL(),
                fn () => $config['query']()
            );

            $datasets[] = [
                'label' => $config['label'],
                'data' => $this->normalizeMonthlyData($data),
                'backgroundColor' => $config['color'],
            ];
        }

        return $datasets;
    }

    /**
     * Normalize monthly data to ensure 12 data points
     */
    protected function normalizeMonthlyData(Collection $data): array
    {
        $normalized = [];
        $current = $this->getStartDate()->copy()->startOfMonth();
        $end = $this->getEndDate()->copy()->startOfMonth();

        while ($current <= $end) {
            $key = $current->format('Y-m-d');
            $normalized[] = $data[$key] ?? 0;
            $current->addMonth();
        }

        return $normalized;
    }

    protected function getStartDate(): Carbon
    {
        $timezone = $this->getTimezone();

        return now($timezone)->startOfMonth()->subMonths(12);
    }

    protected function getEndDate(): Carbon
    {
        $timezone = $this->getTimezone();

        return now($timezone)->endOfMonth();
    }

    protected function getTimezone(): string
    {
        return auth()->user()?->timezone ?? 'UTC';
    }

    protected function getCacheKey(string $label): string
    {
        $orgId = $this->org_id ?? auth()->user()->org_id;
        $class = \class_basename(static::class);
        $labelSlug = \str_replace(' ', '_', \strtolower($label));

        return "{$class}:{$labelSlug}:org={$orgId}:branch={$this->branch_id}:client={$this->client_org_id}";
    }

    protected function getData(): array
    {
        $datasets = [];

        foreach ($this->getMonthlyData() as $datasetData) {
            $datasets[] = $this->makeDataset(
                label: $datasetData['label'],
                data: $datasetData['data'],
                stack: static::class,
                color: $datasetData['backgroundColor'] ?? null,
            );
        }

        return [
            'datasets' => $datasets,
            'labels' => $this->getGraphLabels(),
        ];
    }

    protected function makeDataset(string $label, array $data, ?string $stack = null, ?string $color = null): array
    {
        return \array_merge([
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $color ?? 'rgba(156, 163, 175, 0.3)',
            'borderColor' => $color ?? 'rgba(156, 163, 175, 0.5)',
            'hoverBackgroundColor' => $color ?? 'rgba(156, 163, 175, 0.6)',
            'hoverBorderColor' => 'rgba(156, 163, 175, 0.8)',
            'borderWidth' => 1,
            'hoverBorderWidth' => 2,
        ], $stack ? ['stack' => $stack] : []);
    }

    public function getGraphLabels(): array
    {
        $labels = [];
        $current = $this->getStartDate()->copy()->startOfMonth();
        $end = $this->getEndDate()->copy()->startOfMonth();

        while ($current <= $end) {
            $labels[] = $current->format('M Y');
            $current->addMonth();
        }

        return $labels;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    #[On('refresh-dashboard')]
    public function refreshData(int|string|null $branch_id = null, ?string $start_date = null, ?string $end_date = null, ?string $date_type = null, int|string|null $client_org_id = null): void
    {
        $this->branch_id = ($branch_id !== null && $branch_id !== '' && is_numeric($branch_id))
            ? (int) $branch_id
            : null;

        $this->client_org_id = ($client_org_id !== null && $client_org_id !== '' && is_numeric($client_org_id))
            ? (int) $client_org_id
            : null;

        $this->start_date = $start_date;
        $this->end_date = $end_date;
        $this->date_type = $date_type ?? 'this_month';
    }
}
