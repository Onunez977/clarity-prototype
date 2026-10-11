<?php

namespace Tests\Filament\Resources;

use App\Actions\Activity\Log\ScansSearch;
use App\Enums\DispositionEnum;
use App\Filament\Resources\ScansResource\Pages\ListScans;
use App\Jobs\LogFilterActivity;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Scan;
use App\Models\Shared;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\Queue;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\ResourceTestCase;

class ScansResourceTest extends ResourceTestCase
{
    protected mixed $userPermissions = PermissionEnum::ViewAny_SCAN->value;

    #[Group('resources')]
    #[Group('scans')]
    public function test_initial_load_shows_empty_state(): void
    {
        Scan::factory()->forOrg($this->user->org)->count(3)->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->assertSet('searchApplied', false)
            ->assertSeeText('Enter Search Criteria to View Scans');
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_can_render_table(): void
    {
        $scans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(3)
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords($scans);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(ListScans::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_can_render_table_columns(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanRenderTableColumn('ocr')
            ->assertSeeText($scan->ocr)
            ->assertCanRenderTableColumn('scanned_at')
            ->assertSee($scan->scanned_at->setTimezone($this->user->timezone)->format(config('app.date_format')))
            ->assertCanRenderTableColumn('geo')
            ->assertSeeText('Lat/Long: '.\implode(', ', $scan->coordinates))
            // ->assertCanRenderTableColumn('camera_name')
            // ->assertSeeText($scan->camera_name)
            ->assertCanRenderTableColumn('kit_name')
            ->assertSeeText($scan->kit->name);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_cannot_render_other_org(): void
    {
        $scans = Scan::factory()
            ->count(2)
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanNotSeeTableRecords($scans);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_date_filters(): void
    {
        $recentScans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create([
                'scanned_at' => now()->subHour(),
            ]);

        $olderScans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create([
                'scanned_at' => now()->subDays(45),
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('tableFilters.scanned_at.date_toggles', 'year')
            ->assertCanSeeTableRecords($recentScans->merge($olderScans))
            ->set('tableFilters.scanned_at.date_toggles', 'last_30d')
            ->assertCanSeeTableRecords($recentScans)
            ->assertCanNotSeeTableRecords($olderScans);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_no_default_date_filter(): void
    {
        $recentScans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create([
                'scanned_at' => now()->subHour(),
            ]);

        $oldScans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create([
                'scanned_at' => now()->subDays(90),
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords($recentScans->merge($oldScans));
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_plate_filter(): void
    {
        Queue::fake();

        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => '123456',
            ]);

        $otherScans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords($otherScans->merge([$scan]))
            ->set('tableFilters.filters.ocr', $scan->ocr)
            ->assertCanSeeTableRecords([$scan])
            ->assertCanNotSeeTableRecords($otherScans);

        Queue::assertPushed(LogFilterActivity::class);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_kits_filter(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $otherScans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords($otherScans->merge([$scan]))
            ->set('tableFilters.filters.kits.0', $scan->kit_id)
            ->assertCanSeeTableRecords([$scan])
            ->assertCanNotSeeTableRecords($otherScans);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_address_coordinates_filter(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'geo' => Point::makeGeodetic(1, 1),
            ]);

        $otherScans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords($otherScans->merge([$scan]))
            ->set('tableFilters.geo.lat', 1)
            ->set('tableFilters.geo.lon', 1)
            ->assertCanSeeTableRecords([$scan])
            ->assertCanNotSeeTableRecords($otherScans);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_scans_with_no_dispositions_show_for_new_scans(): void
    {
        $noMetaScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $emptyMetaScan = Scan::factory()
            ->forOrg($this->user->org)
            ->withMeta([
                'meta' => [],
            ])
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords([$noMetaScan])
            ->assertCanSeeTableRecords([$emptyMetaScan]);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_invalid_scans_toggle(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $invalidScan = Scan::factory()
            ->forOrg($this->user->org)
            ->withMeta([
                'meta' => [
                    'disposition' => DispositionEnum::INVALID_SCAN->value,
                ],
            ])
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.invalid', false)
            ->assertCanSeeTableRecords([$scan])
            ->assertCanNotSeeTableRecords([$invalidScan])
            ->set('tableFilters.filters.invalid', true)
            ->assertCanNotSeeTableRecords([$scan])
            ->assertCanSeeTableRecords([$invalidScan]);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_can_search_for_shared_scans(): void
    {
        $this->makeUserPro($this->user);

        $orgScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $sharedScan = Scan::factory()
            ->create();

        Shared::factory()
            ->create([
                'shared_id' => $sharedScan->kit_id,
                'shared_type' => Kit::class,
                'shared_with_id' => $orgScan->kit->org_id,
                'shared_with_type' => Org::class,
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords([$orgScan])
            ->assertCanNotSeeTableRecords([$sharedScan])
            ->assertDontSee('data-shared-scan-id="'.$orgScan->id.'"', false)
            ->set('tableFilters.filters.ocr', $sharedScan->ocr)
            ->assertCanSeeTableRecords([$sharedScan])
            ->assertSee('data-shared-scan-id="'.$sharedScan->id.'"', false);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_cannot_search_for_shared_scans_without_pro(): void
    {
        $orgScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $sharedScan = Scan::factory()
            ->create();

        Shared::factory()
            ->create([
                'shared_id' => $sharedScan->kit_id,
                'shared_type' => Kit::class,
                'shared_with_id' => $orgScan->kit->org_id,
                'shared_with_type' => Org::class,
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords([$orgScan])
            ->assertCanNotSeeTableRecords([$sharedScan])
            ->assertDontSee('data-shared-scan-id="'.$orgScan->id.'"', false)
            ->set('tableFilters.filters.ocr', $sharedScan->ocr)
            // Still can't see without Pro
            ->assertCanNotSeeTableRecords([$sharedScan])
            ->assertDontSee('data-shared-scan-id="'.$sharedScan->id.'"', false);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_cannot_see_shared_scans_without_searching(): void
    {
        $orgScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $sharedScan = Scan::factory()
            ->create();

        Shared::factory()
            ->create([
                'shared_id' => $sharedScan->kit_id,
                'shared_type' => Kit::class,
                'shared_with_id' => $orgScan->kit->org_id,
                'shared_with_type' => Org::class,
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords([$orgScan])
            ->assertCanNotSeeTableRecords([$sharedScan])
            ->assertDontSee('data-shared-scan-id="'.$orgScan->id.'"', false)
            ->assertDontSee('data-shared-scan-id="'.$sharedScan->id.'"', false);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_cannot_see_expired_shared_scans(): void
    {
        $orgScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create();

        $sharedScan = Scan::factory()
            ->create();

        Shared::factory()
            ->create([
                'shared_id' => $orgScan->kit_id,
                'shared_type' => Kit::class,
                'shared_with_id' => $sharedScan->kit->org_id,
                'shared_with_type' => Org::class,
                'expires_at' => now()->subDay(),
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords([$orgScan])
            ->assertCanNotSeeTableRecords([$sharedScan])
            ->assertDontSee('data-shared-scan-id="'.$orgScan->id.'"', false)
            ->set('tableFilters.filters.ocr', $sharedScan->ocr)
            ->assertCanNotSeeTableRecords([$sharedScan])
            ->assertDontSee('data-shared-scan-id="'.$sharedScan->id.'"', false);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_simple_pagination(): void
    {
        $scans = Scan::factory()
            ->forOrg($this->user->org)
            ->count(3)
            ->create();

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertDontSeeText('Showing 1 to 3 of '.$scans->count().' results');
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_logs_plate_filter_activity(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => '123456',
            ]);

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.ocr', $scan->ocr)
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => ScansSearch::$log_name,
            'description' => $this->user->email.' searched for scans by Plate',
        ]);

        $activity = Activity::query()
            ->where('log_name', ScansSearch::$log_name)
            ->where('description', $this->user->email.' searched for scans by Plate')
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals([$scan->ocr], $activity->properties['plateNumber']);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_logs_filter_activity_from_request(): void
    {
        session()->put('impersonator', $this->user->only('id', 'email'));

        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => '123456',
            ]);

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.ocr', $scan->ocr)
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => ScansSearch::$log_name,
            'description' => $this->user->email.' searched for scans by Plate',
        ]);

        $activity = Activity::query()
            ->where('log_name', ScansSearch::$log_name)
            ->where('description', $this->user->email.' searched for scans by Plate')
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals([$scan->ocr], $activity->properties['plateNumber']);

        $this->assertArrayHasKey('_request', $activity->properties);
        $this->assertArrayHasKey('clientIp', $activity->properties['_request']);
        $this->assertArrayHasKey('userAgent', $activity->properties['_request']);
        $this->assertArrayHasKey('_impersonator', $activity->properties);
        $this->assertEquals($this->user->only('id', 'email'), $activity->properties['_impersonator']);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_can_cluster_scans_on_plate_search(): void
    {
        $this->makeUserPro($this->user);

        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => '123456',
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords([$scan])
            ->assertSet('clusterView', false)
            ->set('tableFilters.filters.ocr', $scan->ocr)
            ->assertCanSeeTableRecords([$scan])
            ->assertSet('clusterView', true);
    }

    #[Group('resources')]
    #[Group('scans')]
    public function test_cannot_cluster_scans_without_pro(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => '123456',
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->set('searchApplied', true)
            ->assertCanSeeTableRecords([$scan])
            ->assertSet('clusterView', false)
            ->set('tableFilters.filters.ocr', $scan->ocr)
            ->assertCanSeeTableRecords([$scan])
            ->assertSet('clusterView', false);
    }
}
