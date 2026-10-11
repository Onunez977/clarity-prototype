<?php

namespace App\Filament\Resources\ScansResource\Pages;

use App\Ai\Tools\ApplyScanSearchFiltersTool;
use App\Clickhouse\Models\ClickhouseModel;
use App\Clickhouse\Models\Scan as ClickhouseScan;
use App\Dto\ScanViewDto;
use App\Enums\DispositionEnum;
use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Filament\Actions\BulkDispositionAction;
use App\Filament\Actions\BulkMapAction;
use App\Filament\Concerns\AppliesOttoPageFilters;
use App\Filament\Concerns\HasClickableFilter;
use App\Filament\Concerns\HasFilterActivityLogging;
use App\Filament\Concerns\HasFilterAnalytics;
use App\Filament\Concerns\HasRetryQuery;
use App\Filament\Forms\Components\ProFeatureToggle;
use App\Filament\Resources\ScansResource;
use App\Filament\Tables\Columns\DateTimeColumn;
use App\Filament\Tables\Filters\AddressFilter;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Tables\Filters\FlexSearchToggle;
use App\Filament\Tables\Filters\KitUsersFilter;
use App\Filament\Tables\Filters\PlateFilter;
use App\Livewire\Concerns\InteractsWithOttoPageActions;
use App\Models\Kit;
use App\Models\Scan;
use App\Models\Scopes\ScanTimezoneScope;
use App\Support\FlexSearch;
use App\Support\Otto\OttoAccess;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Pennant\Feature;

class ListScans extends ListRecords
{
    use AppliesOttoPageFilters;
    use HasClickableFilter {
        HasClickableFilter::addFilter as clickableAddFilter;
    }
    use HasFilterActivityLogging;
    use HasFilterAnalytics;
    use HasRetryQuery;
    use InteractsWithOttoPageActions;

    public bool $searchApplied = false;

    public bool $clusterView = false;

    protected array $loggedFilters = [
        'ocr' => 'plateNumber',
    ];

    protected ?string $analyticsPage = 'scans';

    protected array $trackedFilters = [
        'filters.ocr' => 'plate-filter',
        'filters.cluster' => 'clustering-filter',
        'filters.kits' => 'kits-filter',
        'filters.invalid' => 'invalid-filter',
        'geo.lat' => 'address-filter',
        'scanned_at.date_toggles' => 'date-filter',
    ];

    protected static string $resource = ScansResource::class;

    public function mount(): void
    {
        parent::mount();

        if (! empty($this->tableFilters)) {
            $this->searchApplied = true;
        }

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'scans');
    }

    public function applyTableFilters(): void
    {
        $this->searchApplied = true;

        parent::applyTableFilters();
    }

    public function updatedTableFilters(): void
    {
        $this->searchApplied = true;

        parent::updatedTableFilters();
    }

    public function addFilter(mixed $type, mixed $value): void
    {
        $this->searchApplied = true;

        $this->clickableAddFilter($type, $value);
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ocr')
                    ->label('Plate')
                    ->formatStateUsing(function (Model $record, \stdClass $rowLoop, HasTable $livewire): View {
                        $filters = $livewire->getTableFilterState('filters');
                        $searchedPlate = $filters['ocr'] ?? null;
                        $flexEnabled = ($filters['flexsearch'] ?? false) && FlexSearch::isAvailable();

                        return view(
                            'filament.tables.columns.plate-images',
                            [
                                'model' => ScanViewDto::fromRecord($record),
                                'showTabs' => true,
                                'rowLoop' => $rowLoop,
                                'dispositionable' => $record->kit_org_id === auth()->user()->org_id,
                                'trigger_icon' => 'heroicon-o-viewfinder-circle',
                                'defaultDisposition' => DispositionEnum::INVALID_SCAN->value,
                                'flexMatchActive' => (bool) $searchedPlate,
                                'isFlexMatchPro' => Feature::active(FeatureEnum::CLEARPLAN_RESEND->slug()) || session()->has('admin_impersonator'),
                                'probableMatch' => FlexSearch::isCloseMatch($searchedPlate, $record->ocr, $flexEnabled),
                            ],
                        );
                    }),

                DateTimeColumn::make('scanned_at')
                    ->label('Scanned')
                    ->showIcon()
                    ->sortable(),

                Tables\Columns\TextColumn::make('geo')
                    ->label('Address')
                    ->toggleable()
                    ->default('-')
                    ->formatStateUsing(fn (Model $record): View => view(
                        'filament.tables.columns.geo-address',
                        ['model' => ScanViewDto::fromRecord($record)],
                    )),

                Tables\Columns\TextColumn::make('camera_name')
                    ->label('Camera')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('kit_name')
                    ->label('User')
                    ->default('N/A')
                    ->toggleable(),
            ])
            ->toolbarActions([
                BulkMapAction::make('map'),
                BulkDispositionAction::make('bulk-disposition'),
            ])
            ->filters([
                Filter::make('filters')
                    ->columnSpanFull()
                    ->schema([
                        PlateFilter::make('ocr')
                            ->label('Plate')
                            ->autofocus(),

                        FlexSearchToggle::make('flexsearch'),

                        ProFeatureToggle::make('cluster')
                            ->proFeature(FeatureEnum::SCAN_CLUSTERING->slug())
                            ->proDescription('Cluster scans by proximity to each other by prioritizing locations of interest within 0.1 miles. Upgrade to PRO to unlock this feature.')
                            ->label('Location Cluster')
                            ->columnSpanFull()
                            ->when(
                                Feature::active(FeatureEnum::SCAN_CLUSTERING->slug()) || session()->has('admin_impersonator'),
                                fn (ProFeatureToggle $toggle) => $toggle->default(true),
                                fn (ProFeatureToggle $toggle) => $toggle->default(false),
                            )
                            ->inline()
                            ->live(debounce: '250ms')
                            ->offIcon('heroicon-o-x-mark')
                            ->onIcon('heroicon-o-rectangle-stack'),

                        KitUsersFilter::make('kits')
                            ->label('Kit Users')
                            ->multiple(),

                        Forms\Components\Toggle::make('invalid')
                            ->label('Show Only Invalid Scans')
                            ->offIcon('heroicon-o-x-mark')
                            ->onIcon('heroicon-o-hand-thumb-down')
                            ->columnSpanFull()
                            ->inline(),
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];

                        if ($data['ocr']) {
                            $indicators[] = 'Plate: "'.$data['ocr'].'"';
                        }

                        if ($data['kits']) {
                            $indicators[] = KitUsersFilter::indicator($data['kits']);
                        }

                        return $indicators !== []
                            ? $indicators
                            : null;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['ocr'],
                                fn (Builder $q, $plate) => $q->forPlate($plate, $data['flexsearch'] ?? false),
                            )
                            ->when(
                                $data['kits'],
                                fn (Builder $q, $kits) => $q->whereIn('kit_id', KitUsersFilter::format($kits)),
                            )
                            ->when(
                                $data['invalid'],
                                fn (Builder $q) => $q->forDisposition(DispositionEnum::INVALID_SCAN->value),
                                fn (Builder $q) => $q->excludeDisposition(DispositionEnum::INVALID_SCAN->value),
                            );
                    }),

                AddressFilter::make('geo'),

                DateTogglesFilter::make('scanned_at')
                    ->defaultDate(null)
                    ->dateToggles([
                        'today' => 'Today',
                        'yesterday' => 'Yesterday',
                        'last_7d' => 'Last 7d',
                        'last_30d' => 'Last 30d',
                        'last_3m' => 'Last 3m',
                        'last_6m' => 'Last 6m',
                        'year' => 'Year',
                    ])
                    ->modifyQueryUsing(fn (Builder $query, array $data): Builder => $query->between(
                        $data['start'] ?? null,
                        $data['end'] ?? null,
                    )),
            ])
            ->selectable()
            ->defaultSort('scanned_at', 'desc')
            ->recordClasses(function (HasTable $livewire, Model $record): ?string {
                $class = null;
                $filters = $livewire->getTableFilterState('filters');

                if ($filters['invalid'] ?? false) {
                    $class = 'invalid';
                }

                if ($record->kit_org_id !== auth()->user()->org_id) {
                    $class = 'shared';
                }

                $flexEnabled = ($filters['flexsearch'] ?? false) && FlexSearch::isAvailable();
                if (FlexSearch::isCloseMatch($filters['ocr'] ?? null, $record->ocr, $flexEnabled)) {
                    $class = $class ? "{$class} flex-match" : 'flex-match';
                }

                return $class;
            })
            ->emptyStateHeading('No scans found')
            ->emptyStateDescription('Try adjusting your search criteria.')
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    protected function getTableQuery(): Builder
    {
        $this->updateClusterViewState();

        if (! $this->searchApplied) {
            $this->getTable()?->emptyStateIcon('heroicon-o-magnifying-glass');
            $this->getTable()?->emptyStateHeading('Enter Search Criteria to View Scans');
            $this->getTable()?->emptyStateDescription('Filter by Scanned At date ranges, Plate OCRs, Location, or Kit Users.');

            $query = $this->shouldUseClickhouse()
                ? $this->buildClickhouseQuery()
                : $this->buildPostgresQuery();

            return $query->whereRaw('1 = 0');
        }

        if ($this->shouldUseClickhouse()) {
            return $this->buildClickhouseQuery();
        }

        return $this->buildPostgresQuery();
    }

    private function shouldUseClickhouse(): bool
    {
        return config('app.experimental.clickhouse.scans', false) || session()->has('admin_impersonator');
    }

    private function buildClickhouseQuery(): Builder
    {
        [$start, $end] = $this->getVisitDateRange();

        $useHot = false;
        if ($start) {
            $useHot = Carbon::parse($start)->diffInDays(now()) <= 90;
        }

        $model = $useHot ? ClickhouseScan::hot() : new ClickhouseScan;
        $withShared = $this->withSharedKits();
        $kits = Kit::getKits((int) auth()->user()->org_id, withShared: $withShared);

        // org_id leads the scans_cold / scans_hot sort key — kit_id alone cannot prune.
        // Use each kit's current Postgres org_id (DEV-2615). Kits that moved orgs may
        // hide older scans_cold rows still stamped with a former org_id; that trade-off
        // is accepted until a customer reports it.
        $orgIds = $kits->pluck('org_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($orgIds === []) {
            $orgIds = [(int) auth()->user()->org_id];
        }

        return $model->newQuery()
            ->forOrgs($orgIds)
            ->forKits($kits->pluck('id')->all())
            ->select([
                'id', 'ocr', 'guid', 'us_state', 'scanned_at',
                'camera_name', 'kit_name', 'org_id as kit_org_id',
                'disposition', 'lat', 'lng', 'address',
            ]);
    }

    private function withSharedKits(): bool
    {
        return $this->getFilterState('filters.ocr') !== null
            && Feature::active(FeatureEnum::SHARED_KITS->slug());
    }

    private function buildPostgresQuery(): Builder
    {
        return Scan::query()
            ->forOrg(auth()->user()->org_id, withSharedKits: $this->withSharedKits())
            ->withoutGlobalScope(ScanTimezoneScope::class)
            ->join('kits', 'kits.id', '=', 'scans.kit_id')
            ->leftJoin('scans_metadata', 'scans_metadata.scan_id', '=', 'scans.id')
            ->select([
                'scans.id as id',
                'scans.ocr',
                'scans.geo',
                'scans.guid',
                'scans.us_state',
                'scans.scanned_at',
                'scans.camera_name',
                'kits.name as kit_name',
                'kits.org_id as kit_org_id',
                'scans_metadata.meta as metadata',
            ]);
    }

    public function getVisitDateRange(): array
    {
        $dateState = $this->getFilterState('scanned_at');
        $start = data_get($dateState, 'start');
        $end = data_get($dateState, 'end');
        $tz = auth()->user()->timezone;

        if (! $start && ! $end) {
            $start = now($tz)->subDays(30)->startOfDay()->toDateTimeString();
            $end = now($tz)->toDateTimeString();
        }

        return [$start, $end];
    }

    private function getFilterState(string $key): mixed
    {
        return $this->getTable()->hasDeferredFilters()
            ? data_get($this, 'tableDeferredFilters.'.$key)
            : data_get($this, 'tableFilters.'.$key);
    }

    public function getIsAddressClusterMode(): bool
    {
        $ocr = $this->getFilterState('filters.ocr');
        $lat = $this->getFilterState('geo.lat');

        return empty($ocr) && $lat !== null;
    }

    public function getClusterFilterCoordinates(): array
    {
        return [
            'lat' => $this->getFilterState('geo.lat'),
            'lon' => $this->getFilterState('geo.lon'),
        ];
    }

    public function getSearchedPlate(): ?string
    {
        $plate = $this->getFilterState('filters.ocr');

        return is_string($plate) && $plate !== '' ? $plate : null;
    }

    public function isFlexSearchEnabled(): bool
    {
        return (bool) $this->getFilterState('filters.flexsearch')
            && FlexSearch::isAvailable();
    }

    private function updateClusterViewState(): void
    {
        $plate = $this->getFilterState('filters.ocr');
        $addressLat = $this->getFilterState('geo.lat');
        $cluster = $this->getFilterState('filters.cluster');

        $this->clusterView = $cluster && ($plate !== null || $addressLat !== null);
    }

    private function isClickhouseQuery(Builder $query): bool
    {
        return $query->getModel() instanceof ClickhouseModel;
    }

    protected function trackConditionalFilterAnalytics(): void
    {
        $ocr = data_get($this->tableFilters, 'filters.ocr');
        $flex = data_get($this->tableFilters, 'filters.flexsearch');

        if ($this->isFilterValueActive($ocr) && $flex) {
            Analytics::track('flex-search', auth()->user(), ['action' => 'pro-feature'], page: 'scans');
        }
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $perPage = $this->getTableRecordsPerPage();
        $perPage = $perPage === 'all' ? $this->getTable()->getDefaultPaginationPageOption() : $perPage;

        if (! $this->isClickhouseQuery($query)) {
            $query->getQuery()->orders = collect($query->getQuery()->orders ?? [])
                ->filter(fn ($order) => $order['column'] !== 'scans.id')
                ->values()
                ->all();
        }

        return $query->simplePaginate($perPage);
    }

    protected function ottoPageType(): string
    {
        return ApplyScanSearchFiltersTool::PAGE_TYPE;
    }

    protected function ottoPageDescription(): string
    {
        return 'Scans';
    }

    /**
     * @return array<string, mixed>
     */
    protected function ottoPageContextState(): array
    {
        return array_filter([
            'plate' => $this->ottoFilterValue('filters.ocr'),
            'kits' => $this->ottoFilterValue('filters.kits'),
            'flex_search' => $this->ottoFilterValue('filters.flexsearch') ? 'on' : null,
            'invalid' => $this->ottoFilterValue('filters.invalid') ? 'on' : null,
            'time_range' => $this->ottoDateFilterState('scanned_at'),
        ], fn ($value): bool => $value !== '' && $value !== [] && $value !== null);
    }

    protected function ottoPageActionAuthorized(): bool
    {
        $user = auth()->user();

        return OttoAccess::userCanUseOtto($user)
            && (bool) $user?->can(PermissionEnum::ViewAny_SCAN->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyOttoPageAction(string $name, array $payload): void
    {
        if ($name !== ApplyScanSearchFiltersTool::ACTION) {
            return;
        }

        $this->applyOttoFilterPayload($payload, [
            'plate' => 'filters.ocr',
            'kits' => 'filters.kits',
            'flex_search' => 'filters.flexsearch',
            'invalid' => 'filters.invalid',
        ]);

        if (array_key_exists('time_range', $payload)) {
            $this->applyOttoDateFilter('scanned_at', $payload['time_range']);
        }

        $this->commitOttoTableFilters();
    }
}
