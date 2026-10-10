<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Http\Controllers\KitUserPathController;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use LaravelClickhouseEloquent\ClickhouseHttpClient;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * Kit User Path map's `paths()` endpoint — DEV-2451 reverted this back to its
 * pre-`sc_positions` behavior: `clarity.kit_scan_paths` (scan-triggered) is
 * the sole source, for every kit. Response DTO shape is unchanged.
 */
class KitUserPathControllerTest extends TestCase
{
    use RequiresClickhouse;

    private Org $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // clarity.kit_scan_paths carries a 90-day DELETE TTL, so freeze
        // relative to real now() — a hardcoded anchor eventually ages past
        // it and ClickHouse silently drops the fixture on insert.
        Carbon::setTestNow($this->frozenNow());

        $this->user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'UTC']],
            permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value,
        );
        $this->org = $this->user->org;

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Group('clickhouse')]
    public function test_user_without_permission_is_forbidden(): void
    {
        $user = $this->createUser(attributes: ['dti' => ['timeZone' => 'UTC']], permissions: []);
        $kit = Kit::factory()->forOrg($user->org)->create(['kit_mapping' => 'kit-no-perm']);

        $response = $this->actingAs($user)->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertForbidden();
    }

    #[Group('clickhouse')]
    public function test_a_scan_only_kit_renders_from_kit_scan_paths(): void
    {
        $kit = $this->makeKit($this->org, 'kit-scan-only');

        $this->insertKitScanPath($kit, [
            'timestamp' => now()->subDay()->setTime(9, 0, 0)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $rows = $response->json();

        $this->assertNotEmpty($rows);
        $this->assertSame((int) $kit->id, $rows[0]['kit_id']);
        $this->assertCount(2, $rows[0]['path']);
    }

    /** DEV-2451: removal, not gating — a kit WITH sc_positions coverage must still render from kit_scan_paths, never sc_positions. */
    #[Group('clickhouse')]
    public function test_a_kit_with_sc_positions_coverage_still_renders_via_kit_scan_paths(): void
    {
        $kit = $this->makeKit($this->org, 'kit-has-gps-too');

        $base = now()->subDay()->setTime(9, 0, 0);
        $this->insertScPosition('kit-has-gps-too', [-97.7000, 30.3000], $base->copy());
        $this->insertScPosition('kit-has-gps-too', [-97.6997, 30.3002], $base->copy()->addSeconds(3));

        $this->insertKitScanPath($kit, [
            'timestamp' => $base->copy()->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $rows = $response->json();

        $this->assertCount(1, $rows);
        $this->assertSame((int) $kit->id, $rows[0]['kit_id']);
        // The scan-path fixture's coordinates, not the sc_positions ones.
        $this->assertEqualsWithDelta(-86.15, $rows[0]['path'][0][0], 0.0001);
        $this->assertEqualsWithDelta(39.75, $rows[0]['path'][0][1], 0.0001);
    }

    /**
     * DEV-2451 accepted, deliberate consequence: a GPS-only kit (sc_positions
     * coverage, zero kit_scan_paths rows) now renders nothing. ~617
     * production kits are in this bucket.
     */
    #[Group('clickhouse')]
    public function test_a_gps_only_kit_renders_nothing_now_that_sc_positions_is_not_read(): void
    {
        $kit = $this->makeKit($this->org, 'kit-gps-only');

        $base = now()->subDay()->setTime(9, 0, 0);
        $this->insertScPosition('kit-gps-only', [-97.7000, 30.3000], $base->copy());
        $this->insertScPosition('kit-gps-only', [-97.6997, 30.3002], $base->copy()->addSeconds(3));

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    /**
     * Window arithmetic is the sole surviving dating path now that tier-1 is
     * gone: pin that a bucket old enough to fall outside 7_days (and
     * 15_days) still surfaces once the window widens to 30_days.
     */
    #[Group('clickhouse')]
    public function test_a_broad_window_reaches_kit_scan_path_buckets_the_narrow_window_misses(): void
    {
        $kit = $this->makeKit($this->org, 'kit-broad-window');

        $recent = now()->subDays(3)->setTime(9, 0, 0);
        $mid = now()->subDays(12)->setTime(9, 0, 0);
        $far = now()->subDays(20)->setTime(9, 0, 0);

        $this->insertKitScanPath($kit, ['timestamp' => $recent->toDateTimeString()]);
        $this->insertKitScanPath($kit, ['timestamp' => $mid->toDateTimeString()]);
        $this->insertKitScanPath($kit, ['timestamp' => $far->toDateTimeString()]);

        $tripDates = function (string $toggle) use ($kit): array {
            $response = $this->postJson(route('kit-user-path.paths'), [
                'kits' => [$kit->id],
                'date_toggles' => $toggle,
                'address' => null,
            ]);

            $response->assertOk();

            return array_column($response->json(), 'trip_date');
        };

        $narrow = $tripDates('7_days');
        $medium = $tripDates('15_days');
        $broad = $tripDates('30_days');

        $this->assertContains($recent->toDateString(), $narrow);
        $this->assertNotContains($mid->toDateString(), $narrow);
        $this->assertNotContains($far->toDateString(), $narrow);

        $this->assertContains($mid->toDateString(), $medium);
        $this->assertNotContains($far->toDateString(), $medium);

        $this->assertContains($far->toDateString(), $broad, '30_days must reach a bucket 20 days old, which 7_days and 15_days cannot.');
    }

    /** DEV-2451: sc_positions must never be queried by this endpoint. */
    #[Group('clickhouse')]
    public function test_sc_positions_is_never_queried(): void
    {
        $kit = $this->makeKit($this->org, 'kit-spy-sc-positions');
        $base = now()->subDay()->setTime(9, 0, 0);
        $this->insertScPosition('kit-spy-sc-positions', [-97.7000, 30.3000], $base->copy());
        $this->insertKitScanPath($kit, [
            'timestamp' => $base->copy()->toDateTimeString(),
        ]);

        $connection = DB::connection('clarity_clickhouse');
        $realClient = $connection->getClient();
        $spyClient = new class(config('database.connections.clarity_clickhouse')) extends ClickhouseHttpClient
        {
            public int $scPositionSelects = 0;

            public function select(string $sql, array $bindings = []): array
            {
                if (str_contains($sql, 'sc_positions')) {
                    $this->scPositionSelects++;
                }

                return parent::select($sql, $bindings);
            }
        };

        $clientProperty = new ReflectionProperty($connection, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($connection, $spyClient);

        try {
            $response = $this->postJson(route('kit-user-path.paths'), [
                'kits' => [$kit->id],
                'date_toggles' => '7_days',
                'address' => null,
            ]);
        } finally {
            $clientProperty->setValue($connection, $realClient);
        }

        $response->assertOk();
        $this->assertSame(0, $spyClient->scPositionSelects, 'sc_positions must never be queried by this endpoint.');
    }

    #[Group('clickhouse')]
    public function test_zero_point_window_returns_empty_response(): void
    {
        // Kit exists, but has no kit_scan_paths rows anywhere in the window.
        $kit = $this->makeKit($this->org, 'kit-no-scan-paths');

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    #[Group('clickhouse')]
    public function test_a_kit_with_no_kit_mapping_still_renders(): void
    {
        // clarity.kit_scan_paths is keyed by kit_id alone, so a mapping-less
        // kit is a normal render, not a special case.
        $mappingLessKit = Kit::factory()->forOrg($this->org)->create([
            'kit_mapping' => '',
            'kit_type' => 2,
            'name' => 'Kit Mapping-less',
        ]);

        $this->insertKitScanPath($mappingLessKit, [
            'timestamp' => now()->subDay()->setTime(9, 0, 0)->toDateTimeString(),
            'path_points' => '[(-40.0, 20.0), (-41.0, 21.0)]',
        ]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$mappingLessKit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $rows = $response->json();

        $this->assertNotEmpty($rows);
        $this->assertSame((int) $mappingLessKit->id, $rows[0]['kit_id']);
    }

    #[Group('clickhouse')]
    public function test_all_kits_resolves_to_the_orgs_kit_set_and_excludes_other_orgs(): void
    {
        $mine = $this->makeKit($this->org, 'kit-mine');
        $otherOrg = Org::factory()->create();
        $theirs = $this->makeKit($otherOrg, 'kit-theirs');

        $base = now()->subDay()->setTime(9, 0, 0);
        $this->insertKitScanPath($mine, ['timestamp' => $base->copy()->toDateTimeString()]);
        $this->insertKitScanPath($theirs, ['timestamp' => $base->copy()->toDateTimeString()]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => ['All Kits'],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $rows = $response->json();

        $this->assertNotEmpty($rows);
        $kitIds = array_unique(array_column($rows, 'kit_id'));
        $this->assertSame([(int) $mine->id], $kitIds);
        $this->assertNotContains((int) $theirs->id, $kitIds);
    }

    #[Group('clickhouse')]
    public function test_response_row_shape_matches_the_original_dto(): void
    {
        $kit = $this->makeKit($this->org, 'kit-shape');

        $base = now()->subDay()->setTime(9, 0, 0);
        $this->insertKitScanPath($kit, ['timestamp' => $base->copy()->toDateTimeString()]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $rows = $response->json();

        $this->assertNotEmpty($rows);
        $this->assertEqualsCanonicalizing(
            ['kit_id', 'name', 'color', 'path', 'layerId', 'trip_date'],
            array_keys($rows[0])
        );
        $this->assertSame('yesterday', $rows[0]['layerId']);
        $this->assertIsArray($rows[0]['color']);
        $this->assertIsString($rows[0]['trip_date']);
    }

    /** Paths are spliced into the response as pre-encoded JSON, so the body has to be verified as real JSON. */
    #[Group('clickhouse')]
    public function test_response_is_valid_json_with_numeric_coordinate_pairs(): void
    {
        $kit = $this->makeKit($this->org, 'kit-json-shape');

        $base = now()->subDay()->setTime(9, 0, 0);
        $this->insertKitScanPath($kit, ['timestamp' => $base->copy()->toDateTimeString()]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');

        $decoded = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($decoded);

        foreach ($decoded[0]['path'] as $point) {
            $this->assertCount(2, $point);
            $this->assertIsFloat($point[0] + 0);
            $this->assertIsFloat($point[1] + 0);
        }
    }

    /** The response JSON is assembled by hand, so a kit name with JSON-significant characters must not corrupt it. */
    #[Group('clickhouse')]
    public function test_a_kit_name_with_quotes_does_not_corrupt_the_response(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create([
            'kit_mapping' => 'kit-quoted-name',
            'name' => 'Brad "The Hammer" Webb \\ Unit #1 — ünïcode',
        ]);

        $base = now()->subDay()->setTime(9, 0, 0);
        $this->insertKitScanPath($kit, ['timestamp' => $base->copy()->toDateTimeString()]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();

        $decoded = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($decoded);
        $this->assertSame($kit->name, $decoded[0]['name']);
    }

    #[Group('clickhouse')]
    public function test_over_budget_returns_422(): void
    {
        $this->bindTinyBudgetController();

        $kit = $this->makeKit($this->org, 'kit-over-budget');

        // 4 points, over the subclass's tiny budget of 3.
        $this->insertKitScanPath($kit, [
            'timestamp' => $this->frozenNow()->copy()->subDay()->setTime(9, 0, 0)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76), (-86.17, 39.77), (-86.18, 39.78)]',
        ]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            'Selection too large to render — narrow the kits or shorten the date range.',
            $response->json('error')
        );
    }

    #[Group('clickhouse')]
    public function test_refuse_guard_does_not_trigger_for_a_normal_selection_under_the_budget(): void
    {
        $this->bindTinyBudgetController();

        $kit = $this->makeKit($this->org, 'kit-under-budget');

        // 2 points — under the subclass's tiny budget of 3.
        $this->insertKitScanPath($kit, [
            'timestamp' => $this->frozenNow()->copy()->subDay()->setTime(9, 0, 0)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json());
    }

    /** A budget-count throw must return 503, never a silent empty 200, and must log the failure. */
    #[Group('clickhouse')]
    public function test_a_budget_count_throw_returns_503(): void
    {
        $kit = $this->makeKit($this->org, 'kit-budget-count-throw');
        $this->insertKitScanPath($kit, [
            'timestamp' => $this->frozenNow()->copy()->subDay()->setTime(9, 0, 0)->toDateTimeString(),
        ]);

        $connection = DB::connection('clarity_clickhouse');
        $realClient = $connection->getClient();
        $failingClient = new class(config('database.connections.clarity_clickhouse')) extends ClickhouseHttpClient
        {
            public function select(string $sql, array $bindings = []): array
            {
                if (str_contains($sql, 'kit_scan_paths')) {
                    throw new \RuntimeException('Simulated ClickHouse failure');
                }

                return parent::select($sql, $bindings);
            }
        };

        $clientProperty = new ReflectionProperty($connection, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($connection, $failingClient);

        Log::spy();

        try {
            $response = $this->postJson(route('kit-user-path.paths'), [
                'kits' => [$kit->id],
                'date_toggles' => 'yesterday',
                'address' => null,
            ]);
        } finally {
            $clientProperty->setValue($connection, $realClient);
        }

        $response->assertStatus(503);
        $this->assertSame('Scan-path route unavailable', $response->json('error'));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'kit user path scan-path budget count failed'
                && $context['org_id'] === $this->org->id);
    }

    /**
     * Same 503 contract as above, but the fake client only throws on the
     * READ (matches `kit_scan_paths` + `path_json` together, present only in
     * encodedPaths()'s SQL) — the budget count succeeds, so this is the only
     * test that reaches the read's own catch and its own log line.
     */
    #[Group('clickhouse')]
    public function test_a_read_only_throw_returns_503(): void
    {
        $kit = $this->makeKit($this->org, 'kit-read-only-throw');
        $this->insertKitScanPath($kit, [
            'timestamp' => $this->frozenNow()->copy()->subDay()->setTime(9, 0, 0)->toDateTimeString(),
        ]);

        $connection = DB::connection('clarity_clickhouse');
        $realClient = $connection->getClient();
        $failingClient = new class(config('database.connections.clarity_clickhouse')) extends ClickhouseHttpClient
        {
            public function select(string $sql, array $bindings = []): array
            {
                if (str_contains($sql, 'kit_scan_paths') && str_contains($sql, 'path_json')) {
                    throw new \RuntimeException('Simulated READ ClickHouse failure');
                }

                return parent::select($sql, $bindings);
            }
        };

        $clientProperty = new ReflectionProperty($connection, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($connection, $failingClient);

        Log::spy();

        try {
            $response = $this->postJson(route('kit-user-path.paths'), [
                'kits' => [$kit->id],
                'date_toggles' => 'yesterday',
                'address' => null,
            ]);
        } finally {
            $clientProperty->setValue($connection, $realClient);
        }

        $response->assertStatus(503);
        $this->assertSame('Scan-path route unavailable', $response->json('error'));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'kit user path scan-path read failed'
                && $context['org_id'] === $this->org->id);
    }

    /** Wire-shape parity: a row's path is [[lng,lat],...] with 5-decimal coords. */
    #[Group('clickhouse')]
    public function test_path_shape_has_rounded_coordinates(): void
    {
        $kit = $this->makeKit($this->org, 'kit-shape-coords');

        $this->insertKitScanPath($kit, [
            'timestamp' => $this->frozenNow()->copy()->subDay()->setTime(9, 0, 0)->toDateTimeString(),
            'path_points' => '[(-97.712345678, 30.212345678), (-86.15, 39.75)]',
        ]);

        $response = $this->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $rows = $response->json();

        $this->assertCount(1, $rows);
        $this->assertSame([[-97.71235, 30.21235], [-86.15, 39.75]], $rows[0]['path']);
    }

    /**
     * Every other test in this file runs with a UTC user, so the trip_date
     * timezone dimension is unpinned. A bucket stored at 02:00 UTC must date
     * to the PREVIOUS calendar day for a viewer on America/Chicago (UTC-5 in
     * August).
     */
    #[Group('clickhouse')]
    public function test_a_non_utc_user_reports_a_late_utc_bucket_on_the_previous_local_day(): void
    {
        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'America/Chicago']],
            permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value,
        );
        $kit = Kit::factory()->forOrg($user->org)->create([
            'kit_mapping' => 'kit-chicago-scan-only',
            'name' => 'Kit Chicago',
        ]);

        // 02:00 UTC "today" is 21:00 the PREVIOUS day in America/Chicago.
        $this->insertKitScanPath($kit, [
            'timestamp' => $this->frozenNow()->copy()->setTime(2, 0, 0)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->actingAs($user)->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $rows = $response->json();

        $this->assertNotEmpty($rows);
        $this->assertSame($this->frozenNow()->copy()->subDay()->toDateString(), $rows[0]['trip_date']);
    }

    /**
     * `US/Central` is missing from the canonical-only `timezone_identifiers_list()`
     * and would silently fall back to UTC if the whitelist weren't built on
     * `ALL_WITH_BC`. Pin it: a viewer on the legacy alias must get the same
     * day as `America/Chicago`.
     */
    #[Group('clickhouse')]
    public function test_a_legacy_timezone_alias_reports_the_same_day_as_its_canonical_form(): void
    {
        $user = $this->createUser(
            attributes: ['dti' => ['timeZone' => 'US/Central']],
            permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value,
        );
        $kit = Kit::factory()->forOrg($user->org)->create([
            'kit_mapping' => 'kit-us-central-scan-only',
            'name' => 'Kit US Central',
        ]);

        $this->insertKitScanPath($kit, [
            'timestamp' => $this->frozenNow()->copy()->setTime(2, 0, 0)->toDateTimeString(),
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
        ]);

        $response = $this->actingAs($user)->postJson(route('kit-user-path.paths'), [
            'kits' => [$kit->id],
            'date_toggles' => 'yesterday',
            'address' => null,
        ]);

        $response->assertOk();
        $rows = $response->json();

        $this->assertNotEmpty($rows);
        $this->assertSame($this->frozenNow()->copy()->subDay()->toDateString(), $rows[0]['trip_date']);
    }

    /**
     * Midday today (UTC) — the anchor these tests freeze the clock to.
     * clarity.kit_scan_paths fixtures must derive from this, never a
     * hardcoded date, to survive kit_scan_paths' 90-day DELETE TTL.
     */
    private function frozenNow(): Carbon
    {
        return Carbon::today('UTC')->setTime(12, 0);
    }

    /** Binds a subclass with a MAX_RENDER_POINTS of 3 so the 422 branch is testable without seeding a huge fixture. */
    private function bindTinyBudgetController(): void
    {
        $this->app->instance(KitUserPathController::class, new class extends KitUserPathController
        {
            protected const MAX_RENDER_POINTS = 3;
        });
    }

    private function makeKit(Org $org, string $mapping): Kit
    {
        return Kit::factory()->forOrg($org)->create([
            'kit_mapping' => $mapping,
            'name' => 'Kit '.$mapping,
        ]);
    }

    /**
     * @param  array{0: float, 1: float}  $point  [lng, lat]
     */
    private function insertScPosition(string $mapping, array $point, Carbon $at): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.sc_positions')->insert([[
            'kit_mapping' => $mapping,
            'geo' => "({$point[0]}, {$point[1]})",
            'timestamp_utc' => $at->toDateTimeString(),
            'speed' => 30.0,
            'heading' => 90.0,
        ]]);
    }

    /**
     * Seed a kit_scan_paths bucket (>= 2 points so it clears the query's
     * length filter).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertKitScanPath(Kit $kit, array $overrides = []): void
    {
        $defaults = [
            'kit_id' => $kit->id,
            'org_id' => $kit->org_id,
            'timestamp' => now()->toDateTimeString(),
            'scan_count' => 1,
            'path_points' => '[(-86.15, 39.75), (-86.16, 39.76)]',
            'kit_name' => $kit->name ?? 'Test Kit',
            '_version' => now()->format('Y-m-d H:i:s.u'),
        ];

        DB::connection('clarity_clickhouse')->table('clarity.kit_scan_paths')
            ->insert(array_merge($defaults, $overrides));
    }
}
