<?php

declare(strict_types=1);

namespace Tests\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Events\Scout\ZoneAssignmentsChanged;
use App\Events\Scout\ZoneUpdated;
use App\Filament\Actions\ZoneReassignAction;
use App\Filament\Pages\ManageZones;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zones\ZipBoundaryLookup;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Events\ZoneUpdatedTest;
use Tests\ResourceTestCase;

/**
 * Server-side coverage for the Zones admin page (DEV-1916 §3). The polygon
 * drawing itself is JS (covered by Agent QA); everything the Livewire backend
 * does — persistence, validation, no-scan color forcing, quick reassign,
 * org isolation, and gating — is exercised here.
 *
 * Real-time `ZoneUpdated` dispatch on save/delete is covered separately in
 * {@see ZoneUpdatedTest}.
 */
class ManageZonesTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::ADMIN->value;

    protected bool $pro = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
    }

    /** A simple square polygon definition. */
    private function polygonDefinition(): array
    {
        return [
            'polygons' => [[
                'type' => 'Polygon',
                'coordinates' => [[
                    [0, 0], [0, 1], [1, 1], [1, 0], [0, 0],
                ]],
            ]],
            'zip_codes' => [],
        ];
    }

    private function makeKitUser(?Org $org = null): User
    {
        $org ??= $this->user->org;
        $kit = Kit::factory()->forOrg($org)->create();

        return User::factory()->forOrg($org)->forKit($kit)->create();
    }

    private function makeZone(array $overrides = []): Zone
    {
        $zone = Zone::create(array_merge([
            'org_id' => $this->user->org_id,
            'name' => 'Existing Zone',
            'color' => '#2563eb',
            'definition' => $this->polygonDefinition(),
        ], $overrides));

        $zone->rebuildGeometryFromDefinition();

        return $zone;
    }

    // ---- TC-Z1: draw & save a polygon zone ---------------------------------

    #[Group('clarity-scout')]
    public function test_saves_a_polygon_zone_with_assigned_users(): void
    {
        $driver = $this->makeKitUser();

        Livewire::test(ManageZones::class)
            ->set('data.name', 'Downtown')
            ->set('data.color', '#0d9488')
            ->set('data.description', 'Core route')
            ->set('data.definition', $this->polygonDefinition())
            ->set('data.users', [$driver->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $zone = Zone::query()->where('name', 'Downtown')->first();

        $this->assertNotNull($zone);
        $this->assertSame($this->user->org_id, $zone->org_id);
        $this->assertSame('#0d9488', $zone->color);
        $this->assertFalse($zone->is_no_scan_zone);
        $this->assertEqualsCanonicalizing([$driver->id], $zone->users()->pluck('users.id')->all());
    }

    #[Group('clarity-scout')]
    public function test_save_rolls_back_and_does_not_broadcast_when_geometry_rebuild_fails(): void
    {
        Event::fake([ZoneAssignmentsChanged::class, ZoneUpdated::class]);

        $driver = $this->makeKitUser();

        try {
            Livewire::test(ManageZones::class)
                ->set('data.name', 'Broken Geometry')
                ->set('data.color', '#2563eb')
                // Ring of 4 positions clears the ring-count guard, but each
                // position has too few ordinates — ST_GeomFromGeoJSON rejects
                // it, so the rebuild throws inside the save transaction.
                ->set('data.definition', [
                    'polygons' => [[
                        'type' => 'Polygon',
                        'coordinates' => [[[0], [0], [0], [0]]],
                    ]],
                    'zip_codes' => [],
                ])
                ->set('data.users', [$driver->id])
                ->call('save');

            $this->fail('Expected the malformed-geometry rebuild to throw.');
        } catch (\Throwable) {
        }

        $this->assertSame(0, Zone::query()->where('name', 'Broken Geometry')->count());
        $this->assertSame(0, DB::connection('platform')->table('zone_user')->count());
        Event::assertNotDispatched(ZoneAssignmentsChanged::class);
        Event::assertNotDispatched(ZoneUpdated::class);
    }

    #[Group('clarity-scout')]
    public function test_save_persists_the_zone_and_assignment_when_broadcasting_throws(): void
    {
        // A Pusher/Reverb delivery failure must not roll back the save
        // (ShouldRescue on the events swallows it per-dispatch). Force a
        // throwing broadcaster deterministically, no network involved.
        Broadcast::extend('throwing', fn ($app) => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new \RuntimeException('broadcast boom');
            }
        });
        config(['broadcasting.connections.throwing' => ['driver' => 'throwing']]);
        config(['broadcasting.default' => 'throwing']);

        Exceptions::fake();

        $driver = $this->makeKitUser();

        Livewire::test(ManageZones::class)
            ->set('data.name', 'Resilient Zone')
            ->set('data.color', '#2563eb')
            ->set('data.definition', $this->polygonDefinition())
            ->set('data.users', [$driver->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $zone = Zone::query()->where('name', 'Resilient Zone')->first();

        $this->assertNotNull($zone);
        $this->assertEqualsCanonicalizing([$driver->id], $zone->users()->pluck('users.id')->all());

        Exceptions::assertReported(\RuntimeException::class);
    }

    // ---- TC-Z2: ZIP-only zone ----------------------------------------------

    /**
     * DEV-2252. This previously asserted the OPPOSITE — that a zip which resolves
     * to no Census boundary saves cleanly — which is exactly the defect: save()
     * ignored the rebuilt geometry and reported success over a NULL geom. It
     * passed only because tiger_data.zcta5_all is empty in the test DB.
     *
     * The resolvable-zip happy path cannot be pinned here. Rebuilding a zip geometry
     * runs ST_Transform, which must read public.spatial_ref_sys, and the `zod`
     * connection's test setup holds an AccessExclusiveLock on that table for the life
     * of every test (HasZodDatabase rebuilds PostGIS reference data inside the wrapping
     * transaction). Seeding the tiger row on `platform` therefore blocks indefinitely —
     * confirmed from pg_locks — while seeding it on `zod` leaves it invisible to the
     * `platform` connection that the rebuild reads. An unresolvable zip is unaffected:
     * ST_Union yields NULL and ST_Transform(NULL) short-circuits without touching
     * spatial_ref_sys, which is exactly the path this test exercises. The happy path is
     * covered by browser QA instead.
     */
    #[Group('clarity-scout')]
    public function test_zip_only_zone_with_no_census_boundary_is_rejected(): void
    {
        Livewire::test(ManageZones::class)
            ->set('data.name', 'Zip Zone')
            ->set('data.color', '#16a34a')
            ->set('data.definition', ['polygons' => [], 'zip_codes' => ['99999']])
            ->call('save')
            ->assertHasFormErrors(['definition'])
            // The form error alone is invisible — `definition` is a Hidden component with
            // no error slot. The notification IS the user-visible half of this fix, so it
            // is asserted rather than assumed.
            ->assertNotified('Zone not saved');

        $this->assertFalse(
            Zone::query()->where('name', 'Zip Zone')->exists(),
            'an unresolvable zip must not leave an invisible zone behind'
        );
    }

    /**
     * A zip that resolves to nothing must be reported even when a polygon makes the
     * geometry non-empty: ST_Union over ARRAY[...] drops NULL members, so checking
     * emptiness alone would accept the save and silently discard the zip.
     */
    #[Group('clarity-scout')]
    public function test_unresolvable_zip_is_rejected_even_when_a_polygon_supplies_geometry(): void
    {
        $definition = $this->polygonDefinition();
        $definition['zip_codes'] = ['99999'];

        Livewire::test(ManageZones::class)
            ->set('data.name', 'Polygon Plus Bad Zip')
            ->set('data.color', '#16a34a')
            ->set('data.definition', $definition)
            ->call('save')
            ->assertHasFormErrors(['definition']);

        $this->assertFalse(Zone::query()->where('name', 'Polygon Plus Bad Zip')->exists());
    }

    /**
     * Pins the `definition` dehydration (array_values + array_unique + strval): the
     * rejection names each unresolvable zip once, as a string, however it was supplied.
     */
    #[Group('clarity-scout')]
    public function test_definition_zip_codes_are_normalized_before_they_are_resolved(): void
    {
        $component = Livewire::test(ManageZones::class)
            ->set('data.name', 'Messy Zips')
            ->set('data.color', '#16a34a')
            ->set('data.definition', ['polygons' => [], 'zip_codes' => ['99999', '99999', 77777]])
            ->call('save')
            ->assertHasFormErrors(['definition']);

        $message = $component->errors()->first('data.definition');

        $this->assertSame(1, substr_count($message, '99999'), 'duplicate zips should be collapsed');
        $this->assertStringContainsString('77777', $message, 'an integer zip should be cast to a string');
    }

    // ---- DEV-2557: detached (formerly ZIP-derived) boundaries -------------
    //
    // DETACH itself runs in the browser (zone-manager.js): editing a ZIP-derived shape
    // moves it into definition.polygons and drops the ZIP from definition.zip_codes.
    // What is pinned HERE is only the SERVER half — that the post-detach definition shape
    // round-trips and that a dropped ZIP does not come back. None of these can fail from a
    // client-side regression.
    //
    // The client half is currently covered by agent/human QA only. That is a gap, not a
    // constraint: this repo DOES have a Playwright harness (playwright.config.ts,
    // tests/e2e/, `npm run test:e2e`) that could pin the click-selects / drag-detaches /
    // hole-preservation behaviour end to end. It is not wired up for this page and adding
    // it was out of scope for a hotfix — see the follow-up on DEV-2557.

    /** The definition the client sends once a ZIP-derived shape has been edited. */
    private function detachedDefinition(array $remainingZips = []): array
    {
        return [
            'polygons' => [[
                'type' => 'Polygon',
                'coordinates' => [[
                    [-96.80, 32.77], [-96.80, 32.80], [-96.76, 32.80], [-96.76, 32.77], [-96.80, 32.77],
                ]],
            ]],
            'zip_codes' => $remainingZips,
        ];
    }

    #[Group('clarity-scout')]
    public function test_detaching_the_only_zip_drops_it_and_stores_the_edited_shape(): void
    {
        $zone = $this->makeZone([
            'name' => 'Detach Only Zip',
            'definition' => ['polygons' => [], 'zip_codes' => ['75201']],
        ]);

        $this->assertSame(['75201'], $zone->fresh()->definition['zip_codes']);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->set('data.definition', $this->detachedDefinition())
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $zone->fresh()->definition;

        $this->assertSame([], $stored['zip_codes'], 'the detached ZIP must not survive the save');
        $this->assertCount(1, $stored['polygons']);
        $this->assertSame('Polygon', $stored['polygons'][0]['type']);
    }

    #[Group('clarity-scout')]
    public function test_a_detached_zip_does_not_reappear_on_reload(): void
    {
        $zone = $this->makeZone([
            'name' => 'Detach No Reappear',
            'definition' => ['polygons' => [], 'zip_codes' => ['75201']],
        ]);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->set('data.definition', $this->detachedDefinition())
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->assertSet('data.definition.zip_codes', [])
            ->assertSet('data.definition.polygons', $this->detachedDefinition()['polygons']);
    }

    /**
     * The kept ZIP has to resolve to reach the success path, and a real TIGER row cannot
     * be seeded here (see test_zip_only_zone_with_no_census_boundary_is_rejected), so the
     * boundary lookup is stubbed instead.
     */
    #[Group('clarity-scout')]
    public function test_detaching_one_zip_leaves_the_others_attached(): void
    {
        $this->mock(ZipBoundaryLookup::class)
            ->shouldReceive('unresolved')
            ->once()
            ->with(['75202'])
            ->andReturn([]);

        $zone = $this->makeZone([
            'name' => 'Detach One Of Two',
            'definition' => ['polygons' => [], 'zip_codes' => ['75201', '75202']],
        ]);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->set('data.definition', $this->detachedDefinition(['75202']))
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $zone->fresh()->definition;

        $this->assertSame(['75202'], $stored['zip_codes'], 'only the edited ZIP detaches');
        $this->assertCount(1, $stored['polygons']);
    }

    #[Group('clarity-scout')]
    public function test_a_detached_boundary_keeps_its_interior_rings(): void
    {
        // A donut ZCTA (an enclosed separate ZIP) detaches as a polygon WITH a hole. If the
        // hole were dropped the zone would silently cover territory the ZIP never did —
        // which inverts the meaning of an enclave on a no-scan zone.
        $withHole = [
            'type' => 'Polygon',
            'coordinates' => [
                [[-96.90, 32.70], [-96.90, 32.90], [-96.70, 32.90], [-96.70, 32.70], [-96.90, 32.70]],
                [[-96.85, 32.75], [-96.85, 32.80], [-96.80, 32.80], [-96.80, 32.75], [-96.85, 32.75]],
            ],
        ];

        $zone = $this->makeZone([
            'name' => 'Detach With Hole',
            'definition' => ['polygons' => [], 'zip_codes' => ['75201']],
        ]);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->set('data.definition', ['polygons' => [$withHole], 'zip_codes' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $zone->fresh()->definition;

        $this->assertCount(2, $stored['polygons'][0]['coordinates'], 'the interior ring must survive the save');
        $this->assertSame($withHole['coordinates'][1], $stored['polygons'][0]['coordinates'][1]);
    }

    // ---- TC-Z3: validation -------------------------------------------------

    #[Group('clarity-scout')]
    public function test_empty_geometry_is_rejected(): void
    {
        Livewire::test(ManageZones::class)
            ->set('data.name', 'No Geometry')
            ->set('data.color', '#2563eb')
            ->set('data.definition', ['polygons' => [], 'zip_codes' => []])
            ->call('save')
            ->assertHasFormErrors(['definition']);

        $this->assertFalse(Zone::query()->where('name', 'No Geometry')->exists());
    }

    #[Group('clarity-scout')]
    public function test_name_is_required(): void
    {
        Livewire::test(ManageZones::class)
            ->set('data.name', null)
            ->set('data.definition', $this->polygonDefinition())
            ->call('save')
            ->assertHasFormErrors(['name' => 'required']);
    }

    #[Group('clarity-scout')]
    public function test_name_is_capped_at_255_chars(): void
    {
        Livewire::test(ManageZones::class)
            ->set('data.name', str_repeat('a', 256))
            ->set('data.definition', $this->polygonDefinition())
            ->call('save')
            ->assertHasFormErrors(['name' => 'max']);
    }

    #[Group('clarity-scout')]
    public function test_description_is_capped_at_1000_chars(): void
    {
        Livewire::test(ManageZones::class)
            ->set('data.name', 'Long Description')
            ->set('data.description', str_repeat('a', 1001))
            ->set('data.definition', $this->polygonDefinition())
            ->call('save')
            ->assertHasFormErrors(['description' => 'max']);
    }

    // ---- TC-Z4: no-scan zone forces gray -----------------------------------

    #[Group('clarity-scout')]
    public function test_no_scan_zone_persists_gray_color(): void
    {
        Livewire::test(ManageZones::class)
            ->set('data.name', 'Off Limits')
            ->set('data.color', '#ea580c')
            ->set('data.is_no_scan_zone', true)
            ->set('data.definition', $this->polygonDefinition())
            ->call('save')
            ->assertHasNoFormErrors();

        $zone = Zone::query()->where('name', 'Off Limits')->first();

        $this->assertNotNull($zone);
        $this->assertTrue($zone->is_no_scan_zone);
        $this->assertSame(Zone::NO_SCAN_COLOR, $zone->color);
    }

    // ---- Preset palette coercion (DEV-1960) --------------------------------

    #[Group('clarity-scout')]
    public function test_legacy_out_of_palette_color_coerces_to_default_on_load(): void
    {
        $zone = $this->makeZone(['name' => 'Legacy Color', 'color' => '#abcdef']);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->assertSet('data.color', '#2563eb');
    }

    #[Group('clarity-scout')]
    public function test_saving_a_legacy_zone_persists_a_preset_color(): void
    {
        $zone = $this->makeZone(['name' => 'Legacy Save', 'color' => '#abcdef']);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('#2563eb', $zone->fresh()->color);
    }

    // ---- TC-Z5: assigned-user-must-have-kit --------------------------------

    #[Group('clarity-scout')]
    public function test_assigning_a_user_without_a_kit_is_rejected(): void
    {
        $userWithoutKit = User::factory()->forOrg($this->user->org)->create();

        Livewire::test(ManageZones::class)
            ->set('data.name', 'Bad Assignment')
            ->set('data.definition', $this->polygonDefinition())
            ->set('data.users', [$userWithoutKit->id])
            ->call('save')
            ->assertHasFormErrors(['users' => 'One or more assigned users do not have a kit.']);

        $this->assertFalse(Zone::query()->where('name', 'Bad Assignment')->exists());
    }

    // ---- TC-Z6: delete (event dispatch covered in ZoneUpdatedTest) ---------

    #[Group('clarity-scout')]
    public function test_delete_soft_deletes_the_zone(): void
    {
        $zone = $this->makeZone();

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->call('delete');

        $this->assertSoftDeleted($zone);
    }

    #[Group('clarity-scout')]
    public function test_delete_action_is_wired_and_mounts(): void
    {
        // Filament v5 renders modal copy via Alpine after mount, so the strings
        // aren't in the initial DOM — assertion via `assertSeeText` is flaky.
        // We assert the action is registered and mounts without error; the
        // copy itself is a static string in `ManageZones::deleteAction()`.
        $zone = $this->makeZone();

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->assertActionExists('delete')
            ->mountAction('delete');
    }

    // ---- TC-Z7: quick reassign (replace vs add) ----------------------------

    #[Group('clarity-scout')]
    public function test_quick_reassign_replace_swaps_the_driver_set(): void
    {
        $driverA = $this->makeKitUser();
        $driverB = $this->makeKitUser();

        $zone = $this->makeZone();
        $zone->users()->sync([$driverA->id]);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->callAction('quickReassign', data: [
                'mode' => 'replace',
                'users' => [$driverB->id],
            ]);

        $this->assertEqualsCanonicalizing([$driverB->id], $zone->fresh()->users()->pluck('users.id')->all());
    }

    #[Group('clarity-scout')]
    public function test_quick_reassign_add_merges_into_the_driver_set(): void
    {
        $driverA = $this->makeKitUser();
        $driverB = $this->makeKitUser();

        $zone = $this->makeZone();
        $zone->users()->sync([$driverA->id]);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->callAction('quickReassign', data: [
                'mode' => 'add',
                'users' => [$driverB->id],
            ]);

        $this->assertEqualsCanonicalizing(
            [$driverA->id, $driverB->id],
            $zone->fresh()->users()->pluck('users.id')->all()
        );
    }

    #[Group('clarity-scout')]
    public function test_quick_reassign_action_is_wired_and_mounts(): void
    {
        // See `test_delete_action_is_wired_and_mounts` for why we don't assert
        // on modal text. The Replace/Add behavior is covered above by the two
        // callAction tests; this only confirms the action wires up.
        $zone = $this->makeZone();

        Livewire::test(ManageZones::class)
            ->call('loadZone', $zone->id)
            ->assertActionExists('quickReassign')
            ->mountAction('quickReassign');
    }

    #[Group('clarity-scout')]
    public function test_map_page_has_a_switch_to_list_view_header_action(): void
    {
        Livewire::test(ManageZones::class)
            ->assertActionExists('switchToList');
    }

    // ---- Preload / isolation -----------------------------------------------

    #[Group('clarity-scout')]
    public function test_zone_query_param_preloads_the_zone(): void
    {
        $zone = $this->makeZone(['name' => 'Preloaded']);

        Livewire::withQueryParams(['zone' => $zone->id])
            ->test(ManageZones::class)
            ->assertSet('selectedZoneId', $zone->id)
            ->assertSet('data.name', 'Preloaded');
    }

    #[Group('clarity-scout')]
    public function test_out_of_org_zone_query_param_leaves_form_blank(): void
    {
        $otherZone = Zone::create([
            'org_id' => Org::factory()->create()->id,
            'name' => 'Other Org Zone',
            'color' => '#2563eb',
            'definition' => $this->polygonDefinition(),
        ]);

        Livewire::withQueryParams(['zone' => $otherZone->id])
            ->test(ManageZones::class)
            ->assertSet('selectedZoneId', null)
            ->assertSet('data.name', null);
    }

    #[Group('clarity-scout')]
    public function test_cannot_load_a_zone_from_another_org(): void
    {
        $otherZone = Zone::create([
            'org_id' => Org::factory()->create()->id,
            'name' => 'Other Org Zone',
            'color' => '#2563eb',
            'definition' => $this->polygonDefinition(),
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ManageZones::class)
            ->call('loadZone', $otherZone->id);
    }

    // ---- Gating ------------------------------------------------------------

    #[Group('clarity-scout')]
    public function test_can_access_requires_the_clarity_scout_flag(): void
    {
        $this->assertTrue(ManageZones::canAccess());

        config()->set('app.experimental.clarity_scout', false);
        Feature::flushCache();

        $this->assertFalse(ManageZones::canAccess());
    }

    #[Group('clarity-scout')]
    public function test_admin_impersonator_can_access_without_the_clarity_scout_flag(): void
    {
        config()->set('app.experimental.clarity_scout', false);
        Feature::flushCache();

        $this->assertFalse(ManageZones::canAccess());

        session()->put('admin_impersonator', $this->user->id);

        $this->assertTrue(ManageZones::canAccess());
    }

    #[Group('clarity-scout')]
    public function test_pro_tier_without_an_explicit_scout_grant_cannot_access(): void
    {
        // The map editor must be gated identically to the Zones list: a Clarity-Pro
        // org with no explicit clarity_scout grant cannot reach it (DEV-2115).
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::FLEX_SEARCH->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($admin->org_id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin);
        Feature::flushCache();

        $this->assertFalse(ManageZones::canAccess());
    }

    #[Group('clarity-scout')]
    public function test_lives_under_the_scout_navigation_group(): void
    {
        $this->assertSame('Scout', ManageZones::getNavigationGroup());
    }

    #[Group('clarity-scout')]
    public function test_driver_labels_are_scoped_to_the_current_org(): void
    {
        $org = $this->user->org;
        $ownKit = Kit::factory()->forOrg($org)->create();
        $own = User::factory()->forOrg($org)->forKit($ownKit)->create();

        $otherOrg = Org::factory()->create();
        $otherKit = Kit::factory()->forOrg($otherOrg)->create();
        $foreign = User::factory()->forOrg($otherOrg)->forKit($otherKit)->create();

        $labels = ZoneReassignAction::driverLabels([$own->id, $foreign->id]);

        $this->assertArrayHasKey($own->id, $labels);
        $this->assertArrayNotHasKey($foreign->id, $labels);
    }
}
