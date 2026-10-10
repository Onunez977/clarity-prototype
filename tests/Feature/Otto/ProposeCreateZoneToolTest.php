<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Ai\PageActionChange;
use App\Ai\Tools\ProposeCreateZoneTool;
use App\Ai\Tools\ProposeUpdateZoneTool;
use App\Ai\Tools\ToolRegistry;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Enums\FeatureEnum;
use App\Enums\ScoutAnalyticsPage;
use App\Events\OttoInvestigationUpdated;
use App\Events\OttoPageActionProposed;
use App\Events\OttoWidgetCreated;
use App\Models\AgentConversation;
use App\Models\Investigation;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zones\PersistZone;
use App\Services\Zones\ZipCodeLookup;
use App\Services\Zones\ZoneGeometryRejected;
use App\Support\Otto\OttoScoutAccess;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Ai\Tools\Request;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProposeCreateZoneToolTest extends TestCase
{
    private Investigation $investigation;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            OttoPageActionProposed::class,
            OttoWidgetCreated::class,
            OttoInvestigationUpdated::class,
        ]);
        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Permission::findOrCreate('use-otto', 'web');
    }

    public function test_is_registered(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertContains(ProposeCreateZoneTool::class, $registry->registered());
        $this->assertContains(ProposeUpdateZoneTool::class, $registry->registered());
    }

    public function test_soft_fails_when_scout_is_unavailable(): void
    {
        $result = (string) $this->makeTool($this->createAdminWithoutScout())->handle(new Request([
            'name' => 'Downtown',
            'zip_codes' => ['75201'],
        ]));

        $this->assertSame(OttoScoutAccess::ZONE_NEEDS_SCOUT, $result);
        $this->assertFalse($this->investigation->hasProposedPageActions());
    }

    public function test_soft_fails_when_user_is_not_admin(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $user = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $user->assignRole(RoleEnum::USER->value);

        $result = strtolower((string) $this->makeTool($user)->handle(new Request([
            'name' => 'Downtown',
            'zip_codes' => ['75201'],
        ])));

        $this->assertStringContainsString('organization admins', $result);
        $this->assertFalse($this->investigation->hasProposedPageActions());
    }

    public function test_allows_propose_for_admin_impersonator_without_scout(): void
    {
        $admin = $this->createAdminWithoutScout();
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);
        $this->mockZips(['75201']);

        $result = (string) $this->makeTool($admin)->handle(new Request([
            'name' => 'Downtown',
            'zip_codes' => ['75201'],
        ]));

        $this->assertStringContainsString('SUGGESTION', $result);
        $this->assertTrue($this->investigation->hasProposedPageActions());
    }

    public function test_asks_for_a_name_before_proposing(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips(['75201']);

        $result = (string) $this->makeTool($admin)->handle(new Request([
            'zip_codes' => ['75201'],
        ]));

        $this->assertStringContainsString('zone name is required', strtolower($result));
        $this->assertFalse($this->investigation->hasProposedPageActions());
    }

    public function test_asks_for_zips_or_a_drawn_shape_when_neither_is_given(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips([]);

        $result = (string) $this->makeTool($admin)->handle(new Request([
            'name' => 'Downtown',
        ]));

        $this->assertStringContainsString('ZIP codes', $result);
        $this->assertStringContainsString('Zones map', $result);
        $this->assertFalse($this->investigation->hasProposedPageActions());
    }

    public function test_rejects_unknown_zip_codes(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips([]);

        $result = (string) $this->makeTool($admin)->handle(new Request([
            'name' => 'Downtown',
            'zip_codes' => ['00000'],
        ]));

        $this->assertStringContainsString('00000', $result);
        $this->assertStringContainsString('I do not recognize ZIP', $result);
        $this->assertFalse($this->investigation->hasProposedPageActions());
    }

    public function test_rejects_unresolvable_driver_names(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips(['75201']);

        $result = (string) $this->makeTool($admin)->handle(new Request([
            'name' => 'Downtown',
            'zip_codes' => ['75201'],
            'assigned_drivers' => ['Nobody'],
        ]));

        $this->assertStringContainsString('could not uniquely match', strtolower($result));
        $this->assertFalse($this->investigation->hasProposedPageActions());
    }

    public function test_proposes_creating_a_zone_from_zip_codes(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips(['75201', '75202']);

        $result = (string) $this->makeTool($admin)->handle(new Request([
            'name' => 'Downtown Dallas',
            'zip_codes' => ['75201', '75202'],
            'color' => 'blue',
        ]));

        $this->assertStringContainsString('SUGGESTION', $result);
        $this->assertTrue($this->investigation->hasProposedPageActions());

        $action = $this->investigation->pullProposedPageActions()[0];
        $this->assertSame(ProposeCreateZoneTool::PAGE_TYPE, $action->pageType);
        $this->assertSame(ProposeCreateZoneTool::ACTION_CREATE, $action->name);
        $this->assertSame('Downtown Dallas', $this->changeValue($action->changes, 'name'));
        $this->assertSame(['75201', '75202'], $this->changeValue($action->changes, 'zip_codes'));
        $this->assertSame(Zone::DEFAULT_COLOR, $this->changeValue($action->changes, 'color'));

        Event::assertDispatched(OttoPageActionProposed::class);
    }

    public function test_proposes_opening_the_map_editor_when_requested(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips([]);

        $this->makeTool($admin)->handle(new Request([
            'name' => 'North loop',
            'open_map_editor' => true,
        ]));

        $action = $this->investigation->pullProposedPageActions()[0];
        $this->assertSame(ProposeCreateZoneTool::ACTION_OPEN_EDITOR, $action->name);
        $this->assertSame('North loop', $this->changeValue($action->changes, 'name'));
    }

    public function test_no_scan_proposals_use_gray(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips(['75201']);

        $this->makeTool($admin)->handle(new Request([
            'name' => 'DFW Airport',
            'zip_codes' => ['75201'],
            'is_no_scan_zone' => true,
            'color' => 'blue',
        ]));

        $action = $this->investigation->pullProposedPageActions()[0];
        $this->assertTrue($this->changeValue($action->changes, 'is_no_scan_zone'));
        $this->assertSame(Zone::NO_SCAN_COLOR, $this->changeValue($action->changes, 'color'));
    }

    public function test_propose_create_with_existing_name_proposes_update_instead(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips(['37322']);

        Zone::factory()->forOrg($admin->org)->create([
            'name' => 'Decatur, TN',
            'is_no_scan_zone' => false,
            'definition' => [
                'polygons' => [ZoneFactory::squarePolygon(-88.0, 35.5)],
                'zip_codes' => ['37322'],
            ],
        ]);

        $result = (string) $this->makeTool($admin)->handle(new Request([
            'name' => 'Decatur, TN',
            'zip_codes' => ['37322'],
            'is_no_scan_zone' => true,
        ]));

        $this->assertStringContainsString('SUGGESTION', $result);
        $action = $this->investigation->pullProposedPageActions()[0];
        $this->assertSame(ProposeUpdateZoneTool::PAGE_TYPE, $action->pageType);
        $this->assertSame(ProposeUpdateZoneTool::ACTION_UPDATE, $action->name);
        $this->assertTrue($this->changeValue($action->changes, 'is_no_scan_zone'));
        $this->assertSame(['37322'], $this->changeValue($action->changes, 'zip_codes'));
        $this->assertSame('Decatur, TN', $this->changeValue($action->changes, 'name'));
    }

    public function test_propose_update_sets_no_scan_without_changing_boundary(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->mockZips(['37322']);

        $zone = Zone::factory()->forOrg($admin->org)->create([
            'name' => 'Decatur, TN',
            'is_no_scan_zone' => false,
            'definition' => [
                'polygons' => [ZoneFactory::squarePolygon(-88.0, 35.5)],
                'zip_codes' => ['37322'],
            ],
        ]);

        $tool = $this->makeUpdateTool($admin);
        $result = (string) $tool->handle(new Request([
            'name' => 'Decatur, TN',
            'is_no_scan_zone' => true,
        ]));

        $this->assertStringContainsString('SUGGESTION', $result);
        $action = $this->investigation->pullProposedPageActions()[0];
        $this->assertSame(ProposeUpdateZoneTool::ACTION_UPDATE, $action->name);
        $this->assertSame($zone->id, $this->changeValue($action->changes, 'zone_id'));
        $this->assertTrue($this->changeValue($action->changes, 'is_no_scan_zone'));
        $this->assertSame(['37322'], $this->changeValue($action->changes, 'zip_codes'));
    }

    public function test_chat_apply_zone_create_with_existing_name_updates_instead_of_duplicating(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo('use-otto');
        $this->actingAs($admin);
        $this->mockZips([]);

        $zone = app(PersistZone::class)->create($admin, [
            'name' => 'Decatur, TN',
            'is_no_scan_zone' => false,
            'definition' => [
                'polygons' => [ZoneFactory::squarePolygon(-88.0, 35.5)],
                'zip_codes' => [],
            ],
            'user_ids' => [],
        ], ScoutAnalyticsPage::Otto);

        $conversation = AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'title' => 'Update a zone',
        ]);

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();

        $response = $this->postJson(route('otto.conversations.zone-create', ['id' => $conversation->id]), [
            'name' => 'Decatur, TN',
            'is_no_scan_zone' => true,
        ])->assertSuccessful();

        $this->assertTrue($response->json('ok'));
        $this->assertSame($zone->id, $response->json('zone_id'));
        $this->assertSame(1, Zone::query()->forOrg((int) $admin->org_id)->count());

        $fresh = $zone->fresh();
        $this->assertTrue($fresh->is_no_scan_zone);
        $this->assertSame(Zone::NO_SCAN_COLOR, $fresh->color);
        Event::assertDispatched(OttoInvestigationUpdated::class);
    }

    public function test_chat_apply_zone_update_persists_via_persist_zone(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo('use-otto');
        $this->actingAs($admin);
        $this->mockZips(['37322']);

        $zone = Zone::factory()->forOrg($admin->org)->create([
            'name' => 'Decatur, TN',
            'is_no_scan_zone' => false,
            'definition' => [
                'polygons' => [ZoneFactory::squarePolygon(-88.0, 35.5)],
                'zip_codes' => ['37322'],
            ],
        ]);

        $persist = Mockery::mock(PersistZone::class);
        $persist->shouldReceive('update')
            ->once()
            ->withArgs(function (Zone $target, User $actor, array $attributes, ScoutAnalyticsPage $page) use ($admin, $zone): bool {
                return $target->is($zone)
                    && $actor->is($admin)
                    && $attributes['is_no_scan_zone'] === true
                    && $attributes['definition']['zip_codes'] === ['37322']
                    && $page === ScoutAnalyticsPage::Otto;
            })
            ->andReturn($zone);
        $persist->shouldReceive('create')->never();
        $this->app->instance(PersistZone::class, $persist);

        $conversation = AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'title' => 'Update a zone',
        ]);

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();

        $response = $this->postJson(route('otto.conversations.zone-update', ['id' => $conversation->id]), [
            'zone_id' => $zone->id,
            'name' => 'Decatur, TN',
            'is_no_scan_zone' => true,
        ])->assertSuccessful();

        $this->assertTrue($response->json('ok'));
        $this->assertSame($zone->id, $response->json('zone_id'));
        Event::assertDispatched(OttoInvestigationUpdated::class);
    }

    public function test_chat_apply_zone_create_persists_via_persist_zone(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo('use-otto');
        $this->actingAs($admin);
        $this->mockZips(['75201']);

        // applyZoneCreate updates when the org already has this name — do not
        // insert a same-name row or PersistZone::create is never called.
        $saved = Zone::factory()->forOrg($admin->org)->make([
            'id' => 424_242,
            'name' => 'Downtown Dallas',
        ]);
        $persist = Mockery::mock(PersistZone::class);
        $persist->shouldReceive('create')
            ->once()
            ->withArgs(function (User $actor, array $attributes, ScoutAnalyticsPage $page) use ($admin): bool {
                return $actor->is($admin)
                    && $attributes['name'] === 'Downtown Dallas'
                    && $attributes['definition']['zip_codes'] === ['75201']
                    && $page === ScoutAnalyticsPage::Otto;
            })
            ->andReturn($saved);
        $this->app->instance(PersistZone::class, $persist);

        $conversation = AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'title' => 'Create a zone',
        ]);

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();

        $response = $this->postJson(route('otto.conversations.zone-create', ['id' => $conversation->id]), [
            'name' => 'Downtown Dallas',
            'zip_codes' => ['75201'],
            'color' => Zone::DEFAULT_COLOR,
            'is_no_scan_zone' => false,
        ])->assertSuccessful();

        $this->assertTrue($response->json('ok'));
        $this->assertSame($saved->id, $response->json('zone_id'));
        Event::assertDispatched(OttoInvestigationUpdated::class);
    }

    public function test_chat_apply_zone_create_reports_a_geometry_rejection(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo('use-otto');
        $this->actingAs($admin);
        $this->mockZips(['99999']);

        $persist = Mockery::mock(PersistZone::class);
        $persist->shouldReceive('create')
            ->once()
            ->andThrow(new ZoneGeometryRejected('No Census boundary found for ZIP code(s): 99999. Remove them, or draw the zone manually.'));
        $this->app->instance(PersistZone::class, $persist);

        $conversation = AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'title' => 'Create a zone',
        ]);

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();

        $response = $this->postJson(route('otto.conversations.zone-create', ['id' => $conversation->id]), [
            'name' => 'Missing Zip',
            'zip_codes' => ['99999'],
            'color' => Zone::DEFAULT_COLOR,
            'is_no_scan_zone' => false,
        ])->assertSuccessful();

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('99999', (string) $response->json('error'));
        Event::assertNotDispatched(OttoInvestigationUpdated::class);
    }

    public function test_chat_open_zone_editor_returns_the_map_url(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo('use-otto');
        $this->actingAs($admin);
        $this->mockZips([]);

        $conversation = AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'title' => 'Draw a zone',
        ]);

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();

        $response = $this->postJson(route('otto.conversations.open-zone-editor', ['id' => $conversation->id]), [
            'name' => 'North loop',
            'open_map_editor' => true,
        ])->assertSuccessful();

        $this->assertTrue($response->json('ok'));
        $this->assertNotEmpty($response->json('url'));
        $query = [];
        parse_str((string) parse_url((string) $response->json('url'), PHP_URL_QUERY), $query);
        $this->assertSame('North loop', $query['name'] ?? null);
    }

    public function test_persist_zone_compiles_polygon_geometry(): void
    {
        [$admin] = $this->createScoutAdmin();

        $zone = app(PersistZone::class)->create($admin, [
            'name' => 'Polygon zone',
            'definition' => [
                'polygons' => [ZoneFactory::squarePolygon(-96.8, 32.78)],
                'zip_codes' => [],
            ],
            'user_ids' => [],
        ], ScoutAnalyticsPage::Otto);

        $this->assertDatabaseHas('zones', [
            'id' => $zone->id,
            'name' => 'Polygon zone',
            'org_id' => $admin->org_id,
        ], 'platform');

        $hydrated = Zone::query()->withGeoJson()->find($zone->id);
        $this->assertNotNull($hydrated?->geom_geojson);
        $decoded = json_decode((string) $hydrated->geom_geojson, true);
        $this->assertContains($decoded['type'] ?? null, ['Polygon', 'MultiPolygon']);
    }

    /**
     * @param  list<string>  $existing
     */
    private function mockZips(array $existing): void
    {
        $lookup = Mockery::mock(ZipCodeLookup::class);
        $lookup->shouldReceive('normalize')->andReturnUsing(function (mixed $zipCodes) {
            $items = is_array($zipCodes) ? $zipCodes : [$zipCodes];
            $out = [];

            foreach ($items as $item) {
                if (! is_string($item) && ! is_numeric($item)) {
                    continue;
                }

                if (preg_match_all('/\b(\d{5})\b/', (string) $item, $matches) < 1) {
                    continue;
                }

                foreach ($matches[1] as $zip) {
                    $out[] = $zip;
                }
            }

            return array_values(array_unique($out));
        });
        $lookup->shouldReceive('existing')->andReturn($existing);
        $this->app->instance(ZipCodeLookup::class, $lookup);
    }

    /**
     * @param  list<PageActionChange>  $changes
     */
    private function changeValue(array $changes, string $field): mixed
    {
        foreach ($changes as $change) {
            if ($change->field === $field) {
                return $change->value;
            }
        }

        $this->fail("Missing page-action change for field {$field}");
    }

    /**
     * @return array{0: User}
     */
    private function createScoutAdmin(): array
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        return [$admin->fresh()];
    }

    private function createAdminWithoutScout(): User
    {
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $user->assignRole(RoleEnum::ADMIN->value);

        return $user;
    }

    private function makeTool(User $user): ProposeCreateZoneTool
    {
        $this->investigation = new Investigation;
        $this->investigation->data = new AgentInvestigationProperties;
        $this->investigation->setActiveConversation((string) Str::uuid());

        return new ProposeCreateZoneTool($user, $this->investigation);
    }

    private function makeUpdateTool(User $user): ProposeUpdateZoneTool
    {
        $this->investigation = new Investigation;
        $this->investigation->data = new AgentInvestigationProperties;
        $this->investigation->setActiveConversation((string) Str::uuid());

        return new ProposeUpdateZoneTool($user, $this->investigation);
    }
}
