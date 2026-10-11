<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\DispositionEnum;
use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Enums\VehicleTypeEnum;
use App\Http\Controllers\Api\ClarityScoutBirdsEyeController;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Org;
use App\Models\User;
use App\Services\Scout\ResolveOrgDriverPositions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use LaravelClickhouseEloquent\ClickhouseHttpClient;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * Scout Bird's Eye admin API — gating + org-scoping (DEV-2116). Exercises the
 * `drivers` endpoint (Postgres roster annotated with a ClickHouse-derived
 * `active_in_range` flag per selected date range, DEV-2293) plus the org-wide
 * recentHits feed (DEV-2137); both read ClickHouse and so run only when
 * CLICKHOUSE_DRIVER=clickhouse.
 */
class ClarityScoutBirdsEyeControllerTest extends TestCase
{
    use RequiresClickhouse;

    private Org $org;

    private User $admin;

    private int $hitSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');

        $this->org = Org::factory()->create();
        $this->makeOrgPro($this->org, FeatureEnum::CLARITY_SCOUT->value);

        $this->admin = User::factory()->forOrg($this->org)->create([
            'dti' => ['timeZone' => 'UTC'],
        ]);
        setPermissionsTeamId($this->org->id);
        $this->admin->assignRole(RoleEnum::ADMIN->value);
    }

    /** A Scout-enrolled kit = a kit with a kit_settings row (forKit creates one). */
    private function enrolledKit(Org $org, string $name): Kit
    {
        $kit = Kit::factory()->forOrg($org)->create(['name' => $name]);
        User::factory()->forOrg($org)->forKit($kit)->create();

        return $kit;
    }

    /**
     * Seed a scans_hot row for a kit (DEV-2293's activeKitIdsForRange reads
     * this). Mirrors ClarityScoutDashboardControllerTest::insertScanHot, but
     * keyed by an explicit kit_id since this class juggles multiple kits/orgs.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertScanHot(int $kitId, array $overrides = []): void
    {
        static $scanId = 500000;
        $defaults = [
            'id' => $scanId++,
            'kit_id' => $kitId,
            'org_id' => $this->org->id,
            'ocr' => 'ABC1234',
            'us_state' => 'TX',
            'camera_name' => 'cam1',
            'scanned_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'lng' => -97.7,
            'lat' => 30.3,
            '_peerdb_is_deleted' => 0,
        ];

        DB::connection('clarity_clickhouse')->table('clarity.scans_hot')
            ->insert(array_merge($defaults, $overrides));
    }

    /**
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     */
    private function insertScPositionFor(string $mapping, array $point, Carbon $at, float $speed = 30.0, float $heading = 90.0): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.sc_positions')->insert([
            'kit_mapping' => $mapping,
            'geo' => "({$point[0]}, {$point[1]})",
            'timestamp_utc' => $at->toDateTimeString(),
            'speed' => $speed,
            'heading' => $heading,
        ]);
    }

    private function insertKitScanPath(int $kitId, string $pathPoints): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.kit_scan_paths')->insert([
            'kit_id' => $kitId,
            'org_id' => $this->org->id,
            'timestamp' => now()->toDateTimeString(),
            'scan_count' => 1,
            'path_points' => $pathPoints,
            'kit_name' => 'Test Kit '.$kitId,
            '_version' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function seedHotlist(int $id, int $status, ?int $orgId = null): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
            'id' => $id,
            'org_id' => $orgId ?? $this->org->id,
            'status' => $status,
            'order_type' => 1,
            'is_staged' => false,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ]);
    }

    /**
     * Midday today (UTC) — the anchor these tests freeze the clock to so the
     * default 'today' window is deterministic without landing on a midnight
     * boundary. Relative to now by design: clarity.hits_hot and
     * clarity.kit_scan_paths carry a 90-day DELETE TTL, so a hardcoded anchor
     * eventually ages past it and ClickHouse drops the fixtures on insert.
     */
    private function frozenNow(): Carbon
    {
        return Carbon::today('UTC')->setTime(12, 0);
    }

    /**
     * Seed a Live/Staging hit row into clarity.hits_hot for the given kit.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedHit(Kit $kit, array $overrides = []): void
    {
        $defaults = [
            'id' => 95000 + (++$this->hitSeq),
            'hotlist_id' => 1,
            'scan_id' => 1,
            'hit_type' => 'L',
            'hit_created_at' => now()->subDays(1)->toDateTimeString(),
            'ocr' => 'PLATE'.$this->hitSeq,
            'scanned_at' => now()->subDays(1)->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => $kit->id,
            'kit_name' => $kit->name ?? 'Test Kit',
            'kit_org_id' => $this->org->id,
            'hotlist_org_id' => $this->org->id,
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'lat' => 32.7,
            'lng' => -96.7,
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
        ];

        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')
            ->insert(array_merge($defaults, $overrides));
    }

    /**
     * Seed an OPEN order + a matching `hits_cold` row so the hit-density layer
     * (HitDirectHotspotQuery) — which inner-joins hits_cold to an OPEN,
     * non-staged hotlist on hotlist_id — has something to aggregate.
     */
    private function seedDirectHotspotHit(Kit $kit, float $lat, float $lng): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
            'id' => 1,
            'org_id' => $this->org->id,
            'status' => HotlistStatusEnum::Open->value,
            'order_type' => 1,
            'is_staged' => false,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ]);

        DB::connection('clarity_clickhouse')->table('clarity.hits_cold')->insert([
            'id' => ++$this->hitSeq,
            'hotlist_id' => 1,
            'scan_id' => 1,
            'hit_type' => 'L',
            'hit_created_at' => now()->subDays(1)->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->subDays(1)->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => $kit->id,
            'kit_name' => $kit->name ?? 'Test Kit',
            'kit_org_id' => $this->org->id,
            'hotlist_org_id' => $this->org->id,
            'hotlist_us_state' => 'TX',
            'hotlist_plate' => 'ABC1234',
            'hotlist_status' => 1,
            'lat' => $lat,
            'lng' => $lng,
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
        ]);
    }

    /**
     * Seed a today's-path row for the given kit (>= 2 points so it clears the
     * query's length filter).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedKitScanPath(Kit $kit, array $overrides = []): void
    {
        $defaults = [
            'kit_id' => $kit->id,
            'org_id' => $this->org->id,
            'timestamp' => now()->toDateTimeString(),
            'scan_count' => 1,
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
            'kit_name' => $kit->name ?? 'Test Kit',
            '_version' => now()->format('Y-m-d H:i:s.u'),
        ];

        DB::connection('clarity_clickhouse')->table('clarity.kit_scan_paths')
            ->insert(array_merge($defaults, $overrides));
    }

    #[Group('clarity-scout')]
    public function test_admin_with_scout_can_load_the_birds_eye_shell(): void
    {
        // Bird's Eye reuses the real Scout App.vue, so the shell mounts on the
        // shared #clarity-scout-app node and flips on the birdsEye config flag.
        $this->actingAs($this->admin)
            ->get(route('clarity-scout.org-map'))
            ->assertOk()
            ->assertSee('clarity-scout-app', false)
            ->assertSee('birdsEye', false);
    }

    #[Group('clarity-scout')]
    public function test_non_admin_cannot_load_the_birds_eye_shell(): void
    {
        $user = User::factory()->forOrg($this->org)->create();
        setPermissionsTeamId($this->org->id);
        $user->assignRole(RoleEnum::USER->value);

        $this->actingAs($user)
            ->get(route('clarity-scout.org-map'))
            ->assertForbidden();
    }

    #[Group('clarity-scout')]
    public function test_drivers_lists_all_active_org_kits_regardless_of_scout_enrollment(): void
    {
        // DEV-2293: the endpoint annotates rather than shrinks, so the roster
        // still lists every non-deleted org kit regardless of scan activity —
        // seed a same-day scan for both so they also read active_in_range true.
        Carbon::setTestNow($this->frozenNow());

        $enrolled = $this->enrolledKit($this->org, 'Enrolled');
        $notEnrolled = Kit::factory()->forOrg($this->org)->create(['name' => 'Not Enrolled']); // no settings row → still listed
        $this->insertScanHot($enrolled->id);
        $this->insertScanHot($notEnrolled->id);

        $deletedKit = Kit::factory()->forOrg($this->org)->create(['name' => 'Deleted Kit']);
        DB::connection('zod')->table('kit_metadata')->insert([
            'kit_id' => $deletedKit->id,
            'deleted_at' => now(),
        ]);

        // Another org's enrolled kit must never leak across the tenant boundary.
        $otherOrg = Org::factory()->create();
        $otherKit = $this->enrolledKit($otherOrg, 'Other Org Kit');

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/drivers')
            ->assertOk();

        $data = collect($response->json('data'));
        $kitIds = $data->pluck('kit_id')->all();

        $this->assertContains($enrolled->id, $kitIds);
        $this->assertContains($notEnrolled->id, $kitIds);
        $this->assertNotContains($deletedKit->id, $kitIds);
        $this->assertNotContains($otherKit->id, $kitIds);
        $this->assertCount(2, $kitIds);

        $notEnrolledEntry = $data->firstWhere('kit_id', $notEnrolled->id);
        $this->assertNull($notEnrolledEntry['driver_name']);
        $this->assertNull($notEnrolledEntry['vehicle_type']);
        $this->assertTrue($notEnrolledEntry['active_in_range']);
        $this->assertTrue($data->firstWhere('kit_id', $enrolled->id)['active_in_range']);

        Carbon::setTestNow();
    }

    #[Group('clarity-scout')]
    public function test_kit_with_a_settings_row_but_no_assigned_user_still_appears(): void
    {
        // The ticket's motivating case: unassigning a scout user nulls user_id but
        // leaves the kit_settings row. The kit must still render — with a null
        // driver_name (no user) and its vehicle_type still driving the icon.
        Carbon::setTestNow($this->frozenNow());

        $kit = Kit::factory()->forOrg($this->org)->create(['name' => 'Unassigned']);
        KitSettings::create([
            'kit_id' => $kit->id,
            'user_id' => null,
            'vehicle_type' => VehicleTypeEnum::RepoTruck,
        ]);
        $this->insertScanHot($kit->id);

        $entry = collect($this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/drivers')
            ->assertOk()
            ->json('data'))
            ->firstWhere('kit_id', $kit->id);

        $this->assertNotNull($entry);
        $this->assertNull($entry['driver_name']);
        $this->assertSame(VehicleTypeEnum::RepoTruck->value, $entry['vehicle_type']);
        $this->assertTrue($entry['active_in_range']);

        Carbon::setTestNow();
    }

    #[Group('clarity-scout')]
    public function test_drivers_flags_active_in_range_by_scan_activity(): void
    {
        // AC-1: a kit that scanned in-window reads active_in_range true; a kit
        // with no in-window scan reads false — the FULL roster still returns both.
        Carbon::setTestNow($this->frozenNow());

        $activeKit = $this->enrolledKit($this->org, 'Active Kit');
        $idleKit = $this->enrolledKit($this->org, 'Idle Kit');
        $this->insertScanHot($activeKit->id);

        $data = collect($this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/drivers')
            ->assertOk()
            ->json('data'));

        $this->assertTrue($data->firstWhere('kit_id', $activeKit->id)['active_in_range']);
        $this->assertFalse($data->firstWhere('kit_id', $idleKit->id)['active_in_range']);

        Carbon::setTestNow();
    }

    #[Group('clarity-scout')]
    public function test_drivers_active_in_range_widens_with_the_range_window(): void
    {
        // AC-1/AC-2: a scan ~40 days old falls outside 'today' but inside
        // 'last_60', so the SAME kit flips active_in_range across the two calls.
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Stale Driver');
        $this->insertScanHot($kit->id, ['scanned_at' => now()->subDays(40)->toDateTimeString()]);

        $today = collect($this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/drivers')
            ->assertOk()
            ->json('data'))
            ->firstWhere('kit_id', $kit->id);
        $this->assertFalse($today['active_in_range']);

        $last60 = collect($this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/drivers?range=last_60')
            ->assertOk()
            ->json('data'))
            ->firstWhere('kit_id', $kit->id);
        $this->assertTrue($last60['active_in_range']);

        Carbon::setTestNow();
    }

    #[Group('clarity-scout')]
    public function test_drivers_returns_full_roster_when_no_kit_has_scanned_in_range(): void
    {
        // AC-3: zero active drivers must never shrink the roster — the client
        // (not the server) decides what to hide when nothing is active.
        $this->enrolledKit($this->org, 'Kit A');
        $this->enrolledKit($this->org, 'Kit B');

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/drivers')
            ->assertOk();

        $data = collect($response->json('data'));
        $this->assertNotEmpty($data);
        $this->assertTrue($data->every(fn ($d) => $d['active_in_range'] === false));
    }

    #[Group('clarity-scout')]
    public function test_stats_accepts_a_kit_ids_filter_including_a_non_enrolled_kit(): void
    {
        // scopedKits() (which stats() uses) is derived from orgScoutKits(); a
        // non-enrolled kit_id must be honoured, and another org's kit must never
        // widen scope past the admin's own org.
        $enrolled = $this->enrolledKit($this->org, 'Enrolled');
        $notEnrolled = Kit::factory()->forOrg($this->org)->create(['name' => 'Not Enrolled']);

        $otherOrg = Org::factory()->create();
        $otherKit = $this->enrolledKit($otherOrg, 'Other Org Kit');

        $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/stats?'.http_build_query([
                'kit_ids' => [$enrolled->id, $notEnrolled->id, $otherKit->id],
            ]))
            ->assertOk()
            ->assertJsonStructure(['data' => ['scans_today', 'direct_hits', 'staging_hits', 'repos_affected', 'trip_count']]);
    }

    #[Group('clarity-scout')]
    public function test_drivers_response_caches_the_org_kit_list(): void
    {
        // orgScoutKits() runs on every bird's-eye poll (via scopedKits), so the
        // built list is cached per org for the ~10s map cadence, mirroring the
        // sibling stats/hits caches.
        $this->enrolledKit($this->org, 'Enrolled');

        $cacheKey = 'scanner:birds-eye-org-kits:'.$this->org->id;
        $this->assertFalse(Cache::has($cacheKey));

        $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/drivers')
            ->assertOk();

        $this->assertTrue(Cache::has($cacheKey));
    }

    #[Group('clarity-scout')]
    public function test_driver_paths_accepts_a_per_driver_sinces_cursor_map(): void
    {
        // The live trail reader needs a ClickHouse-seeded env (QA pipeline), so
        // here we only assert the per-driver `sinces` contract: a valid cursor map
        // is accepted and the endpoint answers with the `drivers` envelope.
        $kit = $this->enrolledKit($this->org, 'Enrolled');

        $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-paths?'.http_build_query([
                'sinces' => [$kit->id => '2026-07-01 12:00:00'],
            ]))
            ->assertOk()
            ->assertJsonStructure(['drivers']);
    }

    #[Group('clarity-scout')]
    public function test_driver_paths_rejects_a_non_date_cursor(): void
    {
        $kit = $this->enrolledKit($this->org, 'Enrolled');

        $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-paths?'.http_build_query([
                'sinces' => [$kit->id => 'not-a-timestamp'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sinces.'.$kit->id);
    }

    /**
     * Regression pin: driverPositions() had ZERO coverage before DEV-2253. A
     * kit with a recent sc_positions row renders a true-GPS dot.
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_returns_the_latest_sc_positions_fix(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => 'GPSKIT1']);
        $this->insertScPositionFor('GPSKIT1', [-96.7, 32.7], now()->subMinutes(2));

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $entry = $response->json('data.0');
        $this->assertSame($kit->id, $entry['kit_id']);
        $this->assertEqualsWithDelta(32.7, $entry['lat'], 0.0001);
        $this->assertEqualsWithDelta(-96.7, $entry['lng'], 0.0001);
        $this->assertNotNull($entry['scanned_at']);
        $this->assertIsInt($entry['minutes_ago']);
        $this->assertSame('scan', $entry['source']);
        $this->assertSame('gps', $entry['position_source']);

        Carbon::setTestNow();
    }

    /**
     * THE FIX (DEV-2253): a kit with no sc_positions row but a recent
     * kit_scan_paths bucket must still render, from the bucket's LAST point.
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_falls_back_to_the_last_scan_path_point_when_no_gps_row(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => 'SCANONLY1']);
        $this->seedKitScanPath($kit, [
            'timestamp' => now()->subMinutes(5)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $entry = $response->json('data.0');
        $this->assertSame($kit->id, $entry['kit_id']);
        $this->assertEqualsWithDelta(39.76, $entry['lat'], 0.0001);
        $this->assertEqualsWithDelta(-86.16, $entry['lng'], 0.0001);
        $this->assertNull($entry['heading']);
        $this->assertSame('scan_path', $entry['position_source']);

        Carbon::setTestNow();
    }

    #[Group('clarity-scout')]
    public function test_driver_positions_prefers_the_live_gps_fix_over_the_scan_path(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => 'BOTHKIT1']);
        $this->insertScPositionFor('BOTHKIT1', [-96.7, 32.7], now()->subMinutes(2));
        $this->seedKitScanPath($kit, [
            'timestamp' => now()->subMinutes(5)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $entry = $response->json('data.0');
        $this->assertEqualsWithDelta(32.7, $entry['lat'], 0.0001);
        $this->assertEqualsWithDelta(-96.7, $entry['lng'], 0.0001);
        $this->assertSame('gps', $entry['position_source']);

        Carbon::setTestNow();
    }

    #[Group('clarity-scout')]
    public function test_driver_positions_omits_a_kit_with_neither_source(): void
    {
        Carbon::setTestNow($this->frozenNow());

        Kit::factory()->forOrg($this->org)->create(['kit_mapping' => 'NOSOURCE1']);

        $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        Carbon::setTestNow();
    }

    /**
     * kitsByMapping() drops any kit with a null/empty kit_mapping,
     * so it never becomes a tier-1 candidate at all. kit_scan_paths is keyed
     * by kit_id and needs no mapping, so such a kit is still rescuable.
     * (kit_mapping is NOT NULL in the schema, so "no mapping" is modelled as
     * an empty string, matching kitsByMapping()'s own null-OR-empty check.)
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_rescues_a_kit_with_no_kit_mapping_via_kit_id(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => '']);
        $this->seedKitScanPath($kit, [
            'timestamp' => now()->subMinutes(5)->toDateTimeString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($kit->id, $response->json('data.0.kit_id'));
        $this->assertSame('scan_path', $response->json('data.0.position_source'));

        Carbon::setTestNow();
    }

    /**
     * Production's actual shape, and the one combination every other tier-2 case
     * misses: they all seed ONLY mapping-less kits, so $byMapping comes out empty
     * and the tier-1 read is skipped altogether. Pin both tiers resolving in a
     * single response (Premier: ~37 kits on GPS, ~141 on scan paths).
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_returns_both_tiers_in_one_response(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $gpsKit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => 'MIXEDGPS1']);
        $this->insertScPositionFor('MIXEDGPS1', [-96.7, 32.7], now()->subMinutes(2));

        $scanKit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => '']);
        $this->seedKitScanPath($scanKit, [
            'timestamp' => now()->subMinutes(5)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $bySource = collect($response->json('data'))->keyBy('position_source');
        $gps = $bySource->get('gps');
        $scan = $bySource->get('scan_path');

        $this->assertNotNull($gps, 'Expected a tier-1 GPS dot alongside the tier-2 rescue.');
        $this->assertNotNull($scan, 'Expected a tier-2 scan-path dot alongside the GPS fix.');

        $this->assertSame($gpsKit->id, $gps['kit_id']);
        $this->assertEqualsWithDelta(32.7, $gps['lat'], 0.0001);
        $this->assertNotNull($gps['heading']);

        $this->assertSame($scanKit->id, $scan['kit_id']);
        $this->assertEqualsWithDelta(39.76, $scan['lat'], 0.0001);
        $this->assertNull($scan['heading']);

        Carbon::setTestNow();
    }

    /**
     * A tier-1 row at (0,0) is discarded, which leaves the kit unresolved — so it
     * falls through to the tier-2 rescue instead of vanishing. That is emergent
     * from the `continue`, not chosen; pin it so a refactor cannot quietly turn a
     * sentinel row back into a missing driver. Measured absent in production (0 of
     * 172.7M sc_positions rows over 60 days), hence a pin rather than a fix.
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_rescues_a_kit_whose_only_gps_row_is_zero_geo(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => 'ZEROGEO1']);
        $this->insertScPositionFor('ZEROGEO1', [0, 0], now()->subMinutes(2));
        $this->seedKitScanPath($kit, [
            'timestamp' => now()->subMinutes(5)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $entry = $response->json('data.0');
        $this->assertSame($kit->id, $entry['kit_id']);
        $this->assertSame('scan_path', $entry['position_source']);
        $this->assertEqualsWithDelta(39.76, $entry['lat'], 0.0001);
        $this->assertEqualsWithDelta(-86.16, $entry['lng'], 0.0001);

        Carbon::setTestNow();
    }

    /**
     * Non-functional requirement: the endpoint is polled ~10s for a whole org
     * (Premier: 178 kits), so the fallback must be ONE round trip regardless of
     * how many kits are missing, not a per-kit fan-out.
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_issues_one_fallback_query_for_the_whole_missing_set(): void
    {
        Carbon::setTestNow($this->frozenNow());

        // kit_mapping is unique per kit_type, so distinct kit_types keep three
        // empty-mapping kits from colliding on that constraint.
        $kits = collect([1, 2, 3])->map(
            fn (int $kitType) => Kit::factory()->forOrg($this->org)->create(['kit_mapping' => '', 'kit_type' => $kitType, 'name' => "Missing {$kitType}"])
        );

        foreach ($kits as $kit) {
            $this->seedKitScanPath($kit, ['timestamp' => now()->subMinutes(5)->toDateTimeString()]);
        }

        // The clickhouse-eloquent Connection issues raw curl selects outside
        // Laravel's run()/logQuery() path (Connection::select() calls the HTTP
        // client directly), so DB::enableQueryLog() never sees them — count
        // kit_scan_paths SELECTs at the HTTP client itself instead.
        $connection = DB::connection('clarity_clickhouse');
        $realClient = $connection->getClient();
        $spyClient = new class(config('database.connections.clarity_clickhouse')) extends ClickhouseHttpClient
        {
            public int $kitScanPathSelects = 0;

            public function select(string $sql, array $bindings = []): array
            {
                if (str_contains($sql, 'kit_scan_paths')) {
                    $this->kitScanPathSelects++;
                }

                return parent::select($sql, $bindings);
            }
        };

        $clientProperty = new ReflectionProperty($connection, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($connection, $spyClient);

        try {
            $response = $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/driver-positions')
                ->assertOk()
                ->assertJsonCount(3, 'data');
        } finally {
            $clientProperty->setValue($connection, $realClient);
        }

        $this->assertSame(1, $spyClient->kitScanPathSelects, 'Expected exactly one kit_scan_paths statement for the whole missing set.');

        $rescuedIds = collect($response->json('data'))->pluck('kit_id')->sort()->values()->all();
        $this->assertSame($kits->pluck('id')->sort()->values()->all(), $rescuedIds);

        Carbon::setTestNow();
    }

    /**
     * seedKitScanPath()'s shared default path_points means the multi-kit
     * fallback test above can't tell "kit A got kit B's dot" from correct
     * behaviour — every rescued kit gets an IDENTICAL point either way. Pin
     * each kit's OWN distinct coordinate so a broken GROUP BY kit_id or a
     * mis-keyed $byId lookup fails this test even though the kit_id set
     * would still look right (DEV-2253 review finding).
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_gives_each_rescued_kit_its_own_distinct_point(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kitA = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => '', 'kit_type' => 1, 'name' => 'Kit A']);
        $kitB = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => '', 'kit_type' => 2, 'name' => 'Kit B']);

        $this->seedKitScanPath($kitA, [
            'timestamp' => now()->subMinutes(5)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);
        $this->seedKitScanPath($kitB, [
            'timestamp' => now()->subMinutes(5)->toDateTimeString(),
            'path_points' => '[(-118.24, 34.05), (-118.25, 34.06)]',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $byId = collect($response->json('data'))->keyBy('kit_id');

        $this->assertEqualsWithDelta(39.76, $byId[$kitA->id]['lat'], 0.0001);
        $this->assertEqualsWithDelta(-86.16, $byId[$kitA->id]['lng'], 0.0001);
        $this->assertEqualsWithDelta(34.06, $byId[$kitB->id]['lat'], 0.0001);
        $this->assertEqualsWithDelta(-118.25, $byId[$kitB->id]['lng'], 0.0001);

        Carbon::setTestNow();
    }

    /**
     * A thrown tier-1 read must short-circuit to an empty payload WITHOUT
     * attempting tier 2 (same cluster, same 10s-poll timeout-repay reasoning
     * as kitPosition()). Recipe from
     * ClarityScoutMapControllerTest::test_teammate_paths_degrades_gracefully_on_clickhouse_failure():
     * config(['app.env' => 'local']) bypasses ClickhouseQuery::query()'s
     * testing-env Throwable swallow, so the CONTROLLER's own catch is what's
     * under test, not an incidental testing-env behavior.
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_tier_one_throw_short_circuits_before_tier_two(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => 'DEADCONN1']);
        // A bucket that WOULD render a tier-2 dot if tier 2 were ever reached.
        $this->seedKitScanPath($kit, ['timestamp' => now()->subMinutes(5)->toDateTimeString()]);

        config(['app.env' => 'local']);
        config([
            'database.connections.clarity_clickhouse.driver' => 'clickhouse',
            'database.connections.clarity_clickhouse.host' => '127.0.0.1',
            'database.connections.clarity_clickhouse.port' => '1',
            'database.connections.clarity_clickhouse.https' => false,
            'database.connections.clarity_clickhouse.options' => ['protocol' => 'http'],
            'database.connections.clarity_clickhouse.timeout_connect' => 1,
            'database.connections.clarity_clickhouse.timeout_query' => 1,
            'database.connections.clarity_clickhouse.retries' => 0,
        ]);
        DB::purge('clarity_clickhouse');

        Log::spy();

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/driver-positions');

        // {"data":[]}, not just an empty set: if tier 2 were (wrongly)
        // attempted despite the tier-1 throw, the seeded scan-path bucket
        // above would render a dot here.
        $response->assertOk()->assertExactJson(['data' => []]);

        // Exactly one warning: a second would mean tier 2 was also attempted
        // (and also threw, against the same dead connection).
        Log::shouldHaveReceived('warning')->once();

        Carbon::setTestNow();
    }

    /**
     * A thrown tier-2 read must degrade to tier-1-only: the tier-1 GPS dots
     * that DID resolve must survive in the payload. This is the only thing
     * stopping a partial ClickHouse failure from wiping every dot on the
     * board. Isolating tier 2 alone — without also breaking tier 1, which
     * shares the same clarity_clickhouse connection — uses the same
     * HTTP-client spy as test_driver_positions_issues_one_fallback_query_for_the_whole_missing_set,
     * but throwing instead of counting: the sc_positions (tier 1) select
     * passes through untouched, only the kit_scan_paths (tier 2) select
     * fails. config(['app.env' => 'local']) bypasses
     * ClickhouseQuery::query()'s own testing-env Throwable swallow, so the
     * CONTROLLER's own catch — not that incidental swallow — is what's
     * proven here (same reasoning as the tier-1 test above).
     */
    #[Group('clarity-scout')]
    public function test_driver_positions_tier_two_throw_keeps_tier_one_dots(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $gpsKit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => 'GPSSURVIVE1']);
        $this->insertScPositionFor('GPSSURVIVE1', [-96.7, 32.7], now()->subMinutes(2));

        $missingKit = Kit::factory()->forOrg($this->org)->create(['kit_mapping' => '', 'kit_type' => 9]);
        $this->seedKitScanPath($missingKit, ['timestamp' => now()->subMinutes(5)->toDateTimeString()]);

        config(['app.env' => 'local']);

        $connection = DB::connection('clarity_clickhouse');
        $realClient = $connection->getClient();
        $failingClient = new class(config('database.connections.clarity_clickhouse')) extends ClickhouseHttpClient
        {
            public function select(string $sql, array $bindings = []): array
            {
                if (str_contains($sql, 'kit_scan_paths')) {
                    throw new \RuntimeException('Simulated tier-2 ClickHouse failure');
                }

                return parent::select($sql, $bindings);
            }
        };

        $clientProperty = new ReflectionProperty($connection, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($connection, $failingClient);

        Log::spy();

        try {
            $response = $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/driver-positions')
                ->assertOk();
        } finally {
            $clientProperty->setValue($connection, $realClient);
        }

        $data = collect($response->json('data'));
        $this->assertCount(1, $data, 'A failed tier-2 read must drop only the unresolved kit, not the whole response.');
        $this->assertSame($gpsKit->id, $data->first()['kit_id']);
        $this->assertSame('gps', $data->first()['position_source']);

        Log::shouldHaveReceived('warning')->once();

        Carbon::setTestNow();
    }

    /**
     * Query string for historical Org Map scan-paths with required kit + bbox
     * (DEV-2374 prod hard gates).
     *
     * @param  list<int>  $kitIds
     * @param  array<string, mixed>  $extra
     */
    private function historicalScanPathsQuery(array $kitIds, array $extra = []): string
    {
        return http_build_query(array_merge([
            'range' => 'last_7',
            'kit_ids' => array_values($kitIds),
            'north' => 40,
            'south' => 39,
            'east' => -74,
            'west' => -75,
        ], $extra));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_historical_scan_paths_require_kit_ids(): void
    {
        Carbon::setTestNow($this->frozenNow());

        try {
            $kit = $this->enrolledKit($this->org, 'Kit Gate');
            $at = now()->subDay()->setTime(12, 0, 0);
            $this->insertScPositionFor((string) $kit->kit_mapping, [-74.5, 39.5], $at);
            $this->insertScPositionFor((string) $kit->kit_mapping, [-74.501, 39.5], $at->copy()->addSeconds(3));

            $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.http_build_query([
                    'range' => 'last_30',
                    'north' => 40,
                    'south' => 39,
                    'east' => -74,
                    'west' => -75,
                ]))
                ->assertStatus(422)
                ->assertJsonFragment(['error' => 'Historical paths require kit_ids.']);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_historical_scan_paths_require_bbox(): void
    {
        Carbon::setTestNow($this->frozenNow());

        try {
            $kit = $this->enrolledKit($this->org, 'Kit Bbox Gate');

            $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.http_build_query([
                    'range' => 'last_7',
                    'kit_ids' => [$kit->id],
                ]))
                ->assertStatus(422);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_historical_scan_paths_reject_too_many_kit_ids(): void
    {
        Carbon::setTestNow($this->frozenNow());

        try {
            $kitA = $this->enrolledKit($this->org, 'Kit A');
            $kitB = $this->enrolledKit($this->org, 'Kit B');

            $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery(
                    [$kitA->id, $kitB->id],
                    ['range' => 'last_30'],
                ))
                ->assertStatus(422)
                ->assertJsonFragment([
                    'error' => 'Too many kits in one historical path request — send one kit per chunk.',
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_historical_scan_paths_reject_oversized_bbox(): void
    {
        Carbon::setTestNow($this->frozenNow());

        try {
            $kit = $this->enrolledKit($this->org, 'Kit Span');

            $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery(
                    [$kit->id],
                    [
                        'range' => 'last_7',
                        'north' => 45,
                        'south' => 39,
                        'east' => -74,
                        'west' => -80,
                    ],
                ))
                ->assertStatus(422)
                ->assertJsonFragment(['error' => 'Viewport too large — zoom in to load paths.']);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_returns_kit_when_kit_ids_and_bbox_given(): void
    {
        // DEV-2374: historical ranges read continuous sc_positions (KUP parity),
        // not kit_scan_paths. Step > 50 m dedup floor (~0.001° ≈ 90 m).
        Carbon::setTestNow($this->frozenNow());

        try {
            $kitA = $this->enrolledKit($this->org, 'Kit A');
            $kitB = $this->enrolledKit($this->org, 'Kit B');

            $at = now()->subDay()->setTime(12, 0, 0);
            $this->insertScPositionFor((string) $kitA->kit_mapping, [-74.5, 39.5], $at);
            $this->insertScPositionFor((string) $kitA->kit_mapping, [-74.501, 39.5], $at->copy()->addSeconds(3));
            $this->insertScPositionFor((string) $kitB->kit_mapping, [-80.0, 45.0], $at);
            $this->insertScPositionFor((string) $kitB->kit_mapping, [-80.001, 45.0], $at->copy()->addSeconds(3));

            $response = $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery(
                    [$kitA->id],
                    ['range' => 'last_30'],
                ))
                ->assertOk();

            $rows = $response->json();
            $kitIds = collect($rows)->pluck('kit_id')->unique()->sort()->values()->all();
            $this->assertSame([$kitA->id], $kitIds);

            foreach ($rows as $row) {
                $this->assertFalse($row['is_today']);
                $this->assertIsArray($row['path']);
                $this->assertGreaterThanOrEqual(2, count($row['path']));
                $this->assertIsNumeric($row['path'][0][0]);
                $this->assertIsNumeric($row['path'][0][1]);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_bbox_excludes_out_of_viewport_points_for_requested_kit(): void
    {
        // DEV-2374 strategy B: early CH geo filter — only GPS inside the
        // viewport is assembled. Outside kits drop; clipped kits keep only
        // in-box points (need ≥2 in-box points to form a segment).
        Carbon::setTestNow($this->frozenNow());

        try {
            $kitInside = $this->enrolledKit($this->org, 'Kit Inside');
            $kitPartial = $this->enrolledKit($this->org, 'Kit Partial');
            $kitOutside = $this->enrolledKit($this->org, 'Kit Outside');

            $at = now()->subDay()->setTime(12, 0, 0);
            // Inside viewport (-75..-74, 39..40): both points in.
            $this->insertScPositionFor((string) $kitInside->kit_mapping, [-74.5, 39.5], $at);
            $this->insertScPositionFor((string) $kitInside->kit_mapping, [-74.501, 39.5], $at->copy()->addSeconds(3));

            // Partial: two points inside near the east edge + one outside.
            // Spaced >50 m so broad dedup keeps the in-box pair.
            $this->insertScPositionFor((string) $kitPartial->kit_mapping, [-74.002, 39.5], $at);
            $this->insertScPositionFor((string) $kitPartial->kit_mapping, [-74.001, 39.5], $at->copy()->addSeconds(3));
            $this->insertScPositionFor((string) $kitPartial->kit_mapping, [-73.999, 39.5], $at->copy()->addSeconds(6));

            // Outside: both points west of the viewport.
            $this->insertScPositionFor((string) $kitOutside->kit_mapping, [-80.0, 45.0], $at);
            $this->insertScPositionFor((string) $kitOutside->kit_mapping, [-80.001, 45.0], $at->copy()->addSeconds(3));

            $bbox = [
                'range' => 'last_30',
                'north' => 40,
                'south' => 39,
                'east' => -74,
                'west' => -75,
            ];

            $insideRows = collect($this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery([$kitInside->id], $bbox))
                ->assertOk()
                ->json());
            $this->assertSame([$kitInside->id], $insideRows->pluck('kit_id')->unique()->sort()->values()->all());

            $partialRows = collect($this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery([$kitPartial->id], $bbox))
                ->assertOk()
                ->json());
            $this->assertSame([$kitPartial->id], $partialRows->pluck('kit_id')->unique()->sort()->values()->all());

            $outsideRows = collect($this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery([$kitOutside->id], $bbox))
                ->assertOk()
                ->json());
            $this->assertSame([], $outsideRows->pluck('kit_id')->unique()->values()->all());

            foreach ($insideRows->merge($partialRows) as $row) {
                foreach ($row['path'] as $point) {
                    $lng = (float) $point[0];
                    $lat = (float) $point[1];
                    $this->assertGreaterThanOrEqual(39.0, $lat);
                    $this->assertLessThanOrEqual(40.0, $lat);
                    $this->assertGreaterThanOrEqual(-75.0, $lng);
                    $this->assertLessThanOrEqual(-74.0, $lng);
                }
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_historical_scan_paths_return_422_when_render_budget_exceeded(): void
    {
        Carbon::setTestNow($this->frozenNow());

        try {
            $this->app->instance(
                ClarityScoutBirdsEyeController::class,
                new class($this->app->make(ResolveOrgDriverPositions::class)) extends ClarityScoutBirdsEyeController
                {
                    protected const MAX_HISTORICAL_RENDER_POINTS = 1;
                }
            );

            $kit = $this->enrolledKit($this->org, 'Kit Over Budget');
            $at = now()->subDay()->setTime(12, 0, 0);
            $this->insertScPositionFor((string) $kit->kit_mapping, [-74.5, 39.5], $at);
            $this->insertScPositionFor((string) $kit->kit_mapping, [-74.501, 39.5], $at->copy()->addSeconds(3));

            $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery([$kit->id]))
                ->assertStatus(422)
                ->assertJsonFragment(['error' => 'Selection too large to render — narrow the kits or shorten the date range.']);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_historical_scan_paths_honor_max_points_remaining_budget(): void
    {
        Carbon::setTestNow($this->frozenNow());

        try {
            $kit = $this->enrolledKit($this->org, 'Kit Remaining Budget');
            $at = now()->subDay()->setTime(12, 0, 0);
            $this->insertScPositionFor((string) $kit->kit_mapping, [-74.5, 39.5], $at);
            $this->insertScPositionFor((string) $kit->kit_mapping, [-74.501, 39.5], $at->copy()->addSeconds(3));

            $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery(
                    [$kit->id],
                    ['max_points' => 1],
                ))
                ->assertStatus(422)
                ->assertJsonFragment(['error' => 'Selection too large to render — narrow the kits or shorten the date range.']);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_historical_scan_paths_expose_render_points_header(): void
    {
        Carbon::setTestNow($this->frozenNow());

        try {
            $kit = $this->enrolledKit($this->org, 'Kit Header');
            $at = now()->subDay()->setTime(12, 0, 0);
            $this->insertScPositionFor((string) $kit->kit_mapping, [-74.5, 39.5], $at);
            $this->insertScPositionFor((string) $kit->kit_mapping, [-74.501, 39.5], $at->copy()->addSeconds(3));

            $response = $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/map/scan-paths?'.$this->historicalScanPathsQuery([$kit->id]))
                ->assertOk();

            $header = $response->headers->get('X-Scout-Render-Points');
            $this->assertNotNull($header);
            $this->assertGreaterThanOrEqual(2, (int) $header);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_hits_defaults_to_precision_7_in_production(): void
    {
        // DEV-2234: with no precision param, hit-density cells must render at
        // geohash precision 7 (the removed debug-precision gate's coerced default).
        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedDirectHotspotHit($kit, 32.7, -96.7);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/heatmap/hits?north=33&south=32&east=-96&west=-97')
            ->assertOk();

        $geohashes = collect($response->json('features'))->pluck('properties.geohash')->filter();
        $this->assertNotEmpty($geohashes, 'Expected at least one hit feature');
        foreach ($geohashes as $g) {
            $this->assertSame(7, strlen($g), "Production default must be precision 7, got '{$g}'");
        }
    }

    #[Group('clarity-scout')]
    public function test_non_admin_user_is_forbidden(): void
    {
        $user = User::factory()->forOrg($this->org)->create();
        setPermissionsTeamId($this->org->id);
        $user->assignRole(RoleEnum::USER->value);

        $this->actingAs($user)
            ->getJson('/scout/org-map/api/drivers')
            ->assertForbidden();
    }

    #[Group('clarity-scout')]
    public function test_admin_without_the_scout_feature_is_forbidden(): void
    {
        $plainOrg = Org::factory()->create(); // not pro / no Scout grant
        $admin = User::factory()->forOrg($plainOrg)->create();
        setPermissionsTeamId($plainOrg->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin)
            ->getJson('/scout/org-map/api/drivers')
            ->assertForbidden();
    }

    #[Group('clarity-scout')]
    public function test_admin_impersonator_can_load_the_birds_eye_shell_without_the_scout_feature(): void
    {
        $plainOrg = Org::factory()->create(); // not pro / no Scout grant
        $admin = User::factory()->forOrg($plainOrg)->create();
        setPermissionsTeamId($plainOrg->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin)
            ->withSession(['admin_impersonator' => ['id' => $admin->id, 'name' => 'Tester']])
            ->get(route('clarity-scout.org-map'))
            ->assertOk()
            ->assertSee('clarity-scout-app', false);
    }

    #[Group('clarity-scout')]
    public function test_guest_is_unauthenticated(): void
    {
        // The `auth` middleware runs before the admin gate, so an unauthenticated
        // JSON request is rejected as 401 rather than reaching the 403 gate.
        $this->getJson('/scout/org-map/api/drivers')
            ->assertUnauthorized();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_includes_both_direct_and_staging_live(): void
    {
        // Freeze the clock so the default 'today' window is deterministic.
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        // Direct Live ('L') and Staging Live ('S') both surface (regression vs the
        // old Direct-only liveValid()). Staging's hotlist lives on a different org,
        // so kit_org_id scoping — not hotlist_org_id — must keep it.
        $this->seedHit($kit, ['ocr' => 'DIRECT1', 'hit_type' => 'L', 'scan_id' => 111, 'hit_created_at' => now()->toDateTimeString()]);
        $this->seedHit($kit, ['ocr' => 'STAGING1', 'hit_type' => 'S', 'scan_id' => 222, 'hotlist_org_id' => $this->org->id + 777, 'hit_created_at' => now()->toDateTimeString()]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $types = collect($response->json('data'))->pluck('hit_type')->sort()->values()->all();
        $this->assertSame(['L', 'S'], $types);

        // The disposition control on re-open must seed from the saved value, not
        // the placeholder — that requires both scan_id (the disposition endpoint's
        // identifier) and disposition itself on the payload (DEV-2059 F1 parity).
        $scanIds = collect($response->json('data'))->pluck('scan_id')->sort()->values()->all();
        $this->assertSame([111, 222], $scanIds);
        $this->assertArrayHasKey('disposition', $response->json('data.0'));

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_includes_vehicle_description(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);
        $this->seedHit($kit, [
            'ocr' => 'DIRECT1',
            'hit_type' => 'L',
            'hit_created_at' => now()->toDateTimeString(),
            'vehicle_year' => '2020',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Camry',
            'hotlist_us_state' => 'TX',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits')
            ->assertOk();

        $this->assertSame('2020', $response->json('data.0.vehicle_year'));
        $this->assertSame('Toyota', $response->json('data.0.vehicle_make'));
        $this->assertSame('Camry', $response->json('data.0.vehicle_model'));
        $this->assertSame('TX', $response->json('data.0.hotlist_us_state'));

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_response_is_cached(): void
    {
        // The org-wide Live Hits Pending list is polled ~10s (DEV-2179), so the
        // feed is now cached to that cadence (it was previously uncached). The key
        // is org + scoped-kit + range scoped, mirroring the sibling hits() shape.
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);
        $this->seedHit($kit, ['ocr' => 'DIRECT1', 'hit_type' => 'L', 'hit_created_at' => now()->toDateTimeString()]);

        $cacheKey = "scanner:birds-eye-recent-hits-v2:{$this->org->id}:{$kit->id}:today:{$this->admin->timezone}";
        $this->assertFalse(Cache::has($cacheKey));

        $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits')
            ->assertOk();

        $this->assertTrue(Cache::has($cacheKey));

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_reflects_current_hotlist_status_via_final_semi_join(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');

        // Order 1 is currently Open; order 2 is currently Repossessed. Both hit rows
        // carry the frozen hotlist_status=Open — only the currently-open order may
        // surface (DEV-2117 current-status FINAL semi-join).
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);
        $this->seedHotlist(2, HotlistStatusEnum::Repossessed->value);

        $this->seedHit($kit, ['ocr' => 'OPENNOW', 'hotlist_id' => 1, 'hotlist_status' => HotlistStatusEnum::Open->value, 'hit_created_at' => now()->toDateTimeString()]);
        $this->seedHit($kit, ['ocr' => 'REPONOW', 'hotlist_id' => 2, 'hotlist_status' => HotlistStatusEnum::Open->value, 'hit_created_at' => now()->toDateTimeString()]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('OPENNOW', $response->json('data.0.plate'));

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_respects_the_range_window(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        // One hit today, one 20 days ago (inside last_30, outside last_7/today).
        $this->seedHit($kit, ['ocr' => 'TODAYHIT', 'hit_created_at' => now()->toDateTimeString()]);
        $this->seedHit($kit, ['ocr' => 'OLDHIT', 'hit_created_at' => now()->subDays(20)->toDateTimeString()]);

        // Default 'today' — only the fresh hit.
        $today = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame('TODAYHIT', $today->json('data.0.plate'));

        // last_30 widens the window to include the 20-day-old hit.
        $month = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits?range=last_30')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $plates = collect($month->json('data'))->pluck('plate')->sort()->values()->all();
        $this->assertSame(['OLDHIT', 'TODAYHIT'], $plates);

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_multi_day_ranges_are_not_capped_to_todays_newest_twenty(): void
    {
        // DEV-2595: BirdsEye recentHits used to →limit(20). On a busy org the newest
        // 20 open plates are all from today, so last_7/15/30 looked like "today only"
        // when scrolling the Live Hits list. Cap removed to match the driver (95da4d7f).
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit Cap');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        for ($i = 0; $i < 25; $i++) {
            $this->seedHit($kit, [
                'ocr' => sprintf('TODAY%03d', $i),
                'hotlist_id' => 1,
                'hit_created_at' => now()->subMinutes($i)->toDateTimeString(),
            ]);
        }

        $this->seedHit($kit, [
            'ocr' => 'OLDERHIT',
            'hotlist_id' => 1,
            'hit_created_at' => now()->subDays(3)->toDateTimeString(),
        ]);

        foreach (['last_7', 'last_15', 'last_30'] as $range) {
            Cache::flush();

            $response = $this->actingAs($this->admin)
                ->getJson('/scout/org-map/api/dashboard/recent-hits?range='.$range)
                ->assertOk();

            $plates = collect($response->json('data'))->pluck('plate')->all();
            $this->assertCount(26, $plates, "range={$range} should return all 26 pending plates");
            $this->assertContains('OLDERHIT', $plates, "range={$range} must include a pre-today hit");
        }

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_is_org_wide_and_narrows_under_a_kit_ids_selector(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kitA = $this->enrolledKit($this->org, 'Kit A');
        $kitB = $this->enrolledKit($this->org, 'Kit B');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        // A hit for each of the org's two kits, plus another org's kit that must
        // never leak across the tenant boundary (kit_org_id scoping).
        $this->seedHit($kitA, ['ocr' => 'KITAHIT', 'hit_created_at' => now()->toDateTimeString()]);
        $this->seedHit($kitB, ['ocr' => 'KITBHIT', 'hit_created_at' => now()->toDateTimeString()]);
        $this->seedHit($kitA, ['ocr' => 'OTHERORG', 'kit_id' => $kitA->id + 9999, 'kit_org_id' => $this->org->id + 555, 'hit_created_at' => now()->toDateTimeString()]);

        // Org-wide default: both org kits, never the other org's.
        $orgWide = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $plates = collect($orgWide->json('data'))->pluck('plate')->sort()->values()->all();
        $this->assertSame(['KITAHIT', 'KITBHIT'], $plates);

        // The driver selector narrows to a single kit.
        $narrowed = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits?kit_ids[]='.$kitA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame('KITAHIT', $narrowed->json('data.0.plate'));

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_hits_includes_scan_id_and_disposition_for_direct_and_staging_live(): void
    {
        // map/hits (the marker layer) has no coverage in this file at all yet;
        // pin the DEV-2235 payload keys for both hit types, mirroring the
        // sibling driver-map assertions.
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        $this->seedHit($kit, [
            'ocr' => 'DIRECT1',
            'hit_type' => 'L',
            'scan_id' => 333,
            // hits()/map/hits applies actionableDisposition() (NULL/New Hit/
            // Accessible), so exercise a non-null disposition that still passes
            // that filter.
            'disposition' => DispositionEnum::NEW_HIT->value,
            'hit_created_at' => now()->toDateTimeString(),
        ]);
        $this->seedHit($kit, [
            'ocr' => 'STAGING1',
            'hit_type' => 'S',
            'scan_id' => 444,
            'hotlist_org_id' => $this->org->id + 777,
            'hit_created_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/hits')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $byPlate = collect($response->json('data'))->keyBy('plate');

        $this->assertSame(333, $byPlate['DIRECT1']['scan_id']);
        $this->assertSame(DispositionEnum::NEW_HIT->value, $byPlate['DIRECT1']['disposition']);
        $this->assertSame(444, $byPlate['STAGING1']['scan_id']);
        $this->assertNull($byPlate['STAGING1']['disposition']);

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_hits_retains_an_accessible_disposition_hit(): void
    {
        // AC-2 driver parity (DEV-2235 t4): the client's KEEP_VISIBLE_DISPOSITIONS
        // treats Accessible as a keep disposition, so map/hits must use the
        // actionable rule (not the analytics validDisposition() rule, which drops
        // Accessible) or an admin-set Accessible hit vanishes on the next poll.
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        $this->seedHit($kit, [
            'ocr' => 'ACCESSIBLE1',
            'disposition' => DispositionEnum::ACCESSIBLE->value,
            'hit_created_at' => now()->toDateTimeString(),
        ]);
        // New-Hit / NULL disposition must still surface (no regression).
        $this->seedHit($kit, [
            'ocr' => 'NEWHIT1',
            'hit_created_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/hits')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $plates = collect($response->json('data'))->pluck('plate')->sort()->values()->all();
        $this->assertSame(['ACCESSIBLE1', 'NEWHIT1'], $plates);

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_retains_an_accessible_disposition_hit(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        $this->seedHit($kit, [
            'ocr' => 'ACCESSIBLE1',
            'disposition' => DispositionEnum::ACCESSIBLE->value,
            'hit_created_at' => now()->toDateTimeString(),
        ]);
        $this->seedHit($kit, [
            'ocr' => 'NEWHIT1',
            'hit_created_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $plates = collect($response->json('data'))->pluck('plate')->sort()->values()->all();
        $this->assertSame(['ACCESSIBLE1', 'NEWHIT1'], $plates);

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_hits_retains_a_staging_live_accessible_disposition_hit(): void
    {
        // Must use actionableDisposition(), not liveActionable() — the latter
        // forces hit_type=Live and would drop Staging_Live entirely (DEV-2053
        // requires Staging_Live to stay visible on both admin surfaces).
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        $this->seedHit($kit, [
            'ocr' => 'STAGINGACC',
            'hit_type' => HitTypeEnum::Staging_Live->value,
            'disposition' => DispositionEnum::ACCESSIBLE->value,
            'hotlist_org_id' => $this->org->id + 777,
            'hit_created_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/hits')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('STAGINGACC', $response->json('data.0.plate'));
        $this->assertSame(HitTypeEnum::Staging_Live->value, $response->json('data.0.hit_type'));

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_hits_excludes_a_hide_disposition_hit(): void
    {
        // The switch to actionableDisposition() must not become a pass-through —
        // a genuine HIDE disposition (Inaccessible) stays excluded.
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        $this->seedHit($kit, [
            'ocr' => 'ACCESSIBLE1',
            'disposition' => DispositionEnum::ACCESSIBLE->value,
            'hit_created_at' => now()->toDateTimeString(),
        ]);
        $this->seedHit($kit, [
            'ocr' => 'HIDDEN1',
            'disposition' => DispositionEnum::INACCESSIBLE->value,
            'hit_created_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/hits')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('ACCESSIBLE1', $response->json('data.0.plate'));

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_recent_hits_excludes_a_hide_disposition_hit(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);

        $this->seedHit($kit, [
            'ocr' => 'ACCESSIBLE1',
            'disposition' => DispositionEnum::ACCESSIBLE->value,
            'hit_created_at' => now()->toDateTimeString(),
        ]);
        $this->seedHit($kit, [
            'ocr' => 'HIDDEN1',
            'disposition' => DispositionEnum::MOVING->value,
            'hit_created_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/recent-hits')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('ACCESSIBLE1', $response->json('data.0.plate'));

        Carbon::setTestNow();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_stats_excludes_an_accessible_disposition_hit(): void
    {
        // stats() intentionally keeps the analytics validDisposition() rule
        // (New Hit or Valid) — unlike hits()/recentHits(), an Accessible-
        // dispositioned hit must NOT count toward direct_hits. A NULL-
        // disposition hit is also seeded as a positive control: without it,
        // this test would stay green for the wrong reason if the Accessible
        // fixture ever silently failed to land (wrong org, TTL rot, etc).
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);
        $this->seedHotlist(2, HotlistStatusEnum::Open->value);

        $this->seedHit($kit, [
            'ocr' => 'ACCESSIBLE1',
            'disposition' => DispositionEnum::ACCESSIBLE->value,
            'hit_created_at' => now()->toDateTimeString(),
        ]);

        $this->seedHit($kit, [
            'ocr' => 'NEWHIT1',
            'hotlist_id' => 2,
            'hit_created_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/dashboard/stats')
            ->assertOk();

        $this->assertSame(1, $response->json('data.direct_hits'));

        Carbon::setTestNow();
    }

    /**
     * L2 (DEV-2164): an unknown `range` must never reach KitScanPathsQuery's
     * addslashes-only layerId literal — it collapses to 'today'. This runs under
     * ClickHouse (the whole class is RequiresClickhouse-gated), but the coercion
     * itself never depends on the seeded rows: a junk range answers 200 and the
     * today's-path row is tagged is_today (proving the 'today' fallback).
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_coerces_an_unknown_range_to_today(): void
    {
        Carbon::setTestNow($this->frozenNow());

        $kit = $this->enrolledKit($this->org, 'Kit A');
        $this->seedKitScanPath($kit, ['timestamp' => now()->toDateTimeString()]);

        $response = $this->actingAs($this->admin)
            ->getJson('/scout/org-map/api/map/scan-paths?range='.urlencode('today\';DROP'))
            ->assertOk();

        $this->assertNotEmpty($response->json());
        $this->assertTrue($response->json('0.is_today'));

        Carbon::setTestNow();
    }
}
