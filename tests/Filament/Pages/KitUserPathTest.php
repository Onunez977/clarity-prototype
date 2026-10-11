<?php

namespace Tests\Filament\Pages;

use App\Filament\Pages\KitUserPath;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class KitUserPathTest extends TestCase
{
    #[Group('pages')]
    #[Group('kit-user-path')]
    public function test_render(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value)
        );

        $this->get(route('filament.app.pages.kit-user-path'))
            ->assertSuccessful();
    }

    #[Group('pages')]
    #[Group('kit-user-path')]
    public function test_doesnt_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        $this->get(route('filament.app.pages.kit-user-path'))
            ->assertForbidden();
    }

    #[Group('pages')]
    #[Group('kit-user-path')]
    public function test_filters_dispatch_events(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value)
        );

        Livewire::test(KitUserPath::class)
            ->set('date_toggles', '7_days')
            ->call('search')
            ->assertDispatched('reset-map');
    }

    #[Group('pages')]
    #[Group('kit-user-path')]
    public function test_three_heatmap_toggles_are_independent(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value, pro: true)
        );

        Livewire::test(KitUserPath::class)
            ->set('loadGeohash', true)
            ->assertDispatched('load-geohash', load: true)
            ->set('loadStagingGeohash', true)
            ->assertDispatched('load-staging-geohash', load: true)
            ->set('loadHitDensity', true)
            ->assertDispatched('load-hit-density', load: true)
            ->assertSet('loadGeohash', true)
            ->assertSet('loadStagingGeohash', true)
            ->assertSet('loadHitDensity', true);
    }

    #[Group('pages')]
    #[Group('kit-user-path')]
    public function test_staging_geohash_toggles_without_repo(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value, pro: true)
        );

        Livewire::test(KitUserPath::class)
            ->set('loadStagingGeohash', true)
            ->assertDispatched('load-staging-geohash', load: true)
            ->assertNotDispatched('load-geohash')
            ->assertSet('loadStagingGeohash', true)
            ->assertSet('loadGeohash', false);
    }

    #[Group('pages')]
    #[Group('kit-user-path')]
    public function test_tier_change_dispatches_to_matching_layer_only(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value, pro: true)
        );

        Livewire::test(KitUserPath::class)
            ->set('loadGeohash', true)
            ->set('loadStagingGeohash', true)
            ->set('heatmapTiers', ['tier_25_plus'])
            ->assertDispatched('load-geohash', load: true, tiers: ['tier_25_plus'])
            ->assertNotDispatched('load-staging-geohash', tiers: ['tier_25_plus'])
            ->set('stagingHeatmapTiers', ['tier_1'])
            ->assertDispatched('load-staging-geohash', load: true, tiers: ['tier_1']);
    }

    #[Group('pages')]
    #[Group('kit-user-path')]
    public function test_reset_filters_clears_staging_geohash(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value, pro: true)
        );

        Livewire::test(KitUserPath::class)
            ->set('loadStagingGeohash', true)
            ->assertSet('loadStagingGeohash', true)
            ->call('resetTableFiltersForm')
            ->assertSet('loadStagingGeohash', false);
    }

    #[Group('pages')]
    #[Group('kit-user-path')]
    public function test_centered_address_filter(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value)
        );

        $address = '123 Main St, Somewhere, FL 12345';

        cache()->put(
            'geocode-'.md5($address),
            [
                'lat' => 0,
                'lng' => 0,
            ],
        );

        Livewire::test(KitUserPath::class)
            ->set('address', $address)
            ->call('search')
            ->assertSet('centeredAddress', $address)
            ->assertDispatched('set-center', lat: 0, lng: 0)
            // Doesn't resend on same value
            ->set('address', $address)
            ->call('search')
            ->assertNotDispatched('set-center')
            // Reset
            ->set('address', null)
            ->call('search')
            ->assertSet('centeredAddress', null)
            ->assertDispatched('set-center');
    }
}
