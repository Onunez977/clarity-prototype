<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models\ClientHistoricalHits;
use App\Clickhouse\Models\HotlistStatusAggregate;
use App\Clickhouse\Models\HotlistStatusSource;
use App\Filament\Widgets\Client\Concerns\ClientClickhouseChartWidget;
use Illuminate\Support\Facades\DB;

class HistoricalCoverageBreakdownChart extends ClientClickhouseChartWidget
{
    protected ?string $heading = 'Historical LPR Data Coverage';

    protected function getDatasetConfigs(): array
    {
        $branchId = $this->branch_id;
        $assignmentsTimeColumn = HotlistStatusSource::usesTransitions($branchId) ? 'created_at' : 'status_hour';
        $assignmentsMonthGroupBy = HotlistStatusAggregate::getMonthGroupBySql($assignmentsTimeColumn);
        $assignmentsMonthSelect = HotlistStatusAggregate::getMonthSelectExpression($assignmentsTimeColumn);
        $assignmentsTotalExpr = HotlistStatusSource::usesTransitions($branchId)
            ? 'uniqExact(hotlist_id) as value'
            : 'uniqMerge(unique_hotlists) as value';

        $historicalMonthGroupBy = ClientHistoricalHits::getMonthGroupBySql('hour_timestamp');
        $historicalMonthSelect = ClientHistoricalHits::getMonthSelectExpression('hour_timestamp');

        return [
            [
                'label' => 'Total Assignments',
                'color' => 'rgba(34, 197, 94, 0.7)',
                'query' => fn () => HotlistStatusSource::query($branchId)
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($branchId)
                    ->forClient($this->client_org_id)
                    ->accepted()
                    ->groupBy(DB::raw($assignmentsMonthGroupBy))
                    ->selectRaw($assignmentsMonthSelect)
                    ->selectRaw($assignmentsTotalExpr)
                    ->orderBy('month')
                    ->pluck('value', 'month'),
            ],
            [
                'label' => 'Historical LPR Data',
                'color' => 'rgba(139, 92, 246, 0.7)',
                'query' => fn () => ClientHistoricalHits::query()
                    ->forOrg($this->org_id ?? auth()->user()->org_id)
                    ->between($this->getStartDate(), $this->getEndDate())
                    ->forBranch($this->branch_id)
                    ->forClient($this->client_org_id)
                    ->groupBy(DB::raw($historicalMonthGroupBy))
                    ->selectRaw($historicalMonthSelect)
                    ->selectRaw('uniqExactMerge(historical_hit_count) as value')
                    ->orderBy('month')
                    ->pluck('value', 'month'),
            ],
        ];
    }
}
