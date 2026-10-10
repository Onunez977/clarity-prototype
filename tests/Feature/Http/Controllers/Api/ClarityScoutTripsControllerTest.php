<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\DispositionEnum;
use App\Facades\Analytics;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class ClarityScoutTripsControllerTest extends TestCase
{
    use RequiresClickhouse;

    private User $user;

    private Kit $kit;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        config()->set('app.experimental.clarity_scout_trips', true);
        Permission::findOrCreate('clarity-scout', 'web');
        Analytics::shouldReceive('track')->zeroOrMoreTimes()->andReturnNull();

        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $this->kit = Kit::factory()->forOrg($org)->create();

        $this->user = User::factory()->forOrg($org)->forKit($this->kit)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);

        setPermissionsTeamId($this->user->org_id);
        $this->user->givePermissionTo('clarity-scout');

        $this->token = $this->user->createToken('clarity-scout-token')->plainTextToken;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function insertKitTrip(array $overrides = []): void
    {
        $defaults = [
            'kit_id' => $this->kit->id,
            'org_id' => $this->user->org_id,
            'kit_mapping' => $this->kit->kit_mapping ?? 'TEST-KIT',
            'gl' => '',
            'trip_date' => now()->toDateString(),
            'trip_start' => now()->subMinutes(30)->toDateTimeString(),
            'trip_end' => now()->toDateTimeString(),
            'trip_start_local' => now()->subMinutes(30)->toDateTimeString(),
            'trip_end_local' => now()->toDateTimeString(),
            'duration_minutes' => 30,
            'point_count' => 10,
            'path_simplified' => config('database.connections.clarity_clickhouse.driver') === 'clickhouse'
                ? '[(-97.7, 30.3), (-97.71, 30.31), (-97.72, 30.32)]'
                : json_encode([[-97.7, 30.3], [-97.71, 30.31], [-97.72, 30.32]]),
            'avg_speed' => 25.0,
            'max_speed' => 45.0,
        ];

        DB::connection('clarity_clickhouse')->table('clarity.kit_trips')
            ->insert(array_merge($defaults, $overrides));
    }

    private function insertScanHot(array $overrides = []): void
    {
        static $scanId = 1;
        $defaults = [
            'id' => $scanId++,
            'kit_id' => $this->kit->id,
            'org_id' => $this->user->org_id,
            'ocr' => 'ABC1234',
            'us_state' => 'TX',
            'camera_name' => 'cam1',
            'scanned_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'lng' => -97.7,
            'lat' => 30.3,
            '_peerdb_is_deleted' => 0,
        ];
        $row = array_merge($defaults, $overrides);

        // Production CDC writes to both hot and cold. The `current()` endpoint
        // reads scans_hot; `browse()` reads scans_cold — tests need both populated.
        DB::connection('clarity_clickhouse')->table('clarity.scans_hot')->insert($row);
        DB::connection('clarity_clickhouse')->table('clarity.scans_cold')->insert($row);
    }

    private function insertHitHot(array $overrides = []): void
    {
        static $hitId = 1;
        $defaults = [
            'id' => $hitId++,
            'kit_id' => $this->kit->id,
            'kit_org_id' => $this->user->org_id,
            'hotlist_org_id' => $this->user->org_id,
            'hotlist_id' => 1,
            'scan_id' => 1,
            'hit_type' => 'L',
            'hit_created_at' => now()->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->toDateTimeString(),
            'camera_name' => 'cam1',
            'scan_us_state' => 'TX',
            'kit_name' => 'Test Kit',
            'lat' => 30.3,
            'lng' => -97.7,
            'vehicle_year' => '2020',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Camry',
            'hotlist_us_state' => 'TX',
            '_peerdb_is_deleted' => 0,
        ];
        $row = array_merge($defaults, $overrides);

        // `current()` reads hits_cold (not hot); `browse()` also reads hits_cold.
        // Production CDC writes both — populate both to match.
        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert($row);
        DB::connection('clarity_clickhouse')->table('clarity.hits_cold')->insert($row);
    }

    private function insertHitCold(array $overrides = []): void
    {
        static $hitColdId = 10_000;
        $defaults = [
            'id' => $hitColdId++,
            'kit_id' => $this->kit->id,
            'kit_org_id' => $this->user->org_id,
            'hotlist_org_id' => $this->user->org_id,
            'hotlist_id' => 1,
            'scan_id' => 1,
            'hit_type' => 'L',
            'hit_created_at' => now()->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->toDateTimeString(),
            'camera_name' => 'cam1',
            'scan_us_state' => 'TX',
            'kit_name' => 'Test Kit',
            'lat' => 30.3,
            'lng' => -97.7,
            'vehicle_year' => '2020',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Camry',
            'hotlist_us_state' => 'TX',
            '_peerdb_is_deleted' => 0,
        ];

        DB::connection('clarity_clickhouse')->table('clarity.hits_cold')
            ->insert(array_merge($defaults, $overrides));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_current_trip(): void
    {
        $tripStart = now()->subMinutes(20);
        $tripEnd = now();

        $this->insertKitTrip([
            'trip_start' => $tripStart->toDateTimeString(),
            'trip_end' => $tripEnd->toDateTimeString(),
            'duration_minutes' => 20,
        ]);

        // Insert 3 scans within the trip window
        $this->insertScanHot(['scanned_at' => $tripStart->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $tripStart->copy()->addMinutes(10)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $tripEnd->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/trips/current');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['scans', 'hits', 'miles_driven', 'active_duration_minutes', 'started_at', 'is_active', 'path'],
            ]);

        $this->assertEquals(3, $response->json('data.scans'));

        // The saved trip path is returned so the Live-mode map can backfill its
        // real-time trail from it. [lng, lat] order matches path_simplified.
        $path = $response->json('data.path');
        $this->assertCount(3, $path);
        $this->assertEquals([-97.7, 30.3], $path[0]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_current_trip_returns_null_when_no_scans(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/scout/trips/current');

        $response->assertSuccessful();
        $this->assertNull($response->json('data'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_current_trip_dedupes_hits_with_final(): void
    {
        // Two CDC versions of one hit inside the trip window. Without FINAL the
        // trip hit count double-counts; FINAL must collapse them to one (DEV-2080).
        $this->insertKitTrip([
            'trip_start' => now()->subMinutes(20)->toDateTimeString(),
            'trip_end' => now()->toDateTimeString(),
            'duration_minutes' => 20,
        ]);

        $hitAt = now()->subMinutes(10)->toDateTimeString();
        $this->insertHitHot(['id' => 7001, 'ocr' => 'TRIPDUP', 'hit_created_at' => $hitAt, '_version' => 1]);
        $this->insertHitHot(['id' => 7001, 'ocr' => 'TRIPDUP', 'hit_created_at' => $hitAt, '_version' => 2]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/trips/current');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_current_trip_excludes_hits_with_rejected_dispositions(): void
    {
        // Consistent disposition rule (DEV-2080): a NULL/Valid hit counts; an
        // Invalid hit in the same window does not.
        $this->insertKitTrip([
            'trip_start' => now()->subMinutes(20)->toDateTimeString(),
            'trip_end' => now()->toDateTimeString(),
            'duration_minutes' => 20,
        ]);

        $hitAt = now()->subMinutes(10)->toDateTimeString();
        $this->insertHitHot(['ocr' => 'TRIPOK1', 'hit_created_at' => $hitAt]);
        $this->insertHitHot(['ocr' => 'TRIPBAD', 'hit_created_at' => $hitAt, 'disposition' => DispositionEnum::INVALID->value]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/trips/current');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_trip_list(): void
    {
        $today = now()->startOfDay();

        // Trip 1: 8:00 - 8:20 with 3 scans
        $this->insertKitTrip([
            'trip_start' => $today->copy()->setTime(8, 0)->toDateTimeString(),
            'trip_end' => $today->copy()->setTime(8, 20)->toDateTimeString(),
            'duration_minutes' => 20,
        ]);

        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(8, 0)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(8, 10)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(8, 20)->toDateTimeString()]);

        // Trip 2: 10:30 - 10:45 with 2 scans
        $this->insertKitTrip([
            'trip_start' => $today->copy()->setTime(10, 30)->toDateTimeString(),
            'trip_end' => $today->copy()->setTime(10, 45)->toDateTimeString(),
            'duration_minutes' => 15,
        ]);

        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(10, 30)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(10, 45)->toDateTimeString()]);

        $date = $today->toDateString();
        $response = $this->withToken($this->token)
            ->getJson("/api/scout/trips/browse?start_date={$date}&end_date={$date}");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['date', 'trip_index', 'scans', 'hits', 'miles_driven', 'active_duration_minutes', 'started_at', 'ended_at'],
                ],
            ]);

        $this->assertNotEmpty($response->json('data'));
        $this->assertEquals(5, collect($response->json('data'))->sum('scans'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trip_list_filters_by_range(): void
    {
        $today = now()->startOfDay();

        // Trip today with 2 scans
        $this->insertKitTrip([
            'trip_date' => $today->toDateString(),
            'trip_start' => $today->copy()->setTime(9, 0)->toDateTimeString(),
            'trip_end' => $today->copy()->setTime(9, 30)->toDateTimeString(),
        ]);

        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(9, 0)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(9, 30)->toDateTimeString()]);

        // Trip last week with 3 scans
        $lastWeek = now()->subWeek()->startOfDay();
        $this->insertKitTrip([
            'trip_date' => $lastWeek->toDateString(),
            'trip_start' => $lastWeek->copy()->setTime(9, 0)->toDateTimeString(),
            'trip_end' => $lastWeek->copy()->setTime(9, 30)->toDateTimeString(),
        ]);

        $this->insertScanHot(['scanned_at' => $lastWeek->copy()->setTime(9, 0)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $lastWeek->copy()->setTime(9, 15)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $lastWeek->copy()->setTime(9, 30)->toDateTimeString()]);

        $date = $today->toDateString();
        $todayResponse = $this->withToken($this->token)
            ->getJson("/api/scout/trips/browse?start_date={$date}&end_date={$date}");

        $todayResponse->assertSuccessful();
        $totalTodayScans = collect($todayResponse->json('data'))->sum('scans');
        $this->assertEquals(2, $totalTodayScans);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_trip_detail(): void
    {
        $today = now()->format('Y-m-d');
        $tripStart = now()->setTime(10, 0);
        $tripEnd = now()->setTime(10, 15);

        $this->insertKitTrip([
            'trip_start' => $tripStart->toDateTimeString(),
            'trip_end' => $tripEnd->toDateTimeString(),
            'duration_minutes' => 15,
        ]);

        // Scans within trip
        $this->insertScanHot(['scanned_at' => $tripStart->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $tripEnd->toDateTimeString()]);

        // Hit within trip
        $this->insertHitHot(['hit_created_at' => $tripStart->copy()->addMinutes(5)->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson("/api/scout/trips/{$today}/0");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['date', 'trip_index', 'scans', 'hits', 'miles_driven', 'active_duration_minutes', 'started_at', 'ended_at', 'path', 'hits_detail'],
            ]);

        $this->assertEquals(2, $response->json('data.scans'));
        $this->assertEquals($today, $response->json('data.date'));
        $this->assertEquals(0, $response->json('data.trip_index'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trip_detail_returns_404_for_invalid_trip(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/scout/trips/2020-01-01/99')
            ->assertStatus(404);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trip_list_is_scoped_to_kit(): void
    {
        $today = now()->startOfDay();

        // Trip for user's kit with 2 scans
        $this->insertKitTrip([
            'trip_start' => $today->copy()->setTime(9, 0)->toDateTimeString(),
            'trip_end' => $today->copy()->setTime(9, 30)->toDateTimeString(),
        ]);

        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(9, 0)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(9, 30)->toDateTimeString()]);

        // Trip for a different kit (should not appear)
        $otherKit = Kit::factory()->forOrg($this->user->org)->create();

        DB::connection('clarity_clickhouse')->table('clarity.kit_trips')->insert([
            'kit_id' => $otherKit->id,
            'org_id' => $this->user->org_id,
            'kit_mapping' => 'OTHER-KIT',
            'gl' => '',
            'trip_date' => $today->toDateString(),
            'trip_start' => $today->copy()->setTime(10, 0)->toDateTimeString(),
            'trip_end' => $today->copy()->setTime(10, 30)->toDateTimeString(),
            'trip_start_local' => $today->copy()->setTime(10, 0)->toDateTimeString(),
            'trip_end_local' => $today->copy()->setTime(10, 30)->toDateTimeString(),
            'duration_minutes' => 30,
            'point_count' => 5,
            'path_simplified' => config('database.connections.clarity_clickhouse.driver') === 'clickhouse'
                ? '[(-97.8, 30.4)]'
                : json_encode([[-97.8, 30.4]]),
            'avg_speed' => 20.0,
            'max_speed' => 40.0,
        ]);

        // Scans for other kit (should not be counted)
        DB::connection('clarity_clickhouse')->table('clarity.scans_hot')->insert([
            'kit_id' => $otherKit->id,
            'org_id' => $this->user->org_id,
            'ocr' => 'XYZ9999',
            'us_state' => 'TX',
            'camera_name' => 'cam1',
            'scanned_at' => $today->copy()->setTime(10, 0)->toDateTimeString(),
            'created_at' => $today->copy()->setTime(10, 0)->toDateTimeString(),
            '_peerdb_is_deleted' => 0,
        ]);
        DB::connection('clarity_clickhouse')->table('clarity.scans_hot')->insert([
            'kit_id' => $otherKit->id,
            'org_id' => $this->user->org_id,
            'ocr' => 'XYZ9999',
            'us_state' => 'TX',
            'camera_name' => 'cam1',
            'scanned_at' => $today->copy()->setTime(10, 15)->toDateTimeString(),
            'created_at' => $today->copy()->setTime(10, 15)->toDateTimeString(),
            '_peerdb_is_deleted' => 0,
        ]);
        DB::connection('clarity_clickhouse')->table('clarity.scans_hot')->insert([
            'kit_id' => $otherKit->id,
            'org_id' => $this->user->org_id,
            'ocr' => 'XYZ9999',
            'us_state' => 'TX',
            'camera_name' => 'cam1',
            'scanned_at' => $today->copy()->setTime(10, 30)->toDateTimeString(),
            'created_at' => $today->copy()->setTime(10, 30)->toDateTimeString(),
            '_peerdb_is_deleted' => 0,
        ]);

        $date = now()->toDateString();
        $response = $this->withToken($this->token)
            ->getJson("/api/scout/trips/browse?start_date={$date}&end_date={$date}");

        $response->assertSuccessful();

        $totalScans = collect($response->json('data'))->sum('scans');
        $this->assertEquals(2, $totalScans);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trip_list_response_is_cached(): void
    {
        $date = now()->toDateString();
        $cacheKey = "scanner:trips:browse:{$this->user->org_id}:{$this->kit->id}:{$date}:{$date}";

        $this->assertFalse(Cache::has($cacheKey));

        $this->withToken($this->token)
            ->getJson("/api/scout/trips/browse?start_date={$date}&end_date={$date}")
            ->assertSuccessful();

        $this->assertTrue(Cache::has($cacheKey));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_current_trip_includes_hits_count(): void
    {
        $tripStart = now()->subMinutes(10);
        $tripEnd = now();

        $this->insertKitTrip([
            'trip_start' => $tripStart->toDateTimeString(),
            'trip_end' => $tripEnd->toDateTimeString(),
            'duration_minutes' => 10,
        ]);

        $this->insertScanHot(['scanned_at' => $tripStart->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $tripEnd->toDateTimeString()]);

        // 2 hits within trip window
        $this->insertHitHot(['hit_created_at' => $tripStart->copy()->addMinutes(3)->toDateTimeString()]);
        $this->insertHitHot(['hit_created_at' => $tripStart->copy()->addMinutes(7)->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/trips/current');

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_current_trip_is_active_when_recent(): void
    {
        // Trip ended 5 minutes ago (within 30-min threshold)
        $this->insertKitTrip([
            'trip_start' => now()->subMinutes(15)->toDateTimeString(),
            'trip_end' => now()->subMinutes(5)->toDateTimeString(),
            'duration_minutes' => 10,
        ]);

        $this->insertScanHot(['scanned_at' => now()->subMinutes(5)->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/trips/current');

        $response->assertSuccessful();
        $this->assertTrue($response->json('data.is_active'));
        $this->assertNull($response->json('data.ended_at'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_current_trip_is_not_active_when_stale(): void
    {
        // Trip ended 45 minutes ago (over 30-min threshold)
        $this->insertKitTrip([
            'trip_start' => now()->subMinutes(60)->toDateTimeString(),
            'trip_end' => now()->subMinutes(45)->toDateTimeString(),
            'duration_minutes' => 15,
        ]);

        $this->insertScanHot(['scanned_at' => now()->subMinutes(45)->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/trips/current');

        $response->assertSuccessful();
        $this->assertFalse($response->json('data.is_active'));
        $this->assertNotNull($response->json('data.ended_at'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trip_detail_includes_hits_detail(): void
    {
        $today = now()->format('Y-m-d');
        $tripStart = now()->setTime(11, 0);
        $tripEnd = now()->setTime(11, 15);

        $this->insertKitTrip([
            'trip_start' => $tripStart->toDateTimeString(),
            'trip_end' => $tripEnd->toDateTimeString(),
            'duration_minutes' => 15,
        ]);

        $this->insertScanHot(['scanned_at' => $tripStart->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $tripEnd->toDateTimeString()]);

        $this->insertHitCold([
            'hit_created_at' => $tripStart->copy()->addMinutes(5)->toDateTimeString(),
            'ocr' => 'HIT1234',
            'vehicle_year' => '2019',
            'vehicle_make' => 'Honda',
            'vehicle_model' => 'Accord',
            'hotlist_us_state' => 'CA',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/scout/trips/{$today}/0");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'hits_detail' => [
                        '*' => ['plate', 'time', 'type', 'lat', 'lng', 'vehicle_year', 'vehicle_make', 'vehicle_model', 'hotlist_us_state'],
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.hits_detail'));

        $hit = $response->json('data.hits_detail.0');
        $this->assertEquals('2019', $hit['vehicle_year']);
        $this->assertEquals('Honda', $hit['vehicle_make']);
        $this->assertEquals('Accord', $hit['vehicle_model']);
        $this->assertEquals('CA', $hit['hotlist_us_state']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trip_detail_hits_detail_vehicle_fields_are_nullable(): void
    {
        $today = now()->format('Y-m-d');
        $tripStart = now()->setTime(11, 0);
        $tripEnd = now()->setTime(11, 15);

        $this->insertKitTrip([
            'trip_start' => $tripStart->toDateTimeString(),
            'trip_end' => $tripEnd->toDateTimeString(),
            'duration_minutes' => 15,
        ]);

        $this->insertScanHot(['scanned_at' => $tripStart->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $tripEnd->toDateTimeString()]);

        // Vehicle fields are Nullable(String) in hits_cold — a hit with no
        // decode data must surface the keys as null, not be coerced/omitted.
        $this->insertHitCold([
            'hit_created_at' => $tripStart->copy()->addMinutes(5)->toDateTimeString(),
            'ocr' => 'NULLVEH',
            'vehicle_year' => null,
            'vehicle_make' => null,
            'vehicle_model' => null,
            'hotlist_us_state' => null,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/scout/trips/{$today}/0");

        $response->assertSuccessful();

        $hit = $response->json('data.hits_detail.0');
        $this->assertNull($hit['vehicle_year']);
        $this->assertNull($hit['vehicle_make']);
        $this->assertNull($hit['vehicle_model']);
        $this->assertNull($hit['hotlist_us_state']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_day_detail_hits_detail_includes_vehicle_fields(): void
    {
        $today = now()->format('Y-m-d');
        $tripStart = now()->setTime(12, 0);
        $tripEnd = now()->setTime(12, 15);

        $this->insertKitTrip([
            'trip_start' => $tripStart->toDateTimeString(),
            'trip_end' => $tripEnd->toDateTimeString(),
            'duration_minutes' => 15,
        ]);

        $this->insertScanHot(['scanned_at' => $tripStart->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => $tripEnd->toDateTimeString()]);

        // Hit placed on the trip path so it survives the corridor filter.
        $this->insertHitCold([
            'hit_created_at' => $tripStart->copy()->addMinutes(5)->toDateTimeString(),
            'ocr' => 'DAYHIT1',
            'lat' => 30.3,
            'lng' => -97.7,
            'vehicle_year' => '2021',
            'vehicle_make' => 'Ford',
            'vehicle_model' => 'F-150',
            'hotlist_us_state' => 'NV',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/scout/trips/day/{$today}");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'hits_detail' => [
                        '*' => ['plate', 'time', 'type', 'lat', 'lng', 'vehicle_year', 'vehicle_make', 'vehicle_model', 'hotlist_us_state'],
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.hits_detail'));

        $hit = $response->json('data.hits_detail.0');
        $this->assertEquals('2021', $hit['vehicle_year']);
        $this->assertEquals('Ford', $hit['vehicle_make']);
        $this->assertEquals('F-150', $hit['vehicle_model']);
        $this->assertEquals('NV', $hit['hotlist_us_state']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trip_list_returns_empty_for_no_scans(): void
    {
        $date = now()->toDateString();
        $response = $this->withToken($this->token)
            ->getJson("/api/scout/trips/browse?start_date={$date}&end_date={$date}");

        $response->assertSuccessful();
        $this->assertEmpty($response->json('data'));
    }

    /**
     * Contract: lock the envelope the PWA trips views read. `current` always
     * returns a `data` key (null when there's no trip today); `browse` always
     * returns `data` + a `meta` paging block. A shape change fails CI.
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trip_response_envelopes_are_locked(): void
    {
        $this->withToken($this->token)->getJson('/api/scout/trips/current')
            ->assertSuccessful()
            ->assertJsonStructure(['data']);

        $date = now()->toDateString();
        $this->withToken($this->token)
            ->getJson("/api/scout/trips/browse?start_date={$date}&end_date={$date}")
            ->assertSuccessful()
            ->assertJsonStructure([
                'data',
                'meta' => ['start_date', 'end_date', 'has_more'],
            ]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_browse_rejects_a_date_range_over_90_days(): void
    {
        $start = now()->subDays(200)->toDateString();
        $end = now()->toDateString();

        $this->withToken($this->token)
            ->getJson("/api/scout/trips/browse?start_date={$start}&end_date={$end}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Date range may not exceed 90 days.');
    }

    /**
     * DEV-2164 M1: every trips action is gated behind clarity_scout_trips. With
     * the sub-flag off, the guard 404s before any ClickHouse query runs.
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trips_routes_return_404_when_trips_flag_disabled(): void
    {
        config()->set('app.experimental.clarity_scout_trips', false);

        $date = now()->toDateString();

        $this->withToken($this->token)->getJson('/api/scout/trips/current')->assertStatus(404);
        $this->withToken($this->token)->getJson("/api/scout/trips/browse?start_date={$date}&end_date={$date}")->assertStatus(404);
        $this->withToken($this->token)->getJson("/api/scout/trips/day/{$date}")->assertStatus(404);
        $this->withToken($this->token)->getJson("/api/scout/trips/{$date}/0")->assertStatus(404);
    }

    /**
     * DEV-2164 M1: with the flag on the guard is a no-op. A seeded trip lets
     * day/show resolve so the only remaining 404 source (the gate) is gone.
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_trips_routes_are_reachable_when_trips_flag_enabled(): void
    {
        $today = now()->startOfDay();

        $this->insertKitTrip([
            'trip_start' => $today->copy()->setTime(9, 0)->toDateTimeString(),
            'trip_end' => $today->copy()->setTime(9, 30)->toDateTimeString(),
            'duration_minutes' => 30,
        ]);
        $this->insertScanHot(['scanned_at' => $today->copy()->setTime(9, 0)->toDateTimeString()]);

        $date = now()->toDateString();

        $this->withToken($this->token)->getJson('/api/scout/trips/current')->assertStatus(200);
        $this->withToken($this->token)->getJson("/api/scout/trips/browse?start_date={$date}&end_date={$date}")->assertStatus(200);
        $this->withToken($this->token)->getJson("/api/scout/trips/day/{$date}")->assertStatus(200);
        $this->withToken($this->token)->getJson("/api/scout/trips/{$date}/0")->assertStatus(200);
    }

    /**
     * DEV-2164 M2: browse() builds its window in the user's timezone but filters
     * the UTC scanned_at column. An evening scan in a US timezone crosses into
     * the next UTC day and was dropped before the ->utc() conversion.
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_browse_counts_a_scan_whose_utc_time_lands_in_the_local_evening(): void
    {
        // kit_trips carries a ClickHouse TTL of trip_end + 90 days, evaluated against the
        // CH server clock (which Carbon::setTestNow cannot move) — so a hardcoded past date
        // silently ages out and the trip is dropped. Anchor the fixture to a recent day so it
        // stays inside the TTL whenever CI runs, while preserving the tz-crossing this test
        // exercises: a 04:00 UTC scan is the previous evening in America/Chicago, so it must
        // be counted for the previous local date.
        $scanUtc = Carbon::now('UTC')->subDays(2)->setTime(4, 0, 0);
        $localDate = $scanUtc->copy()->setTimezone('America/Chicago')->toDateString();
        $tripStart = $scanUtc->copy()->subMinutes(30);
        $tripEnd = $scanUtc->copy()->addMinutes(30);

        // Freeze "now" just after the fixture so the trip/scan stay inside the
        // kit_trips recency window and the hot/cold routing, isolating the tz fix.
        Carbon::setTestNow($scanUtc->copy()->addHours(8));

        $this->user->update(['dti' => ['timeZone' => 'America/Chicago']]);

        $this->insertKitTrip([
            'trip_date' => $localDate,
            'trip_start' => $tripStart->toDateTimeString(),
            'trip_end' => $tripEnd->toDateTimeString(),
            'trip_start_local' => $tripStart->copy()->setTimezone('America/Chicago')->toDateTimeString(),
            'trip_end_local' => $tripEnd->copy()->setTimezone('America/Chicago')->toDateTimeString(),
            'duration_minutes' => 60,
        ]);

        $this->insertScanHot(['scanned_at' => $scanUtc->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson("/api/scout/trips/browse?start_date={$localDate}&end_date={$localDate}");

        $response->assertSuccessful();
        $this->assertEquals(1, collect($response->json('data'))->sum('scans'));
    }
}
