<?php

namespace App\Filament\Resources\AffiliateStatsResource\Pages;

use App\Facades\Analytics;
use App\Filament\Concerns\HasRetryQuery;
use App\Filament\Resources\AffiliateStatsResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListAffiliateStats extends ListRecords
{
    use HasRetryQuery;

    protected static string $resource = AffiliateStatsResource::class;

    protected static ?string $title = 'Affiliate Stats';

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'affiliate-stats');
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
