<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\KitScanPathsQuery;
use App\Models\Kit;
use App\Models\Org;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class KitScanPathsQueryTest extends TestCase
{
    use RequiresClickhouse;

    private Org $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Org::factory()->create();
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    #[Group('kit-user-path')]
    public function test_omitting_bbox_preserves_all_buckets(): void
    {
        $inside = Kit::factory()->forOrg($this->org)->create();
        $outside = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($inside->id, '[(-74.5, 39.5), (-74.6, 39.6)]');
        $this->insertKitScanPath($outside->id, '[(-80.0, 45.0), (-81.0, 46.0)]');

        $rows = KitScanPathsQuery::query([
            'org_id' => $this->org->id,
            'kit_ids' => [$inside->id, $outside->id],
            'start' => now()->subHour()->toDateTimeString(),
            'end' => now()->addHour()->toDateTimeString(),
            'timezone' => 'UTC',
            'layer' => 'today',
        ]);

        $kitIds = array_map(static fn ($row) => (int) ($row['kit_id'] ?? $row->kit_id), $rows);
        sort($kitIds);
        $this->assertSame([$inside->id, $outside->id], $kitIds);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    #[Group('kit-user-path')]
    public function test_bbox_keeps_fully_inside_and_partly_inside_buckets_and_drops_fully_outside(): void
    {
        $fullyInside = Kit::factory()->forOrg($this->org)->create();
        $partlyInside = Kit::factory()->forOrg($this->org)->create();
        $fullyOutside = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($fullyInside->id, '[(-74.5, 39.5), (-74.6, 39.6)]');
        // arrayExists keeps a bucket if ANY point touches the bbox.
        $this->insertKitScanPath($partlyInside->id, '[(-74.5, 39.5), (-80.0, 45.0)]');
        $this->insertKitScanPath($fullyOutside->id, '[(-80.0, 45.0), (-81.0, 46.0)]');

        $rows = KitScanPathsQuery::query([
            'org_id' => $this->org->id,
            'kit_ids' => [$fullyInside->id, $partlyInside->id, $fullyOutside->id],
            'start' => now()->subHour()->toDateTimeString(),
            'end' => now()->addHour()->toDateTimeString(),
            'timezone' => 'UTC',
            'layer' => 'today',
            'north' => 40.0,
            'south' => 39.0,
            'east' => -74.0,
            'west' => -75.0,
        ]);

        $kitIds = array_map(static fn ($row) => (int) ($row['kit_id'] ?? $row->kit_id), $rows);
        sort($kitIds);
        $this->assertSame([$fullyInside->id, $partlyInside->id], $kitIds);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    #[Group('kit-user-path')]
    public function test_partial_bbox_is_ignored_like_no_bbox(): void
    {
        // KitUserPathController's non-Scout caller never sends a bbox; a
        // partial one (west missing) must also be treated as no bbox.
        $inside = Kit::factory()->forOrg($this->org)->create();
        $outside = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($inside->id, '[(-74.5, 39.5), (-74.6, 39.6)]');
        $this->insertKitScanPath($outside->id, '[(-80.0, 45.0), (-81.0, 46.0)]');

        $rows = KitScanPathsQuery::query([
            'org_id' => $this->org->id,
            'kit_ids' => [$inside->id, $outside->id],
            'start' => now()->subHour()->toDateTimeString(),
            'end' => now()->addHour()->toDateTimeString(),
            'timezone' => 'UTC',
            'layer' => 'today',
            'north' => 40.0,
            'south' => 39.0,
            'east' => -74.0,
        ]);

        $kitIds = array_map(static fn ($row) => (int) ($row['kit_id'] ?? $row->kit_id), $rows);
        sort($kitIds);
        $this->assertSame([$inside->id, $outside->id], $kitIds);
    }

    /**
     * handle() must PROPAGATE a ClickHouse failure. The controller's tier-2
     * fallback calls handle() directly (not query(), which swallows Throwable
     * to [] in production/testing), so an outage must be distinguishable from
     * "this kit has no scan buckets".
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    #[Group('kit-user-path')]
    public function test_handle_propagates_a_clickhouse_failure(): void
    {
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

        $this->expectException(ClickhouseException::class);

        KitScanPathsQuery::handle([
            'org_id' => $this->org->id,
            'kit_ids' => [1],
            'start' => now()->subHour()->toDateTimeString(),
            'end' => now()->addHour()->toDateTimeString(),
            'timezone' => 'UTC',
            'layer' => 'today',
        ]);
    }

    /** Wire-shape parity: pre-encoded path_json matches KitRangePathsQuery's [[lng,lat],...] shape, rounded to 5 decimals — Bird's Eye emits both shapes. */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    #[Group('kit-user-path')]
    public function test_encoded_paths_matches_the_decoded_shape_with_rounded_coordinates(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $this->insertKitScanPath($kit->id, '[(-97.712345678, 30.212345678), (-86.15, 39.75)]');

        $rows = KitScanPathsQuery::encodedPaths([
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
            'start' => now()->subHour()->toDateTimeString(),
            'end' => now()->addHour()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame($kit->id, $rows[0]['kit_id']);
        $this->assertSame('[[-97.71235,30.21235],[-86.15,39.75]]', $rows[0]['path_json']);
    }

    /** A bucket stored at 02:00 UTC must date to the PREVIOUS calendar day in America/Chicago (UTC-5 in August). */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    #[Group('kit-user-path')]
    public function test_encoded_paths_dates_the_trip_in_the_viewers_timezone(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $bucketUtc = Carbon::today('UTC')->setTime(2, 0);
        $localBucket = $bucketUtc->copy()->setTimezone('America/Chicago');

        $this->insertKitScanPath($kit->id, '[(-97.7, 30.3), (-97.71, 30.31)]', $bucketUtc);

        $rows = KitScanPathsQuery::encodedPaths([
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
            'start' => $localBucket->copy()->subHour()->toDateTimeString(),
            'end' => $localBucket->copy()->addHour()->toDateTimeString(),
            'timezone' => 'America/Chicago',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame($localBucket->toDateString(), $rows[0]['trip_date']);
        $this->assertNotSame($bucketUtc->toDateString(), $rows[0]['trip_date']);
    }

    /**
     * `US/Central` is missing from the canonical-only `timezone_identifiers_list()`
     * (419 zones) and would silently fall back to UTC if whitelisted against
     * that list — a 5-6 hour day-boundary shift. It must resolve identically
     * to its canonical form, `America/Chicago`.
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    #[Group('kit-user-path')]
    public function test_encoded_paths_treats_a_legacy_timezone_alias_same_as_its_canonical_form(): void
    {
        $kit = Kit::factory()->forOrg($this->org)->create();

        $bucketUtc = Carbon::today('UTC')->setTime(2, 0);
        $localBucket = $bucketUtc->copy()->setTimezone('America/Chicago');

        $this->insertKitScanPath($kit->id, '[(-97.7, 30.3), (-97.71, 30.31)]', $bucketUtc);

        $arguments = [
            'org_id' => $this->org->id,
            'kit_ids' => [$kit->id],
            'start' => $localBucket->copy()->subHour()->toDateTimeString(),
            'end' => $localBucket->copy()->addHour()->toDateTimeString(),
        ];

        $canonical = KitScanPathsQuery::encodedPaths($arguments + ['timezone' => 'America/Chicago']);
        $legacy = KitScanPathsQuery::encodedPaths($arguments + ['timezone' => 'US/Central']);

        $this->assertCount(1, $legacy);
        $this->assertSame($canonical[0]['trip_date'], $legacy[0]['trip_date']);
        $this->assertSame($localBucket->toDateString(), $legacy[0]['trip_date']);
    }

    /**
     * encodedPaths() must PROPAGATE a ClickHouse failure, same as handle() —
     * the controller's tier-2 fallback calls it directly, never query().
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    #[Group('kit-user-path')]
    public function test_encoded_paths_propagates_a_clickhouse_failure(): void
    {
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

        $this->expectException(ClickhouseException::class);

        KitScanPathsQuery::encodedPaths([
            'org_id' => $this->org->id,
            'kit_ids' => [1],
            'start' => now()->subHour()->toDateTimeString(),
            'end' => now()->addHour()->toDateTimeString(),
            'timezone' => 'UTC',
        ]);
    }

    private function insertKitScanPath(int $kitId, string $pathPoints, ?Carbon $timestamp = null): void
    {
        DB::connection('clarity_clickhouse')->table('clarity.kit_scan_paths')->insert([
            'kit_id' => $kitId,
            'org_id' => $this->org->id,
            'timestamp' => ($timestamp ?? now())->toDateTimeString(),
            'scan_count' => 1,
            'path_points' => $pathPoints,
            'kit_name' => 'Test Kit '.$kitId,
            '_version' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
