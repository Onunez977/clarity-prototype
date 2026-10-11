<?php

namespace App\Filament\Pages\Concerns;

use App\Clickhouse\Models as Clickhouse;
use App\Clickhouse\Models\DaysToRepoAggregate;
use App\Clickhouse\Models\HotlistStatusSource;
use App\Clickhouse\Models\LprAssist;
use App\Clickhouse\Models\MissedOpportunity;
use App\Clickhouse\Queries\Client\ClientRankedMetricsBatch;
use App\Clickhouse\Queries\Client\CohortClosedQuery;
use App\Clickhouse\Queries\Client\CohortDeclinedQuery;
use App\Facades\Analytics;
use App\Filament\Widgets\Client\Concerns\ClientStatHelper;
use App\Filament\Widgets\Client\DaysToRepoWidget;
use App\Filament\Widgets\Client\LprAssistsWidget;
use App\Filament\Widgets\Client\MissedOpportunitiesWidget;
use App\Filament\Widgets\Client\TotalAcceptedWidget;
use App\Filament\Widgets\Client\TotalAssignmentsWidget;
use App\Filament\Widgets\Client\TotalClientAssignmentsWidget;
use App\Filament\Widgets\Client\TotalClosedWidget;
use App\Filament\Widgets\Client\TotalDeclinedWidget;
use App\Filament\Widgets\Client\TotalRepossessionsWidget;
use App\Models\Org;
use Carbon\Carbon;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;

trait HasStatDrillDown
{
    use ClientStatHelper;

    #[On('openDrillDown')]
    public function openDrillDown(string $stat): void
    {
        Analytics::track('drill-down', auth()->user(), [
            'action' => 'click',
            'stat' => $stat,
        ], page: $this->getAnalyticsPage());

        $this->mountAction($stat.'DrillDown');
    }

    abstract protected function getAnalyticsPage(): string;

    protected function getDrillDownDates(): array
    {
        $timezone = auth()->user()?->timezone ?? 'UTC';
        $dateType = $this->filters['date_toggles'] ?? $this->filters['date_type'] ?? 'this_month';

        if (! in_array($dateType, ['this_month', 'ytd'], true)
            && ! empty($this->filters['start']) && ! empty($this->filters['end'])) {
            return [
                'start' => Carbon::parse($this->filters['start'], $timezone)->toDateTimeString(),
                'end' => Carbon::parse($this->filters['end'], $timezone)->toDateTimeString(),
            ];
        }

        $dateRange = $this->getDateRangeForType($dateType, timezone: $timezone);

        return [
            'start' => $dateRange['start']->toDateTimeString(),
            'end' => $dateRange['end']->toDateTimeString(),
        ];
    }

    protected function getDrillDownOrgId(): int
    {
        return auth()->user()->org_id;
    }

    protected function getDrillDownBranchId(): ?int
    {
        $branchId = $this->filters['branch_id'] ?? null;

        return $branchId !== null && $branchId !== '' ? (int) $branchId : null;
    }

    protected function getDrillDownClientOrgId(): ?int
    {
        return property_exists($this, 'clientId') ? $this->clientId : null;
    }

    protected function getClientNames(): array
    {
        $org = Org::find($this->getDrillDownOrgId());

        return $org ? $org->getHotlistClients()->toArray() : [];
    }

    protected function fetchBatchMetrics(int $orgId, ?int $branchId, array $dates): array
    {
        $clientNames = $this->getClientNames();
        $clientOrgIds = array_keys($clientNames);

        $batchMetrics = ClientRankedMetricsBatch::query([
            'org_id' => $orgId,
            'client_org_ids' => $clientOrgIds,
            'branch_id' => $branchId,
            'start' => $dates['start'],
            'end' => $dates['end'],
            'timezone' => auth()->user()?->timezone ?? 'UTC',
            'cacheTTL' => now()->addMinutes(10),
        ], cache: true);

        return [$clientNames, is_array($batchMetrics) ? $batchMetrics : []];
    }

    protected function buildSimpleOrgDrillDown(
        array $dates, int $orgId, ?int $branchId,
        string $metricKey, string $columnLabel, string $summaryLabel,
        ?string $widgetClass = null
    ): array {
        [$clientNames, $batchMetrics] = $this->fetchBatchMetrics($orgId, $branchId, $dates);

        $perClientBreakdown = collect($batchMetrics)
            ->map(fn ($metrics, $clientId) => [
                'client_name' => $clientNames[$clientId] ?? "Client #{$clientId}",
                'value' => (int) ($metrics[$metricKey] ?? 0),
            ])
            ->filter(fn ($row) => $row['value'] > 0)
            ->sortByDesc('value')
            ->values()
            ->toArray();

        // Use widget total when available
        $total = $widgetClass
            ? (int) $this->resolveWidgetValue($widgetClass, $orgId, $dates['start'], $dates['end'], $branchId)
            : collect($perClientBreakdown)->sum('value');

        // Add "Unattributed" row if breakdown sum doesn't match total (e.g., client_org_id=0 records)
        $breakdownSum = collect($perClientBreakdown)->sum('value');
        if ($total > $breakdownSum) {
            $perClientBreakdown[] = [
                'client_name' => 'Unattributed',
                'value' => $total - $breakdownSum,
            ];
        }

        return [
            'dates' => $dates,
            'barChart' => [],
            'monthlyTrend' => [],
            'clientTable' => $perClientBreakdown,
            'clientTableColumns' => [
                'client_name' => 'Client',
                'value' => $columnLabel,
            ],
            'clientTableValueKey' => 'value',
            'summaryValue' => number_format($total),
            'summaryLabel' => $summaryLabel,
        ];
    }

    protected function makeDrillDownAction(string $name, string $heading, array $data, ?string $description = null): Action
    {
        return Action::make($name)
            ->slideOver()
            ->modalWidth('4xl')
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (): View => view('filament.pages.drill-downs.stat-breakdown', $data));
    }

    public function totalAssignmentsDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'totalAssignmentsDrillDown',
            'Total Assignments — Breakdown',
            $this->getTotalAssignmentsData(),
            'Total assignments received during the selected time range.',
        );
    }

    protected function getTotalAssignmentsData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();

        if ($clientOrgId) {
            $total = $this->resolveWidgetValue(
                TotalClientAssignmentsWidget::class, $orgId, $dates['start'], $dates['end'], $branchId, $clientOrgId
            );

            $monthlyTrend = $this->getAssignmentMonthlyTrend($orgId, $branchId, $clientOrgId, $dates['start'], $dates['end']);

            return [
                'dates' => $dates,
                'barChart' => [],
                'monthlyTrend' => $monthlyTrend,
                'monthlyTrendColor' => 'bg-success-500',
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => number_format((int) $total),
                'summaryLabel' => 'total assignments',
            ];
        }

        return $this->buildSimpleOrgDrillDown($dates, $orgId, $branchId, 'assignments', 'Assignments', 'total assignments', TotalAssignmentsWidget::class);
    }

    protected function resolveWidgetValue(string $widgetClass, int $orgId, string $start, string $end, ?int $branchId = null, ?int $clientOrgId = null): mixed
    {
        $dateType = $this->filters['date_toggles'] ?? $this->filters['date_type'] ?? 'this_month';
        $cacheKey = $this->buildCacheKey(
            dateType: $dateType,
            orgId: $orgId,
            jobClass: $widgetClass,
            startDate: $this->filters['start'] ?? null,
            endDate: $this->filters['end'] ?? null,
            branchId: $branchId,
            clientOrgId: $clientOrgId,
        );

        return cache()->remember(
            $cacheKey,
            $this->getCacheTTL(),
            function () use ($widgetClass, $orgId, $start, $end, $branchId, $clientOrgId) {
                $widget = new $widgetClass;
                $widget->org_id = $orgId;
                $widget->branch_id = $branchId;
                $widget->client_org_id = $clientOrgId;

                return $widget->getModelQueryForDates($start, $end);
            }
        );
    }

    protected function getAssignmentMonthlyTrend(int $orgId, ?int $branchId, int $clientOrgId, string $start, string $end): array
    {
        return Clickhouse\Hotlist::query()
            ->forOrg($orgId)
            ->between($start, $end, auth()->user()?->timezone ?? 'UTC')
            ->forBranch($branchId)
            ->forClient($clientOrgId)
            ->selectRaw('toDate(toStartOfMonth(created_at)) as month, count(*) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => Carbon::parse($row->month)->format('M Y'),
                'value' => (int) $row->total,
            ])
            ->toArray();
    }

    // ──────────────────────────────────────────────
    // Total Repossessions
    // ──────────────────────────────────────────────

    public function totalRepossessionsDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'totalRepossessionsDrillDown',
            'Total Repossessions — Breakdown',
            $this->getTotalRepossessionsData(),
            'Total recorded repossessions during the selected time range.',
        );
    }

    protected function getTotalRepossessionsData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();

        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($clientOrgId) {
            $monthlyTrend = HotlistStatusSource::query($branchId)
                ->forOrg($orgId)
                ->between($dates['start'], $dates['end'], $timezone)
                ->forBranch($branchId)
                ->forClient($clientOrgId)
                ->repossessions()
                ->byMonth()
                ->get()
                ->map(fn ($row) => [
                    'month' => Carbon::parse($row->month)->format('M Y'),
                    'value' => (int) $row->total,
                ])
                ->toArray();

            $total = collect($monthlyTrend)->sum('value');

            return [
                'dates' => $dates,
                'barChart' => [],
                'monthlyTrend' => $monthlyTrend,
                'monthlyTrendColor' => 'bg-primary-500',
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => number_format($total),
                'summaryLabel' => 'total repossessions',
            ];
        }

        return $this->buildSimpleOrgDrillDown($dates, $orgId, $branchId, 'repos', 'Repossessions', 'total repossessions', TotalRepossessionsWidget::class);
    }

    // ──────────────────────────────────────────────
    // LPR Assists
    // ──────────────────────────────────────────────

    public function lprAssistsDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'lprAssistsDrillDown',
            'LPR Assists — Breakdown',
            $this->getLprAssistsData(),
            'Repossessions where LPR scanning assisted in locating the vehicle within 0.1 of a mile for the selected time range.',
        );
    }

    protected function getLprAssistsData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();
        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($clientOrgId) {
            $monthlyData = LprAssist::byMonth($orgId, $dates['start'], $dates['end'], $timezone, $branchId, $clientOrgId);

            $monthlyTrend = collect($monthlyData)->map(fn ($total, $month) => [
                'month' => Carbon::parse($month)->format('M Y'),
                'value' => (int) $total,
            ])->values()->toArray();

            $total = collect($monthlyTrend)->sum('value');

            return [
                'dates' => $dates,
                'barChart' => [],
                'monthlyTrend' => $monthlyTrend,
                'monthlyTrendColor' => 'bg-primary-500',
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => number_format($total),
                'summaryLabel' => 'total LPR assists',
            ];
        }

        return $this->buildSimpleOrgDrillDown($dates, $orgId, $branchId, 'lpr_assists', 'LPR Assists', 'total LPR assists', LprAssistsWidget::class);
    }

    // ──────────────────────────────────────────────
    // Missed Opportunities
    // ──────────────────────────────────────────────

    public function missedOpportunitiesDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'missedOpportunitiesDrillDown',
            'Missed Opportunities — Breakdown',
            $this->getMissedOpportunitiesData(),
            'Vehicles scanned but not recovered before assignment ended for the selected time range.',
        );
    }

    protected function getMissedOpportunitiesData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();
        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($clientOrgId) {
            $total = $this->resolveWidgetValue(
                MissedOpportunitiesWidget::class, $orgId, $dates['start'], $dates['end'], $branchId, $clientOrgId
            );

            $monthlyTrend = MissedOpportunity::query()
                ->forOrg($orgId)
                ->between($dates['start'], $dates['end'], $timezone)
                ->forBranch($branchId)
                ->forClient($clientOrgId)
                ->byMonth()
                ->get()
                ->map(fn ($row) => [
                    'month' => Carbon::parse($row->month)->format('M Y'),
                    'value' => (int) $row->total,
                ])
                ->toArray();

            return [
                'dates' => $dates,
                'barChart' => [],
                'monthlyTrend' => $monthlyTrend,
                'monthlyTrendColor' => 'bg-danger-500',
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => number_format((int) $total),
                'summaryLabel' => 'total missed opportunities',
            ];
        }

        return $this->buildSimpleOrgDrillDown($dates, $orgId, $branchId, 'missed', 'Missed Opportunities', 'total missed opportunities', MissedOpportunitiesWidget::class);
    }

    // ──────────────────────────────────────────────
    // Average Recovery Rate
    // ──────────────────────────────────────────────

    public function avgRecoveryRateDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'avgRecoveryRateDrillDown',
            'Average Recovery Rate — Breakdown',
            $this->getAvgRecoveryRateData(),
            'Percentage of assignments that resulted in recorded repossessions; Repossessions / Assignments',
        );
    }

    protected function getAvgRecoveryRateData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();
        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($clientOrgId) {
            $assignments = (int) (HotlistStatusSource::query($branchId)
                ->forOrg($orgId)
                ->between($dates['start'], $dates['end'], $timezone)
                ->forBranch($branchId)
                ->forClient($clientOrgId)
                ->openAssignments()
                ->selectRaw(HotlistStatusSource::totalExpression($branchId))
                ->value('total') ?? 0);

            $repos = (int) (HotlistStatusSource::query($branchId)
                ->forOrg($orgId)
                ->between($dates['start'], $dates['end'], $timezone)
                ->forBranch($branchId)
                ->forClient($clientOrgId)
                ->repossessions()
                ->selectRaw(HotlistStatusSource::totalExpression($branchId))
                ->value('total') ?? 0);

            $rate = $assignments > 0 ? round(($repos / $assignments) * 100, 1) : 0;

            return [
                'dates' => $dates,
                'barChart' => [
                    ['label' => 'Total Assignments', 'value' => $assignments],
                    ['label' => 'Total Repossessions', 'value' => $repos],
                    ['label' => 'Recovery Rate', 'value' => $rate.'%'],
                ],
                'barChartTitle' => 'Rate Components',
                'barChartColors' => [
                    'Total Assignments' => 'bg-success-500',
                    'Total Repossessions' => 'bg-primary-500',
                    'Recovery Rate' => 'bg-warning-500',
                ],
                'barChartShowPercentage' => false,
                'monthlyTrend' => [],
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => $rate.'%',
                'summaryLabel' => 'recovery rate',
            ];
        }

        [$clientNames, $batchMetrics] = $this->fetchBatchMetrics($orgId, $branchId, $dates);

        $perClientBreakdown = collect($batchMetrics)
            ->map(function ($metrics, $clientId) use ($clientNames) {
                $assignments = (int) ($metrics['assignments'] ?? 0);
                $repos = (int) ($metrics['repos'] ?? 0);
                $rate = $assignments > 0 ? round(($repos / $assignments) * 100, 1) : 0;

                return [
                    'client_name' => $clientNames[$clientId] ?? "Client #{$clientId}",
                    'assignments' => $assignments,
                    'repossessions' => $repos,
                    'rate' => $rate,
                ];
            })
            ->filter(fn ($row) => $row['assignments'] > 0 || $row['repossessions'] > 0)
            ->sortByDesc('rate')
            ->values()
            ->toArray();

        $totalAssignments = collect($perClientBreakdown)->sum('assignments');
        $totalRepos = collect($perClientBreakdown)->sum('repossessions');
        $overallRate = $totalAssignments > 0 ? round(($totalRepos / $totalAssignments) * 100, 1) : 0;

        return [
            'dates' => $dates,
            'barChart' => [],
            'monthlyTrend' => [],
            'clientTable' => $perClientBreakdown,
            'clientTableColumns' => [
                'client_name' => 'Client',
                'assignments' => 'Assignments',
                'repossessions' => 'Repos',
                'rate' => 'Rate %',
            ],
            'clientTableValueKey' => 'rate',
            'summaryValue' => $overallRate.'%',
            'summaryLabel' => 'overall recovery rate',
        ];
    }

    // ──────────────────────────────────────────────
    // Total Accepted
    // ──────────────────────────────────────────────

    public function totalAcceptedDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'totalAcceptedDrillDown',
            'Total Accepted — Breakdown',
            $this->getTotalAcceptedData(),
            'Assignments marked as accepted during the selected time range.',
        );
    }

    protected function getTotalAcceptedData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();

        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($clientOrgId) {
            $total = $this->resolveWidgetValue(
                TotalAcceptedWidget::class, $orgId, $dates['start'], $dates['end'], $branchId, $clientOrgId
            );

            $monthlyTrend = HotlistStatusSource::query($branchId)
                ->forOrg($orgId)
                ->between($dates['start'], $dates['end'], $timezone)
                ->forBranch($branchId)
                ->forClient($clientOrgId)
                ->accepted()
                ->byMonth()
                ->get()
                ->map(fn ($row) => [
                    'month' => Carbon::parse($row->month)->format('M Y'),
                    'value' => (int) $row->total,
                ])
                ->toArray();

            return [
                'dates' => $dates,
                'barChart' => [],
                'monthlyTrend' => $monthlyTrend,
                'monthlyTrendColor' => 'bg-success-500',
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => number_format((int) $total),
                'summaryLabel' => 'total accepted',
            ];
        }

        return $this->buildSimpleOrgDrillDown($dates, $orgId, $branchId, 'accepted', 'Accepted', 'total accepted', TotalAcceptedWidget::class);
    }

    // ──────────────────────────────────────────────
    // Total Closed
    // ──────────────────────────────────────────────

    public function totalClosedDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'totalClosedDrillDown',
            'Total Closed — Breakdown',
            $this->getTotalClosedData(),
            'Assignments closed during the selected time range.',
        );
    }

    protected function getTotalClosedData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();
        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($clientOrgId) {
            $total = $this->resolveWidgetValue(
                TotalClosedWidget::class, $orgId, $dates['start'], $dates['end'], $branchId, $clientOrgId
            );

            $monthlyTrend = collect(CohortClosedQuery::byMonth(
                orgId: $orgId,
                start: $dates['start'],
                end: $dates['end'],
                timezone: $timezone,
                branchId: $branchId,
                clientOrgId: $clientOrgId,
            ))->map(fn ($row) => [
                'month' => Carbon::parse($row['month'])->format('M Y'),
                'value' => (int) $row['total'],
            ])->toArray();

            return [
                'dates' => $dates,
                'barChart' => [],
                'monthlyTrend' => $monthlyTrend,
                'monthlyTrendColor' => 'bg-danger-500',
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => number_format((int) $total),
                'summaryLabel' => 'total closed',
            ];
        }

        return $this->buildSimpleOrgDrillDown($dates, $orgId, $branchId, 'closed', 'Closed', 'total closed', TotalClosedWidget::class);
    }

    // ──────────────────────────────────────────────
    // Total Declined
    // ──────────────────────────────────────────────

    public function totalDeclinedDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'totalDeclinedDrillDown',
            'Total Declined — Breakdown',
            $this->getTotalDeclinedData(),
            'Assignments declined during the selected time range.',
        );
    }

    protected function getTotalDeclinedData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();
        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($clientOrgId) {
            $total = $this->resolveWidgetValue(
                TotalDeclinedWidget::class, $orgId, $dates['start'], $dates['end'], $branchId, $clientOrgId
            );

            $monthlyTrend = collect(CohortDeclinedQuery::byMonth(
                orgId: $orgId,
                start: $dates['start'],
                end: $dates['end'],
                timezone: $timezone,
                branchId: $branchId,
                clientOrgId: $clientOrgId,
            ))->map(fn ($row) => [
                'month' => Carbon::parse($row['month'])->format('M Y'),
                'value' => (int) $row['total'],
            ])->toArray();

            return [
                'dates' => $dates,
                'barChart' => [],
                'monthlyTrend' => $monthlyTrend,
                'monthlyTrendColor' => 'bg-danger-500',
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => number_format((int) $total),
                'summaryLabel' => 'total declined',
            ];
        }

        return $this->buildSimpleOrgDrillDown($dates, $orgId, $branchId, 'declined', 'Declined', 'total declined', TotalDeclinedWidget::class);
    }

    // ──────────────────────────────────────────────
    // Days to Repo
    // ──────────────────────────────────────────────

    public function daysToRepoDrillDownAction(): Action
    {
        return $this->makeDrillDownAction(
            'daysToRepoDrillDown',
            'Days to Repo — Breakdown',
            $this->getDaysToRepoData(),
            'Average days from assignment to repossession during the selected time range.',
        );
    }

    protected function getDaysToRepoData(): array
    {
        $dates = $this->getDrillDownDates();
        $orgId = $this->getDrillDownOrgId();
        $branchId = $this->getDrillDownBranchId();
        $clientOrgId = $this->getDrillDownClientOrgId();
        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($clientOrgId) {
            $total = $this->resolveWidgetValue(
                DaysToRepoWidget::class, $orgId, $dates['start'], $dates['end'], $branchId, $clientOrgId
            );

            $monthlyTrend = DaysToRepoAggregate::query()
                ->forOrg($orgId)
                ->between($dates['start'], $dates['end'], $timezone)
                ->forBranch($branchId)
                ->forClient($clientOrgId)
                ->byMonth()
                ->get()
                ->map(fn ($row) => [
                    'month' => Carbon::parse($row->month)->format('M Y'),
                    'value' => $row->total_count > 0 ? round($row->total_days / $row->total_count, 1) : 0,
                ])
                ->toArray();

            return [
                'dates' => $dates,
                'barChart' => [],
                'monthlyTrend' => $monthlyTrend,
                'monthlyTrendColor' => 'bg-warning-500',
                'clientTable' => [],
                'clientTableColumns' => [],
                'summaryValue' => (string) $total,
                'summaryLabel' => 'avg days to repo',
            ];
        }

        $clientNames = $this->getClientNames();
        $clientOrgIds = array_keys($clientNames);

        $perClientBreakdown = collect($clientOrgIds)
            ->map(function ($clientId) use ($orgId, $branchId, $dates, $clientNames, $timezone) {
                $result = DaysToRepoAggregate::query()
                    ->forOrg($orgId)
                    ->between($dates['start'], $dates['end'], $timezone)
                    ->forBranch($branchId)
                    ->forClient($clientId)
                    ->selectRaw('SUM(sum_days_to_repo_all) as total_days, SUM(count_repos_all) as total_count')
                    ->first();

                $count = $result->total_count ?? 0;
                $days = $result->total_days ?? 0;
                $avg = $count > 0 ? round($days / $count, 1) : 0;

                return [
                    'client_name' => $clientNames[$clientId] ?? "Client #{$clientId}",
                    'value' => $avg,
                ];
            })
            ->filter(fn ($row) => $row['value'] > 0)
            ->sortBy('value')
            ->values()
            ->toArray();

        $overallAvg = $this->resolveWidgetValue(
            DaysToRepoWidget::class, $orgId, $dates['start'], $dates['end'], $branchId
        );

        return [
            'dates' => $dates,
            'barChart' => [],
            'monthlyTrend' => [],
            'clientTable' => $perClientBreakdown,
            'clientTableColumns' => [
                'client_name' => 'Client',
                'value' => 'Avg Days',
            ],
            'clientTableValueKey' => 'value',
            'summaryValue' => (string) $overallAvg,
            'summaryLabel' => 'avg days to repo',
        ];
    }
}
