<?php

namespace App\Filament\Resources\MissedOpportunityResource\Pages;

use App\Clickhouse\Models\MissedOpportunity as ClickhouseMissedOpportunity;
use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Facades\Analytics;
use App\Filament\Concerns\HasClickableFilter;
use App\Filament\Concerns\HasFilterActivityLogging;
use App\Filament\Concerns\HasFilterAnalytics;
use App\Filament\Concerns\HasProFeature;
use App\Filament\Concerns\HasRetryQuery;
use App\Filament\Resources\MissedOpportunityResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListMissedOpportunities extends ListRecords
{
    use HasClickableFilter;
    use HasFilterActivityLogging;
    use HasFilterAnalytics;
    use HasProFeature;
    use HasRetryQuery;

    protected array $loggedFilters = [
        'hotlist.vin' => 'vin',
        'scan.ocr' => 'plateNumber',
    ];

    protected ?string $analyticsPage = 'missed-opportunities';

    protected array $trackedFilters = [
        'filters.hotlist.vin' => 'vin-filter',
        'filters.dispositions' => 'validation-filter',
        'filters.clients' => 'clients-filter',
        'filters.lienholders' => 'lienholders-filter',
        'hits.created_at.date_toggles' => 'date-filter',
    ];

    protected static string $resource = MissedOpportunityResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'missed-opportunities');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getFeatureName(): string|array
    {
        return FeatureEnum::MISSED_OPPORTUNITY->slug();
    }

    protected function getTableQuery(): Builder
    {
        $kitIds = auth()->user()->getUserKits()->pluck('id')->toArray();

        return ClickhouseMissedOpportunity::query()
            ->notNicb()
            ->whereIn('hit_type', [
                HitTypeEnum::Missed_Opportunity->value,
                HitTypeEnum::Missed_Opportunity_Historical->value,
            ])
            ->forOrg(auth()->user()->org_id)
            ->forKits($kitIds)
            ->select([
                'missed_opportunities.id', 'missed_opportunities.hotlist_id', 'missed_opportunities.scan_id',
                'missed_opportunities.hit_created_at as created_at',
                'missed_opportunities.ocr as scans_ocr',
                'missed_opportunities.scanned_at as scans_scanned_at',
                'missed_opportunities.camera_name as scans_camera_name',
                'missed_opportunities.scan_us_state as scans_us_state',
                'missed_opportunities.scan_guid as scans_guid',
                'missed_opportunities.kit_name', 'missed_opportunities.kit_org_id',
                'missed_opportunities.lat', 'missed_opportunities.lng',
                'missed_opportunities.address',
                'missed_opportunities.disposition',
                'missed_opportunities.hotlist_us_state', 'missed_opportunities.hotlist_plate', 'missed_opportunities.hotlist_status',
                'missed_opportunities.hotlist_vin',
                'missed_opportunities.vehicle_year as hotlist_vehicle_year',
                'missed_opportunities.vehicle_make as hotlist_vehicle_make',
                'missed_opportunities.vehicle_model as hotlist_vehicle_model',
                'missed_opportunities.hotlist_org_id',
                'missed_opportunities.remote_order_id as hotlist_remote_order_id',
                'missed_opportunities.integration_id as hotlist_integration_id',
                'missed_opportunities.client_org_id', 'missed_opportunities.client_org_name',
                'missed_opportunities.lienholder_org_id', 'missed_opportunities.lienholder_org_name',
                'missed_opportunities.close_date',
                'missed_opportunities.repo_date',
                'missed_opportunities.hit_type as mo_hit_type',
            ]);
    }

    private function getFilterState(string $key): mixed
    {
        return $this->getTable()->hasDeferredFilters()
            ? data_get($this, 'tableDeferredFilters.'.$key)
            : data_get($this, 'tableFilters.'.$key);
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $perPage = $this->getTableRecordsPerPage();

        return $query->simplePaginate(
            $perPage === 'all' ? $this->getTable()->getDefaultPaginationPageOption() : $perPage
        );
    }
}
