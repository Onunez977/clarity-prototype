<?php

declare(strict_types=1);

namespace App\Filament\Resources\ZoneResource\Pages;

use App\Enums\ScoutAnalyticsPage;
use App\Filament\Concerns\HasFilterActivityLogging;
use App\Filament\Concerns\HasFilterAnalytics;
use App\Filament\Pages\ManageZones;
use App\Filament\Resources\ZoneResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListZones extends ListRecords
{
    use HasFilterActivityLogging;
    use HasFilterAnalytics;

    protected static string $resource = ZoneResource::class;

    protected array $loggedFilters = [
        'assigned_driver.driver' => 'assignedDriver',
        'color.value' => 'color',
    ];

    protected ?string $analyticsPage = ScoutAnalyticsPage::Zones->value;

    protected array $trackedFilters = [
        'assigned_driver.driver' => 'driver-filter',
        'unassigned.value' => 'assignment-filter',
        'is_no_scan_zone.value' => 'no-scan-filter',
        'color.value' => 'color-filter',
    ];

    /**
     * The list is the alternate (inventory) view; this switches to the full map
     * — every zone visible, any one editable — which stays the map-first editing
     * surface. The map links back via its own "Switch to list view" action.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('switchToMap')
                ->label('Map view')
                ->icon('heroicon-o-map')
                ->color('gray')
                ->url(fn (): string => ManageZones::getUrl()),
        ];
    }
}
