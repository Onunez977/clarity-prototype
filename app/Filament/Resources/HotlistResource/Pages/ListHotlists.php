<?php

namespace App\Filament\Resources\HotlistResource\Pages;

use App\Ai\Tools\ApplyHotlistSearchFiltersTool;
use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Facades\Analytics;
use App\Filament\Concerns\AppliesOttoPageFilters;
use App\Filament\Concerns\HasClickableFilter;
use App\Filament\Concerns\HasFilterActivityLogging;
use App\Filament\Concerns\HasFilterAnalytics;
use App\Filament\Resources\HotlistResource;
use App\Livewire\Concerns\InteractsWithOttoPageActions;
use App\Support\Otto\OttoAccess;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class ListHotlists extends ListRecords
{
    use AppliesOttoPageFilters;
    use HasClickableFilter;
    use HasFilterActivityLogging;
    use HasFilterAnalytics;
    use InteractsWithOttoPageActions;

    protected array $loggedFilters = [
        'vin' => 'vin',
        'plate' => 'plateNumber',
    ];

    protected ?string $analyticsPage = 'hotlists';

    protected array $trackedFilters = [
        'filters.status' => 'status-filter',
        'filters.vin' => 'vin-filter',
        'filters.plate' => 'plate-filter',
        'filters.missing_plate' => 'missing-plate-filter',
        'filters.order_id' => 'order-id-filter',
        'filters.clients' => 'clients-filter',
    ];

    protected static string $resource = HotlistResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'hotlists');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    protected function getTableQuery(): Builder
    {
        return ClickhouseHotlist::query()
            ->forOrg(auth()->user()->org_id)
            ->select([
                'id', 'plate', 'status', 'vin',
                'vehicle_year', 'vehicle_make', 'vehicle_model',
                'remote_order_id', 'integration_id', 'created_at',
                'client_org_id', 'client_org_name',
                'lienholder_org_id', 'lienholder_org_name',
            ]);
    }

    private function getFilterState(string $key): mixed
    {
        return $this->getTable()->hasDeferredFilters()
            ? data_get($this, 'tableDeferredFilters.'.$key)
            : data_get($this, 'tableFilters.'.$key);
    }

    protected function trackConditionalFilterAnalytics(): void
    {
        $plate = data_get($this->tableFilters, 'filters.plate');
        $flex = data_get($this->tableFilters, 'filters.flexsearch');

        if ($this->isFilterValueActive($plate) && $flex) {
            Analytics::track('flex-search', auth()->user(), ['action' => 'pro-feature'], page: 'hotlists');
        }
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $perPage = $this->getTableRecordsPerPage();

        return $query->simplePaginate(
            $perPage === 'all' ? $this->getTable()->getDefaultPaginationPageOption() : $perPage
        );
    }

    protected function ottoPageType(): string
    {
        return ApplyHotlistSearchFiltersTool::PAGE_TYPE;
    }

    protected function ottoPageDescription(): string
    {
        return 'Hotlist assignments';
    }

    /**
     * @return array<string, mixed>
     */
    protected function ottoPageContextState(): array
    {
        return array_filter([
            'plate' => $this->ottoFilterValue('filters.plate'),
            'vin' => $this->ottoFilterValue('filters.vin'),
            'order_id' => $this->ottoFilterValue('filters.order_id'),
            'status' => $this->ottoFilterValue('filters.status'),
            'clients' => $this->ottoFilterValue('filters.clients'),
            'flex_search' => $this->ottoFilterValue('filters.flexsearch') ? 'on' : null,
            'missing_plate' => $this->ottoFilterValue('filters.missing_plate') ? 'on' : null,
        ], fn ($value): bool => $value !== '' && $value !== [] && $value !== null);
    }

    protected function ottoPageActionAuthorized(): bool
    {
        $user = auth()->user();

        return OttoAccess::userCanUseOtto($user)
            && (bool) $user?->can(PermissionEnum::ViewAny_Hotlist->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyOttoPageAction(string $name, array $payload): void
    {
        if ($name !== ApplyHotlistSearchFiltersTool::ACTION) {
            return;
        }

        $this->applyOttoFilterPayload($payload, [
            'plate' => 'filters.plate',
            'vin' => 'filters.vin',
            'order_id' => 'filters.order_id',
            'status' => 'filters.status',
            'clients' => 'filters.clients',
            'flex_search' => 'filters.flexsearch',
            'missing_plate' => 'filters.missing_plate',
        ]);

        $this->commitOttoTableFilters();
    }
}
