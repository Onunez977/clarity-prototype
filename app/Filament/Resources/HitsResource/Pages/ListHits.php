<?php

namespace App\Filament\Resources\HitsResource\Pages;

use App\Ai\Tools\ApplyHitSearchFiltersTool;
use App\Clickhouse\Models\ClickhouseModel;
use App\Enums\FeatureEnum;
use App\Enums\HotlistStatusEnum;
use App\Facades\Analytics;
use App\Filament\Concerns\AppliesOttoPageFilters;
use App\Filament\Concerns\HasClickableFilter;
use App\Filament\Concerns\HasFilterActivityLogging;
use App\Filament\Concerns\HasFilterAnalytics;
use App\Filament\Concerns\HasHitMetricCards;
use App\Filament\Concerns\HasRetryQuery;
use App\Filament\Resources\HitsResource;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Widgets\Hits\HitMetricCards;
use App\Livewire\Concerns\InteractsWithOttoPageActions;
use App\Support\FlexSearch;
use App\Support\Hits\HitsListQuery;
use App\Support\Otto\OttoAccess;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Pennant\Feature;

class ListHits extends ListRecords
{
    use AppliesOttoPageFilters;
    use HasClickableFilter;
    use HasFilterActivityLogging;
    use HasFilterAnalytics;
    use HasHitMetricCards;
    use HasRetryQuery;
    use InteractsWithOttoPageActions;

    public bool $clusterView = false;

    protected array $loggedFilters = [
        'hotlist.vin' => 'vin',
        'scan.ocr' => 'plateNumber',
    ];

    protected ?string $analyticsPage = 'hits';

    protected array $trackedFilters = [
        'filters.hit_type' => 'type-filter',
        'filters.status' => 'status-filter',
        'filters.scan.ocr' => 'plate-filter',
        'filters.hotlist.vin' => 'vin-filter',
        'filters.hotlist.remote_order_id' => 'order-id-filter',
        'filters.dispositions' => 'dispositions-filter',
        'filters.kits' => 'kits-filter',
        'filters.clients' => 'clients-filter',
        'filters.lienholders' => 'lienholders-filter',
        'filters.cluster' => 'clustering-filter',
        'filters.polling' => 'polling-filter',
        'scan.geo.lat' => 'address-filter',
        'hits.created_at.date_toggles' => 'hit-date-filter',
        'scans.scanned_at.date_toggles' => 'scan-date-filter',
    ];

    protected static string $resource = HitsResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'hits');

        if (Feature::active(FeatureEnum::HIT_ADDRESS_DISTANCE->slug()) || session()->has('admin_impersonator')) {
            Analytics::track('distance-from-given', auth()->user(), ['action' => 'pro-feature'], page: 'hits');
        }
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            HitMetricCards::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function applyTableFilters(): void
    {
        $this->commitDateToggleBounds();

        parent::applyTableFilters();
    }

    private function commitDateToggleBounds(): void
    {
        foreach (['tableDeferredFilters', 'tableFilters'] as $property) {
            if (! is_array($this->{$property})) {
                continue;
            }

            foreach (['hits.created_at', 'scans.scanned_at'] as $filterName) {
                $filter = $this->getTable()->getFilter($filterName);

                if (! $filter instanceof DateTogglesFilter) {
                    continue;
                }

                $state = data_get($this->{$property}, $filterName);

                if (! is_array($state)) {
                    continue;
                }

                data_set($this->{$property}, $filterName, $filter->stateWithPresetBounds($state));
            }
        }
    }

    /**
     * The cards are computed HERE, on the page that owns the filter state, and handed to
     * the widget as plain reactive data — see {@see HasHitMetricCards} for why.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'hitMetricCards' => $this->getHitMetricCardData(),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $this->updateClusterViewState();

        $status = $this->getFilterState('filters.status') ?? HotlistStatusEnum::Open->value;
        $start = data_get($this->getFilterState('hits.created_at'), 'start');

        return HitsListQuery::baseQuery(auth()->user(), $start, $status);
    }

    public function shouldUseClickhouse(): bool
    {
        return HitsListQuery::shouldUseClickhouse();
    }

    private function isClickhouseQuery(Builder $query): bool
    {
        return $query->getModel() instanceof ClickhouseModel;
    }

    public function getVisitDateRange(): array
    {
        $dateState = $this->getFilterState('scans.scanned_at');
        $start = data_get($dateState, 'start');
        $end = data_get($dateState, 'end');

        if (! $start && ! $end) {
            $tz = auth()->user()->timezone;
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
        $ocr = $this->getFilterState('filters.scan.ocr');
        $lat = $this->getFilterState('scan.geo.lat');

        return empty($ocr) && $lat !== null;
    }

    public function getClusterFilterCoordinates(): array
    {
        return [
            'lat' => $this->getFilterState('scan.geo.lat'),
            'lon' => $this->getFilterState('scan.geo.lon'),
        ];
    }

    public function getSearchedPlate(): ?string
    {
        $plate = $this->getFilterState('filters.scan.ocr');

        return is_string($plate) && $plate !== '' ? $plate : null;
    }

    public function isFlexSearchEnabled(): bool
    {
        return (bool) $this->getFilterState('filters.flexsearch')
            && FlexSearch::isAvailable();
    }

    private function updateClusterViewState(): void
    {
        $plate = $this->getFilterState('filters.scan.ocr');
        $addressLat = $this->getFilterState('scan.geo.lat');
        $cluster = $this->getFilterState('filters.cluster');

        $this->clusterView = $cluster && ($plate !== null || $addressLat !== null);
    }

    protected function trackConditionalFilterAnalytics(): void
    {
        $ocr = data_get($this->tableFilters, 'filters.scan.ocr');
        $flex = data_get($this->tableFilters, 'filters.flexsearch');

        if ($this->isFilterValueActive($ocr) && $flex) {
            Analytics::track('flex-search', auth()->user(), ['action' => 'pro-feature'], page: 'hits');
        }
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $perPage = $this->getTableRecordsPerPage();

        // Remove the automatic secondary 'id' ordering that Filament adds
        $idColumn = $this->isClickhouseQuery($query) ? 'id' : 'hits.id';
        $query->getQuery()->orders = collect($query->getQuery()->orders ?? [])
            ->filter(fn ($order) => $order['column'] !== $idColumn)
            ->values()
            ->all();

        return $query->simplePaginate(
            $perPage === 'all' ? $this->getTable()->getDefaultPaginationPageOption() : $perPage
        );
    }

    protected function ottoPageType(): string
    {
        return ApplyHitSearchFiltersTool::PAGE_TYPE;
    }

    protected function ottoPageDescription(): string
    {
        return 'Hits';
    }

    /**
     * @return array<string, mixed>
     */
    protected function ottoPageContextState(): array
    {
        return array_filter([
            'plate' => $this->ottoFilterValue('filters.scan.ocr'),
            'vin' => $this->ottoFilterValue('filters.hotlist.vin'),
            'order_id' => $this->ottoFilterValue('filters.hotlist.remote_order_id'),
            'status' => $this->ottoFilterValue('filters.status'),
            'hit_type' => $this->ottoFilterValue('filters.hit_type'),
            'kits' => $this->ottoFilterValue('filters.kits'),
            'clients' => $this->ottoFilterValue('filters.clients'),
            'flex_search' => $this->ottoFilterValue('filters.flexsearch') ? 'on' : null,
            'hit_date' => $this->ottoDateFilterState('hits.created_at'),
            'scanned_date' => $this->ottoDateFilterState('scans.scanned_at'),
        ], fn ($value): bool => $value !== '' && $value !== [] && $value !== null);
    }

    protected function ottoPageActionAuthorized(): bool
    {
        $user = auth()->user();

        return OttoAccess::userCanUseOtto($user)
            && (bool) $user?->can(PermissionEnum::ViewAny_Hit->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyOttoPageAction(string $name, array $payload): void
    {
        if ($name !== ApplyHitSearchFiltersTool::ACTION) {
            return;
        }

        $this->applyOttoFilterPayload($payload, [
            'plate' => 'filters.scan.ocr',
            'vin' => 'filters.hotlist.vin',
            'order_id' => 'filters.hotlist.remote_order_id',
            'status' => 'filters.status',
            'hit_type' => 'filters.hit_type',
            'kits' => 'filters.kits',
            'clients' => 'filters.clients',
            'flex_search' => 'filters.flexsearch',
        ]);

        if (array_key_exists('time_range', $payload)) {
            $this->applyOttoDateFilter('hits.created_at', $payload['time_range']);
            $this->applyOttoDateFilter('scans.scanned_at', $payload['time_range'], supportsAdvanced: false);
        }

        $this->commitOttoTableFilters();
    }
}
