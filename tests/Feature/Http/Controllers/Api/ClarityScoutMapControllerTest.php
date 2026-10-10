<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Clickhouse\Queries\HotlistAddressGeohashQuery;
use App\Enums\DispositionEnum;
use App\Enums\HotlistStatusEnum;
use App\Facades\Analytics;
use App\Http\Controllers\Api\ClarityScoutMapController;
use App\Models\Address;
use App\Models\ApprovalList;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\Org;
use App\Models\OrgRunArea;
use App\Models\User;
use App\Models\Zone;
use App\Services\RunArea\Materializer;
use App\Support\Scout\Geohash;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class ClarityScoutMapControllerTest extends TestCase
{
    use RequiresClickhouse;

    private User $user;

    private Kit $kit;

    private string $token;

    private int $hitSeq = 0;

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

        // Map markers are gated on the order's CURRENT hotlist status (DEV-2113), so a
        // hit only surfaces when its hotlist_id maps to an Open hotlist. Seed a default
        // open hotlist (id=1) the shared hotlist_id=1 fixtures below point at.
        $this->seedHotlist(1, HotlistStatusEnum::Open->value);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_scan_paths(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/scan-paths');

        $response->assertSuccessful();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_today_layer_tags_paths_is_today_without_a_color(): void
    {
        $this->insertKitScanPath([
            'path_points' => '[(-86.20, 39.80), (-86.25, 39.85)]',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/scan-paths');

        $response->assertSuccessful();

        $paths = $response->json();
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertTrue($path['is_today']);
            $this->assertArrayHasKey('path', $path);
            $this->assertArrayNotHasKey('color', $path);
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_range_returns_hourly_segments_from_sc_positions(): void
    {
        // Two 2-point clusters in the same hour separated by a >500ft jump (a
        // power-off-and-drive gap) → two line strings, each carrying trip_date
        // and no server-side color (debug off). DEV-2018.
        $base = now()->subDays(2)->setTime(15, 0, 0);
        $this->insertScPosition([-97.7400, 30.3000], $base->copy());
        $this->insertScPosition([-97.7401, 30.3001], $base->copy()->addSeconds(5));
        $this->insertScPosition([-97.7900, 30.3400], $base->copy()->addSeconds(10));
        $this->insertScPosition([-97.7901, 30.3401], $base->copy()->addSeconds(15));

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/scan-paths?range=last_30');

        $response->assertSuccessful();

        $paths = $response->json();
        $this->assertGreaterThanOrEqual(2, count($paths));

        foreach ($paths as $path) {
            $this->assertArrayHasKey('path', $path);
            $this->assertArrayHasKey('trip_date', $path);
            $this->assertArrayNotHasKey('color', $path);
            $this->assertArrayNotHasKey('label', $path);
            $this->assertGreaterThanOrEqual(2, count($path['path']));
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_range_adds_color_and_label_when_debug_enabled(): void
    {
        config(['app.scanner_debug_map_enabled' => true]);

        $base = now()->subDays(2)->setTime(15, 0, 0);
        $this->insertScPosition([-97.7400, 30.3000], $base->copy());
        $this->insertScPosition([-97.7401, 30.3001], $base->copy()->addSeconds(5));

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/scan-paths?range=last_30');

        $response->assertSuccessful();

        $paths = $response->json();
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertArrayHasKey('color', $path);
            $this->assertArrayHasKey('label', $path);
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_range_returns_empty_when_no_positions(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/scout/map/scan-paths?range=last_30')
            ->assertSuccessful()
            ->assertExactJson([]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_accepts_each_new_day_count_range(): void
    {
        foreach (['last_7', 'last_15', 'last_30', 'last_60'] as $range) {
            $this->withToken($this->token)
                ->getJson("/api/scout/map/scan-paths?range={$range}")
                ->assertSuccessful();
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_bbox_excludes_positions_outside_the_viewport(): void
    {
        // DEV-2185: a plain lat/lng WHERE on KitHistoricPositionsQuery. One
        // 2-point cluster inside the requested viewport, one well outside.
        $base = now()->subDays(2)->setTime(15, 0, 0);
        $this->insertScPosition([-97.5000, 30.5000], $base->copy());
        $this->insertScPosition([-97.5001, 30.5001], $base->copy()->addSeconds(5));
        $this->insertScPosition([-70.0000, 10.0000], $base->copy()->addSeconds(10));
        $this->insertScPosition([-70.0001, 10.0001], $base->copy()->addSeconds(15));

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/scan-paths?range=last_30&north=31&south=30&east=-97&west=-98');

        $response->assertSuccessful();

        $paths = $response->json();
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            foreach ($path['path'] as $point) {
                $this->assertGreaterThanOrEqual(-98, $point[0]);
                $this->assertLessThanOrEqual(-97, $point[0]);
                $this->assertGreaterThanOrEqual(30, $point[1]);
                $this->assertLessThanOrEqual(31, $point[1]);
            }
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_scan_paths_cache_key_differentiates_by_bbox(): void
    {
        // Regression: the cache key must fold in the snapped bbox, or a second
        // viewport at the same range would reuse the first viewport's entry.
        $base = now()->subDays(2)->setTime(15, 0, 0);
        $this->insertScPosition([-97.5000, 30.5000], $base->copy());
        $this->insertScPosition([-97.5001, 30.5001], $base->copy()->addSeconds(5));

        // A viewport that excludes the seeded points entirely — warms an
        // "empty" cache entry.
        $empty = $this->withToken($this->token)
            ->getJson('/api/scout/map/scan-paths?range=last_30&north=1&south=0&east=-60&west=-61')
            ->assertSuccessful();
        $this->assertSame([], $empty->json());

        // A different viewport, same range, that DOES cover the seeded points —
        // must not reuse the first viewport's cached (empty) entry.
        $covering = $this->withToken($this->token)
            ->getJson('/api/scout/map/scan-paths?range=last_30&north=31&south=30&east=-97&west=-98')
            ->assertSuccessful();

        $this->assertNotEmpty($covering->json());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_paths_returns_empty_when_flag_disabled(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', false);
        $teammateKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertKitScanPath([
            'kit_id' => $teammateKit->id,
            'path_points' => '[(-86.20, 39.80), (-86.25, 39.85)]',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/teammate-paths');

        $response->assertSuccessful();
        $this->assertSame([], $response->json());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_paths_available_to_impersonator_when_flag_disabled(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', false);

        $forceToken = $this->user->createToken('clarity-scout-force-token', ['*', 'clarity-scout-force'])->plainTextToken;

        $teammateKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertKitScanPath([
            'kit_id' => $teammateKit->id,
            'path_points' => '[(-86.20, 39.80), (-86.25, 39.85)]',
        ]);

        $response = $this->withToken($forceToken)
            ->getJson('/api/scout/map/teammate-paths');

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json());
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_paths_returns_org_kits_excluding_own_kit(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        $teammateKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertKitScanPath([
            'kit_id' => $teammateKit->id,
            'path_points' => '[(-86.20, 39.80), (-86.25, 39.85)]',
        ]);
        // The caller's own kit has a path too — it must never surface in this layer.
        $this->insertKitScanPath([
            'kit_id' => $this->kit->id,
            'path_points' => '[(-90.10, 40.10), (-90.15, 40.15)]',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/teammate-paths');

        $response->assertSuccessful();
        $paths = $response->json();
        $this->assertNotEmpty($paths);

        $lngs = collect($paths)->flatMap(fn ($p) => $p['path'])->map(fn ($p) => round($p[0], 2))->all();
        $this->assertContains(-86.2, $lngs, "Expected the teammate kit's path.");
        $this->assertNotContains(-90.1, $lngs, "The requester's own kit path must be excluded.");
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_paths_excludes_rows_older_than_last_7_days(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        $teammateKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertKitScanPath([
            'kit_id' => $teammateKit->id,
            'timestamp' => now()->subDays(3)->toDateTimeString(),
            'path_points' => '[(-86.30, 39.90), (-86.35, 39.95)]',
        ]);
        $this->insertKitScanPath([
            'kit_id' => $teammateKit->id,
            'timestamp' => now()->subDays(10)->toDateTimeString(),
            'path_points' => '[(-95.00, 45.00), (-95.05, 45.05)]',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/teammate-paths');

        $response->assertSuccessful();
        $lngs = collect($response->json())->flatMap(fn ($p) => $p['path'])->map(fn ($p) => round($p[0], 2))->all();
        $this->assertContains(-86.3, $lngs, 'A row within the last 7 days must surface.');
        $this->assertNotContains(-95.0, $lngs, 'A row older than 7 days must be excluded.');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_paths_bbox_narrows_results(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        // Distinct timestamps: the ORDER BY key is (org_id, kit_id, timestamp), so
        // two rows for the same kit in the same second would collide under FINAL
        // and only the higher-_version row would survive the merge.
        $teammateKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertKitScanPath([
            'kit_id' => $teammateKit->id,
            'timestamp' => now()->toDateTimeString(),
            'path_points' => '[(-97.50, 30.50), (-97.51, 30.51)]',
        ]);
        $this->insertKitScanPath([
            'kit_id' => $teammateKit->id,
            'timestamp' => now()->subMinute()->toDateTimeString(),
            'path_points' => '[(-70.00, 10.00), (-70.01, 10.01)]',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/teammate-paths?north=31&south=30&east=-97&west=-98');

        $response->assertSuccessful();
        $paths = $response->json();
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            foreach ($path['path'] as $point) {
                $this->assertGreaterThanOrEqual(-98, $point[0]);
                $this->assertLessThanOrEqual(-97, $point[0]);
                $this->assertGreaterThanOrEqual(30, $point[1]);
                $this->assertLessThanOrEqual(31, $point[1]);
            }
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_paths_payload_has_no_live_position_or_per_teammate_metric(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        $teammateKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertKitScanPath([
            'kit_id' => $teammateKit->id,
            'path_points' => '[(-86.20, 39.80), (-86.25, 39.85)]',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/teammate-paths');

        $response->assertSuccessful();
        $paths = $response->json();
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertArrayHasKey('path', $path);
            $this->assertArrayNotHasKey('kit_id', $path);
            $this->assertArrayNotHasKey('lat', $path);
            $this->assertArrayNotHasKey('lng', $path);
            $this->assertArrayNotHasKey('scanned_at', $path);
            $this->assertArrayNotHasKey('minutes_ago', $path);
            $this->assertArrayNotHasKey('color', $path);
            $this->assertArrayNotHasKey('name', $path);
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_paths_degrades_gracefully_on_clickhouse_failure(): void
    {
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);
        Kit::factory()->forOrg($this->user->org)->create();

        // Staging (this feature's only intended environment) isn't 'production'
        // or 'testing', so ClickhouseQuery::query()'s own internal catch would
        // rethrow rather than swallow — pin that the CONTROLLER's own catch is
        // what degrades gracefully here, not an incidental testing-env behavior.
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

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/teammate-paths');

        $response->assertSuccessful();
        $this->assertSame([], $response->json());
        Log::shouldHaveReceived('warning')->once();
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_teammate_overlay_flag_does_not_change_existing_map_hits_scoping(): void
    {
        // Additive-only guardrail (DEV-2199): enabling the overlay flag must not
        // leak a teammate's hits into the driver's own individually-scoped
        // /map/hits — metrics/hits stay unaffected by this feature.
        config()->set('app.experimental.clarity_scout_teammate_overlay', true);

        $teammateKit = Kit::factory()->forOrg($this->user->org)->create();

        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            'id' => 90099, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L',
            'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'TEAMMATE',
            'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX', 'kit_id' => $teammateKit->id, 'kit_name' => 'Teammate',
            'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id,
            'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7,
            '_peerdb_is_deleted' => 0, '_version' => 1,
        ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_live_path_returns_segment_through_intermediate_sc_positions(): void
    {
        // A short drive: five points a few seconds apart, all close together so
        // they stay one segment. The live line must run through every one (the
        // intermediates), not hop straight between the first and last (DEV-2012).
        $base = now()->subMinutes(5);
        $this->insertScPosition([-97.7400, 30.3000], $base->copy());
        $this->insertScPosition([-97.7401, 30.3001], $base->copy()->addSeconds(3));
        $this->insertScPosition([-97.7402, 30.3002], $base->copy()->addSeconds(6));
        $this->insertScPosition([-97.7403, 30.3003], $base->copy()->addSeconds(9));
        $this->insertScPosition([-97.7404, 30.3004], $base->copy()->addSeconds(12));

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/live-path');

        $response->assertSuccessful();

        $segments = $response->json('segments');
        $this->assertNotEmpty($segments);
        $this->assertGreaterThanOrEqual(3, count($segments[0]['path']), 'Expected the intermediate points, not a straight hop.');
        $this->assertNotNull($response->json('cursor'));

        foreach ($segments as $segment) {
            $this->assertArrayHasKey('path', $segment);
            $this->assertArrayNotHasKey('is_today', $segment);
            $this->assertArrayNotHasKey('trip_date', $segment);
            $this->assertArrayNotHasKey('color', $segment);
            $this->assertArrayNotHasKey('label', $segment);
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_live_path_since_cursor_returns_only_newer_points(): void
    {
        // An older segment and a newer one; a cursor between them must return
        // only the newer points (the incremental delta), never the old ones.
        $base = now()->subMinutes(10);
        $this->insertScPosition([-97.7400, 30.3000], $base->copy());
        $this->insertScPosition([-97.7401, 30.3001], $base->copy()->addSeconds(4));

        $newer = $base->copy()->addMinutes(2);
        $this->insertScPosition([-97.8500, 30.4000], $newer->copy());
        $this->insertScPosition([-97.8501, 30.4001], $newer->copy()->addSeconds(4));

        $since = $base->copy()->addMinutes(1)->toDateTimeString();

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/live-path?since='.urlencode($since));

        $response->assertSuccessful();

        $lngs = collect($response->json('segments'))
            ->flatMap(fn ($s) => $s['path'])
            ->map(fn ($p) => round($p[0], 2))
            ->all();

        $this->assertContains(-97.85, $lngs, 'Newer points after the cursor must be returned.');
        $this->assertNotContains(-97.74, $lngs, 'Points at/before the cursor must be excluded.');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_live_path_breaks_segment_on_power_off_gap(): void
    {
        // Two clusters in the same hour separated by a >500ft jump (a
        // power-off-and-drive gap) → two segments, not one connector. Mirrors the
        // historic range behavior (DEV-2018), applied to the live line.
        $base = now()->subMinutes(5);
        $this->insertScPosition([-97.7400, 30.3000], $base->copy());
        $this->insertScPosition([-97.7401, 30.3001], $base->copy()->addSeconds(4));
        $this->insertScPosition([-97.7900, 30.3400], $base->copy()->addSeconds(8));
        $this->insertScPosition([-97.7901, 30.3401], $base->copy()->addSeconds(12));

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/live-path');

        $response->assertSuccessful();
        $this->assertGreaterThanOrEqual(2, count($response->json('segments')));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_live_path_returns_empty_when_no_positions(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/scout/map/live-path')
            ->assertSuccessful()
            ->assertExactJson(['segments' => [], 'cursor' => null]);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_live_path_echoes_cursor_when_no_new_points(): void
    {
        $since = now()->toDateTimeString();

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/live-path?since='.urlencode($since));

        $response->assertSuccessful();
        $this->assertSame([], $response->json('segments'));
        $this->assertSame($since, $response->json('cursor'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_live_path_matches_kit_mapping_case_insensitively(): void
    {
        // Platform stores kit_mapping lowercased; the scanner feed keeps its own
        // casing. ClickHouse `=` is case-sensitive, so an exact match silently
        // misses every kit whose casing differs — the live line must still resolve.
        $this->kit->update(['kit_mapping' => 'crtdfw2']);

        $base = now()->subMinutes(5);
        $this->insertScPositionFor('CRTDFW2', [-97.7400, 30.3000], $base->copy());
        $this->insertScPositionFor('CRTDFW2', [-97.7401, 30.3001], $base->copy()->addSeconds(3));
        $this->insertScPositionFor('CRTDFW2', [-97.7402, 30.3002], $base->copy()->addSeconds(6));

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/live-path');

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json('segments'));
        $this->assertNotNull($response->json('cursor'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_heatmap_hits(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits?north=40&south=39&east=-74&west=-75');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'type',
                'features',
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_hits_snaps_near_identical_viewports_to_one_cache_entry(): void
    {
        // F1: the viewport is snapped outward to a 0.02° grid before it becomes
        // query args, so two near-identical pans collapse to one cache entry —
        // the second request must reuse it and issue no new ClickHouse read.
        //
        // The custom ClickHouse connection runs over an HTTP client that never
        // populates Laravel's query log, so we prove "no second query" by data:
        // seed a hit, warm the cache on the first viewport, drop the row, then
        // assert a near-identical viewport still returns the cached feature — a
        // re-query would now see an empty table.
        $this->seedHit($this->user->org_id, lat: 32.7767, lng: -96.7970);

        $first = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits?north=32.778431&south=32.701000&east=-96.790000&west=-96.811000')
            ->assertSuccessful();

        $this->assertNotEmpty(
            $first->json('features'),
            'The first viewport must hit ClickHouse and return the seeded hit.'
        );

        // Drop the underlying row so a fresh ClickHouse read would come back empty.
        DB::connection('clarity_clickhouse')->statement('TRUNCATE TABLE IF EXISTS clarity.hits_cold');

        $second = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits?north=32.778500&south=32.701500&east=-96.790500&west=-96.811500')
            ->assertSuccessful();

        $this->assertSame(
            $first->json('features'),
            $second->json('features'),
            'A near-identical viewport must reuse the snapped cache entry rather than re-query ClickHouse.'
        );
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_returns_individual_range_hits_with_status_label(): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            'id' => 90001,
            'hotlist_id' => 1,
            'scan_id' => 1,
            'hit_type' => 'L',
            'hit_created_at' => now()->subDays(1)->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->subDays(1)->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => $this->kit->id,
            'kit_name' => 'Test Kit',
            'kit_org_id' => $this->user->org_id,
            'hotlist_org_id' => $this->user->org_id,
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'lat' => 32.7767,
            'lng' => -96.7970,
            'vehicle_year' => '2021',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Camry',
            'hotlist_us_state' => 'TX',
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['*' => ['plate', 'scan_id', 'time', 'status', 'hit_type', 'lat', 'lng', 'plate_image', 'vehicle_image', 'vehicle_year', 'vehicle_make', 'vehicle_model', 'hotlist_us_state', 'disposition']],
                'capped',
            ])
            ->assertJsonPath('data.0.plate', 'ABC1234')
            // DEV-2059: scan_id rides along so the hit-detail modal can write a disposition.
            ->assertJsonPath('data.0.scan_id', 1)
            // Regression with the recent-hits fix: status is the readable label, not an int or "Unknown".
            ->assertJsonPath('data.0.status', 'Open')
            ->assertJsonPath('data.0.hit_type', 'L')
            // DEV-2058 parity: the map-hits payload carries vehicle info for the modal.
            ->assertJsonPath('data.0.vehicle_make', 'Toyota')
            ->assertJsonPath('data.0.vehicle_model', 'Camry')
            // DEV-2059 F1: a New Hit (no disposition row) surfaces a null, not "New Hit".
            ->assertJsonPath('data.0.disposition', null)
            ->assertJsonPath('capped', false);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_carries_the_saved_accessible_disposition(): void
    {
        // DEV-2059 F1: an Accessible hit (the only non-null value this allowlisted
        // surface can carry — see actionableDisposition()) must round-trip its
        // disposition so the hit-detail modal can seed the dropdown from it on
        // re-open, instead of resetting to the placeholder.
        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            'id' => 90005,
            'hotlist_id' => 1,
            'scan_id' => 5,
            'hit_type' => 'L',
            'hit_created_at' => now()->subDays(1)->toDateTimeString(),
            'ocr' => 'ACCESS99',
            'disposition' => DispositionEnum::ACCESSIBLE->value,
            'scanned_at' => now()->subDays(1)->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'TX',
            'kit_id' => $this->kit->id,
            'kit_name' => 'Test Kit',
            'kit_org_id' => $this->user->org_id,
            'hotlist_org_id' => $this->user->org_id,
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'lat' => 32.7767,
            'lng' => -96.7970,
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
        ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonPath('data.0.scan_id', 5)
            ->assertJsonPath('data.0.disposition', DispositionEnum::ACCESSIBLE->value);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_response_is_cached(): void
    {
        $cacheKey = "scanner:map-hits-v2:{$this->user->org_id}:{$this->kit->id}:last_7";

        $this->assertFalse(Cache::has($cacheKey));

        $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful();

        $this->assertTrue(Cache::has($cacheKey));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_scoped_to_kit_and_live_types(): void
    {
        // Wrong kit + a non-live ('H' Historic) hit for this kit: neither should
        // come back. Only Direct Live ('L') and Staging Live ('S') for this kit do.
        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            ['id' => 90002, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'OTHERKIT', 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id + 999, 'kit_name' => 'Other', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => 1, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90003, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'H', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'HISTORIC', 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => 1, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
        ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_includes_staging_live_alongside_direct(): void
    {
        // DEV-2052: both Direct Live ('L') and Staging Live ('S') for this kit
        // surface on the map, each carrying its own hit_type. The Staging Live hit
        // matches a *staging* org's hotlist, so its hotlist_org_id differs from the
        // kit's org — the query must scope by kit_org_id (not forOrg/hotlist_org_id)
        // or the staging marker is dropped.
        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            ['id' => 90010, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'DIRECT1', 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => 1, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90011, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'S', 'hit_created_at' => now()->subDays(2)->toDateTimeString(), 'ocr' => 'STAGING1', 'scanned_at' => now()->subDays(2)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id + 777, 'hotlist_status' => 1, 'lat' => 32.8, 'lng' => -96.8, '_peerdb_is_deleted' => 0, '_version' => 1],
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');

        $types = collect($response->json('data'))->pluck('hit_type')->sort()->values()->all();
        $this->assertSame(['L', 'S'], $types);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_dedupes_cdc_versions_with_final(): void
    {
        // Two CDC versions of the same hit (same id, higher _version wins).
        // FINAL must collapse them to a single marker — not two.
        $hitCreatedAt = now()->subDays(1)->toDateTimeString();

        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            ['id' => 91001, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => $hitCreatedAt, 'ocr' => 'DUPE111', 'scanned_at' => $hitCreatedAt, 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => 1, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 91001, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => $hitCreatedAt, 'ocr' => 'DUPE111', 'scanned_at' => $hitCreatedAt, 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => 1, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 2],
        ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_only_returns_open_status_hits(): void
    {
        // Markers reflect the order's CURRENT hotlist status, not the hit row's
        // denormalized hotlist_status (DEV-2113). Every hit row here is stamped
        // Open (the frozen-at-hit-write value), but only the hit whose hotlist is
        // currently Open may surface — the Repossessed and Hold hotlists are excluded
        // even though their hit rows still say Open.
        $this->seedHotlist(11, HotlistStatusEnum::Repossessed->value);
        $this->seedHotlist(12, HotlistStatusEnum::Hold->value);

        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            ['id' => 90020, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'OPENHIT', 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90021, 'hotlist_id' => 11, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'REPOHIT', 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90022, 'hotlist_id' => 12, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'HOLDHIT', 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->assertSame('OPENHIT', $response->json('data.0.plate'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_excludes_repossessed_staging_live_hits(): void
    {
        // DEV-2113 regression: the leak was overwhelmingly Staging Live ('S'). A
        // staging hit row is almost always stamped Open and rarely re-scanned, so it
        // must be filtered on its hotlist's CURRENT status. Both rows below are stamped
        // Open at the row level; only the one whose staging hotlist is currently Open
        // surfaces — the repossessed-order staging marker is excluded.
        $this->seedHotlist(20, HotlistStatusEnum::Repossessed->value, $this->user->org_id + 777);
        $this->seedHotlist(21, HotlistStatusEnum::Open->value, $this->user->org_id + 777);

        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            ['id' => 90040, 'hotlist_id' => 20, 'scan_id' => 1, 'hit_type' => 'S', 'hit_created_at' => now()->subDays(2)->toDateTimeString(), 'ocr' => 'STAGEREPO', 'scanned_at' => now()->subDays(2)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id + 777, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.8, 'lng' => -96.8, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90041, 'hotlist_id' => 21, 'scan_id' => 1, 'hit_type' => 'S', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'STAGEOPEN', 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id + 777, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.9, 'lng' => -96.9, '_peerdb_is_deleted' => 0, '_version' => 1],
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->assertSame('STAGEOPEN', $response->json('data.0.plate'));
        $this->assertSame('S', $response->json('data.0.hit_type'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_resolves_latest_hotlist_version_on_status_transition(): void
    {
        // The production root cause (DEV-2113): an order transitions Open -> Repossessed
        // AFTER its hit. The hit row stays frozen Open; the current-status filter relies on
        // FINAL over clarity.hotlist to resolve the LATEST version. Two CDC versions of the
        // same hotlist id (v1 Open, v2 Repossessed) — the marker must be excluded. This pins
        // the fix's load-bearing dependency on the Hotlist model's ApplyFinal global scope.
        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
            ['id' => 30, 'org_id' => $this->user->org_id, 'status' => HotlistStatusEnum::Open->value, 'order_type' => 1, 'is_staged' => false, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(), '_peerdb_synced_at' => now()->toDateTimeString(), '_version' => 1, '_peerdb_is_deleted' => 0],
            ['id' => 30, 'org_id' => $this->user->org_id, 'status' => HotlistStatusEnum::Repossessed->value, 'order_type' => 1, 'is_staged' => false, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(), '_peerdb_synced_at' => now()->toDateTimeString(), '_version' => 2, '_peerdb_is_deleted' => 0],
        ]);

        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            ['id' => 90050, 'hotlist_id' => 30, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'TRANSREPO', 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
        ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_hits_excludes_rejected_dispositions(): void
    {
        // DEV-2059 correction: map markers use the base-Hits-page "actionable"
        // rule (NULL/New Hit or Accessible), matching HitsResource's default
        // filter — NOT the analytics validDisposition rule (NULL/Valid) that
        // stats/heatmap keep. Valid now hides here; Accessible now surfaces.
        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert([
            ['id' => 90030, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'NULLDISP', 'disposition' => null, 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90031, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'ACCESDIS', 'disposition' => DispositionEnum::ACCESSIBLE->value, 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90032, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'VALIDISP', 'disposition' => DispositionEnum::VALID->value, 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90033, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'MOVEDISP', 'disposition' => DispositionEnum::MOVING->value, 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90034, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'INACDISP', 'disposition' => DispositionEnum::INACCESSIBLE->value, 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90035, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'BADSCAND', 'disposition' => DispositionEnum::INVALID_SCAN->value, 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
            ['id' => 90036, 'hotlist_id' => 1, 'scan_id' => 1, 'hit_type' => 'L', 'hit_created_at' => now()->subDays(1)->toDateTimeString(), 'ocr' => 'BADDISP1', 'disposition' => DispositionEnum::INVALID->value, 'scanned_at' => now()->subDays(1)->toDateTimeString(), 'camera_name' => 'CAM-1', 'scan_us_state' => 'TX', 'kit_id' => $this->kit->id, 'kit_name' => 'Test Kit', 'kit_org_id' => $this->user->org_id, 'hotlist_org_id' => $this->user->org_id, 'hotlist_status' => HotlistStatusEnum::Open->value, 'lat' => 32.7, 'lng' => -96.7, '_peerdb_is_deleted' => 0, '_version' => 1],
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/hits?range=last_7')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');

        $plates = collect($response->json('data'))->pluck('plate')->sort()->values()->all();
        $this->assertSame(['ACCESDIS', 'NULLDISP'], $plates);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_hits_validates_bounds(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['north', 'south', 'east', 'west']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_heatmap_orders_direct(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=40&south=39&east=-74&west=-75&layers[]=direct');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'type',
                'features',
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_can_get_heatmap_orders_staging(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=40&south=39&east=-74&west=-75&layers[]=staging');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'type',
                'features',
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_orders_requires_a_layers_param(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=40&south=39&east=-74&west=-75')
            ->assertStatus(422);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=40&south=39&east=-74&west=-75&layers[]=garbage')
            ->assertStatus(422);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_falls_back_to_bbox_when_org_has_no_run_area(): void
    {
        // Direct is clipped to the driver's assigned zones, so cover the cell.
        $this->assignZoneWithPolygon([
            [-74.6, 39.4], [-74.4, 39.4], [-74.4, 39.6], [-74.6, 39.6], [-74.6, 39.4],
        ]);
        $inside = $this->seedGeohashCell($this->user->org_id, lng: -74.5, lat: 39.5, addressCount: 3);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=40&south=39&east=-74&west=-75&layers[]=direct');

        $response->assertSuccessful();
        $this->assertSame('FeatureCollection', $response->json('type'));
        $this->assertContains($inside, $this->featureGeohashes($response->json('features')));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_falls_back_to_bbox_when_run_area_not_materialized(): void
    {
        OrgRunArea::create([
            'org_id' => $this->user->org_id,
            'inputs' => [
                ['kind' => 'polygon', 'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[-75, 39], [-74, 39], [-74, 40], [-75, 40], [-75, 39]]],
                ]],
            ],
        ]);

        // Direct is clipped to the driver's assigned zones, so cover the cell.
        $this->assignZoneWithPolygon([
            [-74.6, 39.4], [-74.4, 39.4], [-74.4, 39.6], [-74.6, 39.6], [-74.6, 39.4],
        ]);
        $inside = $this->seedGeohashCell($this->user->org_id, lng: -74.5, lat: 39.5, addressCount: 3);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=40&south=39&east=-74&west=-75&layers[]=direct');

        $response->assertSuccessful();
        $this->assertContains($inside, $this->featureGeohashes($response->json('features')));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_bbox_fallback_excludes_cells_outside_the_viewport(): void
    {
        // Direct is clipped to the driver's assigned zones, so cover the in-viewport
        // cell; the out-of-viewport cell is excluded by the bbox before the zone clip.
        $this->assignZoneWithPolygon([
            [-74.6, 39.4], [-74.4, 39.4], [-74.4, 39.6], [-74.6, 39.6], [-74.6, 39.4],
        ]);
        $inside = $this->seedGeohashCell($this->user->org_id, lng: -74.5, lat: 39.5, addressCount: 3);
        $outside = $this->seedGeohashCell($this->user->org_id, lng: -76.5, lat: 39.5, addressCount: 3);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=40&south=39&east=-74&west=-75&layers[]=direct');

        $response->assertSuccessful();
        $geohashes = $this->featureGeohashes($response->json('features'));
        $this->assertContains($inside, $geohashes);
        $this->assertNotContains($outside, $geohashes);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_succeeds_with_run_area_clip_after_materialization(): void
    {
        $runArea = OrgRunArea::create([
            'org_id' => $this->user->org_id,
            'inputs' => [
                ['kind' => 'polygon', 'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[-75, 39], [-74, 39], [-74, 40], [-75, 40], [-75, 39]]],
                ]],
            ],
        ]);
        Materializer::materialize($runArea);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=40&south=39&east=-74&west=-75&layers[]=direct');

        $response->assertSuccessful();
        $this->assertSame('FeatureCollection', $response->json('type'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_hotlist_query_clips_against_a_non_simple_run_area_ring(): void
    {
        // A run area materialized from simplified TIGER state/MSA boundaries is
        // PostGIS-valid but produces non-simple rings. ClickHouse's pointInPolygon
        // rejects those under the default validate_polygons=1 and throws — which
        // ClickhouseQuery::query swallows into an empty layer (DEV-2013). The
        // connection runs with validate_polygons=0, so the clip must still resolve.
        // This test calls the query directly at precision 6 (below), so seed the
        // expected cells at 6 too — the bbox/clip semantics here are precision-agnostic.
        $inside = $this->seedGeohashCell($this->user->org_id, lng: -96.55, lat: 32.80, addressCount: 3, precision: 6);
        // The "outside" cell sits on the bowtie's self-intersection (-96.80, 32.80):
        // ONLY that exact crossing point reads as outside — ClickHouse's pointInPolygon
        // counts the notch just north of it as inside — so seed a single point there.
        $outside = $this->seedGeohashCell($this->user->org_id, lng: -96.80, lat: 32.80, addressCount: 1, precision: 6);

        // Self-intersecting "bowtie" outer ring spanning the bbox: its right lobe
        // contains the inside cell, the crossing leaves the centre cell outside.
        $bowtie = [[-97.1, 32.5], [-96.5, 33.1], [-96.5, 32.5], [-97.1, 33.1], [-97.1, 32.5]];

        $rows = HotlistAddressGeohashQuery::query([
            'org_id' => $this->user->org_id,
            'staging' => false,
            'tiers' => ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'],
            'north' => 33.1,
            'south' => 32.5,
            'east' => -96.5,
            'west' => -97.1,
            'precision' => 6,
            'mode' => 'aggregate',
            '_run_area_rings' => [$bowtie],
        ]);

        $geohashes = collect($rows)->map(fn ($r) => $r->geohash ?? ($r['geohash'] ?? null))->all();

        $this->assertContains($inside, $geohashes, 'Cell inside the run-area ring must survive the clip (a thrown pointInPolygon would empty it).');
        $this->assertNotContains($outside, $geohashes, 'Cell outside the ring must be clipped out.');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_unions_direct_and_staging_into_one_summed_cell(): void
    {
        $this->seedDallasRunArea();
        // A zone is assigned, so both layers clip to it; the zone covers the
        // shared cell so it still surfaces.
        $this->assignZoneWithPolygon([
            [-96.60, 32.75], [-96.50, 32.75], [-96.50, 32.85], [-96.60, 32.85], [-96.60, 32.75],
        ]);

        // Same lng/lat → same geohash from both layers (default precision).
        $direct = $this->seedGeohashCell($this->user->org_id, lng: -96.55, lat: 32.80, addressCount: 3, source: 'own');
        $provider = $this->seedStagingProvider();
        $staging = $this->seedGeohashCell($provider->id, lng: -96.55, lat: 32.80, addressCount: 5, source: 'staging');
        $this->assertSame($direct, $staging, 'Both cells must resolve to the same geohash for the union to combine them.');

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=33&south=32&east=-96&west=-97&layers[]=direct&layers[]=staging');

        $response->assertSuccessful();

        $features = collect($response->json('features'))->where('properties.geohash', $direct)->values();
        $this->assertCount(1, $features, 'A geohash present in both layers must render as exactly one cell.');
        $this->assertSame(8, $features[0]['properties']['count'], 'Per-cell counts must sum across the unioned layers (3 + 5).');
        $this->assertSame('tier_5_9', $features[0]['properties']['tier']);
        $this->assertSame('#AB47BC', $features[0]['properties']['color']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_direct_clips_to_assigned_zones(): void
    {
        $this->seedDallasRunArea();
        // Zone covers cell A only; cell B sits well outside it.
        $this->assignZoneWithPolygon([
            [-96.60, 32.75], [-96.50, 32.75], [-96.50, 32.85], [-96.60, 32.85], [-96.60, 32.75],
        ]);

        $inZone = $this->seedGeohashCell($this->user->org_id, lng: -96.55, lat: 32.80, addressCount: 2, source: 'own');
        $outOfZone = $this->seedGeohashCell($this->user->org_id, lng: -96.20, lat: 32.40, addressCount: 4, source: 'own');

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=33&south=32&east=-96&west=-97&layers[]=direct');

        $response->assertSuccessful();
        $geohashes = collect($response->json('features'))->pluck('properties.geohash');

        $this->assertContains($inZone, $geohashes->all(), 'Direct cell inside an assigned zone must survive.');
        $this->assertNotContains($outOfZone, $geohashes->all(), 'Direct cell outside assigned zones must be clipped.');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_direct_renders_org_wide_without_assigned_zones(): void
    {
        $this->seedDallasRunArea();
        // No zones assigned to the driver. Direct cell seeded inside the run area.
        $geohash = $this->seedGeohashCell($this->user->org_id, lng: -96.55, lat: 32.80, addressCount: 2, source: 'own');

        // Direct alone, no zones → renders org-wide (DEV-2278), unlike staging.
        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=33&south=32&east=-96&west=-97&layers[]=direct')
            ->assertSuccessful();

        $this->assertContains(
            $geohash,
            collect($response->json('features'))->pluck('properties.geohash')->all(),
            'Direct cell must render org-wide when the driver has no assigned zones (DEV-2278).',
        );
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_staging_is_empty_without_assigned_zones(): void
    {
        $this->seedDallasRunArea();
        // No zones assigned to the driver. Staging seeded inside the run area.
        $provider = $this->seedStagingProvider();
        $this->seedGeohashCell($provider->id, lng: -96.55, lat: 32.80, addressCount: 5, source: 'staging');

        // Staging alone → nothing (no geozones, AC#4 pushes users to set them).
        $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=33&south=32&east=-96&west=-97&layers[]=staging')
            ->assertSuccessful()
            ->assertJsonPath('features', []);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_staging_clips_to_assigned_zones(): void
    {
        $this->seedDallasRunArea();
        // Zone covers the in-zone cell only.
        $this->assignZoneWithPolygon([
            [-96.60, 32.75], [-96.50, 32.75], [-96.50, 32.85], [-96.60, 32.85], [-96.60, 32.75],
        ]);

        $provider = $this->seedStagingProvider();
        $inZone = $this->seedGeohashCell($provider->id, lng: -96.55, lat: 32.80, addressCount: 3, source: 'staging');
        $outOfZone = $this->seedGeohashCell($provider->id, lng: -96.20, lat: 32.40, addressCount: 3, source: 'staging');

        $geohashes = collect(
            $this->withToken($this->token)
                ->getJson('/api/scout/map/heatmap/orders?north=33&south=32&east=-96&west=-97&layers[]=staging')
                ->json('features')
        )->pluck('properties.geohash');

        $this->assertContains($inZone, $geohashes->all(), 'Staging cell inside an assigned zone must survive.');
        $this->assertNotContains($outOfZone, $geohashes->all(), 'Staging cell outside assigned zones must be clipped.');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_staging_excludes_non_staged_forwarder_orders(): void
    {
        // DEV-2080 finding A: the staging layer resolves forwarder orgs via
        // approval_list but must also require is_staged = true — a forwarder order
        // the provider has not staged must not surface, even within an assigned zone.
        $this->seedDallasRunArea();
        $this->assignZoneWithPolygon([
            [-96.60, 32.75], [-96.50, 32.75], [-96.50, 32.85], [-96.60, 32.85], [-96.60, 32.75],
        ]);

        $provider = $this->seedStagingProvider();
        $staged = $this->seedGeohashCell($provider->id, lng: -96.55, lat: 32.80, addressCount: 3, source: 'staging');
        $notStaged = $this->seedGeohashCell($provider->id, lng: -96.53, lat: 32.80, addressCount: 3, source: 'staging', isStaged: false);

        $geohashes = collect(
            $this->withToken($this->token)
                ->getJson('/api/scout/map/heatmap/orders?north=33&south=32&east=-96&west=-97&layers[]=staging')
                ->json('features')
        )->pluck('properties.geohash')->all();

        $this->assertContains($staged, $geohashes, 'A staged forwarder order must surface in the staging layer.');
        $this->assertNotContains($notStaged, $geohashes, 'A non-staged forwarder order must be excluded (DEV-2080 finding A).');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_order_query_excludes_plates_shorter_than_three(): void
    {
        // DEV-2183: the order query drops orders whose plate is too short to be
        // actionable, matching the dashboard-stats `length(plate) >= 3` rule. A
        // length-3 plate surfaces; a length-2 plate does not.
        $args = [
            'org_id' => $this->user->org_id,
            'tiers' => ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'],
            'north' => 33, 'south' => 32, 'east' => -96, 'west' => -97,
            'precision' => 7,
        ];

        // seedGeohashCell returns the cell's hash at precision 7; query at 7 to match
        // (the query defaults to 6, which would encode a different, shorter geohash).
        $len3 = $this->seedGeohashCell($this->user->org_id, lng: -96.55, lat: 32.80, addressCount: 1, source: 'own', plate: 'ABC', precision: 7);
        $len2 = $this->seedGeohashCell($this->user->org_id, lng: -96.50, lat: 32.70, addressCount: 1, source: 'own', plate: 'AB', precision: 7);

        $hashes = collect(HotlistAddressGeohashQuery::query($args))
            ->map(fn ($r) => $r->geohash ?? ($r['geohash'] ?? null))->all();

        $this->assertContains($len3, $hashes, 'A length-3 plate must surface under the >= 3 rule.');
        $this->assertNotContains($len2, $hashes, 'A length-2 plate must be excluded.');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_direct_includes_length_three_plate_orders(): void
    {
        // DEV-2183: the Direct order layer uses the dashboard `>= 3` plate rule, so a
        // length-3 plate order surfaces (it was excluded under the old `> 3`).
        $this->seedDallasRunArea();
        $this->assignZoneWithPolygon([
            [-96.60, 32.75], [-96.50, 32.75], [-96.50, 32.85], [-96.60, 32.85], [-96.60, 32.75],
        ]);
        $len3 = $this->seedGeohashCell($this->user->org_id, lng: -96.55, lat: 32.80, addressCount: 2, source: 'own', plate: 'ABC');

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/orders?north=33&south=32&east=-96&west=-97&layers[]=direct')
            ->assertSuccessful();

        $this->assertContains(
            $len3,
            collect($response->json('features'))->pluck('properties.geohash')->all(),
            'A length-3 plate Direct order must surface under the >= 3 rule (DEV-2183).',
        );
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    #[Group('run-area')]
    public function test_heatmap_orders_staging_includes_length_three_plate_orders(): void
    {
        // DEV-2183: the Staging order layer uses the same `>= 3` plate rule.
        $this->seedDallasRunArea();
        $this->assignZoneWithPolygon([
            [-96.60, 32.75], [-96.50, 32.75], [-96.50, 32.85], [-96.60, 32.85], [-96.60, 32.75],
        ]);
        $provider = $this->seedStagingProvider();
        $len3 = $this->seedGeohashCell($provider->id, lng: -96.55, lat: 32.80, addressCount: 3, source: 'staging', plate: 'ABC');

        $geohashes = collect(
            $this->withToken($this->token)
                ->getJson('/api/scout/map/heatmap/orders?north=33&south=32&east=-96&west=-97&layers[]=staging')
                ->json('features')
        )->pluck('properties.geohash')->all();

        $this->assertContains($len3, $geohashes, 'A length-3 plate Staging order must surface under the >= 3 rule (DEV-2183).');
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_kit_position_returns_latest_kit_scan_path_point(): void
    {
        // Older bucket
        $this->insertKitScanPath([
            'timestamp' => now()->subMinutes(30)->toDateTimeString(),
            'path_points' => '[(-86.10, 39.70), (-86.11, 39.71)]',
        ]);
        // Newest bucket — controller should use this one, last element
        $this->insertKitScanPath([
            'timestamp' => now()->toDateTimeString(),
            'path_points' => '[(-86.20, 39.80), (-86.25, 39.85)]',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/kit-position');

        $response->assertSuccessful();
        $this->assertEqualsWithDelta(39.85, $response->json('lat'), 0.0001);
        $this->assertEqualsWithDelta(-86.25, $response->json('lng'), 0.0001);
        $this->assertNotNull($response->json('scanned_at'));
        $this->assertIsInt($response->json('minutes_ago'));
        $this->assertSame('scan', $response->json('source'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_kit_position_falls_back_to_kit_trip_end_point_when_no_paths(): void
    {
        $this->insertKitTrip([
            'trip_end' => now()->toDateTimeString(),
            'end_point' => '(-86.30, 39.90)',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/kit-position');

        $response->assertSuccessful();
        $this->assertEqualsWithDelta(39.90, $response->json('lat'), 0.0001);
        $this->assertEqualsWithDelta(-86.30, $response->json('lng'), 0.0001);
        $this->assertSame('scan', $response->json('source'));
        // Fallback branches carry an explicit null heading, matching the primary
        // sc_positions branch and emptyKitPosition (DEV-2164).
        $this->assertArrayHasKey('heading', $response->json());
        $this->assertNull($response->json('heading'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_kit_position_returns_null_when_no_data(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/kit-position');

        $response->assertSuccessful();
        $this->assertNull($response->json('lat'));
        $this->assertNull($response->json('lng'));
        $this->assertNull($response->json('scanned_at'));
        $this->assertNull($response->json('minutes_ago'));
        $this->assertSame('scan', $response->json('source'));
        $this->assertArrayHasKey('heading', $response->json());
        $this->assertNull($response->json('heading'));
    }

    /**
     * CH-free: the empty kit-position payload is constructed with no ClickHouse
     * access, so assert its shape directly on the controller's private helper.
     * It must include heading => null so the PWA always reads a consistent key
     * set across the primary, fallback, and empty branches (DEV-2164).
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_empty_kit_position_payload_includes_null_heading(): void
    {
        $method = new \ReflectionMethod(ClarityScoutMapController::class, 'emptyKitPosition');

        /** @var JsonResponse $response */
        $response = $method->invoke(new ClarityScoutMapController, 'car');

        $payload = $response->getData(true);

        $this->assertArrayHasKey('heading', $payload);
        $this->assertNull($payload['heading']);
        $this->assertNull($payload['lat']);
        $this->assertNull($payload['lng']);
        $this->assertSame('scan', $payload['source']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_kit_position_matches_kit_mapping_case_insensitively(): void
    {
        // The live dot's primary source is sc_positions, keyed by kit_mapping; like
        // the live line it must resolve despite platform/scanner casing drift rather
        // than falling through to the kit_scan_paths/kit_trips fallbacks.
        $this->kit->update(['kit_mapping' => 'crtdfw2']);

        $this->insertScPositionFor('CRTDFW2', [-97.7400, 30.3000], now()->subMinute());

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/kit-position');

        $response->assertSuccessful();
        $this->assertEqualsWithDelta(30.3000, $response->json('lat'), 0.0001);
        $this->assertEqualsWithDelta(-97.7400, $response->json('lng'), 0.0001);
        $this->assertSame('scan', $response->json('source'));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_kit_position_scoped_to_kit(): void
    {
        // Path for a different kit
        $otherKit = Kit::factory()->forOrg($this->user->org)->create();
        $this->insertKitScanPath([
            'kit_id' => $otherKit->id,
            'path_points' => '[(-86.40, 39.40)]',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/kit-position');

        $response->assertSuccessful();
        $this->assertNull($response->json('lat'));
    }

    private function insertKitScanPath(array $overrides = []): void
    {
        $defaults = [
            'kit_id' => $this->kit->id,
            'org_id' => $this->user->org_id,
            'timestamp' => now()->toDateTimeString(),
            'scan_count' => 1,
            'path_points' => '[(-86.15, 39.75)]',
            'kit_name' => $this->kit->name ?? 'Test Kit',
            '_version' => now()->format('Y-m-d H:i:s.u'),
        ];

        DB::connection('clarity_clickhouse')->table('clarity.kit_scan_paths')
            ->insert(array_merge($defaults, $overrides));
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
            'path_simplified' => '[(-86.30, 39.90)]',
            'avg_speed' => 25.0,
            'max_speed' => 45.0,
            'start_point' => '(-86.25, 39.85)',
            'end_point' => '(-86.30, 39.90)',
        ];

        DB::connection('clarity_clickhouse')->table('clarity.kit_trips')
            ->insert(array_merge($defaults, $overrides));
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_hits_defaults_to_precision_7_in_production(): void
    {
        // DEV-2112: with debug disabled and NO precision param (the production
        // path), hit-density cells must render at geohash precision 7.
        config(['app.scanner_debug_map_enabled' => false]);
        $this->seedHit($this->user->org_id, lat: 39.7684, lng: -86.1581);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits?north=40&south=39&east=-86&west=-87');

        $response->assertSuccessful();

        $geohashes = collect($response->json('features'))->pluck('properties.geohash')->filter();
        $this->assertNotEmpty($geohashes, 'Expected at least one hit feature');
        foreach ($geohashes as $g) {
            $this->assertSame(7, strlen($g), "Production default must be precision 7, got '{$g}'");
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_clip_rejects_a_zone_the_caller_is_not_assigned_to(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits?north=40&south=39&east=-74&west=-75&zone_ids[]=999999')
            ->assertStatus(403);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_clip_rejects_when_any_requested_zone_is_unassigned(): void
    {
        $zone = $this->assignZoneWithPolygon([
            [-97.72, 30.30], [-97.70, 30.30], [-97.70, 30.32], [-97.72, 30.32], [-97.72, 30.30],
        ]);

        // One assigned zone is fine; pairing it with a foreign id is rejected wholesale.
        $this->withToken($this->token)
            ->getJson("/api/scout/map/heatmap/hits?north=40&south=39&east=-74&west=-75&zone_ids[]={$zone->id}&zone_ids[]=999999")
            ->assertStatus(403);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_hits_clips_to_the_union_of_multiple_assigned_zones(): void
    {
        // Two disjoint zones; a hit inside each. Clipping to both keeps both.
        $zoneA = $this->assignZoneWithPolygon([
            [-97.72, 30.30], [-97.70, 30.30], [-97.70, 30.32], [-97.72, 30.32], [-97.72, 30.30],
        ]);
        $zoneB = $this->assignZoneWithPolygon([
            [-97.62, 30.40], [-97.60, 30.40], [-97.60, 30.42], [-97.62, 30.42], [-97.62, 30.40],
        ]);

        $this->seedHit($this->user->org_id, lat: 30.31, lng: -97.71);
        $this->seedHit($this->user->org_id, lat: 30.41, lng: -97.61);
        // A hit well outside either zone, which the clip must drop.
        $this->seedHit($this->user->org_id, lat: 30.10, lng: -97.90);

        $response = $this->withToken($this->token)
            ->getJson("/api/scout/map/heatmap/hits?north=31&south=30&east=-97&west=-98&zone_ids[]={$zoneA->id}&zone_ids[]={$zoneB->id}");

        $response->assertSuccessful();
        $this->assertSame('FeatureCollection', $response->json('type'));

        // Every surviving feature must intersect one of the two zones; the
        // out-of-zone hit at (30.10, -97.90) is excluded by the union clip.
        foreach ($response->json('features') as $feature) {
            $lng = $feature['geometry']['coordinates'][0][0][0];
            $this->assertGreaterThan(-97.80, $lng);
        }
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_heatmap_hits_renders_unclipped_when_no_zone_ids_sent(): void
    {
        // No-op path: zero zones → no clip param → all in-bounds data renders.
        $this->seedHit($this->user->org_id, lat: 30.10, lng: -97.90);

        $response = $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits?north=31&south=30&east=-97&west=-98');

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json('features'));
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ring  closed [lng, lat] ring
     */
    private function assignZoneWithPolygon(array $ring, bool $noScan = false): Zone
    {
        $zone = Zone::create([
            'org_id' => $this->user->org_id,
            'name' => $noScan ? 'No Scan Zone' : 'Coverage Zone',
            'color' => $noScan ? Zone::NO_SCAN_COLOR : '#2563eb',
            'is_no_scan_zone' => $noScan,
            'definition' => ['polygons' => [['type' => 'Polygon', 'coordinates' => [$ring]]], 'zip_codes' => []],
        ]);
        $zone->rebuildGeometryFromDefinition();

        $this->user->zones()->attach($zone->id);

        return $zone;
    }

    /**
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     */
    private function insertScPosition(array $point, Carbon $at): void
    {
        $this->insertScPositionFor($this->kit->kit_mapping, $point, $at);
    }

    /**
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     */
    private function insertScPositionFor(string $mapping, array $point, Carbon $at): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.sc_positions')->insert([
            'kit_mapping' => $mapping,
            'geo' => "({$point[0]}, {$point[1]})",
            'timestamp_utc' => $at->toDateTimeString(),
            'speed' => 30.0,
            'heading' => 90.0,
        ]);
    }

    private function seedHotlist(int $id, int $status, ?int $orgId = null): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
            'id' => $id,
            'org_id' => $orgId ?? $this->user->org_id,
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

    private function seedHit(int $orgId, float $lat, float $lng): void
    {
        // The hit-density layer (HitDirectHotspotQuery) inner-joins hits_cold to
        // an OPEN, non-staged hotlist on hotlist_id, so a bare hit is invisible
        // without a matching order. Seed (idempotently — hotlist FINAL dedups
        // the repeat id=1 inserts) before the hit.
        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
            'id' => 1,
            'org_id' => $orgId,
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
            // Unique id per hit so hits_cold FINAL keeps distinct rows that share
            // an org + hit_created_at (e.g. the zone-union test's three hits).
            'id' => ++$this->hitSeq,
            'hotlist_id' => 1,
            'scan_id' => 1,
            'hit_type' => 'L',
            'hit_created_at' => now()->subDays(1)->toDateTimeString(),
            'ocr' => 'ABC1234',
            'scanned_at' => now()->subDays(1)->toDateTimeString(),
            'camera_name' => 'CAM-1',
            'scan_us_state' => 'IN',
            'kit_id' => $this->kit->id,
            'kit_name' => 'Test Kit',
            'kit_org_id' => $orgId,
            'hotlist_org_id' => $orgId,
            'hotlist_us_state' => 'IN',
            'hotlist_plate' => 'ABC1234',
            'hotlist_status' => 1,
            'lat' => $lat,
            'lng' => $lng,
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
        ]);
    }

    private function seedHotlistAddress(int $orgId, float $lat, float $lng): void
    {
        $hotlist = Hotlist::factory()->forOrg(Org::find($orgId))->create();
        $address = Address::factory()->create(['geo' => Point::makeGeodetic($lat, $lng)]);

        $address->hotlists()->attach($hotlist->id);
    }

    /**
     * Materialize a run area covering the Dallas test bbox (-97..-96, 32..33) so
     * the order heatmap clips resolve against real rings.
     */
    private function seedDallasRunArea(): void
    {
        $runArea = OrgRunArea::create([
            'org_id' => $this->user->org_id,
            'inputs' => [
                ['kind' => 'polygon', 'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[-97, 32], [-96, 32], [-96, 33], [-97, 33], [-97, 32]]],
                ]],
            ],
        ]);
        Materializer::materialize($runArea);
    }

    /**
     * Register an approved staging provider for the test user's org and return it,
     * so {@see Org::stagingOrgIds()} resolves the provider's seeded geohash cells.
     */
    private function seedStagingProvider(): Org
    {
        $provider = Org::factory()->create();

        $approval = new ApprovalList;
        $approval->agent_org_id = $this->user->org_id;
        $approval->staging_org_id = $provider->id;
        $approval->save();

        cache()->forget('staging_org_ids:'.$this->user->org_id);

        return $provider;
    }

    /**
     * Seed an order-density cell into the RAW CDC tables the heatmap now reads
     * ({@see HotlistAddressGeohashQuery::queryClickhouseRaw}, which retired the
     * precision-6 MV): an open, plate-valid hotlist for the org plus $addressCount
     * addresses linked through hotlist_address. The points share the caller's
     * longitude and step the latitude by a metre so they stay in the one cell yet
     * count as distinct geos — count(DISTINCT lat, lng) then equals $addressCount.
     * Point 0 sits exactly on (lng, lat) so the run-area / bbox clip in/out
     * semantics are preserved. The staging layer requires is_staged = true
     * (source 'staging'); direct orders are is_staged = false. Returns the cell's
     * geohash at $precision — default 7 (the production default, DEV-2112); pass 6
     * to match a query/endpoint resolved at the legacy precision. Pass $plate to
     * exercise the plate-length guard (default 'ABCD12', length 6 — always valid).
     */
    private function seedGeohashCell(int $orgId, float $lng, float $lat, int $addressCount, string $source = 'own', ?bool $isStaged = null, int $precision = 7, string $plate = 'ABCD12'): string
    {
        static $hotlistId = 700_000;
        static $addressId = 800_000;
        static $linkId = 900_000;

        $isStaged ??= $source === 'staging';
        $hid = $hotlistId++;

        DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
            'id' => $hid,
            'org_id' => $orgId,
            'status' => HotlistStatusEnum::Open->value,
            'order_type' => 1,
            'is_staged' => $isStaged,
            'plate' => $plate,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ]);

        for ($i = 0; $i < $addressCount; $i++) {
            $pointLat = $lat + ($i * 0.00001);
            $aid = $addressId++;

            DB::connection('clarity_clickhouse')->table('zod.public_address')->insert([
                'id' => $aid,
                'house_number' => '100',
                'road' => 'Test St',
                'geo' => sprintf('SRID=4326;POINT (%.8f %.8f)', $lng, $pointLat),
                'orig' => '',
                '_peerdb_is_deleted' => 0,
                '_peerdb_version' => 1,
            ]);

            DB::connection('clarity_clickhouse')->table('zod.public_hotlist_address')->insert([
                'id' => $linkId++,
                'hotlist_id' => $hid,
                'address_id' => $aid,
                '_peerdb_is_deleted' => 0,
                '_peerdb_version' => 1,
            ]);
        }

        return Geohash::encode($lng, $lat, $precision);
    }

    /**
     * Pull the geohash off each aggregate feature's properties.
     *
     * @param  array<int, array<string, mixed>>  $features
     * @return list<string>
     */
    private function featureGeohashes(array $features): array
    {
        return array_values(array_filter(array_map(
            static fn ($f) => $f['properties']['geohash'] ?? null,
            $features,
        )));
    }

    /**
     * Contract: lock the fixed-key shapes the PWA map reads. kit-position always
     * returns the same six keys (null payload when no recent fix); the heatmap
     * endpoints always return a GeoJSON FeatureCollection. A shape change fails CI.
     */
    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_map_response_shapes_are_locked(): void
    {
        $this->withToken($this->token)->getJson('/api/scout/map/kit-position')
            ->assertSuccessful()
            ->assertJsonStructure([
                'lat', 'lng', 'scanned_at', 'minutes_ago', 'vehicle_type', 'heading', 'source',
            ]);

        $this->withToken($this->token)
            ->getJson('/api/scout/map/heatmap/hits?north=40&south=39&east=-74&west=-75')
            ->assertSuccessful()
            ->assertJsonStructure(['type', 'features']);

        $this->withToken($this->token)->getJson('/api/scout/map/live-path')
            ->assertSuccessful()
            ->assertJsonStructure(['segments', 'cursor']);
    }

    #[Group('controllers')]
    #[Group('clarity-scout')]
    public function test_clickhouse_backed_map_routes_are_rate_limited(): void
    {
        $throttled = [
            'api/scout/map/scan-paths',
            'api/scout/map/hits',
            'api/scout/map/heatmap/hits',
            'api/scout/map/heatmap/orders',
            'api/scout/map/kit-position',
            'api/scout/map/live-path',
        ];

        foreach ($throttled as $uri) {
            $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === $uri);

            $this->assertNotNull($route, "Route {$uri} is not registered.");
            $this->assertStringContainsString('120,1', implode(' ', $route->gatherMiddleware()), "{$uri} must carry the per-user rate limit.");
        }
    }
}
