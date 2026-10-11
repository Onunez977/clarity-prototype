<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ZoneSearchTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Enums\FeatureEnum;
use App\Events\OttoWidgetCreated;
use App\Models\AgentInvestigation;
use App\Models\Investigation;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Support\Otto\LimitedToolResultMessage;
use App\Support\Otto\OttoScoutAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Ai\Tools\Request;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ZoneSearchToolTest extends TestCase
{
    private Investigation $investigation;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OttoWidgetCreated::class]);
        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Permission::findOrCreate('use-otto', 'web');
    }

    public function test_is_registered(): void
    {
        $this->assertContains(ZoneSearchTool::class, app(ToolRegistry::class)->registered());
    }

    public function test_soft_fails_when_scout_is_unavailable(): void
    {
        $user = $this->createAdminWithoutScout();
        $tool = $this->makeTool($user);

        $result = (string) $tool->handle(new Request([]));

        $this->assertSame(OttoScoutAccess::ZONE_NEEDS_SCOUT, $result);
        $this->assertFalse($this->investigation->toolCallLimitReached(1));
        Event::assertNotDispatched(OttoWidgetCreated::class);
    }

    public function test_soft_fails_when_user_is_not_admin(): void
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $user = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $user->assignRole(RoleEnum::USER->value);

        $result = strtolower((string) $this->makeTool($user)->handle(new Request([])));

        $this->assertStringContainsString('organization admins', $result);
        Event::assertNotDispatched(OttoWidgetCreated::class);
    }

    public function test_allows_search_for_admin_impersonator_without_scout(): void
    {
        $admin = $this->createAdminWithoutScout();
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);
        $zone = Zone::factory()->forOrg($admin->org)->create(['name' => 'Impersonated Zone']);

        $result = (string) $this->makeTool($admin)->handle(new Request([]));

        $this->assertStringContainsString('Here is the most recent zone', $result);
        $this->assertSame([$zone->id], collect($this->investigation->data->searchResults)->pluck('resultId')->all());
        Event::assertDispatched(OttoWidgetCreated::class);
    }

    public function test_impersonator_without_admin_role_is_still_denied(): void
    {
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $user->assignRole(RoleEnum::USER->value);
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $result = strtolower((string) $this->makeTool($user)->handle(new Request([])));

        $this->assertStringContainsString('organization admins', $result);
        Event::assertNotDispatched(OttoWidgetCreated::class);
    }

    public function test_searches_org_zones_and_records_the_investigation(): void
    {
        [$admin] = $this->createScoutAdmin();
        $downtown = Zone::factory()->forOrg($admin->org)->create(['name' => 'Downtown Dallas']);
        $north = Zone::factory()->forOrg($admin->org)->create(['name' => 'North loop']);

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request([]));

        $this->assertStringContainsString('most recent 2', $result);
        $this->assertStringNotContainsString((string) LimitedToolResultMessage::SEARCH_LIMIT, $result);
        $this->assertSame('Zone search', $this->investigation->data->searches[0]->title);
        $this->assertSame(SearchType::Zone, $this->investigation->data->searches[0]->searchType);
        $this->assertEqualsCanonicalizing(
            [$downtown->id, $north->id],
            collect($this->investigation->data->searchResults)->pluck('resultId')->all(),
        );

        Event::assertDispatched(OttoWidgetCreated::class, function (OttoWidgetCreated $event): bool {
            return $event->widgetType === SearchType::Zone->value;
        });
    }

    public function test_filters_by_name(): void
    {
        [$admin] = $this->createScoutAdmin();
        $match = Zone::factory()->forOrg($admin->org)->create(['name' => 'Downtown Dallas']);
        Zone::factory()->forOrg($admin->org)->create(['name' => 'North loop']);

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['name' => 'downtown']));

        $this->assertStringContainsString('Here is the most recent zone', $result);
        $this->assertSame([$match->id], collect($this->investigation->data->searchResults)->pluck('resultId')->all());
        $this->assertSame('Zone search: downtown', $this->investigation->data->searches[0]->title);
    }

    public function test_filters_by_no_scan_flag(): void
    {
        [$admin] = $this->createScoutAdmin();
        $noScan = Zone::factory()->forOrg($admin->org)->noScan()->create(['name' => 'DFW Airport']);
        Zone::factory()->forOrg($admin->org)->create(['name' => 'Downtown Dallas']);

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['is_no_scan_zone' => true]));

        $this->assertStringContainsString('Here is the most recent zone', $result);
        $this->assertSame([$noScan->id], collect($this->investigation->data->searchResults)->pluck('resultId')->all());
        $this->assertSame('Zone search: no-scan', $this->investigation->data->searches[0]->title);
    }

    public function test_filters_by_assigned_driver(): void
    {
        [$admin, $driver] = $this->createScoutAdminWithDriver('Maria Garcia');
        $marias = Zone::factory()->forOrg($admin->org)->assignedTo([$driver->id])->create(['name' => 'Downtown Dallas']);
        Zone::factory()->forOrg($admin->org)->create(['name' => 'North loop']);

        $tool = $this->makeTool($admin);
        $result = (string) $tool->handle(new Request(['assigned_driver' => 'maria']));

        $this->assertStringContainsString('Here is the most recent zone', $result);
        $this->assertSame([$marias->id], collect($this->investigation->data->searchResults)->pluck('resultId')->all());
        $this->assertSame('Zone search: assigned to maria', $this->investigation->data->searches[0]->title);
    }

    public function test_hides_soft_deleted_zones(): void
    {
        [$admin] = $this->createScoutAdmin();
        Zone::factory()->forOrg($admin->org)->trashed()->create(['name' => 'Gone']);
        $live = Zone::factory()->forOrg($admin->org)->create(['name' => 'Live']);

        $tool = $this->makeTool($admin);
        $tool->handle(new Request([]));

        $this->assertSame([$live->id], collect($this->investigation->data->searchResults)->pluck('resultId')->all());
    }

    public function test_does_not_return_other_org_zones(): void
    {
        [$admin] = $this->createScoutAdmin();
        $own = Zone::factory()->forOrg($admin->org)->create(['name' => 'Ours']);
        Zone::factory()->create(['name' => 'Theirs']);

        $tool = $this->makeTool($admin);
        $tool->handle(new Request([]));

        $this->assertSame([$own->id], collect($this->investigation->data->searchResults)->pluck('resultId')->all());
    }

    public function test_description_omits_the_search_cap(): void
    {
        $tool = $this->makeTool($this->createAdminWithoutScout());
        $description = strtolower((string) $tool->description());

        $this->assertStringContainsString('a page of the most recent matching zones', $description);
        $this->assertStringNotContainsString((string) LimitedToolResultMessage::SEARCH_LIMIT, $description);
        $this->assertStringNotContainsString('there may be more', $description);
    }

    public function test_investigation_endpoint_hydrates_zone_polygons(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo('use-otto');
        $this->actingAs($admin);

        $zone = Zone::factory()->forOrg($admin->org)->withGeometry()->create([
            'name' => 'Downtown Dallas',
            'color' => Zone::DEFAULT_COLOR,
        ]);

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $admin->id,
            'title' => 'Zone search',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $admin->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'zonesearch01',
                        messageId: (string) Str::uuid(),
                        parameters: ['name' => null],
                        searchType: SearchType::Zone,
                        title: 'Zone search',
                        createdBy: $admin->id,
                        conversationId: $conversationId,
                    ),
                ],
                searchResults: [
                    new SearchResult(searchId: 'zonesearch01', resultId: $zone->id),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));

        $response->assertOk();
        $response->assertJsonPath('zones.0.id', $zone->id);
        $response->assertJsonPath('zones.0.name', 'Downtown Dallas');
        $response->assertJsonPath('zones.0.searchIds.0', 'zonesearch01');
        $this->assertContains($response->json('zones.0.geometry.type'), ['Polygon', 'MultiPolygon']);
        $this->assertNotEmpty($response->json('zones.0.geometry.coordinates'));
        $this->assertNotNull($response->json('zones.0.lat'));
        $this->assertNotNull($response->json('zones.0.lng'));
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function createScoutAdminWithDriver(string $driverName): array
    {
        [$admin] = $this->createScoutAdmin();
        $kit = Kit::factory()->forOrg($admin->org)->create();
        $driver = User::factory()->forOrg($admin->org)->forKit($kit)->create(['name' => $driverName]);

        return [$admin, $driver];
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

    private function makeTool(User $user): ZoneSearchTool
    {
        $this->investigation = new Investigation;
        $this->investigation->data = new AgentInvestigationProperties;
        $this->investigation->setActiveConversation((string) Str::uuid());

        return new ZoneSearchTool($user, $this->investigation);
    }
}
