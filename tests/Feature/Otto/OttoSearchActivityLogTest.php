<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Actions\Activity\ActivityFactory;
use App\Actions\Activity\Log\OttoHitsSearch;
use App\Actions\Activity\Log\OttoHotlistsSearch;
use App\Actions\Activity\Log\OttoScansSearch;
use App\Actions\Activity\Log\OttoScoutDriverPositionsSearch;
use App\Actions\Activity\Log\OttoZonesSearch;
use App\Actions\Activity\Log\ZonesSearch;
use App\Ai\PageContext;
use App\Ai\Tools\HitSearchTool;
use App\Ai\Tools\HotlistSearchTool;
use App\Ai\Tools\KnowledgeBaseSearchTool;
use App\Ai\Tools\ScanSearchTool;
use App\Ai\Tools\ScoutDriverPositionTool;
use App\Ai\Tools\ShowResultDetailsTool;
use App\Ai\Tools\ZoneSearchTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Enums\FeatureEnum;
use App\Events\OttoPageActionProposed;
use App\Events\OttoUserMessagePosted;
use App\Events\OttoWidgetCreated;
use App\Jobs\RunOttoChat;
use App\Models\Investigation;
use App\Models\Org;
use App\Models\User;
use App\Support\Otto\SearchActivityLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Ai\Tools\Request;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OttoSearchActivityLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            OttoWidgetCreated::class,
            OttoPageActionProposed::class,
            OttoUserMessagePosted::class,
        ]);

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('use-otto', 'web');
        Permission::findOrCreate('clarity-scout', 'web');
    }

    public function test_scan_search_logs_the_plate_and_who_searched(): void
    {
        $user = $this->createUser();
        $investigation = $this->investigation();
        $tool = new ScanSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request([
            'plate' => 'abc123',
        ]));

        $this->assertStringContainsString('No matching scans', $result);

        $activity = $this->activityFor($user, OttoScansSearch::$log_name);

        $this->assertSame($user->email.' searched via Otto for scans by Plate', $activity->description);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($user->org_id, $activity->causer->org_id);
        $this->assertSame(['ABC123'], $activity->properties['plateNumber']);
        $this->assertNotNull($activity->created_at);
        $this->assertArrayHasKey('clientIp', $activity->properties['_request']);
        $this->assertArrayHasKey('userAgent', $activity->properties['_request']);
        $this->assertLoggedViaOtto($activity, $investigation);
    }

    public function test_scan_search_without_a_plate_still_logs_the_window(): void
    {
        $user = $this->createUser();
        $investigation = $this->investigation();

        (new ScanSearchTool($user, $investigation))->handle(new Request([
            'time_range' => 'today',
        ]));

        $activity = $this->activityFor($user, OttoScansSearch::$log_name);

        $this->assertSame($user->email.' searched via Otto for scans', $activity->description);
        $this->assertArrayNotHasKey('plateNumber', $activity->properties->toArray());
        $this->assertNotEmpty($activity->properties['startDate']);
        $this->assertNotEmpty($activity->properties['endDate']);
        $this->assertLoggedViaOtto($activity, $investigation);
    }

    public function test_scan_search_without_a_conversation_id_does_not_invent_one(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $this->assertNull($investigation->activeConversationId());

        (new ScanSearchTool($user, $investigation))->handle(new Request([
            'plate' => 'abc123',
        ]));

        $activity = $this->activityFor($user, OttoScansSearch::$log_name);

        $this->assertSame($user->email.' searched via Otto for scans by Plate', $activity->description);
        $this->assertSame('otto', $activity->properties['via']);
        $this->assertArrayNotHasKey('conversationId', $activity->properties->toArray());
    }

    public function test_scan_search_on_the_scans_page_does_not_log_in_chat(): void
    {
        $user = $this->createUser();
        $tool = new ScanSearchTool($user, $this->investigation());
        $tool->onPage(new PageContext('scan-search'));

        $result = (string) $tool->handle(new Request(['plate' => 'ABC123']));

        $this->assertStringContainsString('Proposed', $result);
        $this->assertNoActivityFor($user);
    }

    public function test_scan_search_does_not_log_when_the_tool_call_limit_is_reached(): void
    {
        config()->set('otto.tool_call_limit', 0);

        $user = $this->createUser();
        $result = (string) (new ScanSearchTool($user, $this->investigation()))->handle(new Request([
            'plate' => 'ABC123',
        ]));

        $this->assertStringContainsString('Tool call limit', $result);
        $this->assertNoActivityFor($user);
    }

    public function test_hit_search_logs_the_plate(): void
    {
        $user = $this->createUser();
        $investigation = $this->investigation();

        $result = (string) (new HitSearchTool($user, $investigation))->handle(new Request([
            'plate' => 'hit999',
        ]));

        $this->assertStringContainsString('No matching hits', $result);

        $activity = $this->activityFor($user, OttoHitsSearch::$log_name);

        $this->assertSame($user->email.' searched via Otto for hits by Plate', $activity->description);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(['HIT999'], $activity->properties['plateNumber']);
        $this->assertLoggedViaOtto($activity, $investigation);
    }

    public function test_hotlist_search_logs_plate_and_vin(): void
    {
        $user = $this->createUser();
        $investigation = $this->investigation();

        $result = (string) (new HotlistSearchTool($user, $investigation))->handle(new Request([
            'plate' => 'hot1',
            'vin' => '1hgcm',
            'status' => 'Open',
        ]));

        $this->assertStringContainsString('No matching assignments', $result);

        $activity = $this->activityFor($user, OttoHotlistsSearch::$log_name);

        $this->assertSame($user->email.' searched via Otto for hotlists by Plate, VIN', $activity->description);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(['HOT1'], $activity->properties['plateNumber']);
        $this->assertSame(['1HGCM'], $activity->properties['vin']);
        $this->assertSame(1, $activity->properties['status']);
        $this->assertLoggedViaOtto($activity, $investigation);
    }

    public function test_hotlist_search_on_the_page_does_not_log_a_mappable_filter(): void
    {
        $user = $this->createUser();
        $tool = new HotlistSearchTool($user, $this->investigation());
        $tool->onPage(new PageContext('hotlist-search'));

        $result = (string) $tool->handle(new Request(['plate' => 'ABC123']));

        $this->assertStringContainsString('Proposed', $result);
        $this->assertNoActivityFor($user);
    }

    public function test_hotlist_date_window_on_the_page_still_logs_the_chat_search(): void
    {
        $user = $this->createUser();
        $investigation = $this->investigation();
        $tool = new HotlistSearchTool($user, $investigation);
        $tool->onPage(new PageContext('hotlist-search'));

        $tool->handle(new Request(['time_range' => 'today']));

        $activity = $this->activityFor($user, OttoHotlistsSearch::$log_name);

        $this->assertSame($user->email.' searched via Otto for hotlists', $activity->description);
        $this->assertNotEmpty($activity->properties['startDate']);
        $this->assertLoggedViaOtto($activity, $investigation);
    }

    public function test_unknown_hotlist_status_does_not_log(): void
    {
        $user = $this->createUser();

        $result = (string) (new HotlistSearchTool($user, $this->investigation()))->handle(new Request([
            'status' => 'not-a-status',
        ]));

        $this->assertStringContainsString("don't recognize", $result);
        $this->assertNoActivityFor($user);
    }

    public function test_zone_search_logs_name_driver_and_no_scan(): void
    {
        $admin = $this->createScoutAdmin();
        $investigation = $this->investigation();

        $result = (string) (new ZoneSearchTool($admin, $investigation))->handle(new Request([
            'name' => 'Downtown',
            'assigned_driver' => 'Priya',
            'is_no_scan_zone' => true,
        ]));

        $this->assertStringContainsString('No matching zones', $result);

        $activity = $this->activityFor($admin, OttoZonesSearch::$log_name);

        $this->assertSame(
            $admin->email.' searched via Otto for zones by Name, Assigned driver, No-scan',
            $activity->description,
        );
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($admin->org_id, $activity->causer->org_id);
        $this->assertSame('Downtown', $activity->properties['name']);
        $this->assertSame(['Priya'], $activity->properties['assignedDriver']);
        $this->assertTrue($activity->properties['isNoScanZone']);
        $this->assertLoggedViaOtto($activity, $investigation);
    }

    public function test_zone_filter_shape_uses_the_zones_search_log(): void
    {
        $user = $this->createUser();

        ActivityFactory::make()->log(
            logName: 'Zones',
            data: [
                'assignedDriver' => ['Priya'],
                'color' => ['#2563eb'],
            ],
            causer: $user,
        );

        $activity = $this->activityFor($user, ZonesSearch::$log_name);
        $properties = $activity->properties->toArray();

        $this->assertSame(
            $user->email.' searched for zones by Assigned driver, Color',
            $activity->description,
        );
        $this->assertSame(['Priya'], $activity->properties['assignedDriver']);
        $this->assertSame(['#2563eb'], $activity->properties['color']);
        $this->assertArrayNotHasKey('via', $properties);
        $this->assertArrayNotHasKey('conversationId', $properties);
    }

    public function test_denied_zone_search_does_not_log(): void
    {
        $user = $this->createAdminWithoutScout();

        $result = (string) (new ZoneSearchTool($user, $this->investigation()))->handle(new Request([
            'name' => 'Downtown',
        ]));

        $this->assertStringContainsString('Clarity Scout', $result);
        $this->assertNoActivityFor($user);
    }

    public function test_scout_driver_position_search_logs_the_driver(): void
    {
        $admin = $this->createScoutAdmin();
        $investigation = $this->investigation();

        $result = (string) (new ScoutDriverPositionTool($admin, $investigation))->handle(new Request([
            'driver_name' => 'Maria',
        ]));

        $this->assertStringContainsString('No Scout kits', $result);

        $activity = $this->activityFor($admin, OttoScoutDriverPositionsSearch::$log_name);

        $this->assertSame(
            $admin->email.' searched via Otto for Scout driver positions by Driver',
            $activity->description,
        );
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($admin->org_id, $activity->causer->org_id);
        $this->assertSame('maria', $activity->properties['driverName']);
        $this->assertLoggedViaOtto($activity, $investigation);
    }

    public function test_denied_scout_driver_lookup_does_not_log(): void
    {
        $user = $this->createAdminWithoutScout();

        $result = (string) (new ScoutDriverPositionTool($user, $this->investigation()))->handle(new Request([
            'driver_name' => 'Maria',
        ]));

        $this->assertStringContainsString('Clarity Scout', $result);
        $this->assertNoActivityFor($user);
    }

    public function test_knowledge_base_lookup_is_not_activity_logged(): void
    {
        $user = $this->createUser();

        $result = (string) (new KnowledgeBaseSearchTool($user, $this->investigation()))->handle(new Request([
            'query' => 'how do I search for a plate',
        ]));

        $this->assertNotSame('', $result);
        $this->assertNoActivityFor($user);
    }

    public function test_showing_known_result_details_is_not_activity_logged(): void
    {
        $user = $this->createUser();

        (new ShowResultDetailsTool($user, $this->investigation()))->handle(new Request([
            'type' => 'scan',
            'result_ids' => [1],
        ]));

        $this->assertNoActivityFor($user);
    }

    public function test_stamped_turn_session_is_what_the_activity_row_records(): void
    {
        $user = $this->createUser();
        $impersonator = ['id' => 9, 'email' => 'staff@example.com'];

        session()->put(SearchActivityLog::SESSION_KEY, [
            'impersonator' => $impersonator,
            'clientIp' => '203.0.113.10',
            'userAgent' => 'OttoAudit/1.0',
        ]);

        $investigation = $this->investigation();

        (new ScanSearchTool($user, $investigation))->handle(new Request([
            'plate' => 'ZZZ999',
        ]));

        $activity = $this->activityFor($user, OttoScansSearch::$log_name);

        $this->assertSame($impersonator, $activity->properties['_impersonator']);
        $this->assertSame('203.0.113.10', $activity->properties['_request']['clientIp']);
        $this->assertSame('OttoAudit/1.0', $activity->properties['_request']['userAgent']);
        $this->assertLoggedViaOtto($activity, $investigation);
    }

    public function test_send_stamps_the_browser_session_onto_the_job(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        $impersonator = ['id' => 7, 'email' => 'audit@example.com'];
        session(['impersonator' => $impersonator]);
        $this->actingAs($user->fresh());

        $this->withHeader('User-Agent', 'OttoAudit/1.0')
            ->postJson(route('otto.conversations.store'), [
                'message' => 'Find plate ABC123',
            ])
            ->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, function (RunOttoChat $job) use ($impersonator): bool {
            return ($job->activitySession['impersonator'] ?? null) === $impersonator
                && ($job->activitySession['userAgent'] ?? null) === 'OttoAudit/1.0'
                && is_string($job->activitySession['clientIp'] ?? null);
        });
    }

    private function investigation(): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());

        return $investigation;
    }

    private function assertLoggedViaOtto(Activity $activity, Investigation $investigation): void
    {
        $this->assertSame('otto', $activity->properties['via']);
        $this->assertSame($investigation->activeConversationId(), $activity->properties['conversationId']);
        $this->assertStringContainsString('searched via Otto for', $activity->description);
    }

    private function activityFor(User $user, string $logName): Activity
    {
        $activity = Activity::query()
            ->where('log_name', $logName)
            ->where('causer_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertInstanceOf(Activity::class, $activity);

        return $activity;
    }

    private function assertNoActivityFor(User $user): void
    {
        $this->assertSame(
            0,
            Activity::query()->where('causer_id', $user->id)->count(),
        );
    }

    private function createScoutAdmin(): User
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        return $admin->fresh();
    }

    private function createAdminWithoutScout(): User
    {
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $user->assignRole(RoleEnum::ADMIN->value);

        return $user->fresh();
    }
}
