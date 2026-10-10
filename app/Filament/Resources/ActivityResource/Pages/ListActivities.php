<?php

namespace App\Filament\Resources\ActivityResource\Pages;

use App\Facades\Analytics;
use App\Filament\Resources\ActivityResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'activity-log');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $perPage = $this->getTableRecordsPerPage();

        return $query->simplePaginate(
            $perPage === 'all' ? $this->getTable()->getDefaultPaginationPageOption() : $perPage
        );
    }
}
