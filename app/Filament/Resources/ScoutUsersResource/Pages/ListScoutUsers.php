<?php

namespace App\Filament\Resources\ScoutUsersResource\Pages;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Filament\Concerns\HasProFeature;
use App\Filament\Resources\ScoutUsersResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListScoutUsers extends ListRecords
{
    use HasProFeature;

    protected static string $resource = ScoutUsersResource::class;

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'scout-users');
    }

    public function getFeatureName(): string|array
    {
        return FeatureEnum::CLARITY_SCOUT->slug();
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
