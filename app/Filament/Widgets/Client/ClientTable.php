<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Queries\Client\ClientRankedMetricsBatch;
use App\Facades\Analytics;
use App\Filament\Pages\IndividualClientAnalytics;
use App\Filament\Widgets\Client\Concerns\ClientStatHelper;
use App\Models\Org;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

class ClientTable extends BaseWidget
{
    use ClientStatHelper;

    protected int|string|array $columnSpan = 'full';

    public ?int $org_id = null;

    public ?int $branch_id = null;

    public ?int $client_org_id = null;

    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?string $date_type = 'this_month';

    public ?int $hiddenClientsCount = null;

    public bool $showAllClients = false;

    public bool $compareToLastMonth = true;

    #[On('refresh-dashboard')]
    public function refreshData(int|string|null $branch_id = null, ?string $start_date = null, ?string $end_date = null, ?string $date_type = null): void
    {
        $this->branch_id = ($branch_id !== null && $branch_id !== '' && is_numeric($branch_id))
            ? (int) $branch_id
            : null;

        $this->hiddenClientsCount = null;
        $this->start_date = $start_date;
        $this->end_date = $end_date;
        $this->date_type = $date_type ?? 'this_month';
    }

    public function toggleShowAllClients(): void
    {
        $this->showAllClients = ! $this->showAllClients;
    }

    protected function getHiddenClientsDescription(): ?HtmlString
    {
        $this->ensureHiddenClientsCount();

        if ($this->hiddenClientsCount === null || $this->hiddenClientsCount === 0) {
            return null;
        }

        return new HtmlString(
            'Hidden '.$this->hiddenClientsCount.' client'.($this->hiddenClientsCount === 1 ? '' : 's').' from display with 0 assignments. '.
            '<button type="button" wire:click="toggleShowAllClients" class="text-danger-600 dark:text-danger-400 underline font-medium">'.
            ($this->showAllClients ? 'Hide' : 'Show All').
            '</button>'
        );
    }

    protected function ensureHiddenClientsCount(): void
    {
        if ($this->hiddenClientsCount !== null) {
            return;
        }

        $orgId = $this->org_id ?? auth()->user()->org_id;
        $timezone = auth()->user()?->timezone ?? 'UTC';
        $org = Org::findOrFail($orgId);
        $hotlistClients = $org->getHotlistClients();

        if ($hotlistClients->isEmpty()) {
            $this->hiddenClientsCount = 0;

            return;
        }

        if ($this->start_date === null || $this->end_date === null) {
            $dateRange = $this->getDateRangeForType($this->date_type, timezone: $timezone);
            $this->start_date = $dateRange['start']->toDateString();
            $this->end_date = $dateRange['end']->toDateString();
        }

        $dates = $this->getDateRangeForType($this->date_type, $this->start_date, $this->end_date, $timezone);

        $startDateTime = $dates['start']->copy()->startOfDay()->format('Y-m-d H:i:s');
        $endDateTime = $dates['end']->copy()->endOfDay()->format('Y-m-d H:i:s');

        $allClientIds = $hotlistClients->keys()->toArray();

        $rankedMetrics = ClientRankedMetricsBatch::query([
            'org_id' => $orgId,
            'client_org_ids' => $allClientIds,
            'branch_id' => $this->branch_id,
            'start' => $startDateTime,
            'end' => $endDateTime,
            'timezone' => $timezone,
            'cacheTTL' => now()->addMinutes(10),
        ], cache: true);
        $rankedMetrics = \is_array($rankedMetrics) ? $rankedMetrics : [];

        $this->hiddenClientsCount = $hotlistClients->reduce(
            function (int $count, string $clientName, int $clientOrgId) use ($rankedMetrics): int {
                $assignmentCount = (int) ($rankedMetrics[$clientOrgId]['assignments'] ?? 0);

                return $assignmentCount === 0 ? $count + 1 : $count;
            },
            0
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(null)
            ->description(fn () => $this->getHiddenClientsDescription())
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->records(fn (int $page, int $recordsPerPage, ?string $sortColumn, ?string $sortDirection, array $filters) => $this->getTableData($page, $recordsPerPage, $sortColumn, $sortDirection, $filters))
            ->columns([
                Tables\Columns\TextColumn::make('client_name')
                    ->label('Client')
                    ->sortable()
                    ->formatStateUsing(function ($record) {
                        $record = (array) $record;
                        $percentage = number_format($record['percentage_of_total'], 1).'%';

                        return new HtmlString('
                            <div class="flex items-center gap-3">
                                <div>
                                    <div class="text-lg font-medium text-gray-950 dark:text-white">'.$record['client_name'].'</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">'.$percentage.' of total assignments</div>
                                </div>
                            </div>
                        ');
                    }),

                Tables\Columns\TextColumn::make('accepted_current')
                    ->label($this->columnLabelWithInfo('Accepted'))
                    ->headerTooltip('Assignments marked as accepted during the selected period.')
                    ->sortable()
                    ->placeholder($this->placeholderLoader())
                    ->formatStateUsing(function ($record) {
                        $record = (array) $record;

                        return $this->formatMetricColumn(
                            $record['accepted_current'],
                            $record['accepted_last_year'],
                            $record['accepted_change'],
                            null,
                            $this->getComparisonLabel(),
                            null,
                            'success'
                        );
                    })
                    ->extraAttributes([
                        'data-stat' => 'Accepted',
                    ]),

                Tables\Columns\TextColumn::make('closed_current')
                    ->label($this->columnLabelWithInfo('Closed'))
                    ->headerTooltip('Assignments closed during the selected period.')
                    ->sortable()
                    ->placeholder($this->placeholderLoader())
                    ->formatStateUsing(function ($record) {
                        $record = (array) $record;

                        return $this->formatMetricColumn(
                            $record['closed_current'],
                            $record['closed_last_year'],
                            $record['closed_change'],
                            null,
                            $this->getComparisonLabel(),
                            null,
                            'danger'
                        );
                    })
                    ->extraAttributes([
                        'data-stat' => 'Closed',
                    ]),

                Tables\Columns\TextColumn::make('repos_current')
                    ->label($this->columnLabelWithInfo('Repossessions'))
                    ->headerTooltip('Total successful repossessions during the selected period')
                    ->sortable()
                    ->placeholder($this->placeholderLoader())
                    ->formatStateUsing(function ($record) {
                        $record = (array) $record;

                        return $this->formatMetricColumn(
                            $record['repos_current'],
                            $record['repos_last_year'],
                            $record['repos_change'],
                            null,
                            $this->getComparisonLabel()
                        );
                    }),

                Tables\Columns\TextColumn::make('lpr_assists_current')
                    ->label($this->columnLabelWithInfo('LPR Assists'))
                    ->headerTooltip('Repossessions where LPR scanning assisted in locating the vehicle')
                    ->sortable()
                    ->placeholder($this->placeholderLoader())
                    ->formatStateUsing(function ($record) {
                        $record = (array) $record;
                        $lprPercentage = $record['repos_current'] > 0
                            ? round(($record['lpr_assists_current'] / $record['repos_current']) * 100)
                            : 0;

                        return $this->formatMetricColumn(
                            $record['lpr_assists_current'],
                            $record['lpr_assists_last_year'],
                            $record['lpr_assists_change'],
                            $lprPercentage.'% of repos',
                            $this->getComparisonLabel()
                        );
                    }),

                Tables\Columns\TextColumn::make('missed_repos_current')
                    ->label($this->columnLabelWithInfo('Missed Opportunities'))
                    ->headerTooltip('Vehicles scanned but not recovered before assignment ended')
                    ->sortable()
                    ->placeholder($this->placeholderLoader())
                    ->formatStateUsing(function ($record) {
                        $record = (array) $record;

                        return $this->formatMetricColumn(
                            $record['missed_repos_current'],
                            $record['missed_repos_last_year'],
                            $record['missed_repos_change'],
                            null,
                            $this->getComparisonLabel()
                        );
                    }),

                Tables\Columns\TextColumn::make('recovery_rate_current')
                    ->label($this->columnLabelWithInfo('Recovery Rate'))
                    ->headerTooltip('Percentage of assignments that resulted in successful repossession')
                    ->sortable()
                    ->placeholder($this->placeholderLoader())
                    ->formatStateUsing(function ($record) {
                        $record = (array) $record;
                        $comparisonLabel = $this->getComparisonLabel();

                        $currentRate = \number_format($record['recovery_rate_current'], 1).'%';

                        $lastPeriodHtml = ($record['recovery_rate_last_year'] !== null && $comparisonLabel !== null)
                            ? '<div class="text-sm text-gray-500 dark:text-gray-400">'.\number_format($record['recovery_rate_last_year'], 1).'% '.$comparisonLabel.'</div>'
                            : '';

                        $changeHtml = $record['recovery_rate_change'] !== null
                            ? '<div class="text-sm">'.$this->formatPercentageChange($record['recovery_rate_change']).'</div>'
                            : '';

                        return new HtmlString('
                            <div>
                                <div class="text-2xl font-semibold text-gray-950 dark:text-white">'.$currentRate.'</div>
                                '.$lastPeriodHtml.$changeHtml.'
                            </div>
                        ');
                    }),
            ])
            ->recordUrl(fn (array $record): string => IndividualClientAnalytics::getUrl(parameters: ['clientId' => $record['client_id']]))
            ->recordActions([
                Action::make('view_dashboard')
                    ->label('View Dashboard')
                    ->icon('heroicon-m-arrow-right')
                    ->iconPosition('after')
                    ->url(fn (array $record): string => IndividualClientAnalytics::getUrl(parameters: ['clientId' => $record['client_id']]))
                    ->button()
                    ->color('primary'),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterCells)
            ->defaultSort('accepted_current', 'desc')
            ->filters([
                Tables\Filters\Filter::make('filters')
                    ->schema([
                        Forms\Components\Select::make('clientSearch')
                            ->label('Filter Clients')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => auth()->user()->org->getHotlistClients()->toArray())
                            ->placeholder('Select clients...')
                            ->extraAttributes(trackAttr('client-analytics', 'client-search')),

                        Forms\Components\Toggle::make('compareToLastMonth')
                            ->label('Compare to Previous Timeframe')
                            ->default(true)
                            ->inline(false)
                            ->afterStateUpdated(function ($state): void {
                                Analytics::track('compare-toggle', auth()->user(), [
                                    'action' => 'toggle',
                                    'value' => $state ? 'on' : 'off',
                                ], page: 'client-analytics');
                            }),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->emptyStateHeading('No Data');
    }

    protected function getTableData(int $page, int $recordsPerPage, ?string $sortColumn = null, ?string $sortDirection = null, array $filters = []): LengthAwarePaginator
    {
        $orgId = $this->org_id ?? auth()->user()->org_id;
        $timezone = auth()->user()?->timezone ?? 'UTC';

        // Calculate dates from date_type if not provided
        if ($this->start_date === null || $this->end_date === null) {
            $dateRange = $this->getDateRangeForType($this->date_type, timezone: $timezone);
            $this->start_date = $dateRange['start']->toDateString();
            $this->end_date = $dateRange['end']->toDateString();
        }

        $dates = $this->getDateRangeForType($this->date_type, $this->start_date, $this->end_date, $timezone);

        $sortedClients = $this->getSortedClients($orgId, $dates);

        if ($sortedClients->isEmpty()) {
            return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
        }

        $clientSearch = $filters['filters']['clientSearch'] ?? [];
        $this->compareToLastMonth = (bool) ($filters['filters']['compareToLastMonth'] ?? true);

        $sortedClients = $this->applySortingAndFilters($sortedClients, $sortColumn, $sortDirection, $clientSearch);
        $paginatedClients = $sortedClients->slice(($page - 1) * $recordsPerPage, $recordsPerPage);

        $tableData = $this->buildTableDataWithMetrics($paginatedClients, $orgId, $dates, $sortedClients->sum('assignment_count'));

        return new LengthAwarePaginator($tableData, $sortedClients->count(), $recordsPerPage, $page);
    }

    protected function getSortedClients(int $orgId, array $dates): Collection
    {
        $timezone = auth()->user()?->timezone ?? 'UTC';
        $org = Org::findOrFail($orgId);
        $hotlistClients = $org->getHotlistClients();

        if ($hotlistClients->isEmpty()) {
            $this->hiddenClientsCount = 0;

            return collect([]);
        }

        $startDateTime = $dates['start']->copy()->startOfDay()->format('Y-m-d H:i:s');
        $endDateTime = $dates['end']->copy()->endOfDay()->format('Y-m-d H:i:s');

        $allClientIds = $hotlistClients->keys()->toArray();

        $rankedMetrics = ClientRankedMetricsBatch::query([
            'org_id' => $orgId,
            'client_org_ids' => $allClientIds,
            'branch_id' => $this->branch_id,
            'start' => $startDateTime,
            'end' => $endDateTime,
            'timezone' => $timezone,
            'cacheTTL' => now()->addMinutes(10),
        ], cache: true);

        $rankedMetrics = \is_array($rankedMetrics) ? $rankedMetrics : [];

        $allClients = $hotlistClients->map(function ($clientName, $clientOrgId) use ($rankedMetrics) {
            $metrics = $rankedMetrics[$clientOrgId] ?? null;

            return [
                'client_id' => $clientOrgId,
                'client_name' => $clientName,
                'assignment_count' => (int) ($metrics['assignments'] ?? 0),
                'batch_metrics' => $metrics,
            ];
        })->values();

        // Track hidden clients count
        $this->hiddenClientsCount = $allClients->filter(fn ($c) => $c['assignment_count'] === 0)->count();

        // Filter out clients with 0 assignments only if not showing all
        if (! $this->showAllClients) {
            return $allClients
                ->filter(fn ($client) => $client['assignment_count'] > 0)
                ->sortBy([['assignment_count', 'desc'], ['client_name', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)
                ->values();
        }

        return $allClients
            ->sortBy([['assignment_count', 'desc'], ['client_name', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    protected function applySortingAndFilters(Collection $clients, ?string $sortColumn = null, ?string $sortDirection = null, array $clientSearch = []): Collection
    {
        if (! empty($clientSearch)) {
            $clients = $clients->filter(fn ($c) => \in_array($c['client_id'], $clientSearch));
        }

        $sortColumn = $sortColumn ?? 'accepted_current';
        $descending = ($sortDirection ?? 'desc') === 'desc';

        // Map column names to their sort callbacks
        $sortCallback = match ($sortColumn) {
            'client_name' => fn ($c) => $c['client_name'],
            'accepted_current' => fn ($c) => (int) ($c['batch_metrics']['accepted'] ?? 0),
            'closed_current' => fn ($c) => (int) ($c['batch_metrics']['closed'] ?? 0),
            'repos_current' => fn ($c) => (int) ($c['batch_metrics']['repos'] ?? 0),
            'lpr_assists_current' => fn ($c) => (int) ($c['batch_metrics']['lpr_assists'] ?? 0),
            'missed_repos_current' => fn ($c) => (int) ($c['batch_metrics']['missed'] ?? 0),
            'recovery_rate_current' => function ($c) {
                $accepted = (int) ($c['batch_metrics']['accepted'] ?? 0);
                $repos = (int) ($c['batch_metrics']['repos'] ?? 0);

                return $accepted > 0 ? ($repos / $accepted) * 100 : 0;
            },
            default => fn ($c) => (int) ($c['batch_metrics']['accepted'] ?? 0),
        };

        return $clients->sortBy($sortCallback, SORT_REGULAR, $descending)->values();
    }

    protected function buildTableDataWithMetrics(Collection $clients, int $orgId, array $dates, int $totalAssignments): array
    {
        $previousDates = null;
        $timezone = auth()->user()?->timezone ?? 'UTC';

        if ($this->compareToLastMonth) {
            // MTD: compare to last month, YTD: compare to last year, Custom: no comparison
            $previousDates = match ($this->date_type) {
                'this_month' => [
                    'start' => $dates['start']->copy()->subMonth(),
                    'end' => $dates['end']->copy()->subMonth(),
                ],
                'ytd' => [
                    'start' => $dates['start']->copy()->subYear(),
                    'end' => $dates['end']->copy()->subYear(),
                ],
                default => null,
            };
        }

        // Extract client IDs for batch queries
        $clientIds = $clients->pluck('client_id')->toArray();

        // Batch-fetch previous period metrics for all paginated clients at once
        $previousMetricsBatch = ($previousDates !== null && ! empty($clientIds))
            ? ClientRankedMetricsBatch::query([
                'org_id' => $orgId,
                'client_org_ids' => $clientIds,
                'branch_id' => $this->branch_id,
                'start' => $previousDates['start']->copy()->startOfDay()->format('Y-m-d H:i:s'),
                'end' => $previousDates['end']->copy()->endOfDay()->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
                'cacheTTL' => now()->addMinutes(10),
            ], cache: true)
            : [];

        return $clients->map(function ($client) use ($totalAssignments, $previousMetricsBatch) {
            $clientId = $client['client_id'];
            $metrics = $this->getClientMetrics(
                $client['batch_metrics'] ?? null,
                $previousMetricsBatch[$clientId] ?? null
            );

            return [
                'client_id' => $clientId,
                'client_name' => $client['client_name'],
                'assignment_count' => $client['assignment_count'],
                'percentage_of_total' => $totalAssignments > 0 ? ($client['assignment_count'] / $totalAssignments) * 100 : 0,
                ...$metrics,
            ];
        })->all();
    }

    protected function getClientMetrics(
        ?array $currentBatchMetrics = null,
        ?array $previousBatchMetrics = null
    ): array {
        // Current period metrics from pre-fetched batch
        $metrics = [
            'accepted_current' => (int) ($currentBatchMetrics['accepted'] ?? 0),
            'closed_current' => (int) ($currentBatchMetrics['closed'] ?? 0),
            'repos_current' => (int) ($currentBatchMetrics['repos'] ?? 0),
            'lpr_assists_current' => (int) ($currentBatchMetrics['lpr_assists'] ?? 0),
            'missed_repos_current' => (int) ($currentBatchMetrics['missed'] ?? 0),
        ];

        // Previous period metrics from pre-fetched batch (if available)
        $hasPreviousMetrics = $previousBatchMetrics !== null;
        $metrics['accepted_last_year'] = $hasPreviousMetrics ? (int) ($previousBatchMetrics['accepted'] ?? 0) : null;
        $metrics['closed_last_year'] = $hasPreviousMetrics ? (int) ($previousBatchMetrics['closed'] ?? 0) : null;
        $metrics['repos_last_year'] = $hasPreviousMetrics ? (int) ($previousBatchMetrics['repos'] ?? 0) : null;
        $metrics['lpr_assists_last_year'] = $hasPreviousMetrics ? (int) ($previousBatchMetrics['lpr_assists'] ?? 0) : null;
        $metrics['missed_repos_last_year'] = $hasPreviousMetrics ? (int) ($previousBatchMetrics['missed'] ?? 0) : null;

        $metrics['accepted_change'] = $hasPreviousMetrics
            ? $this->calculatePercentageChange($metrics['accepted_current'], $metrics['accepted_last_year'])
            : null;

        $metrics['closed_change'] = $hasPreviousMetrics
            ? $this->calculatePercentageChange($metrics['closed_current'], $metrics['closed_last_year'])
            : null;

        $metrics['repos_change'] = $hasPreviousMetrics
            ? $this->calculatePercentageChange($metrics['repos_current'], $metrics['repos_last_year'])
            : null;

        $metrics['lpr_assists_change'] = $hasPreviousMetrics
            ? $this->calculatePercentageChange($metrics['lpr_assists_current'], $metrics['lpr_assists_last_year'])
            : null;

        $metrics['missed_repos_change'] = $hasPreviousMetrics
            ? $this->calculatePercentageChange($metrics['missed_repos_current'], $metrics['missed_repos_last_year'])
            : null;

        // Recovery rate calculations
        if ($metrics['accepted_current'] !== null && $metrics['repos_current'] !== null) {
            $metrics['recovery_rate_current'] = $metrics['accepted_current'] > 0
                ? ($metrics['repos_current'] / $metrics['accepted_current']) * 100
                : 0;
        } else {
            $metrics['recovery_rate_current'] = null;
        }

        if ($metrics['accepted_last_year'] !== null && $metrics['repos_last_year'] !== null) {
            $metrics['recovery_rate_last_year'] = $metrics['accepted_last_year'] > 0
                ? ($metrics['repos_last_year'] / $metrics['accepted_last_year']) * 100
                : 0;
        } else {
            $metrics['recovery_rate_last_year'] = null;
        }

        $metrics['recovery_rate_change'] = ($metrics['recovery_rate_current'] !== null && $metrics['recovery_rate_last_year'] !== null)
            ? $this->calculatePercentageChange($metrics['recovery_rate_current'], $metrics['recovery_rate_last_year'])
            : null;

        return $metrics;
    }

    protected function calculatePercentageChange(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (($current - $previous) / $previous) * 100;
    }

    protected function getComparisonLabel(): ?string
    {
        if ($this->compareToLastMonth) {
            return match ($this->date_type) {
                'this_month' => 'last month',
                'ytd' => 'last year',
                default => null,
            };
        }

        return null;
    }

    protected function formatMetricColumn(?int $current, ?int $lastPeriod, ?float $change, ?string $additionalInfo = null, ?string $comparisonLabel = null): HtmlString
    {
        if ($current === null) {
            return new HtmlString('');
        }

        $currentFormatted = \number_format($current);

        $lastPeriodHtml = ($lastPeriod !== null && $comparisonLabel !== null)
            ? '<div class="text-sm text-gray-500 dark:text-gray-400">'.\number_format($lastPeriod).' '.$comparisonLabel.'</div>'
            : '';

        $changeHtml = $change !== null
            ? '<div class="text-sm">'.$this->formatPercentageChange($change).'</div>'
            : '';

        $additionalInfoHtml = $additionalInfo
            ? '<div class="text-sm text-gray-500 dark:text-gray-400 font-medium">'.$additionalInfo.'</div>'
            : '';

        return new HtmlString('
            <div>
                <div class="text-2xl font-semibold text-gray-950 dark:text-white">'.$currentFormatted.'</div>
                '.$lastPeriodHtml.$additionalInfoHtml.$changeHtml.'
            </div>
        ');
    }

    protected function formatPercentageChange(float $change): string
    {
        $arrow = $change > 0 ? '↑ ' : ($change < 0 ? '↓ ' : '');
        $changeAbs = abs($change);
        $colorClass = $change > 0 ? 'text-success-600 dark:text-success-400' : ($change < 0 ? 'text-danger-600 dark:text-danger-400' : '');

        return $change == 0
            ? '<span class="inline-flex items-center gap-1">0%</span>'
            : '<span class="font-medium '.$colorClass.'">'.$arrow.number_format($changeAbs, 1).'%</span>';
    }

    protected function placeholderLoader(): HtmlString
    {
        return new HtmlString('
            <div class="text-right space-y-1">
                <div class="h-6 w-16 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
                <div class="h-4 w-20 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
                <div class="h-4 w-12 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
            </div>
        ');
    }

    protected function columnLabelWithInfo(string $label): HtmlString
    {
        return new HtmlString('
            <span class="inline-flex items-center gap-1.5">
                '.$label.'
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 dark:text-gray-500 cursor-help">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
            </span>
        ');
    }
}
