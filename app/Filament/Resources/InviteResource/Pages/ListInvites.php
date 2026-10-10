<?php

namespace App\Filament\Resources\InviteResource\Pages;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Filament\Concerns\HasFilterAnalytics;
use App\Filament\Concerns\HasProFeature;
use App\Filament\Resources\InviteResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListInvites extends ListRecords
{
    use HasFilterAnalytics;
    use HasProFeature;

    protected ?string $analyticsPage = 'invites';

    protected array $trackedFilters = [
        'filters.type' => 'type-filter',
        'filters.status' => 'status-filter',
        'filters.direction' => 'direction-filter',
    ];

    protected static string $resource = InviteResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'invites');
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getFeatureName(): string|array
    {
        return FeatureEnum::INVITES->slug();
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $perPage = $this->getTableRecordsPerPage();

        return $query->simplePaginate(
            $perPage === 'all' ? $this->getTable()->getDefaultPaginationPageOption() : $perPage
        );
    }
}
