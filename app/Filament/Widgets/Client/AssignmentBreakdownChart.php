<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models\HotlistStatusSource;
use App\Filament\Widgets\Client\Concerns\ClientClickhouseChartWidget;

class AssignmentBreakdownChart extends ClientClickhouseChartWidget
{
    protected ?string $heading = 'Assignment Status Changes';

    protected ?string $description = 'End of month assignment status snapshot.';

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

    protected function getDatasetConfigs(): array
    {
        return [
            [
                'label' => 'Open',
                'color' => 'rgba(34, 197, 94, 0.7)',   // Green
                'query' => fn () => HotlistStatusSource::query($this->branch_id)
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->acceptedOpen()
                    ->byMonth()
                    ->pluck('total', 'month'),
            ],
            [
                'label' => 'Repossessed',
                'color' => 'rgba(127, 172, 255, 0.7)', // Blue
                'query' => fn () => HotlistStatusSource::query($this->branch_id)
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->acceptedRepos()
                    ->byMonth()
                    ->pluck('total', 'month'),
            ],
            [
                'label' => 'Closed',
                'color' => 'rgba(168, 85, 247, 0.7)',  // Purple
                'query' => fn () => HotlistStatusSource::query($this->branch_id)
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->acceptedClosed()
                    ->byMonth()
                    ->pluck('total', 'month'),
            ],
            [
                'label' => 'Hold',
                'color' => 'rgba(251, 191, 36, 0.7)',  // Amber
                'query' => fn () => HotlistStatusSource::query($this->branch_id)
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->acceptedHold()
                    ->byMonth()
                    ->pluck('total', 'month'),
            ],
        ];
    }

    protected function getData(): array
    {
        $datasets = [];
        $monthlyData = $this->getMonthlyData();
        $dataLength = ! empty($monthlyData) ? count($monthlyData[0]['data']) : 13;
        $monthlyTotals = array_fill(0, $dataLength, 0);

        // Build datasets and compute running totals
        foreach ($monthlyData as $datasetData) {
            // Sum for totals
            foreach ($datasetData['data'] as $i => $value) {
                $monthlyTotals[$i] += $value;
            }

            $datasets[] = [
                'label' => $datasetData['label'],
                'data' => $datasetData['data'],
                'backgroundColor' => $datasetData['backgroundColor'],
                'borderColor' => $datasetData['backgroundColor'],
                'hoverBackgroundColor' => $datasetData['backgroundColor'],
                'hoverBorderColor' => 'rgba(156, 163, 175, 0.8)',
                'borderWidth' => 1,
                'hoverBorderWidth' => 2,
                'order' => 1,
                'stack' => 'status',
                'barPercentage' => 0.9,
                'categoryPercentage' => 0.8,
            ];
        }

        array_unshift($datasets, [
            'label' => 'Total',
            'data' => $monthlyTotals,
            'type' => 'line',
            'borderColor' => 'rgba(0, 0, 0, 0)',
            'backgroundColor' => 'rgba(0, 0, 0, 0)',
            'pointRadius' => 0,
            'pointHoverRadius' => 0,
            'borderWidth' => 0,
            'showLine' => false,
            'order' => 0,
        ]);

        return [
            'datasets' => $datasets,
            'labels' => $this->getGraphLabels(),
        ];
    }
}
