<?php

namespace App\Filament\Resources\ScanReportResource\Pages;

use App\Facades\Analytics;
use App\Filament\Resources\ScanReportResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListScanReports extends ListRecords
{
    protected static string $resource = ScanReportResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'scan-reports');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getTableRecordKey(mixed $record): string
    {
        return (string) ($record->id ?? $record->kit_id ?? uniqid());
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $perPage = $this->getTableRecordsPerPage();

        return $query->simplePaginate(
            $perPage === 'all' ? $this->getTable()->getDefaultPaginationPageOption() : $perPage
        );
    }
}
