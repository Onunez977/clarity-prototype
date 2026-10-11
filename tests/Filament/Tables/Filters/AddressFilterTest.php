<?php

namespace Tests\Filament\Tables\Filters;

use App\Filament\Resources\ScansResource\Pages\ListScans;
use App\Models\Scan;
use Clickbar\Magellan\Data\Geometries\Point;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\ResourceTestCase;

#[Group('filters')]
#[Group('tables')]
class AddressFilterTest extends ResourceTestCase
{
    protected mixed $userPermissions = PermissionEnum::ViewAny_SCAN->value;

    #[Test]
    public function it_returns_scans_within_radius(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'geo' => Point::makeGeodetic(40.7128, -74.0060),
            ]);

        $farScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'geo' => Point::makeGeodetic(34.0522, -118.2437),
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('tableFilters.geo.lat', 40.7128)
            ->set('tableFilters.geo.lon', -74.0060)
            ->assertCanSeeTableRecords([$scan])
            ->assertCanNotSeeTableRecords([$farScan]);
    }

    #[Test]
    public function it_excludes_scans_outside_radius(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'geo' => Point::makeGeodetic(40.7244, -74.0060),
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('tableFilters.geo.lat', 40.7128)
            ->set('tableFilters.geo.lon', -74.0060)
            ->assertCanNotSeeTableRecords([$scan]);
    }

    #[Test]
    public function it_returns_all_scans_when_no_coordinates(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'geo' => Point::makeGeodetic(40.7128, -74.0060),
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords([$scan]);
    }

    #[Test]
    public function it_handles_scans_at_boundary_distance(): void
    {
        $nearScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'geo' => Point::makeGeodetic(40.7137, -74.0060),
            ]);

        $farScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'geo' => Point::makeGeodetic(40.7155, -74.0060),
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('tableFilters.geo.lat', 40.7128)
            ->set('tableFilters.geo.lon', -74.0060)
            ->assertCanSeeTableRecords([$nearScan])
            ->assertCanNotSeeTableRecords([$farScan]);
    }
}
