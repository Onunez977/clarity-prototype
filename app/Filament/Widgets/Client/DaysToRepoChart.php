<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models\DaysToRepoAggregate;
use App\Filament\Widgets\Client\Concerns\ClientClickhouseChartWidget;
use Illuminate\Support\Facades\DB;

class DaysToRepoChart extends ClientClickhouseChartWidget
{
    protected ?string $heading = 'Days to Repo Trend';

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
                'mode' => 'index',
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

    protected function getDatasetConfigs(): array
    {
        $monthGroupBy = DaysToRepoAggregate::getMonthGroupBySql('hour_timestamp');
        $monthSelect = DaysToRepoAggregate::getMonthSelectExpression('hour_timestamp');

        return [
            [
                'label' => 'Voluntary',
                'color' => '#10B981',
                'query' => fn () => DaysToRepoAggregate::query()
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->groupBy(DB::raw($monthGroupBy))
                    ->selectRaw($monthSelect)
                    ->selectRaw('SUM(sum_days_to_repo_voluntary) / nullif(SUM(count_repos_voluntary), 0) as value')
                    ->orderBy('month')
                    ->pluck('value', 'month'),
            ],
            [
                'label' => 'Involuntary',
                'color' => '#F59E0B',
                'query' => fn () => DaysToRepoAggregate::query()
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->groupBy(DB::raw($monthGroupBy))
                    ->selectRaw($monthSelect)
                    ->selectRaw('SUM(sum_days_to_repo_involuntary) / nullif(SUM(count_repos_involuntary), 0) as value')
                    ->orderBy('month')
                    ->pluck('value', 'month'),
            ],
            [
                'label' => 'Investigate',
                'color' => '#8B5CF6',
                'query' => fn () => DaysToRepoAggregate::query()
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->groupBy(DB::raw($monthGroupBy))
                    ->selectRaw($monthSelect)
                    ->selectRaw('SUM(sum_days_to_repo_investigate) / nullif(SUM(count_repos_investigate), 0) as value')
                    ->orderBy('month')
                    ->pluck('value', 'month'),
            ],
            [
                'label' => 'Impound',
                'color' => '#EF4444',
                'query' => fn () => DaysToRepoAggregate::query()
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->groupBy(DB::raw($monthGroupBy))
                    ->selectRaw($monthSelect)
                    ->selectRaw('SUM(sum_days_to_repo_impound) / nullif(SUM(count_repos_impound), 0) as value')
                    ->orderBy('month')
                    ->pluck('value', 'month'),
            ],
            [
                'label' => 'LPR Staging',
                'color' => '#6366F1',
                'query' => fn () => DaysToRepoAggregate::query()
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->groupBy(DB::raw($monthGroupBy))
                    ->selectRaw($monthSelect)
                    ->selectRaw('SUM(sum_days_to_repo_lpr_staging) / nullif(SUM(count_repos_lpr_staging), 0) as value')
                    ->orderBy('month')
                    ->pluck('value', 'month'),
            ],
            [
                'label' => 'Unknown',
                'color' => '#6B7280',
                'query' => fn () => DaysToRepoAggregate::query()
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->groupBy(DB::raw($monthGroupBy))
                    ->selectRaw($monthSelect)
                    ->selectRaw('SUM(sum_days_to_repo_unknown) / nullif(SUM(count_repos_unknown), 0) as value')
                    ->orderBy('month')
                    ->pluck('value', 'month'),
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function makeDataset(string $label, array $data, ?string $stack = null, ?string $color = null): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $color ?? 'rgba(156, 163, 175, 0.3)',
            'borderColor' => $color ?? 'rgba(156, 163, 175, 0.5)',
            'hoverBackgroundColor' => $color ?? 'rgba(156, 163, 175, 0.6)',
            'hoverBorderColor' => 'rgba(156, 163, 175, 0.8)',
            'pointBackgroundColor' => $color ?? 'rgba(156, 163, 175, 0.5)',
            'pointBorderColor' => $color ?? 'rgba(156, 163, 175, 0.5)',
            'borderWidth' => 2,
            'hoverBorderWidth' => 3,
            'pointRadius' => 4,
            'pointHoverRadius' => 6,
            'fill' => false,
            'tension' => 0.1,
        ];
    }
}
