<?php

namespace App\Filament\Pages;

use App\Clickhouse\Models\RecoveryKitScanReport;
use App\Enums\HotlistStatusEnum;
use App\Facades\Analytics;
use App\Filament\Exports\ScanPercentageExporter;
use App\Filament\Tables\Filters\KitUsersFilter;
use App\Filament\Widgets\KitUserRecoveries\RecoveryStatsWidget;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Forms;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class KitUserRecoveries extends Page implements HasTable
{
    use HasFiltersForm, InteractsWithTable {
        HasFiltersForm::normalizeTableFilterValuesFromQueryString insteadof InteractsWithTable;
    }

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Kit User Recoveries';

    protected string $view = 'filament.pages.kit-user-recoveries';

    public function mount(): void
    {
        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'kit-user-recoveries');
    }

    public string $date_toggles = 'this_month';

    public mixed $start_date = null;

    public mixed $end_date = null;

    public ?array $kits = [];

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            RecoveryStatsWidget::class,
        ];
    }

    public function getFiltersForm(): Schema
    {
        if ((! $this->isCachingSchemas) && $this->hasCachedSchema('filtersForm')) {
            return $this->getSchema('filtersForm');
        }

        return $this->filtersForm($this->makeSchema()
            ->columns([
                'default' => 1,
                'md' => 2,
                'lg' => 3,
            ])
            ->components([
                KitUsersFilter::make('kits')
                    ->label('Kit')
                    ->searchable()
                    ->multiple()
                    ->columnSpan(1)
                    ->extraAttributes(trackAttr('kit-user-recoveries', 'kits-filter')),

                Forms\Components\ToggleButtons::make('date_toggles')
                    ->label('Recovery Date')
                    ->inline()
                    ->live()
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->default('this_month')
                    ->options([
                        'this_month' => 'This Month',
                        'last_month' => 'Last Month',
                        'advanced' => 'Advanced',
                    ])
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        Analytics::track('date-filter', auth()->user(), [
                            'action' => 'filter-change',
                            'value' => $state,
                        ], page: 'kit-user-recoveries');

                        if ($state === 'advanced') {
                            return;
                        }

                        $start = match ($state) {
                            'last_month' => now(tz: auth()->user()->timezone)->startOfMonth()->subMonth()->startOfDay(),
                            default => now(tz: auth()->user()->timezone)->startOfMonth()->startOfDay(),
                        };
                        $set('start_date', $start->format('Y-m-d H:i'));

                        $end = match ($state) {
                            'last_month' => now(tz: auth()->user()->timezone)->startOfMonth()->subMonth()->endOfMonth()->endOfDay(),
                            default => now(tz: auth()->user()->timezone),
                        };
                        $set('end_date', $end->format('Y-m-d H:i'));
                    }),

                Forms\Components\DateTimePicker::make('start_date')
                    ->label('Recovery Date: From')
                    ->seconds(false)
                    ->visible(fn (): bool => $this->date_toggles === 'advanced')
                    ->timezone(auth()->user()->timezone)
                    ->default(now(tz: auth()->user()->timezone)->startOfMonth()->startOfDay())
                    ->extraAttributes(trackAttr('kit-user-recoveries', 'start-date')),

                Forms\Components\DateTimePicker::make('end_date')
                    ->label('Recovery Date: To')
                    ->seconds(false)
                    ->visible(fn (): bool => $this->date_toggles === 'advanced')
                    ->timezone(auth()->user()->timezone)
                    ->default(now(tz: auth()->user()->timezone))
                    ->maxDate(now(tz: auth()->user()->timezone))
                    ->extraAttributes(trackAttr('kit-user-recoveries', 'end-date')),
            ])
        );
    }

    public function getFiltersApplyAction(): Action
    {
        return Action::make('applyFilters')
            ->label('Search')
            ->action('search')
            ->button();
    }

    public function table(Table $table): Table
    {
        $startDate = $this->start_date ?? now(tz: auth()->user()->timezone)->startOfMonth()->startOfDay();
        $endDate = $this->end_date ?? now(tz: auth()->user()->timezone);
        $kits = KitUsersFilter::format($this->kits ?? []);

        return $table
            ->heading('Scan Percentage')
            ->emptyStateHeading('No kit user recoveries.')
            ->columns([
                Tables\Columns\TextColumn::make('kit_name')
                    ->label('Kit Name')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vin')
                    ->label('VIN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('plate')
                    ->label('Plate')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => match ($state) {
                        HotlistStatusEnum::Other => 'Other',
                        HotlistStatusEnum::Open => 'Open',
                        HotlistStatusEnum::Repossessed => 'Repossessed',
                        default => 'Unknown',
                    })
                    ->color(fn (mixed $state): string => match ($state) {
                        HotlistStatusEnum::Other => 'warning',
                        HotlistStatusEnum::Open => 'success',
                        HotlistStatusEnum::Repossessed => 'danger',
                        default => 'primary',
                    }),

                Tables\Columns\TextColumn::make('hotlist_scan_percentage')
                    ->label('Scan Percentage')
                    ->default('0%')
                    ->formatStateUsing(fn ($state): string => $state !== '0%' ? \number_format((float) $state, 2).'%' : $state)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('hotlist_scan_percentage', $direction);
                    }),

                Tables\Columns\TextColumn::make('repo_date')
                    ->label('Recovery Date')
                    ->timezone(auth()->user()->timezone)
                    ->dateTime(config('app.date_format'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('count')
                    ->label('Kit Scan Count')
                    ->default(0),

                Tables\Columns\TextColumn::make('hotlist_scan_count')
                    ->label('Repo Scan Count')
                    ->default(0),
            ])
            ->headerActions([
                ExportAction::make('download')
                    ->hiddenLabel()
                    ->hidden(fn (HasTable $livewire): bool => $livewire->getTable()->getRecords()->count() === 0)
                    ->icon('heroicon-m-arrow-down-tray')
                    ->exporter(ScanPercentageExporter::class)
                    ->tooltip('Export as CSV')
                    ->fileName(fn (Export $export): string => 'scan-percentage-'.$export->getKey())
                    ->chunkSize(250)
                    ->columnMapping(false)
                    ->modal(false)
                    ->extraAttributes(trackAttr('kit-user-recoveries', 'export'))
                    ->after(fn () => Analytics::track('export', auth()->user(), ['action' => 'export'], 'kit-user-recoveries')),
            ])
            ->defaultSort('kit_name')
            ->query(function () use ($kits, $startDate, $endDate) {
                return RecoveryKitScanReport::query()
                    ->final()
                    ->forOrg(auth()->user()->org_id)
                    ->between(convertToUtc($startDate), convertToUtc($endDate))
                    ->forKits($kits)
                    ->select([
                        'recovery_id',
                        'kit_id',
                        'kit_name',
                        'vin',
                        'plate',
                        'status',
                        'repo_date',
                        'kit_scan_count as count',
                        'total_scan_count as hotlist_scan_count',
                        'hotlist_scan_percentage',
                    ]);
            });
    }

    public function getTableRecordKey(Model|array|null $record = null): string
    {
        return ($record?->recovery_id ?? '').'-'.($record?->kit_id ?? uniqid());
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        return $query->simplePaginate(($this->getTableRecordsPerPage() === 'all') ? $query->count() : $this->getTableRecordsPerPage());
    }

    public function search(): void
    {
        $this->resetTable();

        $this->dispatch('reload-recoveries-total', filters: [
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ]);

        $this->dispatch('reload-recoveries-leaderboard', filters: [
            'kits' => $this->kits ?? [],
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ]);
    }

    public static function canView(): bool
    {
        return auth()->user()->can(PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);
    }

    public static function canAccess(): bool
    {
        return self::canView();
    }
}
