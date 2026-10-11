<?php

namespace App\Filament\Widgets\Client\Concerns;

use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

abstract class ClientChartWidget extends ChartWidget
{
    use ClientStatHelper;

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public array $jobsDispatched = [];

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

    abstract protected function getDatasetConfigs(): array;

    protected function getMonthlyData(string $jobClass): array
    {
        $data = [];
        $timezone = auth()->user()->timezone ?? 'America/Chicago';

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: $timezone)->startOfMonth()->subMonths($i);

            if ($i === 0) {
                $cacheKey = $this->buildCacheKey(
                    dateType: 'this_month',
                    orgId: $this->org_id ?? auth()->user()->org_id,
                    jobClass: $jobClass,
                    branchId: $this->branch_id,
                    clientOrgId: $this->client_org_id
                );

                $cachedValue = cache()->get($cacheKey);

                if ($cachedValue !== null) {
                    unset($this->jobsDispatched[$cacheKey]);
                    $data[] = $cachedValue;

                    continue;
                }
            }

            $startDate = $targetMonth->clone()->startOfDay()->toDateTimeString();
            $endDate = $targetMonth->clone()->endOfMonth()->endOfDay()->toDateTimeString();

            $cacheKey = $this->buildCacheKey(
                dateType: 'custom',
                orgId: $this->org_id ?? auth()->user()->org_id,
                jobClass: $jobClass,
                startDate: $startDate,
                endDate: $endDate,
                branchId: $this->branch_id,
                clientOrgId: $this->client_org_id
            );

            $cachedValue = cache()->get($cacheKey);

            if ($cachedValue === null) {
                if (! isset($this->jobsDispatched[$cacheKey])) {
                    $jobClass::dispatch(
                        orgId: $this->org_id ?? auth()->user()->org_id,
                        dateType: 'custom',
                        branchId: $this->branch_id,
                        dates: [
                            'start' => $startDate,
                            'end' => $endDate,
                        ],
                        clientOrgId: $this->client_org_id
                    );

                    $this->jobsDispatched[$cacheKey] = true;
                }

                $data[] = 0;
            } else {
                unset($this->jobsDispatched[$cacheKey]);
                $data[] = $cachedValue;
            }
        }

        return $data;
    }

    protected function getData(): array
    {
        $datasets = [];
        $allCachesFilled = true;

        foreach ($this->getDatasetConfigs() as $config) {
            $monthlyData = $this->getMonthlyData($config['jobClass']);

            if (\in_array(0, $monthlyData, true)) {
                $allCachesFilled = false;
            }

            $datasets[] = $this->makeDataset(
                label: $config['label'],
                data: $monthlyData,
                stack: static::class,
                color: $config['color'] ?? null,
            );
        }

        if (! $allCachesFilled && ! empty($this->jobsDispatched)) {
            $this->pollingInterval = '3s';
        } else {
            $this->pollingInterval = null;
            $this->jobsDispatched = [];
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

        for ($i = 12; $i >= 0; $i--) {
            $targetMonth = now(tz: auth()->user()->timezone)->startOfMonth()->subMonths($i);
            $labels[] = $targetMonth->format('M Y');
        }

        return $labels;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): string|Htmlable|null
    {
        if (count($this->jobsDispatched) > 0) {
            return new HtmlString('
                <div class="flex items-center gap-2">
                    <div class="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
                </div>
            ');
        }

        return '';
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
