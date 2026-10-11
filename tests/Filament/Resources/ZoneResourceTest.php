<?php

declare(strict_types=1);

namespace Tests\Filament\Resources;

use App\Enums\FeatureEnum;
use App\Filament\Actions\ZoneKmlImportAction;
use App\Filament\Pages\ManageZones;
use App\Filament\Resources\ZoneResource;
use App\Filament\Resources\ZoneResource\Pages\ListZones;
use App\Jobs\ImportZonesFromKml;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Events\ZoneListBroadcastsTest;
use Tests\ResourceTestCase;

/**
 * Coverage for the Zones admin list (DEV-2104) — gating, columns, filters,
 * sorting, org isolation, the map-editor deep-links, and N+1 freedom. The
 * list-side broadcast parity (quick edit / delete / restore / bulk) lives in
 * {@see ZoneListBroadcastsTest}.
 */
#[Group('clarity-scout')]
class ZoneResourceTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::ADMIN->value;

    protected bool $pro = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
    }

    private function makeKitUser(?Org $org = null): User
    {
        $org ??= $this->user->org;
        $kit = Kit::factory()->forOrg($org)->create();

        return User::factory()->forOrg($org)->forKit($kit)->create();
    }

    // ---- Gating (AC1) ------------------------------------------------------

    public function test_admin_with_flag_can_render_the_list(): void
    {
        $zones = Zone::factory()->forOrg($this->user->org)->count(3)->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($zones);
    }

    public function test_access_requires_the_clarity_scout_flag(): void
    {
        $this->assertTrue(ZoneResource::canAccess());

        config()->set('app.experimental.clarity_scout', false);
        Feature::flushCache();

        $this->assertFalse(ZoneResource::canAccess());

        Livewire::test(ListZones::class)->assertForbidden();
    }

    public function test_non_admin_cannot_access(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::USER->value, pro: true));

        $this->assertFalse(ZoneResource::canAccess());

        Livewire::test(ListZones::class)->assertForbidden();
    }

    public function test_admin_impersonator_can_access_without_the_clarity_scout_flag(): void
    {
        config()->set('app.experimental.clarity_scout', false);
        Feature::flushCache();

        $this->assertFalse(ZoneResource::canAccess());

        session()->put('admin_impersonator', $this->user->id);

        $this->assertTrue(ZoneResource::canAccess());
    }

    public function test_pro_tier_without_an_explicit_scout_grant_cannot_access(): void
    {
        // A Clarity-Pro org that was never granted clarity_scout individually:
        // tier membership alone must no longer grant Scout (DEV-2115 — an
        // explicit per-org grant is required). makeOrgPro with a single, unrelated
        // feature gives platform_repo_pro=true (Clarity Pro tier via back-compat)
        // WITHOUT the platform_repo_pro_clarity_scout flag.
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::FLEX_SEARCH->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($admin->org_id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin);
        Feature::flushCache();

        $this->assertFalse($admin->isProEnabled(FeatureEnum::CLARITY_SCOUT));
        $this->assertFalse(ZoneResource::canAccess());

        Livewire::test(ListZones::class)->assertForbidden();
    }

    public function test_lives_under_the_scout_navigation_group(): void
    {
        $this->assertSame('Scout', ZoneResource::getNavigationGroup());
    }

    // ---- Columns (AC2) -----------------------------------------------------

    public function test_renders_all_columns(): void
    {
        $driver = $this->makeKitUser();
        Zone::factory()->forOrg($this->user->org)->assignedTo([$driver->id])->create();

        $component = Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertCanRenderTableColumn('color')
            ->assertCanRenderTableColumn('name')
            ->assertCanRenderTableColumn('is_no_scan_zone')
            ->assertCanRenderTableColumn('users.name')
            ->assertCanRenderTableColumn('users_count')
            ->assertCanRenderTableColumn('created_at');

        // Toggle the hidden-by-default columns on — their cells only render once enabled.
        $state = array_map(function (array $item): array {
            $item['isToggled'] = true;

            return $item;
        }, $component->instance()->getDefaultTableColumnState());

        $component->call('applyTableColumnManager', $state)
            ->assertCanRenderTableColumn('description')
            ->assertCanRenderTableColumn('creator.name')
            ->assertCanRenderTableColumn('updated_at');
    }

    // ---- Filters (AC2/AC3) -------------------------------------------------

    public function test_assigned_driver_filter_isolates_that_drivers_zones(): void
    {
        $driverA = $this->makeKitUser();
        $driverB = $this->makeKitUser();

        $zoneA = Zone::factory()->forOrg($this->user->org)->assignedTo([$driverA->id])->create();
        $zoneB = Zone::factory()->forOrg($this->user->org)->assignedTo([$driverB->id])->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$zoneA, $zoneB])
            ->filterTable('assigned_driver', ['driver' => [$driverA->id]])
            ->assertCanSeeTableRecords([$zoneA])
            ->assertCanNotSeeTableRecords([$zoneB]);
    }

    public function test_unassigned_only_filter_shows_coverage_gaps(): void
    {
        $driver = $this->makeKitUser();

        $assigned = Zone::factory()->forOrg($this->user->org)->assignedTo([$driver->id])->create();
        $unassigned = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->filterTable('unassigned', true)
            ->assertCanSeeTableRecords([$unassigned])
            ->assertCanNotSeeTableRecords([$assigned]);
    }

    public function test_no_scan_filter(): void
    {
        $noScan = Zone::factory()->forOrg($this->user->org)->noScan()->create();
        $normal = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->filterTable('is_no_scan_zone', true)
            ->assertCanSeeTableRecords([$noScan])
            ->assertCanNotSeeTableRecords([$normal]);
    }

    public function test_color_filter(): void
    {
        $teal = Zone::factory()->forOrg($this->user->org)->state(['color' => '#0d9488'])->create();
        $blue = Zone::factory()->forOrg($this->user->org)->state(['color' => Zone::DEFAULT_COLOR])->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->filterTable('color', '#0d9488')
            ->assertCanSeeTableRecords([$teal])
            ->assertCanNotSeeTableRecords([$blue]);
    }

    public function test_trashed_filter(): void
    {
        $active = Zone::factory()->forOrg($this->user->org)->create();
        $trashed = Zone::factory()->forOrg($this->user->org)->trashed()->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$trashed])
            ->filterTable('trashed', 1)
            ->assertCanSeeTableRecords([$active, $trashed])
            ->filterTable('trashed', 0)
            ->assertCanSeeTableRecords([$trashed])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_name_search(): void
    {
        $downtown = Zone::factory()->forOrg($this->user->org)->state(['name' => 'Downtown Loop'])->create();
        $suburb = Zone::factory()->forOrg($this->user->org)->state(['name' => 'Suburb North'])->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->searchTable('Downtown')
            ->assertCanSeeTableRecords([$downtown])
            ->assertCanNotSeeTableRecords([$suburb]);
    }

    public function test_default_sort_is_name_ascending_and_driver_count_is_sortable(): void
    {
        $gamma = Zone::factory()->forOrg($this->user->org)->state(['name' => 'Gamma'])->create();
        $alpha = Zone::factory()->forOrg($this->user->org)->state(['name' => 'Alpha'])->create();
        $beta = Zone::factory()->forOrg($this->user->org)->state(['name' => 'Beta'])->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$alpha, $beta, $gamma], inOrder: true)
            ->sortTable('users_count')
            ->assertSuccessful();
    }

    // ---- Org scoping (AC9) -------------------------------------------------

    public function test_zones_from_other_orgs_are_hidden(): void
    {
        $mine = Zone::factory()->forOrg($this->user->org)->create();
        $foreign = Zone::factory()->forOrg(Org::factory()->create())->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    // ---- Edit / Create deep-links (AC4) ------------------------------------

    public function test_edit_action_links_to_the_map_editor_for_that_zone(): void
    {
        $zone = Zone::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertTableActionHasUrl('edit', ManageZones::getUrl(['zone' => $zone->id]), record: $zone);
    }

    public function test_create_action_links_to_a_blank_map_editor(): void
    {
        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertTableActionExists('create')
            ->assertTableActionHasUrl('create', ManageZones::getUrl());
    }

    public function test_list_has_a_switch_to_map_view_header_action(): void
    {
        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertActionExists('switchToMap');
    }

    // ---- N+1 freedom (AC9) -------------------------------------------------

    public function test_list_does_not_issue_per_row_queries(): void
    {
        $drivers = collect(range(1, 2))->map(fn () => $this->makeKitUser())->pluck('id')->all();

        Zone::factory()->forOrg($this->user->org)->assignedTo($drivers)->create();
        $baseline = $this->countQueriesForListRender();

        Zone::factory()->forOrg($this->user->org)->assignedTo($drivers)->count(5)->create();
        $scaled = $this->countQueriesForListRender();

        $this->assertLessThanOrEqual(
            $baseline + 1,
            $scaled,
            "Zones list issued {$scaled} queries for 6 rows vs {$baseline} for 1 — N+1 regression.",
        );
    }

    private function countQueriesForListRender(): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        Livewire::test(ListZones::class)->assertSuccessful();

        return $count;
    }

    // ---- Import KML (DEV-2201) ---------------------------------------------

    private const VALID_KML = <<<'KML'
        <?xml version="1.0" encoding="UTF-8"?>
        <kml xmlns="http://www.opengis.net/kml/2.2">
          <Document>
            <Placemark>
              <name>Warehouse District</name>
              <Polygon>
                <outerBoundaryIs>
                  <LinearRing>
                    <coordinates>-96.80,32.80,0 -96.79,32.80,0 -96.79,32.81,0 -96.80,32.81,0 -96.80,32.80,0</coordinates>
                  </LinearRing>
                </outerBoundaryIs>
              </Polygon>
            </Placemark>
            <Placemark>
              <name>Donut Zone</name>
              <Polygon>
                <outerBoundaryIs>
                  <LinearRing>
                    <coordinates>-96.90,32.90,0 -96.80,32.90,0 -96.80,33.00,0 -96.90,33.00,0 -96.90,32.90,0</coordinates>
                  </LinearRing>
                </outerBoundaryIs>
                <innerBoundaryIs>
                  <LinearRing>
                    <coordinates>-96.88,32.92,0 -96.82,32.92,0 -96.82,32.98,0 -96.88,32.98,0 -96.88,32.92,0</coordinates>
                  </LinearRing>
                </innerBoundaryIs>
              </Polygon>
            </Placemark>
            <Placemark>
              <Point>
                <coordinates>-96.8,32.8,0</coordinates>
              </Point>
            </Placemark>
            <Placemark>
              <name>Too Small</name>
              <Polygon>
                <outerBoundaryIs>
                  <LinearRing>
                    <coordinates>-96.5,32.5,0 -96.49,32.5,0</coordinates>
                  </LinearRing>
                </outerBoundaryIs>
              </Polygon>
            </Placemark>
          </Document>
        </kml>
        KML;

    // AC1: same canCreate() gate as "New Zone" — page-level forbidden coverage
    // (flag off / non-admin) already lives above; parity here only needs the
    // positive cases (default admin, and via admin_impersonator).

    public function test_import_kml_action_is_visible_for_an_admin_with_the_scout_flag(): void
    {
        Livewire::test(ListZones::class)
            ->assertActionVisible(TestAction::make('importKml')->table());
    }

    public function test_import_kml_action_is_visible_for_an_admin_impersonator_without_the_scout_flag(): void
    {
        config()->set('app.experimental.clarity_scout', false);
        Feature::flushCache();
        session()->put('admin_impersonator', $this->user->id);

        Livewire::test(ListZones::class)
            ->assertSuccessful()
            ->assertActionVisible(TestAction::make('importKml')->table());
    }

    // AC2: the only .kml gate is the server-side extensions:kml rule (client
    // filename extension, not sniffed MIME). A content-MIME filter can't be
    // trusted for .kml (empty/unknown browser MIME), so a .xml is rejected here
    // solely by that rule.

    public function test_a_non_kml_upload_is_rejected(): void
    {
        Livewire::test(ListZones::class)
            ->callAction(TestAction::make('importKml')->table(), data: [
                'file' => UploadedFile::fake()->createWithContent('shapes.xml', '<xml/>'),
            ])
            ->assertHasActionErrors(['file']);

        $this->assertSame(0, Zone::query()->forOrg($this->user->org_id)->count());
    }

    // AC3/AC4/AC5/AC6: one zone per valid polygon (holes preserved), the
    // non-polygon and undersized-ring placemarks are skipped and counted, and
    // no empty/geometry-less zone rows are left behind.

    public function test_importing_a_kml_creates_one_zone_per_valid_polygon_and_reports_accurate_counts(): void
    {
        Livewire::test(ListZones::class)
            ->callAction(TestAction::make('importKml')->table(), data: [
                'file' => UploadedFile::fake()->createWithContent('zones.kml', self::VALID_KML),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Imported 2 zones, skipped 2');

        $zones = Zone::query()
            ->forOrg($this->user->org_id)
            ->withGeoJson()
            ->orderBy('name')
            ->get();

        $this->assertCount(2, $zones);
        $this->assertSame(['Donut Zone', 'Warehouse District'], $zones->pluck('name')->all());

        $zones->each(fn (Zone $zone) => $this->assertNotNull($zone->geom_geojson));

        $donut = $zones->firstWhere('name', 'Donut Zone');
        $this->assertCount(2, $donut->definition['polygons'][0]['coordinates']);

        $plain = $zones->firstWhere('name', 'Warehouse District');
        $this->assertCount(1, $plain->definition['polygons'][0]['coordinates']);
        $this->assertSame(Zone::DEFAULT_COLOR, $plain->color);
        $this->assertFalse($plain->is_no_scan_zone);
        $this->assertSame($this->user->id, $plain->created_by_user_id);
    }

    // AC5: a count-valid ring (>=4 positions) that is collinear/zero-area must
    // never be persisted as an empty zone — the per-placemark transaction
    // rolls back and it's counted as skipped instead.

    private const DEGENERATE_KML = <<<'KML'
        <?xml version="1.0" encoding="UTF-8"?>
        <kml xmlns="http://www.opengis.net/kml/2.2">
          <Document>
            <Placemark>
              <name>Degenerate</name>
              <Polygon>
                <outerBoundaryIs>
                  <LinearRing>
                    <coordinates>-96.50,32.50,0 -96.40,32.50,0 -96.30,32.50,0 -96.50,32.50,0</coordinates>
                  </LinearRing>
                </outerBoundaryIs>
              </Polygon>
            </Placemark>
          </Document>
        </kml>
        KML;

    public function test_importing_a_kml_with_a_collinear_zero_area_ring_creates_no_zone(): void
    {
        Livewire::test(ListZones::class)
            ->callAction(TestAction::make('importKml')->table(), data: [
                'file' => UploadedFile::fake()->createWithContent('degenerate.kml', self::DEGENERATE_KML),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Imported 0 zones, skipped 1');

        $this->assertSame(0, Zone::query()->forOrg($this->user->org_id)->count());
        $this->assertDatabaseMissing('zones', ['name' => 'Degenerate'], 'platform');
    }

    public function test_importing_a_kml_only_creates_zones_for_the_acting_users_org(): void
    {
        $foreignOrg = Org::factory()->create();

        Livewire::test(ListZones::class)
            ->callAction(TestAction::make('importKml')->table(), data: [
                'file' => UploadedFile::fake()->createWithContent('zones.kml', self::VALID_KML),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(2, Zone::query()->forOrg($this->user->org_id)->count());
        $this->assertSame(0, Zone::query()->forOrg($foreignOrg->id)->count());
    }

    // Large uploads are queued instead of imported inline, with async
    // (emailed) feedback instead of an immediate summary notification.

    public function test_large_uploads_are_queued_instead_of_imported_inline(): void
    {
        Bus::fake();
        $disk = config('filesystems.default');
        Storage::fake($disk);

        $largeKilobytes = (int) ceil((ZoneKmlImportAction::QUEUE_THRESHOLD_BYTES + 1024) / 1024);

        Livewire::test(ListZones::class)
            ->callAction(TestAction::make('importKml')->table(), data: [
                'file' => UploadedFile::fake()->create('large.kml', $largeKilobytes),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified("Import started — you'll be emailed when it finishes");

        Bus::assertDispatched(
            ImportZonesFromKml::class,
            fn (ImportZonesFromKml $job): bool => $job->orgId === $this->user->org_id
                && $job->userId === $this->user->id
                && $job->disk === $disk
        );

        $this->assertSame(0, Zone::query()->forOrg($this->user->org_id)->count());
    }
}
