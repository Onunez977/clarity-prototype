<?php

namespace App\Filament\Resources\GlKitsResource\Pages;

use App\Facades\Analytics;
use App\Filament\Resources\GlKitsResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListGlKits extends ListRecords
{
    protected static string $resource = GlKitsResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'gl-kits');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
