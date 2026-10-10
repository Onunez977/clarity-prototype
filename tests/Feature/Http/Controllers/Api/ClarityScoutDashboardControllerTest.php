<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\DispositionEnum;
use App\Enums\HotlistStatusEnum;
use App\Facades\Analytics;
use App\Models\ApprovalList;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class ClarityScoutDashboardControllerTest extends TestCase
{
    use RequiresClickhouse;

    private User $user;

    private Kit $kit;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
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

        // recentHits now derives status from the order's CURRENT clarity.hotlist
        // state (FINAL semi-join, DEV-2117), so the default hit hotlist_id (1)
        // needs a matching currently-Open order to surface.
        $this->insertHotlist(['id' => 1]);
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

        DB::connection('clarity_clickhouse')->table('clarity.scans_hot')->insert($row);
    }

    private function insertHotlist(array $overrides = []): int
    {
        static $hotlistId = 1000;
        $defaults = [
            'id' => $hotlistId++,
            'org_id' => $this->user->org_id,
            'status' => HotlistStatusEnum::Open->value,
            'order_type' => 1,
            'is_staged' => false,
            'plate' => 'ABC1234',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ];
        $row = array_merge($defaults, $overrides);

        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert($row);

        return (int) $row['id'];
    }

    /**
     * Seed a row on clarity.scout_open_addresses. The MV already applied
     * Open + plate-length + EWKT parse (chlocal migration 111).
     */
    private function insertOpenAssignmentAddress(
        int $hotlistId,
        ?float $lng = null,
        ?float $lat = null,
        ?int $addressId = null,
        ?int $orgId = null,
        bool $isStaged = false,
    ): int {
        static $autoAddressId = 5000;
        $resolvedAddressId = $addressId ?? $autoAddressId++;

        DB::connection('clarity_clickhouse')->table('clarity.scout_open_addresses')->insert([
            'org_id' => $orgId ?? $this->user->org_id,
            'is_staged' => $isStaged,
            'hotlist_id' => $hotlistId,
            'address_id' => $resolvedAddressId,
            'geo' => sprintf('(%s, %s)', $lng ?? 0, $lat ?? 0),
            'refreshed_at' => now()->toDateTimeString(),
        ]);

        return $resolvedAddressId;
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
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'hit_created_at' => now()->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->toDateTimeString(),
            'camera_name' => 'cam1',
            'scan_us_state' => 'TX',
            'kit_name' => 'Test Kit',
            'lat' => 30.3,
            'lng' => -97.7,
            '_peerdb_is_deleted' => 0,
        ];
        $row = array_merge($defaults, $overrides);

        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert($row);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_dashboard_stats(): void
    {
        config()->set('app.experimental.clarity_scout_trips', true);

        // Create scans for today
        $this->insertScanHot();
        $this->insertScanHot();
        $this->insertScanHot();

        // Create a hit for today
        $this->insertHitHot();

        // Insert a kit_trip record for today
        DB::connection('clarity_clickhouse')->table('clarity.kit_trips')->insert([
            'kit_id' => $this->kit->id,
            'org_id' => $this->user->org_id,
            'kit_mapping' => 'TEST-KIT',
            'gl' => '',
            'trip_date' => now()->toDateString(),
            'trip_start' => now()->subMinutes(30)->toDateTimeString(),
            'trip_end' => now()->toDateTimeString(),
            'trip_start_local' => now()->subMinutes(30)->toDateTimeString(),
            'trip_end_local' => now()->toDateTimeString(),
            'duration_minutes' => 30,
            'point_count' => 0,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['scans_today', 'direct_hits', 'staging_hits', 'repos_affected', 'trip_count'],
            ]);

        $this->assertEquals(3, $response->json('data.scans_today'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.direct_hits'));
        $this->assertEquals(1, $response->json('data.trip_count'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_skips_trip_count_when_trips_disabled(): void
    {
        // clarity_scout_trips defaults off → the kit_trips query is skipped and
        // trip_count reports 0 even when trips exist for the kit.
        config()->set('app.experimental.clarity_scout_trips', false);

        DB::connection('clarity_clickhouse')->table('clarity.kit_trips')->insert([
            'kit_id' => $this->kit->id,
            'org_id' => $this->user->org_id,
            'kit_mapping' => 'TEST-KIT',
            'gl' => '',
            'trip_date' => now()->toDateString(),
            'trip_start' => now()->subMinutes(30)->toDateTimeString(),
            'trip_end' => now()->toDateTimeString(),
            'trip_start_local' => now()->subMinutes(30)->toDateTimeString(),
            'trip_end_local' => now()->toDateTimeString(),
            'duration_minutes' => 30,
            'point_count' => 0,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(0, $response->json('data.trip_count'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_filters_by_range(): void
    {
        // Scans 20 days ago — inside last_30, outside last_7.
        $this->insertScanHot(['scanned_at' => now()->subDays(20)->toDateTimeString()]);
        $this->insertScanHot(['scanned_at' => now()->subDays(20)->toDateTimeString()]);

        // Create scans for today
        $this->insertScanHot();
        $this->insertScanHot();
        $this->insertScanHot();
        $this->insertScanHot();
        $this->insertScanHot();

        $todayResponse = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats?range=today');

        $todayResponse->assertSuccessful();
        $this->assertEquals(5, $todayResponse->json('data.scans_today'));

        // Clear cache so the next request hits the DB
        Cache::flush();

        // last_7 excludes the 20-day-old scans.
        $weekResponse = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats?range=last_7');

        $weekResponse->assertSuccessful();
        $this->assertEquals(5, $weekResponse->json('data.scans_today'));

        Cache::flush();

        // last_30 includes the 20-day-old scans plus today's.
        $monthResponse = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats?range=last_30');

        $monthResponse->assertSuccessful();
        $this->assertGreaterThanOrEqual(7, $monthResponse->json('data.scans_today'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_are_scoped_to_kit(): void
    {
        // Create scans for the user's kit
        $this->insertScanHot();
        $this->insertScanHot();
        $this->insertScanHot();

        // Create scans for a different kit
        $otherKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertScanHot(['kit_id' => $otherKit->id]);
        $this->insertScanHot(['kit_id' => $otherKit->id]);
        $this->insertScanHot(['kit_id' => $otherKit->id]);
        $this->insertScanHot(['kit_id' => $otherKit->id]);
        $this->insertScanHot(['kit_id' => $otherKit->id]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(3, $response->json('data.scans_today'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_recent_hits(): void
    {
        $this->insertHitHot(['ocr' => 'AAA1111', 'scan_id' => 101]);
        $this->insertHitHot(['ocr' => 'BBB2222', 'scan_id' => 102]);
        $this->insertHitHot(['ocr' => 'CCC3333', 'scan_id' => 103]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'scan_id', 'plate', 'hit_time', 'hit_type', 'status', 'lat', 'lng', 'disposition'],
                ],
            ]);

        // DEV-2059: scan_id (the CH hit's underlying scan) rides alongside the
        // CH hit `id` (used for list keying) so the hit-detail modal can write a
        // disposition against the right scan.
        $scanIds = collect($response->json('data'))->pluck('scan_id')->sort()->values()->all();
        $this->assertSame([101, 102, 103], $scanIds);

        // DEV-2059 F1: a New Hit (no disposition row) surfaces a null, not "New Hit".
        $this->assertSame([null, null, null], collect($response->json('data'))->pluck('disposition')->all());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_carries_the_saved_accessible_disposition(): void
    {
        // DEV-2059 F1: an Accessible hit (the only non-null value this allowlisted
        // surface can carry — see liveActionable()) must round-trip its
        // disposition so the hit-detail modal can seed the dropdown from it on
        // re-open, instead of resetting to the placeholder.
        $this->insertHitHot(['ocr' => 'ACCESS77', 'scan_id' => 201, 'disposition' => DispositionEnum::ACCESSIBLE->value]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()
            ->assertJsonPath('data.0.scan_id', 201)
            ->assertJsonPath('data.0.disposition', DispositionEnum::ACCESSIBLE->value);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_respects_the_range_filter(): void
    {
        $this->insertHitHot(['ocr' => 'TODAY11', 'hit_created_at' => now()->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'OLD2222', 'hit_created_at' => now()->subDays(20)->toDateTimeString()]);

        // Default range (today) shows only the hit inside today's window.
        $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plate', 'TODAY11');

        // Widening to last_30 pulls in the 20-day-old hit — the list tracks the filter.
        $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits?range=last_30')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_yesterday_range_returns_only_the_previous_day(): void
    {
        // DEV-2186: the driver "Yesterday" pill is a distinct single-day window
        // (previous day 00:00–23:59), not a rolling range — so it must exclude
        // both today's hits and anything older than yesterday.
        $this->insertHitHot(['ocr' => 'TODAY11', 'hit_created_at' => now()->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'YESTER1', 'hit_created_at' => now()->subDay()->setTime(12, 0)->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'OLD3333', 'hit_created_at' => now()->subDays(3)->toDateTimeString()]);

        $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits?range=yesterday')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plate', 'YESTER1');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_status_is_human_readable_label(): void
    {
        $this->insertHitHot(['hotlist_status' => HotlistStatusEnum::Open->value]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful();

        // The current-status semi-join guarantees Open, emitted as a human-readable
        // label rather than a raw enum value.
        $this->assertSame('Open', $response->json('data.0.status'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_excludes_staging_live(): void
    {
        $this->insertHitHot(['ocr' => 'LIVE111', 'hit_type' => 'L']);
        // Staging Live is dropped so the list reconciles with the Live-only My Hits count.
        $this->insertHitHot(['ocr' => 'STAG222', 'hit_type' => 'S', 'hotlist_org_id' => $this->user->org_id + 999]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()->assertJsonCount(1, 'data');
        $this->assertSame('LIVE111', $response->json('data.0.plate'));
        $this->assertSame('L', $response->json('data.0.hit_type'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_filters_by_disposition(): void
    {
        // DEV-2059 correction: recent-hits uses the base-Hits-page "actionable"
        // rule (NULL/New Hit or Accessible) — matching HitsResource's default
        // filter — NOT the analytics validDisposition rule (NULL/Valid) that
        // stats() keeps. Null (New Hit) and Accessible are pending-repo states
        // the driver should still see; Valid is no longer one of them here.
        $this->insertHitHot(['ocr' => 'NULL111', 'disposition' => null]);
        $this->insertHitHot(['ocr' => 'ACCESS22', 'disposition' => DispositionEnum::ACCESSIBLE->value]);
        // Valid, and any other disposition, means the hit has been actioned/closed
        // out (or is only an analytics "valid" hit) — drop it from this list.
        $this->insertHitHot(['ocr' => 'VALID33', 'disposition' => DispositionEnum::VALID->value]);
        $this->insertHitHot(['ocr' => 'INACC44', 'disposition' => DispositionEnum::INACCESSIBLE->value]);
        $this->insertHitHot(['ocr' => 'INVAL55', 'disposition' => DispositionEnum::INVALID->value]);
        $this->insertHitHot(['ocr' => 'REVIE66', 'disposition' => DispositionEnum::REVIEWED->value]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()->assertJsonCount(2, 'data');

        $plates = collect($response->json('data'))->pluck('plate')->sort()->values()->all();
        $this->assertSame(['ACCESS22', 'NULL111'], $plates);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_is_subset_of_my_hits(): void
    {
        // A Live hit this kit generated, but the order belongs to a partner (staging) org.
        // Both queries now scope by kit_org_id, so it appears in BOTH the list and My Hits —
        // the regression guard for the old kit_org_id vs hotlist_org_id mismatch.
        $this->insertHitHot([
            'ocr' => 'SUBSET1',
            'hit_type' => 'L',
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'kit_org_id' => $this->user->org_id,
            'hotlist_org_id' => $this->user->org_id + 999,
        ]);

        $statsResponse = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');
        $statsResponse->assertSuccessful();

        $recentResponse = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');
        $recentResponse->assertSuccessful();

        $this->assertEquals(1, $statsResponse->json('data.direct_hits'));
        $this->assertLessThanOrEqual(
            $statsResponse->json('data.direct_hits'),
            count($recentResponse->json('data')),
        );
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_dedupes_cdc_versions_with_final(): void
    {
        // Two CDC versions of the same hit (same id / sort key, higher _version wins).
        // FINAL must collapse them to a single counted hit.
        $hitCreatedAt = now()->toDateTimeString();
        $this->insertHitHot(['id' => 9001, 'ocr' => 'CDC1234', '_version' => 1, 'hit_created_at' => $hitCreatedAt]);
        $this->insertHitHot(['id' => 9001, 'ocr' => 'CDC1234', '_version' => 2, 'hit_created_at' => $hitCreatedAt]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.direct_hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_dedupes_scans_with_final(): void
    {
        // Two CDC versions of one scan (same sort key). Without FINAL the scans
        // tile counts both and over-reports; FINAL must collapse them (DEV-2080).
        $scannedAt = now()->toDateTimeString();
        $this->insertScanHot(['id' => 8001, '_version' => 1, 'scanned_at' => $scannedAt]);
        $this->insertScanHot(['id' => 8001, '_version' => 2, 'scanned_at' => $scannedAt]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.scans_today'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_excludes_hits_with_rejected_dispositions(): void
    {
        // Consistent disposition rule (DEV-2080): NULL + Valid count; Invalid and
        // Reviewed hits are excluded so the tiles match the heatmap. DEV-2059
        // deliberately diverged the map markers + recent-hits list onto the
        // base-Hits-page "actionable" rule (NULL/New Hit or Accessible) instead —
        // this pins that stats() keeps counting a 'Valid' hit so that divergence
        // isn't "fixed" back into stats() by mistake.
        $this->insertHitHot(['ocr' => 'NULLDISP']);
        $this->insertHitHot(['ocr' => 'VALIDISP', 'disposition' => DispositionEnum::VALID->value]);
        $this->insertHitHot(['ocr' => 'INVALIDD', 'disposition' => DispositionEnum::INVALID->value]);
        $this->insertHitHot(['ocr' => 'REVIEWD1', 'disposition' => DispositionEnum::REVIEWED->value]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.direct_hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_splits_direct_and_staging_hits(): void
    {
        // Two Direct Live hits and one Staging Live hit for this kit/range.
        // direct_hits and staging_hits must split cleanly; repos_affected stays
        // Direct-only (none here are Repossessed, so it is 0).
        $this->insertHitHot(['ocr' => 'DIRECT01', 'hit_type' => 'L']);
        $this->insertHitHot(['ocr' => 'DIRECT02', 'hit_type' => 'L']);
        $this->insertHitHot(['ocr' => 'STAGING1', 'hit_type' => 'S']);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.direct_hits'));
        $this->assertEquals(1, $response->json('data.staging_hits'));
        $this->assertEquals(0, $response->json('data.repos_affected'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_excludes_non_open_statuses(): void
    {
        // Inclusion follows each order's CURRENT hotlist status (FINAL semi-join,
        // DEV-2117), so drive the statuses off distinct clarity.hotlist orders.
        // Order 1 (Open) comes from setUp; add a Repossessed and a Hold order.
        $this->insertHotlist(['id' => 2, 'status' => HotlistStatusEnum::Repossessed->value]);
        $this->insertHotlist(['id' => 3, 'status' => HotlistStatusEnum::Hold->value]);

        $this->insertHitHot(['ocr' => 'OPEN111', 'hotlist_id' => 1]);
        $this->insertHitHot(['ocr' => 'REPO222', 'hotlist_id' => 2]);
        $this->insertHitHot(['ocr' => 'HOLD333', 'hotlist_id' => 3]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->assertSame('OPEN111', $response->json('data.0.plate'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_reflects_current_hotlist_status_over_frozen(): void
    {
        // H4 (DEV-2117): the frozen per-hit hotlist_status is stale — inclusion and
        // the emitted label must follow the order's CURRENT clarity.hotlist status
        // via the FINAL semi-join, not the value baked into the hit at write time.
        $this->insertHotlist(['id' => 2, 'status' => HotlistStatusEnum::Repossessed->value]);

        // Frozen Open, but the order is currently Repossessed → must be ABSENT.
        $this->insertHitHot([
            'ocr' => 'FROZOPN',
            'hotlist_id' => 2,
            'hotlist_status' => HotlistStatusEnum::Open->value,
        ]);

        // Frozen Repossessed, but order 1 is currently Open (setUp) → must be PRESENT.
        $this->insertHitHot([
            'ocr' => 'CUROPEN',
            'hotlist_id' => 1,
            'hotlist_status' => HotlistStatusEnum::Repossessed->value,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->assertSame('CUROPEN', $response->json('data.0.plate'));
        $this->assertSame('Open', $response->json('data.0.status'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_excludes_other_hit_types(): void
    {
        $this->insertHitHot(['ocr' => 'LIVE111', 'hit_type' => 'L']);
        $this->insertHitHot(['ocr' => 'HIST222', 'hit_type' => 'H']);
        $this->insertHitHot(['ocr' => 'PROB333', 'hit_type' => 'P']);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->assertSame('LIVE111', $response->json('data.0.plate'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_dedupes_by_plate_keeping_most_recent(): void
    {
        $this->insertHitHot(['ocr' => 'DUP1234', 'hit_created_at' => now()->subHours(2)->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'DUP1234', 'hit_created_at' => now()->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');

        // The surviving row is the most recent hit for the plate.
        $hitTime = $response->json('data.0.hit_time');
        $this->assertTrue(now()->diffInMinutes($hitTime) < 5);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_includes_lat_lng(): void
    {
        $this->insertHitHot(['lat' => 30.3, 'lng' => -97.7]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful();
        $this->assertSame(30.3, $response->json('data.0.lat'));
        $this->assertSame(-97.7, $response->json('data.0.lng'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_includes_vehicle_description(): void
    {
        $this->insertHitHot([
            'vehicle_year' => '2020',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Camry',
            'hotlist_us_state' => 'TX',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful();
        $this->assertSame('2020', $response->json('data.0.vehicle_year'));
        $this->assertSame('Toyota', $response->json('data.0.vehicle_make'));
        $this->assertSame('Camry', $response->json('data.0.vehicle_model'));
        $this->assertSame('TX', $response->json('data.0.hotlist_us_state'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_hotlist_stats(): void
    {
        // Create open hotlists
        Hotlist::factory()->count(2)->forOrg($this->user->org)->create([
            'status' => HotlistStatusEnum::Open->value,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/hotlist-stats');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['open', 'staged', 'total_active'],
            ]);

        $this->assertEquals(2, $response->json('data.open'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_hotlist_stats_counts_staged_via_approval_list(): void
    {
        // Forwarder org whose staged orders this user's org is approved to work.
        $stagingOrg = Org::factory()->create();
        ApprovalList::query()->create([
            'agent_org_id' => $this->user->org_id,
            'staging_org_id' => $stagingOrg->id,
        ]);

        // Two valid staged opens (Open + staged + plate length >= 3) → counted.
        Hotlist::factory()->count(2)->forOrg($stagingOrg)->create([
            'status' => HotlistStatusEnum::Open->value,
            'is_staged' => true,
            'plate' => 'ABCD12',
        ]);

        // Excluded controls: short plate (< 3), not staged, not open.
        Hotlist::factory()->forOrg($stagingOrg)->create([
            'status' => HotlistStatusEnum::Open->value,
            'is_staged' => true,
            'plate' => 'AB',
        ]);
        Hotlist::factory()->forOrg($stagingOrg)->create([
            'status' => HotlistStatusEnum::Open->value,
            'is_staged' => false,
            'plate' => 'WXYZ99',
        ]);
        Hotlist::factory()->forOrg($stagingOrg)->create([
            'status' => HotlistStatusEnum::Closed->value,
            'is_staged' => true,
            'plate' => 'WXYZ88',
        ]);

        // Staged open on an org this user is NOT approved for → excluded.
        $unapprovedOrg = Org::factory()->create();
        Hotlist::factory()->forOrg($unapprovedOrg)->create([
            'status' => HotlistStatusEnum::Open->value,
            'is_staged' => true,
            'plate' => 'NOPE12',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/hotlist-stats');

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.staged'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_hotlist_stats_open_excludes_short_plates(): void
    {
        // The plate threshold is length >= 3 (M5): 3-char plates count, only
        // plates shorter than 3 are dropped.
        Hotlist::factory()->forOrg($this->user->org)->create([
            'status' => HotlistStatusEnum::Open->value,
            'plate' => 'ABCD12',
        ]);
        Hotlist::factory()->forOrg($this->user->org)->create([
            'status' => HotlistStatusEnum::Open->value,
            'plate' => 'XYZ',
        ]);
        Hotlist::factory()->forOrg($this->user->org)->create([
            'status' => HotlistStatusEnum::Open->value,
            'plate' => 'AB',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/hotlist-stats');

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.open'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/scout/dashboard/stats')
            ->assertStatus(401);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_response_is_cached(): void
    {
        $cacheKey = "scanner:stats:{$this->user->org_id}:{$this->kit->id}:today";

        $this->assertFalse(Cache::has($cacheKey));

        $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats')
            ->assertSuccessful();

        $this->assertTrue(Cache::has($cacheKey));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_response_is_cached(): void
    {
        // The cache key is range-scoped (DEV-2137) so switching the filter can't
        // serve another range's list; the default range is 'today'.
        $cacheKey = "scanner:recent-hits:{$this->user->org_id}:{$this->kit->id}:today";

        $this->assertFalse(Cache::has($cacheKey));

        $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits')
            ->assertSuccessful();

        $this->assertTrue(Cache::has($cacheKey));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_returns_all_pending_hits(): void
    {
        // The 20-item cap was removed (95da4d7f "Remove 20 limit on hits pending");
        // recent-hits now returns every pending hit, deduped to one per plate.
        // 25 distinct plates → all 25 returned.
        for ($i = 0; $i < 25; $i++) {
            $this->insertHitHot(['ocr' => sprintf('PLATE%03d', $i)]);
        }

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()
            ->assertJsonCount(25, 'data');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_scoped_to_kit(): void
    {
        // Hits for user's kit (distinct plates).
        $this->insertHitHot(['ocr' => 'MINE111']);
        $this->insertHitHot(['ocr' => 'MINE222']);

        // Hits for a different kit in the same org.
        $otherKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertHitHot(['ocr' => 'OTHR111', 'kit_id' => $otherKit->id]);
        $this->insertHitHot(['ocr' => 'OTHR222', 'kit_id' => $otherKit->id]);
        $this->insertHitHot(['ocr' => 'OTHR333', 'kit_id' => $otherKit->id]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/recent-hits');

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_hotlist_stats_scoped_to_org(): void
    {
        // Hotlists for user's org
        Hotlist::factory()->count(3)->forOrg($this->user->org)->create([
            'status' => HotlistStatusEnum::Open->value,
        ]);

        // Hotlists for a different org
        $otherOrg = Org::factory()->create();
        Hotlist::factory()->count(5)->forOrg($otherOrg)->create([
            'status' => HotlistStatusEnum::Open->value,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/hotlist-stats');

        $response->assertSuccessful();
        $this->assertEquals(3, $response->json('data.open'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_check_in_counts(): void
    {
        // Direct: open, non-staged hotlist on user's org
        $directId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($directId, lng: -97.7, lat: 30.3);

        // Staging: open, staged hotlist on a partner org the user is approved for
        $stagingOrg = Org::factory()->create();
        ApprovalList::query()->create([
            'agent_org_id' => $this->user->org_id,
            'staging_org_id' => $stagingOrg->id,
        ]);
        $stagingId = $this->insertHotlist([
            'org_id' => $stagingOrg->id,
            'is_staged' => true,
        ]);
        $this->insertOpenAssignmentAddress($stagingId, lng: -97.71, lat: 30.31, orgId: $stagingOrg->id, isStaged: true);

        // Scan within radius of both addresses
        $this->insertScanHot(['lng' => -97.7, 'lat' => 30.3]);
        $this->insertScanHot(['lng' => -97.71, 'lat' => 30.31]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful()
            ->assertJsonStructure(['data' => ['direct_bucket', 'staging_bucket']]);

        $this->assertEquals(1, $response->json('data.direct_bucket'));
        $this->assertEquals(1, $response->json('data.staging_bucket'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_check_in_counts_respect_radius(): void
    {
        $hotlistId = $this->insertHotlist();
        // Address ~222m north of the scan (0.002 deg lat): inside the scan-window
        // bbox prefilter but outside the 160m check-in radius, so this pins the
        // geoDistance cut rather than the coarse bounding box.
        $this->insertOpenAssignmentAddress($hotlistId, lng: -97.7, lat: 30.302);

        $this->insertScanHot(['lng' => -97.7, 'lat' => 30.3]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful();
        $this->assertEquals(0, $response->json('data.direct_bucket'));
        $this->assertEquals(0, $response->json('data.staging_bucket'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_check_in_counts_dedupe_within_hour_bucket(): void
    {
        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, lng: -97.7, lat: 30.3);

        $base = now()->startOfHour()->addMinutes(10);
        $this->insertScanHot([
            'lng' => -97.7,
            'lat' => 30.3,
            'scanned_at' => $base->copy()->toDateTimeString(),
        ]);
        $this->insertScanHot([
            'lng' => -97.7,
            'lat' => 30.3,
            'scanned_at' => $base->copy()->addMinutes(15)->toDateTimeString(),
        ]);
        $this->insertScanHot([
            'lng' => -97.7,
            'lat' => 30.3,
            'scanned_at' => $base->copy()->addHour()->toDateTimeString(),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.direct_bucket'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_check_in_counts_scoped_to_kit(): void
    {
        $hotlistId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($hotlistId, lng: -97.7, lat: 30.3);

        // Another kit drives past the same address — should NOT count for this user
        $otherKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertScanHot(['kit_id' => $otherKit->id, 'lng' => -97.7, 'lat' => 30.3]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful();
        $this->assertEquals(0, $response->json('data.direct_bucket'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_check_in_counts_absent_book_row_does_not_count(): void
    {
        $this->insertScanHot(['lng' => -97.7, 'lat' => 30.3]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful();
        $this->assertEquals(0, $response->json('data.direct_bucket'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_check_in_counts_book_row_counts_even_when_hotlist_is_closed(): void
    {
        // Open/closed membership lives in the chlocal MV. A row already in the
        // book still counts even if the live hotlist is now closed.
        $hotlistId = $this->insertHotlist([
            'status' => HotlistStatusEnum::Repossessed->value,
        ]);
        $this->insertOpenAssignmentAddress($hotlistId, lng: -97.7, lat: 30.3);

        $this->insertScanHot(['lng' => -97.7, 'lat' => 30.3]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.direct_bucket'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_check_in_counts_ignore_unauthorized_staging_orgs(): void
    {
        // Staging hotlist on an org the user is NOT approved for
        $unauthorizedOrg = Org::factory()->create();
        $hotlistId = $this->insertHotlist([
            'org_id' => $unauthorizedOrg->id,
            'is_staged' => true,
        ]);
        $this->insertOpenAssignmentAddress(
            $hotlistId,
            lng: -97.7,
            lat: 30.3,
            orgId: $unauthorizedOrg->id,
            isStaged: true,
        );

        $this->insertScanHot(['lng' => -97.7, 'lat' => 30.3]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful();
        $this->assertEquals(0, $response->json('data.direct_bucket'));
        $this->assertEquals(0, $response->json('data.staging_bucket'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_repos_affected_counts_repossessed_only(): void
    {
        // Hit with Open hotlist — should NOT count as repos_affected. Distinct OCR
        // so the two Live hits don't collapse under the (ocr, hour) direct-hit dedup.
        $this->insertHitHot([
            'ocr' => 'OPEN0001',
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'hotlist_id' => 1,
        ]);

        // Hit with Repossessed hotlist — SHOULD count
        $this->insertHitHot([
            'ocr' => 'REPO0002',
            'hotlist_status' => HotlistStatusEnum::Repossessed->value,
            'hotlist_id' => 2,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.direct_hits'));
        $this->assertEquals(1, $response->json('data.repos_affected'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_repos_affected_counts_distinct_repossessed_hotlists(): void
    {
        // Two Live hits on the SAME repossessed hotlist, one on a DIFFERENT
        // repossessed hotlist, and one still-Open hit. `direct_hits` must count
        // every Live hit (4) while `repos_affected` counts DISTINCT repossessed
        // hotlists (2) — the uniqExactIf must not collapse to a naive per-hit count.
        $this->insertHitHot(['ocr' => 'REPOA01', 'hotlist_id' => 10, 'hotlist_status' => HotlistStatusEnum::Repossessed->value]);
        $this->insertHitHot(['ocr' => 'REPOA02', 'hotlist_id' => 10, 'hotlist_status' => HotlistStatusEnum::Repossessed->value]);
        $this->insertHitHot(['ocr' => 'REPOB01', 'hotlist_id' => 11, 'hotlist_status' => HotlistStatusEnum::Repossessed->value]);
        $this->insertHitHot(['ocr' => 'OPENC01', 'hotlist_id' => 12, 'hotlist_status' => HotlistStatusEnum::Open->value]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(4, $response->json('data.direct_hits'));
        $this->assertEquals(2, $response->json('data.repos_affected'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_dedupes_repeated_plate_scans_within_hour(): void
    {
        // Same plate scanned three times inside one hour (e.g. up and down a
        // parking lot) collapses to a single Direct hit (DEV-2077).
        $base = now()->startOfDay()->addHours(10)->addMinutes(5);
        $this->insertHitHot(['ocr' => 'LOOP123', 'hit_created_at' => $base->copy()->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'LOOP123', 'hit_created_at' => $base->copy()->addMinutes(15)->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'LOOP123', 'hit_created_at' => $base->copy()->addMinutes(40)->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.direct_hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_counts_same_plate_across_different_hours(): void
    {
        // The dedup is per hour bucket — the same plate in two different hours
        // is two Direct hits, not one.
        $base = now()->startOfDay()->addHours(10)->addMinutes(5);
        $this->insertHitHot(['ocr' => 'HOURS01', 'hit_created_at' => $base->copy()->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'HOURS01', 'hit_created_at' => $base->copy()->addHour()->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('data.direct_hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_dedupes_repeated_staging_scans_within_hour(): void
    {
        // Staging Live counts dedupe the same way as Direct.
        $base = now()->startOfDay()->addHours(10)->addMinutes(5);
        $this->insertHitHot(['ocr' => 'STGLOOP', 'hit_type' => 'S', 'hit_created_at' => $base->copy()->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'STGLOOP', 'hit_type' => 'S', 'hit_created_at' => $base->copy()->addMinutes(20)->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.staging_hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_repos_affected_not_collapsed_by_plate_dedup(): void
    {
        // One plate scanned twice in the same hour, each scan matching a DIFFERENT
        // repossessed hotlist. The plate-dedup collapses direct_hits to 1, but
        // repos_affected is computed over the un-deduped set, so both repossessed
        // hotlists still count — the guard for the DEV-2077 dedup not undercounting repos.
        $base = now()->startOfDay()->addHours(10)->addMinutes(5);
        $this->insertHitHot(['ocr' => 'SAMEPLT', 'hotlist_id' => 60, 'hotlist_status' => HotlistStatusEnum::Repossessed->value, 'hit_created_at' => $base->copy()->toDateTimeString()]);
        $this->insertHitHot(['ocr' => 'SAMEPLT', 'hotlist_id' => 61, 'hotlist_status' => HotlistStatusEnum::Repossessed->value, 'hit_created_at' => $base->copy()->addMinutes(20)->toDateTimeString()]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.direct_hits'));
        $this->assertEquals(2, $response->json('data.repos_affected'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_dedup_applies_within_each_date_range(): void
    {
        // The dedup is per (plate, hour) regardless of how wide the range is, and the
        // date filter must include/exclude by window. A plate scanned 3x in one hour
        // TODAY and another plate scanned 3x in one hour 3 DAYS AGO each collapse to 1.
        // today  → only today's plate            → direct_hits 1
        // last_7 → both plates (each deduped 3→1) → direct_hits 2  (6 raw rows)
        $today = now()->startOfDay()->addHours(10)->addMinutes(5);
        foreach ([0, 15, 40] as $m) {
            $this->insertHitHot(['ocr' => 'TODAY01', 'hit_created_at' => $today->copy()->addMinutes($m)->toDateTimeString()]);
        }

        $threeDaysAgo = now()->subDays(3)->startOfDay()->addHours(11);
        foreach ([0, 15, 40] as $m) {
            $this->insertHitHot(['ocr' => 'PRIOR07', 'hit_created_at' => $threeDaysAgo->copy()->addMinutes($m)->toDateTimeString()]);
        }

        $todayResponse = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats?range=today');
        $todayResponse->assertSuccessful();
        $this->assertEquals(1, $todayResponse->json('data.direct_hits'));

        // Per-range cache key means the next range recomputes; flush to be explicit.
        Cache::flush();

        $weekResponse = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/stats?range=last_7');
        $weekResponse->assertSuccessful();
        // The 3-days-ago plate enters the window and is itself deduped 3→1.
        $this->assertEquals(2, $weekResponse->json('data.direct_hits'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_check_in_counts_ignore_zero_geo_book_rows(): void
    {
        $validId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($validId, lng: -97.7, lat: 30.3);

        $zeroId = $this->insertHotlist();
        $this->insertOpenAssignmentAddress($zeroId, lng: 0, lat: 0);

        $this->insertScanHot(['lng' => -97.7, 'lat' => 30.3]);
        // A scan whose bbox contains the origin, so the != 0 guard is what
        // excludes the (0,0) book row — not the bbox BETWEEN predicates.
        $this->insertScanHot(['lng' => 0.001, 'lat' => 0.001]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.direct_bucket'));
        $this->assertEquals(0, $response->json('data.staging_bucket'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_check_in_counts_book_row_counts_even_when_hotlist_plate_is_short(): void
    {
        // Plate-length membership lives in the chlocal MV. A row already in the
        // book still counts; platform does not re-apply length(plate) > 3.
        $hotlistId = $this->insertHotlist(['plate' => 'AB']);
        $this->insertOpenAssignmentAddress($hotlistId, lng: -97.7, lat: 30.3);

        $this->insertScanHot(['lng' => -97.7, 'lat' => 30.3]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/dashboard/check-in-counts');

        $response->assertSuccessful();
        $this->assertEquals(1, $response->json('data.direct_bucket'));
    }

    /**
     * Contract: lock the dashboard stat/hotlist/check-in envelopes the PWA reads.
     * Keys are present even with zero activity, so no seeding is needed — a
     * controller change that renames/removes a field fails CI.
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_dashboard_response_shapes_are_locked(): void
    {
        $this->withToken($this->token)->getJson('/api/scout/dashboard/stats')
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['scans_today', 'direct_hits', 'staging_hits', 'repos_affected', 'trip_count'],
            ]);

        $this->withToken($this->token)->getJson('/api/scout/dashboard/hotlist-stats')
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['open', 'staged', 'total_active'],
            ]);

        $this->withToken($this->token)->getJson('/api/scout/dashboard/check-in-counts')
            ->assertSuccessful()
            ->assertJsonStructure(['data']);
    }
}
