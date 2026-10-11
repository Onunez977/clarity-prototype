<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\ScoutCheckInMatchQuery;
use App\Clickhouse\Queries\ScoutCheckInRollupQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * AC9: {@see ScoutCheckInMatchQuery} (GPS-sourced, persisted check-ins) and
 * {@see ScoutCheckInRollupQuery} (scan-sourced, on-read dashboard tile) lift
 * the SAME open-address CTE architecture (clarity.scout_open_addresses,
 * bbox prefilter, geoDistance cut). Distinct Direct vs Staging
 * addresses must produce identical per-is_staged match counts from both
 * queries even though their position source differs (clarity.sc_positions
 * vs clarity.scans_hot). When the SAME address sits on both a Direct and a
 * Staging open order, the matcher Direct-wins (one persisted row); the
 * rollup counts both buckets — that grain difference is asserted explicitly
 * rather than papered over.
 *
 * This test targets the CH database the connection is actually configured
 * for (config('database.connections.*_clickhouse.database')) rather than the
 * hardcoded `clarity`/`zod` literal used by sibling fixtures: those literals
 * only line up with the configured database in CI (where
 * CLICKHOUSE_DATABASE_CLARITY=clarity); locally the testing database is
 * `clarity_test`/`zod_test`; hardcoding here would insert fixtures the
 * queries under test (correctly using self::clarityDb()/zodDb()) would never
 * read.
 */
class ScoutCheckInMatchQueryParityTest extends TestCase
{
    use RequiresClickhouse;

    private const ORG_ID = 401_001;

    private const STAGING_ORG_ID = 401_002;

    private const KIT_ID = 401_003;

    private const KIT_MAPPING = 'parity-kit-2342';

    protected function setUp(): void
    {
        parent::setUp();

        // RequiresClickhouse truncates the hardcoded `clarity`/`zod` literal
        // tables; this test writes into the differently-named configured
        // database (see class docblock), so clear it explicitly too.
        foreach (['sc_positions', 'scans_hot', 'scout_open_addresses'] as $table) {
            DB::connection('clarity_clickhouse')->statement("TRUNCATE TABLE IF EXISTS {$this->clarityTable($table)}");
        }
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_matcher_and_rollup_agree_on_direct_and_staging_match_counts(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        $directHotlistId = 402_001;
        $stagingHotlistId = 402_002;
        $directAddressId = 403_001;
        $stagingAddressId = 403_002;

        $this->insertBookAddress(self::ORG_ID, false, $directHotlistId, $directAddressId, -97.70, 30.30);
        $this->insertBookAddress(self::STAGING_ORG_ID, true, $stagingHotlistId, $stagingAddressId, -97.71, 30.31);

        $this->insertScPosition(-97.70, 30.30, $fixAt);
        $this->insertScPosition(-97.71, 30.31, $fixAt);

        $this->insertScanHot(1, self::ORG_ID, -97.70, 30.30, $fixAt);
        $this->insertScanHot(2, self::ORG_ID, -97.71, 30.31, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [self::STAGING_ORG_ID],
            'start' => $start,
            'end' => $end,
        ]);

        $rollup = ScoutCheckInRollupQuery::handle([
            'org_id' => self::ORG_ID,
            'kit_id' => self::KIT_ID,
            'staging_org_ids' => [self::STAGING_ORG_ID],
            'start' => $start,
            'end' => $end,
        ]);

        $matcherDirectCount = count(array_filter($matches, fn (array $match): bool => $match['is_staged'] === false));
        $matcherStagingCount = count(array_filter($matches, fn (array $match): bool => $match['is_staged'] === true));

        $this->assertSame($rollup['direct_bucket'], $matcherDirectCount);
        $this->assertSame($rollup['staging_bucket'], $matcherStagingCount);
        $this->assertSame(1, $matcherDirectCount);
        $this->assertSame(1, $matcherStagingCount);

        $matchesByAddress = collect($matches)->keyBy('address_id');
        $this->assertSame($directHotlistId, $matchesByAddress[$directAddressId]['hotlist_id']);
        $this->assertFalse($matchesByAddress[$directAddressId]['is_staged']);
        $this->assertSame($stagingHotlistId, $matchesByAddress[$stagingAddressId]['hotlist_id']);
        $this->assertTrue($matchesByAddress[$stagingAddressId]['is_staged']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_shared_address_is_direct_wins_on_matcher_and_counted_in_both_rollup_buckets(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        $directHotlistId = 402_011;
        $stagingHotlistId = 402_012;
        $sharedAddressId = 403_011;

        $this->insertBookAddress(self::ORG_ID, false, $directHotlistId, $sharedAddressId, -97.70, 30.30);
        $this->insertBookAddress(self::STAGING_ORG_ID, true, $stagingHotlistId, $sharedAddressId, -97.70, 30.30);

        $this->insertScPosition(-97.70, 30.30, $fixAt);
        $this->insertScanHot(11, self::ORG_ID, -97.70, 30.30, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [self::STAGING_ORG_ID],
            'start' => $start,
            'end' => $end,
        ]);

        $rollup = ScoutCheckInRollupQuery::handle([
            'org_id' => self::ORG_ID,
            'kit_id' => self::KIT_ID,
            'staging_org_ids' => [self::STAGING_ORG_ID],
            'start' => $start,
            'end' => $end,
        ]);

        $this->assertCount(1, $matches);
        $this->assertSame($sharedAddressId, $matches[0]['address_id']);
        $this->assertFalse($matches[0]['is_staged']);
        $this->assertSame($directHotlistId, $matches[0]['hotlist_id']);

        $this->assertSame(1, $rollup['direct_bucket']);
        $this->assertSame(1, $rollup['staging_bucket']);
    }

    /**
     * AC6 under the scenario THIS change creates. The pre-existing shared-address
     * test covers one address_id on both sides; geo-dedup makes the distinct-ids
     * case newly reachable — co-located Directs must collapse to one, co-located
     * Staging must collapse to one, and the location must still count once in
     * EACH bucket. This is what pins `is_staged` inside the rollup's partition
     * key: drop it and staging_bucket silently goes to 0.
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_colocated_distinct_addresses_still_count_once_in_each_rollup_bucket(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        // Two Direct + two Staging open orders, four DISTINCT address_ids, all at
        // the identical coordinate.
        $this->insertBookAddress(self::ORG_ID, false, 402_301, 403_301, -97.70, 30.30);
        $this->insertBookAddress(self::ORG_ID, false, 402_302, 403_302, -97.70, 30.30);
        $this->insertBookAddress(self::STAGING_ORG_ID, true, 402_303, 403_303, -97.70, 30.30);
        $this->insertBookAddress(self::STAGING_ORG_ID, true, 402_304, 403_304, -97.70, 30.30);

        $this->insertScPosition(-97.70, 30.30, $fixAt);
        $this->insertScanHot(31, self::ORG_ID, -97.70, 30.30, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [self::STAGING_ORG_ID],
            'start' => $start,
            'end' => $end,
        ]);

        $rollup = ScoutCheckInRollupQuery::handle([
            'org_id' => self::ORG_ID,
            'kit_id' => self::KIT_ID,
            'staging_org_ids' => [self::STAGING_ORG_ID],
            'start' => $start,
            'end' => $end,
        ]);

        // Matcher elects per bucket: one Direct + one Staging, each the lowest
        // address_id on its own side. Co-located same-side duplicates collapse.
        $this->assertCount(2, $matches);

        $byStaged = collect($matches)->keyBy(fn (array $m): string => $m['is_staged'] ? 'staged' : 'direct');

        $this->assertSame(403_301, $byStaged['direct']['address_id']);
        $this->assertSame(403_303, $byStaged['staged']['address_id']);

        // Rollup dedups per bucket independently, so the location still shows on
        // both sides of the tile.
        $this->assertSame(1, $rollup['direct_bucket']);
        $this->assertSame(1, $rollup['staging_bucket']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_duplicate_book_rows_for_one_address_produce_one_match(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        $hotlistId = 402_021;
        $otherHotlistId = 402_022;
        $addressId = 403_021;

        // Both rows sit inside the bbox / geoDistance cut so GROUP BY address_id
        // is the only thing that collapses them. Different coords would let the
        // bbox drop one row and the test would pass without any dedup.
        $this->insertBookAddress(self::ORG_ID, false, $hotlistId, $addressId, -97.70, 30.30);
        $this->insertBookAddress(self::ORG_ID, false, $otherHotlistId, $addressId, -97.70, 30.30);

        $this->insertScPosition(-97.70, 30.30, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [],
            'start' => $start,
            'end' => $end,
        ]);

        $this->assertCount(1, $matches);
        $this->assertSame($addressId, $matches[0]['address_id']);
        $this->assertSame($hotlistId, $matches[0]['hotlist_id']);
        $this->assertEqualsWithDelta(-97.70, (float) $matches[0]['lng'], 0.0001);
        $this->assertEqualsWithDelta(30.30, (float) $matches[0]['lat'], 0.0001);
    }

    /**
     * The dedup is EXACT Point equality — deliberately not proximity clustering.
     * Two addresses a metre apart are distinct locations and must each still
     * record a check-in. This pins the non-collapse side of that decision, so a
     * later "just round the coordinates" change cannot pass silently.
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_near_but_not_identical_coordinates_do_not_collapse(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        // ~1 m apart: well inside the 160.934 m match radius of the same fix,
        // but NOT byte-identical, so the geo partition must keep them separate.
        $this->insertBookAddress(self::ORG_ID, false, 402_201, 403_201, -97.70, 30.30);
        $this->insertBookAddress(self::ORG_ID, false, 402_202, 403_202, -97.70001, 30.30);

        $this->insertScPosition(-97.70, 30.30, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [],
            'start' => $start,
            'end' => $end,
        ]);

        $this->assertCount(2, $matches);
        $this->assertEqualsCanonicalizing(
            [403_201, 403_202],
            array_column($matches, 'address_id'),
        );
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_distinct_address_ids_at_identical_coordinates_produce_one_match_and_the_lowest_address_id_wins(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        $hotlistIdForLowAddress = 402_101;
        $hotlistIdForHighAddress = 402_102;
        $lowAddressId = 403_101;
        $highAddressId = 403_102;

        $this->insertBookAddress(self::ORG_ID, false, $hotlistIdForLowAddress, $lowAddressId, -97.70, 30.30);
        $this->insertBookAddress(self::ORG_ID, false, $hotlistIdForHighAddress, $highAddressId, -97.70, 30.30);

        $this->insertScPosition(-97.70, 30.30, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [],
            'start' => $start,
            'end' => $end,
        ]);

        $this->assertCount(1, $matches);
        $this->assertSame($lowAddressId, $matches[0]['address_id']);
        $this->assertSame($hotlistIdForLowAddress, $matches[0]['hotlist_id']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_colocated_direct_and_staging_each_record_their_own_check_in(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        $directHotlistId = 402_201;
        $stagingHotlistId = 402_202;
        // Staging deliberately gets the LOWER address_id so a naive
        // address_id-only tie-break would wrongly elect it.
        $stagingAddressId = 403_201;
        $directAddressId = 403_202;

        $this->insertBookAddress(self::STAGING_ORG_ID, true, $stagingHotlistId, $stagingAddressId, -97.70, 30.30);
        $this->insertBookAddress(self::ORG_ID, false, $directHotlistId, $directAddressId, -97.70, 30.30);

        $this->insertScPosition(-97.70, 30.30, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [self::STAGING_ORG_ID],
            'start' => $start,
            'end' => $end,
        ]);

        // DEV-2414: the bucket is in the partition key, so BOTH sides are kept.
        // Without that, the Staging order at this geo would never receive a row
        // and DEV-1756's derived per-address count would read 0 forever.
        $this->assertCount(2, $matches);

        $byStaged = collect($matches)->keyBy(fn (array $m): string => $m['is_staged'] ? 'staged' : 'direct');

        $this->assertSame($directAddressId, $byStaged['direct']['address_id']);
        $this->assertSame($directHotlistId, $byStaged['direct']['hotlist_id']);
        $this->assertSame($stagingAddressId, $byStaged['staged']['address_id']);
        $this->assertSame($stagingHotlistId, $byStaged['staged']['hotlist_id']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_colocated_directs_are_decided_by_address_id_not_hotlist_id(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        // hotlist_id ordering is deliberately the REVERSE of address_id
        // ordering, so a winner picked by hotlist_id would be wrong here.
        $lowAddressId = 403_301;
        $highAddressId = 403_302;
        $hotlistIdForLowAddress = 402_312;
        $hotlistIdForHighAddress = 402_311;

        $this->insertBookAddress(self::ORG_ID, false, $hotlistIdForLowAddress, $lowAddressId, -97.70, 30.30);
        $this->insertBookAddress(self::ORG_ID, false, $hotlistIdForHighAddress, $highAddressId, -97.70, 30.30);

        $this->insertScPosition(-97.70, 30.30, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [],
            'start' => $start,
            'end' => $end,
        ]);

        $this->assertCount(1, $matches);
        $this->assertSame($lowAddressId, $matches[0]['address_id']);
        $this->assertSame($hotlistIdForLowAddress, $matches[0]['hotlist_id']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_rollup_collapses_colocated_direct_addresses_into_one_bucket_count(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        $this->insertBookAddress(self::ORG_ID, false, 402_401, 403_401, -97.70, 30.30);
        $this->insertBookAddress(self::ORG_ID, false, 402_402, 403_402, -97.70, 30.30);

        $this->insertScanHot(41, self::ORG_ID, -97.70, 30.30, $fixAt);

        $rollup = ScoutCheckInRollupQuery::handle([
            'org_id' => self::ORG_ID,
            'kit_id' => self::KIT_ID,
            'staging_org_ids' => [],
            'start' => $start,
            'end' => $end,
        ]);

        $this->assertSame(1, $rollup['direct_bucket']);
        $this->assertSame(0, $rollup['staging_bucket']);
    }

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_another_orgs_book_row_inside_the_bbox_does_not_match(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        $this->insertBookAddress(999_001, false, 402_031, 403_031, -97.70, 30.30);
        $this->insertScPosition(-97.70, 30.30, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [],
            'start' => $start,
            'end' => $end,
        ]);

        $this->assertSame([], $matches);
    }

    /**
     * DEV-2426: `handle()` must return the elected BOOK ADDRESS coordinate
     * (`addr_lng`/`addr_lat`) alongside the earliest GPS FIX coordinate
     * (`lng`/`lat`), and the two pairs must stay distinguishable. The fix and
     * the book address are seeded ~40 m apart (0.0003/0.0002 degrees, the
     * same separation as the storage-layer twin
     * `ScoutRecordCheckInsCommandTest::persisted_rows_pin_addr_lat_lng_to_the_book_address_and_lat_lng_to_the_fix`) —
     * well inside the 160.934 m match radius, but the delta below is
     * tightened to 1e-6 (both pairs round-trip through ClickHouse/PHP
     * essentially exactly at this magnitude) so it sits five orders of
     * magnitude below that separation. A regression that swapped the two
     * pairs would be off by the full ~40 m separation against a 1e-6
     * delta, so it fails loudly instead of passing inside a delta wider
     * than the separation itself.
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_handle_returns_both_the_address_geo_and_the_fix_geo_and_they_differ(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);
        $start = now()->subMinutes(15)->toDateTimeString();
        $end = now()->toDateTimeString();

        $hotlistId = 402_501;
        $addressId = 403_501;
        $bookLng = -97.7000;
        $bookLat = 30.3000;
        // ~40 m from the book address -- within the 160.934 m radius, and
        // far enough that a swap of the two pairs cannot pass the 1e-6
        // delta below.
        $fixLng = -97.7003;
        $fixLat = 30.3002;

        $this->insertBookAddress(self::ORG_ID, false, $hotlistId, $addressId, $bookLng, $bookLat);
        $this->insertScPosition($fixLng, $fixLat, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [],
            'start' => $start,
            'end' => $end,
        ]);

        $this->assertCount(1, $matches);

        $match = $matches[0];

        $this->assertArrayHasKey('addr_lng', $match);
        $this->assertArrayHasKey('addr_lat', $match);
        $this->assertEqualsWithDelta($bookLng, $match['addr_lng'], 1e-6);
        $this->assertEqualsWithDelta($bookLat, $match['addr_lat'], 1e-6);
        $this->assertEqualsWithDelta($fixLng, $match['lng'], 1e-6);
        $this->assertEqualsWithDelta($fixLat, $match['lat'], 1e-6);
        $this->assertNotEqualsWithDelta($match['addr_lng'], $match['lng'], 1e-6);
        $this->assertNotEqualsWithDelta($match['addr_lat'], $match['lat'], 1e-6);
    }

    private function clarityTable(string $table): string
    {
        return config('database.connections.clarity_clickhouse.database').'.'.$table;
    }

    private function insertBookAddress(
        int $orgId,
        bool $isStaged,
        int $hotlistId,
        int $addressId,
        float $lng,
        float $lat,
        ?Carbon $refreshedAt = null,
    ): void {
        DB::connection('clarity_clickhouse')->table($this->clarityTable('scout_open_addresses'))->insert([
            'org_id' => $orgId,
            'is_staged' => $isStaged,
            'hotlist_id' => $hotlistId,
            'address_id' => $addressId,
            'geo' => "({$lng}, {$lat})",
            'refreshed_at' => ($refreshedAt ?? now())->toDateTimeString(),
        ]);
    }

    private function insertScPosition(float $lng, float $lat, Carbon $at): void
    {
        DB::connection('clarity_clickhouse')->table($this->clarityTable('sc_positions'))->insert([
            'kit_mapping' => self::KIT_MAPPING,
            'geo' => "({$lng}, {$lat})",
            'timestamp_utc' => $at->toDateTimeString(),
            'speed' => 30.0,
            'heading' => 90.0,
        ]);
    }

    private function insertScanHot(int $id, int $orgId, float $lng, float $lat, Carbon $at): void
    {
        DB::connection('clarity_clickhouse')->table($this->clarityTable('scans_hot'))->insert([
            'id' => $id,
            'kit_id' => self::KIT_ID,
            'org_id' => $orgId,
            'ocr' => 'ABC1234',
            'us_state' => 'TX',
            'camera_name' => 'cam1',
            'scanned_at' => $at->toDateTimeString(),
            'created_at' => $at->toDateTimeString(),
            'lng' => $lng,
            'lat' => $lat,
            '_peerdb_is_deleted' => 0,
        ]);
    }

    /**
     * Round-4 review raised the "null island" concern: that a zero-geo book row
     * would be cast to 0.0 and collapse every such address into one row keyed on
     * (kit, 0.0, 0.0, hour, side). It cannot happen — the `addresses` CTE excludes
     * zero-geo book rows before the election, so they are never elected and never
     * reach the cast. Asserted here rather than left as an argument.
     */
    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_a_zero_geo_book_row_is_never_elected_so_no_null_island_row_can_be_written(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $fixAt = now()->subMinutes(5);

        // The fix must be NON-zero but within the 160.934 m radius of (0, 0), or
        // the position_window zero-filter would carry the test on its own and it
        // would still pass with the book-side filter removed — i.e. it would pin
        // nothing. ~0.0005 deg is ~55 m from the null island, comfortably inside
        // the radius, so ONLY the addresses-CTE zero-filter can exclude this match.
        $this->insertBookAddress(self::ORG_ID, false, 402_901, 403_901, 0.0, 0.0);
        $this->insertScPosition(0.0005, 0.0005, $fixAt);

        $matches = ScoutCheckInMatchQuery::handle([
            'kit_mapping' => self::KIT_MAPPING,
            'org_id' => self::ORG_ID,
            'staging_org_ids' => [],
            'start' => now()->subMinutes(15)->toDateTimeString(),
            'end' => now()->toDateTimeString(),
        ]);

        $this->assertSame([], $matches, 'a zero-geo book row must never be elected');
    }
}
