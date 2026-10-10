<?php

namespace App\Filament\Client\Resources\ClientSearchScansResource\Pages;

use App\Facades\Analytics;
use App\Filament\Client\Resources\ClientSearchScansResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListClientSearchScans extends ListRecords
{
    protected static string $resource = ClientSearchScansResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'client-search-scans');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public static function canAccess(array $parameters = []): bool
    {
        return (auth()->user()?->canAccessPanel(Filament::getPanel('client')) ?? false)
            || session()->has('admin_impersonator');
    }
}
