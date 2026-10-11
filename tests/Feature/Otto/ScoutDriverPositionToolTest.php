<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Ai\Tools\ScoutDriverPositionTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Datatype\SearchType;
use App\Enums\FeatureEnum;
use App\Events\OttoWidgetCreated;
use App\Models\Investigation;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Services\Scout\ResolveOrgDriverPositions;
use App\Support\Geocoding\ReverseGeocoder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Ai\Tools\Request;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ScoutDriverPositionToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OttoWidgetCreated::class]);
        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Permission::findOrCreate('use-otto', 'web');
    }

    public function test_soft_fails_when_scout_is_unavailable(): void
    {
        $user = $this->createAdminWithoutScout();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());
        $tool = new ScoutDriverPositionTool($user, $investigation);

        $result = strtolower((string) $tool->handle(new Request(['driver_name' => 'Maria'])));

        $this->assertStringContainsString('clarity scout', $result);
        $this->assertStringContainsString('account manager', $result);
        $this->assertFalse($investigation->toolCallLimitReached(1));
    }

    public function test_soft_fails_when_user_is_not_admin(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $user = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $user->assignRole(RoleEnum::USER->value);

        $tool = $this->makeTool($user);
        $result = strtolower((string) $tool->handle(new Request(['driver_name' => 'Maria'])));

        $this->assertStringContainsString('clarity scout', $result);
    }

    public function test_matches_driver_by_name_and_returns_geocoded_place(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Maria Garcia', 'Van 1');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Maria Garcia',
                'lat' => 32.78,
                'lng' => -96.80,
                'heading' => null,
                'scanned_at' => now()->subMinutes(3)->toIso8601String(),
                'minutes_ago' => 3,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder('Main St & 5th, Dallas, TX');

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['driver_name' => 'maria']));

        $this->assertStringContainsString('Maria Garcia', $result);
        $this->assertStringContainsString('Main St & 5th, Dallas, TX', $result);
        $this->assertStringContainsString('3 minutes ago', $result);
    }

    public function test_matches_first_name_to_full_driver_name(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Harold Oran', 'horan');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Harold Oran',
                'lat' => 32.74,
                'lng' => -97.00,
                'heading' => null,
                'scanned_at' => now()->subHours(5)->toIso8601String(),
                'minutes_ago' => 300,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder('2357 Houston St, Grand Prairie, TX 75050');

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['driver_name' => 'harold']));

        $this->assertStringContainsString('Harold Oran', $result);
        $this->assertStringContainsString('2357 Houston St, Grand Prairie, TX 75050', $result);
        $this->assertStringNotContainsString('No drivers or kits', $result);
    }

    public function test_matches_when_model_passes_same_token_as_driver_and_kit_name(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Harold Oran', 'horan');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Harold Oran',
                'lat' => 32.74,
                'lng' => -97.00,
                'heading' => null,
                'scanned_at' => now()->subHours(5)->toIso8601String(),
                'minutes_ago' => 300,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder('2357 Houston St, Grand Prairie, TX 75050');

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request([
            'driver_name' => 'harold',
            'kit_name' => 'harold',
        ]));

        $this->assertStringContainsString('Harold Oran', $result);
        $this->assertStringNotContainsString('No drivers or kits', $result);
    }

    public function test_unknown_driver_name_still_misses(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Harold Oran', 'horan');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [],
        );

        $tool = $this->makeTool($admin);
        $result = strtolower((string) $tool->handle(new Request(['driver_name' => 'nobody-here'])));

        $this->assertStringContainsString('no drivers or kits', $result);
        $this->assertStringNotContainsString('harold oran', $result);
    }

    public function test_prefers_exact_full_name_when_several_candidates_match(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $exactKit = Kit::factory()->forOrg($org)->create(['name' => 'van-harold']);
        User::factory()->forOrg($org)->forKit($exactKit)->create(['name' => 'Harold Oran']);
        $exactKit = $exactKit->fresh(['settings.user']);

        $otherKit = Kit::factory()->forOrg($org)->create(['name' => 'van-other']);
        User::factory()->forOrg($org)->forKit($otherKit)->create(['name' => 'Harold Smith']);
        $otherKit = $otherKit->fresh(['settings.user']);

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$exactKit, $otherKit]),
            positions: [[
                'kit_id' => (int) $exactKit->id,
                'name' => $exactKit->name,
                'driver_name' => 'Harold Oran',
                'lat' => 32.74,
                'lng' => -97.00,
                'heading' => null,
                'scanned_at' => now()->subMinutes(10)->toIso8601String(),
                'minutes_ago' => 10,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder('Grand Prairie, TX');

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['driver_name' => 'Harold Oran']));

        $this->assertStringContainsString('Harold Oran', $result);
        $this->assertStringNotContainsString('Harold Smith', $result);
        $this->assertStringNotContainsString('Several drivers matched', $result);
    }

    public function test_lists_candidates_when_several_drivers_share_a_partial_name(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $kitA = Kit::factory()->forOrg($org)->create(['name' => 'van-a']);
        User::factory()->forOrg($org)->forKit($kitA)->create(['name' => 'Harold Oran']);
        $kitA = $kitA->fresh(['settings.user']);

        $kitB = Kit::factory()->forOrg($org)->create(['name' => 'van-b']);
        User::factory()->forOrg($org)->forKit($kitB)->create(['name' => 'Harold Smith']);
        $kitB = $kitB->fresh(['settings.user']);

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kitA, $kitB]),
            positions: [
                [
                    'kit_id' => (int) $kitA->id,
                    'name' => $kitA->name,
                    'driver_name' => 'Harold Oran',
                    'lat' => 32.74,
                    'lng' => -97.00,
                    'heading' => null,
                    'scanned_at' => now()->subMinutes(10)->toIso8601String(),
                    'minutes_ago' => 10,
                    'vehicle_type' => null,
                    'source' => 'scan',
                    'position_source' => 'gps',
                ],
                [
                    'kit_id' => (int) $kitB->id,
                    'name' => $kitB->name,
                    'driver_name' => 'Harold Smith',
                    'lat' => 32.75,
                    'lng' => -97.01,
                    'heading' => null,
                    'scanned_at' => now()->subMinutes(12)->toIso8601String(),
                    'minutes_ago' => 12,
                    'vehicle_type' => null,
                    'source' => 'scan',
                    'position_source' => 'gps',
                ],
            ],
        );

        $this->mockGeocoder('Dallas, TX');

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['driver_name' => 'harold']));

        $this->assertStringContainsString('Several drivers matched that name', $result);
        $this->assertStringContainsString('Harold Oran', $result);
        $this->assertStringContainsString('Harold Smith', $result);
    }

    public function test_reports_no_recent_location_when_position_missing(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Stale Driver', 'Van 2');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [],
        );

        $tool = $this->makeTool($admin);
        $result = strtolower((string) $tool->handle(new Request(['driver_name' => 'Stale'])));

        $this->assertStringContainsString('no recent scout location', $result);
        $this->assertStringNotContainsString('near ', $result);
    }

    public function test_does_not_invent_address_when_geocode_fails(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Geo Fail', 'Van 3');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Geo Fail',
                'lat' => 32.78,
                'lng' => -96.80,
                'heading' => null,
                'scanned_at' => now()->subMinutes(12)->toIso8601String(),
                'minutes_ago' => 12,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder(null);

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['driver_name' => 'Geo']));

        $this->assertStringContainsString('12 minutes ago', $result);
        $this->assertStringContainsString('Do not invent an address', $result);
        $this->assertStringNotContainsString('near ', $result);
    }

    public function test_ignores_kit_ids_outside_the_users_org(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Local Driver', 'Local Kit');

        $foreignOrg = Org::factory()->create();
        $foreignKit = Kit::factory()->forOrg($foreignOrg)->create(['name' => 'Foreign Kit']);

        $resolver = Mockery::mock(ResolveOrgDriverPositions::class);
        $resolver->shouldReceive('forOrg')
            ->once()
            ->with((int) $admin->org_id, [(int) $foreignKit->id])
            ->andReturn(new EloquentCollection);
        $resolver->shouldNotReceive('positions');
        $this->app->instance(ResolveOrgDriverPositions::class, $resolver);

        $tool = $this->makeTool($admin);
        $result = strtolower((string) $tool->handle(new Request(['kit_id' => (int) $foreignKit->id])));

        $this->assertStringContainsString('no drivers or kits', $result);
        $this->assertNotSame((int) $kit->id, (int) $foreignKit->id);
    }

    public function test_looks_up_by_kit_id_through_for_org_intersection(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Maria Garcia', 'Van 1');

        $resolver = Mockery::mock(ResolveOrgDriverPositions::class);
        $resolver->shouldReceive('forOrg')
            ->once()
            ->with((int) $admin->org_id, [(int) $kit->id])
            ->andReturn(new EloquentCollection([$kit]));
        $resolver->shouldReceive('positions')
            ->once()
            ->with((int) $admin->org_id, Mockery::type(EloquentCollection::class))
            ->andReturn([[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Maria Garcia',
                'lat' => 32.78,
                'lng' => -96.80,
                'heading' => null,
                'scanned_at' => now()->subMinutes(3)->toIso8601String(),
                'minutes_ago' => 3,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]]);
        $this->app->instance(ResolveOrgDriverPositions::class, $resolver);

        $this->mockGeocoder('Dallas, TX');

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['kit_id' => (int) $kit->id]));

        $this->assertStringContainsString('Maria Garcia', $result);
        $this->assertStringContainsString('Dallas, TX', $result);
    }

    public function test_treats_unmatched_driver_name_as_kit_name(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Admin', 'gsmitham');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Admin',
                'lat' => 32.78,
                'lng' => -96.80,
                'heading' => null,
                'scanned_at' => now()->subMinutes(4)->toIso8601String(),
                'minutes_ago' => 4,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder(null);

        $tool = $this->makeTool($admin);
        $result = strtolower((string) $tool->handle(new Request(['driver_name' => 'gsmitham'])));

        $this->assertStringContainsString('gsmitham', $result);
        $this->assertStringContainsString('4 minutes ago', $result);
        $this->assertStringNotContainsString('no drivers or kits', $result);
    }

    public function test_records_geocoded_positions_on_the_investigation(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Maria Garcia', 'Van 1');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Maria Garcia',
                'lat' => 32.78,
                'lng' => -96.80,
                'heading' => null,
                'scanned_at' => now()->subMinutes(3)->toIso8601String(),
                'minutes_ago' => 3,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder('Main St & 5th, Dallas, TX');

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());
        $tool = new ScoutDriverPositionTool($admin, $investigation);
        $tool->handle(new Request(['driver_name' => 'maria']));

        $this->assertCount(1, $investigation->data->searches);
        $search = $investigation->data->searches[0];
        $this->assertSame(SearchType::ScoutDriver, $search->searchType);
        $this->assertSame('Scout driver location', $search->title);
        $this->assertSame((int) $kit->id, $search->parameters['positions'][0]['kit_id']);
        $this->assertSame(32.78, $search->parameters['positions'][0]['lat']);
        $this->assertSame(-96.80, $search->parameters['positions'][0]['lng']);
        $this->assertSame('Main St & 5th, Dallas, TX', $search->parameters['positions'][0]['place']);
        $this->assertSame('Maria Garcia', $search->parameters['positions'][0]['driver_name']);
        $this->assertArrayNotHasKey('minutes_ago', $search->parameters['positions'][0]);
        $this->assertNotEmpty($search->parameters['positions'][0]['last_seen_at_utc']);
        $this->assertArrayNotHasKey('scanned_at', $search->parameters['positions'][0]);
        Event::assertDispatched(OttoWidgetCreated::class, function (OttoWidgetCreated $event) use ($investigation): bool {
            return $event->widgetType === SearchType::ScoutDriver->value
                && $event->title === 'Scout driver location'
                && $event->conversationId === (string) $investigation->activeConversationId()
                && $event->searchId === $investigation->data->searches[0]->searchId;
        });
    }

    public function test_recorded_snapshot_omits_minutes_ago_and_keeps_last_seen_at_utc(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Maria Garcia', 'Van 1');

        $scannedAt = now()->subMinutes(8)->toIso8601String();

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Maria Garcia',
                'lat' => 32.78,
                'lng' => -96.80,
                'heading' => null,
                'scanned_at' => $scannedAt,
                'minutes_ago' => 8,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder('Dallas, TX');

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());
        $tool = new ScoutDriverPositionTool($admin, $investigation);
        $result = (string) $tool->handle(new Request(['driver_name' => 'maria']));

        $this->assertStringContainsString('8 minutes ago', $result);
        $this->assertArrayNotHasKey('minutes_ago', $investigation->data->searches[0]->parameters['positions'][0]);
        $this->assertSame($scannedAt, $investigation->data->searches[0]->parameters['positions'][0]['last_seen_at_utc']);

        $this->travel(30)->minutes();

        $this->assertArrayNotHasKey('minutes_ago', $investigation->data->searches[0]->parameters['positions'][0]);
        $this->assertSame($scannedAt, $investigation->data->searches[0]->parameters['positions'][0]['last_seen_at_utc']);
    }

    public function test_does_not_record_a_search_when_no_positions_are_found(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Stale Driver', 'Van 2');

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [],
        );

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());
        $tool = new ScoutDriverPositionTool($admin, $investigation);
        $tool->handle(new Request(['driver_name' => 'Stale']));

        $this->assertSame([], $investigation->data->searches);
        Event::assertNotDispatched(OttoWidgetCreated::class);
    }

    public function test_looks_up_positions_when_permissions_team_id_is_missing(): void
    {
        [$admin, $kit] = $this->createScoutAdminWithDriver('Maria Garcia', 'Van 1');

        setPermissionsTeamId(null);

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Maria Garcia',
                'lat' => 32.78,
                'lng' => -96.80,
                'heading' => null,
                'scanned_at' => now()->subMinutes(2)->toIso8601String(),
                'minutes_ago' => 2,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder(null);

        $tool = $this->makeTool($admin);
        $result = strtolower((string) $tool->handle(new Request([])));

        $this->assertStringContainsString('recent scout positions', $result);
        $this->assertStringNotContainsString('account manager', $result);
    }

    public function test_allows_lookup_for_admin_impersonator_without_scout(): void
    {
        $admin = $this->createAdminWithoutScout();
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $kit = Kit::factory()->forOrg($admin->org)->create(['name' => 'Impersonated Kit']);
        User::factory()->forOrg($admin->org)->forKit($kit)->create(['name' => 'Impersonated Driver']);
        $kit = $kit->fresh(['settings.user']);

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: new EloquentCollection([$kit]),
            positions: [[
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => 'Impersonated Driver',
                'lat' => 32.78,
                'lng' => -96.80,
                'heading' => null,
                'scanned_at' => now()->subMinutes(5)->toIso8601String(),
                'minutes_ago' => 5,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ]],
        );

        $this->mockGeocoder('Dallas, TX');

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['driver_name' => 'Impersonated']));

        $this->assertStringContainsString('Impersonated Driver', $result);
        $this->assertStringContainsString('Dallas, TX', $result);
        $this->assertStringNotContainsString('account manager', strtolower($result));
    }

    public function test_unnamed_fleet_caps_geocode_and_persist_at_eight(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $kits = new EloquentCollection;
        $positions = [];

        for ($i = 1; $i <= 10; $i++) {
            $kit = Kit::factory()->forOrg($org)->create(['name' => "Van {$i}"]);
            User::factory()->forOrg($org)->forKit($kit)->create(['name' => "Driver {$i}"]);
            $kit = $kit->fresh(['settings.user']);
            $kits->push($kit);

            $positions[] = [
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => "Driver {$i}",
                'lat' => 32.78 + ($i * 0.001),
                'lng' => -96.80 - ($i * 0.001),
                'heading' => null,
                'scanned_at' => now()->subMinutes($i)->toIso8601String(),
                'minutes_ago' => $i,
                'vehicle_type' => null,
                'source' => 'scan',
                'position_source' => 'gps',
            ];
        }

        $this->mockResolver(
            orgId: (int) $admin->org_id,
            kits: $kits,
            positions: $positions,
        );

        $geocoder = Mockery::mock(ReverseGeocoder::class);
        $geocoder->shouldReceive('label')->times(8)->andReturn('Dallas, TX');
        $this->app->instance(ReverseGeocoder::class, $geocoder);

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());
        $tool = new ScoutDriverPositionTool($admin, $investigation);
        $result = (string) $tool->handle(new Request([]));

        $this->assertStringContainsString('and 2 more', $result);
        $this->assertCount(1, $investigation->data->searches);
        $this->assertCount(8, $investigation->data->searches[0]->parameters['positions']);
    }

    /**
     * @return array{0: User, 1: Kit}
     */
    private function createScoutAdminWithDriver(string $driverName, string $kitName): array
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $kit = Kit::factory()->forOrg($org)->create(['name' => $kitName]);
        User::factory()->forOrg($org)->forKit($kit)->create(['name' => $driverName]);

        return [$admin->fresh(), $kit->fresh(['settings.user'])];
    }

    private function createAdminWithoutScout(): User
    {
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $user->assignRole(RoleEnum::ADMIN->value);

        return $user;
    }

    private function makeTool(User $user): ScoutDriverPositionTool
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());

        return new ScoutDriverPositionTool($user, $investigation);
    }

    /**
     * @param  EloquentCollection<int, Kit>  $kits
     * @param  list<array<string, mixed>>  $positions
     */
    private function mockResolver(int $orgId, EloquentCollection $kits, array $positions): void
    {
        $resolver = Mockery::mock(ResolveOrgDriverPositions::class);
        $resolver->shouldReceive('forOrg')
            ->with($orgId)
            ->andReturn($kits);
        $resolver->shouldReceive('positions')
            ->with($orgId, Mockery::type(EloquentCollection::class))
            ->andReturn($positions);

        $this->app->instance(ResolveOrgDriverPositions::class, $resolver);
    }

    private function mockGeocoder(?string $label): void
    {
        $geocoder = Mockery::mock(ReverseGeocoder::class);
        $geocoder->shouldReceive('label')->andReturn($label);
        $this->app->instance(ReverseGeocoder::class, $geocoder);
    }
}
