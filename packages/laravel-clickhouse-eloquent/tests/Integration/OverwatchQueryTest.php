<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Tests\Models\HitHot;
use Tests\Models\ScanHot;
use Tests\TestCase;

/**
 * Integration tests mirroring real query patterns from the Overwatch app.
 *
 * Overwatch models extend Eloquent\Model (not BaseModel), so they use the
 * Laravel Query Builder path: Connection + QueryGrammar.
 */
class OverwatchQueryTest extends TestCase
{
    /** @var int Org with plenty of test data */
    private const TEST_ORG_ID = 445500;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('CLICKHOUSE_CLOUD_HOST')) {
            $this->markTestSkipped('CLICKHOUSE_CLOUD_HOST not configured');
        }
    }

    // ---------------------------------------------------------------
    // Hits: mirrors User::clickhouseHits() query pattern
    // ---------------------------------------------------------------

    public function testHitsQueryWithJoinAndMultipleWheres(): void
    {
        $table = 'hits_hot';

        $rows = HitHot::query()
            ->join('guardian.org_camera_visibility_hit_notification as ocv', 'ocv.kit_id', "$table.kit_id")
            ->where('ocv.org_id', '=', self::TEST_ORG_ID)
            ->where("$table.org_id", '=', self::TEST_ORG_ID)
            ->where("$table._peerdb_is_deleted", '=', 0)
            ->where("$table.hotlist_status", '=', 1)
            ->where("$table.created_at", '>', '2026-01-01 00:00:00')
            ->where("$table.created_at", '<', '2026-02-01 00:00:00')
            ->select([
                "$table.id",
                "$table.created_at",
                'scan_id',
                "$table.kit_id",
                'ocr',
                'scan_us_state',
                'ocv.kit_name as camera_name',
                'scan_guid',
                'scanned_at',
                "$table.lat",
                "$table.lng",
                'plate',
                'hotlist_us_state',
                'integration_id',
                'integration_name',
            ])
            ->orderByDesc("$table.created_at")
            ->limit(10)
            ->get();

        $this->assertNotEmpty($rows, 'Expected hits for org ' . self::TEST_ORG_ID);
        $first = $rows->first();
        $this->assertNotNull($first);
        $this->assertNotNull($first->id);
        $this->assertNotNull($first->camera_name);
        $this->assertNotNull($first->plate);
    }

    public function testHitsQueryWithWhereRawHotlistFilter(): void
    {
        $table = 'hits_hot';

        // Use a simple whereRaw with IN list instead of correlated subquery
        // (ClickHouse requires experimental setting for correlated subqueries)
        $rows = HitHot::query()
            ->join('guardian.org_camera_visibility_hit_notification as ocv', 'ocv.kit_id', "$table.kit_id")
            ->where('ocv.org_id', '=', self::TEST_ORG_ID)
            ->where("$table.org_id", '=', self::TEST_ORG_ID)
            ->where("$table._peerdb_is_deleted", '=', 0)
            ->where("$table.hotlist_status", '=', 1)
            ->where("$table.created_at", '>', '2026-01-01 00:00:00')
            ->where("$table.created_at", '<', '2026-02-01 00:00:00')
            ->whereRaw("$table.integration_id != 0")
            ->select(["$table.id", 'plate', 'integration_id'])
            ->limit(5)
            ->get();

        // May be empty if no matching integrations, but query should not error
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $rows);
    }

    public function testHitsQueryToRawSqlHasNoPlaceholders(): void
    {
        $table = 'hits_hot';

        $rawSql = HitHot::query()
            ->join('guardian.org_camera_visibility_hit_notification as ocv', 'ocv.kit_id', "$table.kit_id")
            ->where('ocv.org_id', '=', self::TEST_ORG_ID)
            ->where("$table.org_id", '=', self::TEST_ORG_ID)
            ->where("$table._peerdb_is_deleted", '=', 0)
            ->where("$table.hotlist_status", '=', 1)
            ->where("$table.created_at", '>', '2026-01-01 00:00:00')
            ->where("$table.created_at", '<', '2026-02-01 00:00:00')
            ->select(["$table.id", 'plate'])
            ->limit(10)
            ->toRawSql();

        // No unsubstituted placeholders should remain
        $this->assertStringNotContainsString('?', $rawSql);
        // The `:0` style placeholders should also be resolved
        // Match placeholders preceded by space/= (avoids false positives from times like 00:00:00)
        $this->assertDoesNotMatchRegularExpression('/[\s=]:\d+(?:\s|$)/', $rawSql, 'Raw SQL should not contain :N placeholders');
        // Should contain the actual bound values
        $this->assertStringContainsString((string) self::TEST_ORG_ID, $rawSql);
        $this->assertStringContainsString('2026-01-01', $rawSql);
    }

    // ---------------------------------------------------------------
    // Scans: mirrors User::clickhouseScans() query pattern
    // ---------------------------------------------------------------

    public function testScansQueryWithJoinAndCameraVisibility(): void
    {
        $table = 'scans_hot';
        $org_id = self::TEST_ORG_ID;

        $rows = ScanHot::query()
            ->join('guardian.org_camera_visibility_scan_search as ocv', 'ocv.kit_id', "$table.kit_id")
            ->whereRaw("$table.kit_id IN (select kit_id from guardian.org_camera_visibility_scan_search ocv where ocv.org_id=$org_id)")
            ->where("$table.scanned_at", '>', '2026-01-01 00:00:00')
            ->where("$table.scanned_at", '<', '2026-01-02 00:00:00')
            ->where('ocv.org_id', '=', $org_id)
            ->select([
                "$table.id",
                'ocv.kit_id',
                'ocr',
                'us_state',
                'guid',
                'scanned_at',
                "$table.lat as lat",
                "$table.lng as lng",
                'vehicle_type',
                'vehicle_color',
                'vehicle_make',
                'address as approximate_address',
                'ocv.kit_name as camera_name',
            ])
            ->orderByDesc('scanned_at')
            ->limit(10)
            ->get();

        $this->assertNotEmpty($rows, 'Expected scans for org ' . self::TEST_ORG_ID);
        $first = $rows->first();
        $this->assertNotNull($first);
        $this->assertNotNull($first->id);
        $this->assertNotNull($first->camera_name);
        $this->assertNotNull($first->ocr);
    }

    public function testScansQueryWithDayNightFilter(): void
    {
        $table = 'scans_hot';
        $org_id = self::TEST_ORG_ID;

        // Daytime only
        $rows = ScanHot::query()
            ->join('guardian.org_camera_visibility_scan_search as ocv', 'ocv.kit_id', "$table.kit_id")
            ->where('ocv.org_id', '=', $org_id)
            ->where("$table.scanned_at", '>', '2026-01-01 00:00:00')
            ->where("$table.scanned_at", '<', '2026-01-02 00:00:00')
            ->whereRaw("$table.kit_id IN (select kit_id from guardian.org_camera_visibility_scan_search ocv where ocv.org_id=$org_id)")
            ->whereRaw('is_daytime = true')
            ->select(["$table.id", 'ocr', 'scanned_at', 'is_daytime'])
            ->limit(5)
            ->get();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $rows);
        foreach ($rows as $row) {
            $this->assertEquals(1, $row->is_daytime);
        }
    }

    public function testScansQueryWithPlateLikeSearch(): void
    {
        $table = 'scans_hot';
        $org_id = self::TEST_ORG_ID;

        // Wildcard plate search — mirrors Overwatch's `str_replace(['*', '?'], ['%', '_'])` pattern
        $rows = ScanHot::query()
            ->join('guardian.org_camera_visibility_scan_search as ocv', 'ocv.kit_id', "$table.kit_id")
            ->where('ocv.org_id', '=', $org_id)
            ->where("$table.scanned_at", '>', '2026-01-01 00:00:00')
            ->where("$table.scanned_at", '<', '2026-02-01 00:00:00')
            ->whereRaw("$table.kit_id IN (select kit_id from guardian.org_camera_visibility_scan_search ocv where ocv.org_id=$org_id)")
            ->where('ocr', 'like', 'A%')
            ->select(["$table.id", 'ocr'])
            ->limit(5)
            ->get();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $rows);
        foreach ($rows as $row) {
            $this->assertStringStartsWith('A', $row->ocr);
        }
    }

    // ---------------------------------------------------------------
    // simplePaginate: mirrors Overwatch's table() pagination
    // ---------------------------------------------------------------

    public function testSimplePaginateOnHitsQuery(): void
    {
        $table = 'hits_hot';

        $paginator = HitHot::query()
            ->join('guardian.org_camera_visibility_hit_notification as ocv', 'ocv.kit_id', "$table.kit_id")
            ->where('ocv.org_id', '=', self::TEST_ORG_ID)
            ->where("$table.org_id", '=', self::TEST_ORG_ID)
            ->where("$table._peerdb_is_deleted", '=', 0)
            ->where("$table.hotlist_status", '=', 1)
            ->where("$table.created_at", '>', '2026-01-01 00:00:00')
            ->where("$table.created_at", '<', '2026-02-01 00:00:00')
            ->select(["$table.id", 'plate', "$table.created_at"])
            ->orderByDesc("$table.created_at")
            ->simplePaginate(perPage: 15, page: 1);

        $this->assertCount(15, $paginator->items());
        $this->assertTrue($paginator->hasMorePages());
    }

    // ---------------------------------------------------------------
    // Raw SQL with bindings: mirrors InteractsWithClickhouseQueries
    // ---------------------------------------------------------------

    public function testRawSelectWithNamedBindings(): void
    {
        $bindings = [];
        $bindings[] = self::TEST_ORG_ID;

        // Mirrors the Connection::select() path with :0, :1 placeholders
        $rows = DB::connection('clickhouse-cloud')->select(
            'SELECT org_id, count() as cnt FROM guardian.hits_hot WHERE org_id = :0 GROUP BY org_id LIMIT 1',
            $bindings
        );

        $this->assertNotEmpty($rows);
        $this->assertEquals(self::TEST_ORG_ID, $rows[0]['org_id']);
        $this->assertGreaterThan(0, $rows[0]['cnt']);
    }

    public function testRawSelectWithMultipleBindings(): void
    {
        $bindings = [self::TEST_ORG_ID, '2026-01-01 00:00:00', '2026-02-01 00:00:00'];

        $rows = DB::connection('clickhouse-cloud')->select(
            'SELECT count() as cnt FROM guardian.hits_hot WHERE org_id = :0 AND created_at > :1 AND created_at < :2',
            $bindings
        );

        $this->assertNotEmpty($rows);
        $this->assertGreaterThan(0, $rows[0]['cnt']);
    }

    // ---------------------------------------------------------------
    // Analytics: mirrors ClickhouseScanReportData queries
    // ---------------------------------------------------------------

    public function testScanCountsHourlyAggregation(): void
    {
        $bindings = [];
        $orgId = self::TEST_ORG_ID;
        $bindings[] = $orgId;       // :0
        $bindings[] = '2026-01-01 00:00:00'; // :1
        $bindings[] = '2026-02-01 00:00:00'; // :2

        $sql = <<<SQL
SELECT
    formatDateTime(toDate(toTimeZone(sc.hour, 'UTC')), '%Y-%m-%d') AS scan_day,
    toHour(toTimeZone(sc.hour, 'UTC')) AS scan_hour,
    COALESCE(SUM(sc.scan_count), 0) AS total
FROM (
    SELECT
        hour,
        org_id,
        camera_id,
        argMax(scan_count, _version) AS scan_count
    FROM guardian.scan_counts_hourly_flat
    WHERE org_id = :0
      AND hour >= toDateTime(:1, 'UTC')
      AND hour < toDateTime(:2, 'UTC')
    GROUP BY hour, org_id, camera_id
) AS sc
WHERE sc.org_id = :0
GROUP BY scan_day, scan_hour
ORDER BY scan_day, scan_hour
LIMIT 5
SQL;

        $rows = DB::connection('clickhouse-cloud')->select($sql, $bindings);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('scan_day', $row);
            $this->assertArrayHasKey('scan_hour', $row);
            $this->assertArrayHasKey('total', $row);
            $this->assertGreaterThanOrEqual(0, $row['total']);
        }
    }

    // ---------------------------------------------------------------
    // CTEs: mirrors geozone filtering pattern from HitsList/Scans
    // ---------------------------------------------------------------

    public function testCteGeozonePatternGeneratesSql(): void
    {
        $table = 'hits_hot';
        $geozoneIds = '1,2';

        // Get the underlying query builder (not the Eloquent wrapper)
        // so that clickhouseCtes flows through QueryGrammar::compileSelect()
        $builder = HitHot::query()
            ->join('guardian.org_camera_visibility_hit_notification as ocv', 'ocv.kit_id', "$table.kit_id")
            ->where('ocv.org_id', '=', self::TEST_ORG_ID)
            ->where("$table.org_id", '=', self::TEST_ORG_ID)
            ->getQuery();

        // Add CTEs on the underlying query builder — mirrors Overwatch's $cte_q->getQuery()->withCte(...)
        $builder->clickhouseCtes = [
            [
                'type' => 'subquery',
                'name' => 'bounding_box',
                'sql' => "SELECT [(min_lng, min_lat), (max_lng, min_lat), (max_lng, max_lat), (min_lng, max_lat)] as bbox
                    FROM (
                        SELECT
                            min(arrayReduce('min', arrayMap(poly -> arrayReduce('min', arrayMap(ring -> arrayReduce('min', arrayMap(pt -> pt.1, ring)), poly)), geom))) as min_lng,
                            max(arrayReduce('max', arrayMap(poly -> arrayReduce('max', arrayMap(ring -> arrayReduce('max', arrayMap(pt -> pt.1, ring)), poly)), geom))) as max_lng,
                            min(arrayReduce('min', arrayMap(poly -> arrayReduce('min', arrayMap(ring -> arrayReduce('min', arrayMap(pt -> pt.2, ring)), poly)), geom))) as min_lat,
                            max(arrayReduce('max', arrayMap(poly -> arrayReduce('max', arrayMap(ring -> arrayReduce('max', arrayMap(pt -> pt.2, ring)), poly)), geom))) as max_lat
                        FROM guardian.geo_zones
                        WHERE id IN ({$geozoneIds})
                    )",
            ],
            [
                'type' => 'subquery',
                'name' => 'geo_polys',
                'sql' => "SELECT arrayJoin(geom) AS poly FROM guardian.geo_zones WHERE id IN ({$geozoneIds})",
            ],
        ];

        $sql = $builder->toSql();

        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('bounding_box AS', $sql);
        $this->assertStringContainsString('geo_polys AS', $sql);
        $this->assertStringContainsString('guardian.geo_zones', $sql);
    }

    public function testCteGeozoneExecutesAgainstClickhouse(): void
    {
        // Execute the geozone CTE pattern as raw SQL — verifies the SQL shape ClickHouse accepts
        $sql = <<<SQL
WITH
    bounding_box AS (
        SELECT [(min_lng, min_lat), (max_lng, min_lat), (max_lng, max_lat), (min_lng, max_lat)] as bbox
        FROM (
            SELECT
                min(arrayReduce('min', arrayMap(poly -> arrayReduce('min', arrayMap(ring -> arrayReduce('min', arrayMap(pt -> pt.1, ring)), poly)), geom))) as min_lng,
                max(arrayReduce('max', arrayMap(poly -> arrayReduce('max', arrayMap(ring -> arrayReduce('max', arrayMap(pt -> pt.1, ring)), poly)), geom))) as max_lng,
                min(arrayReduce('min', arrayMap(poly -> arrayReduce('min', arrayMap(ring -> arrayReduce('min', arrayMap(pt -> pt.2, ring)), poly)), geom))) as min_lat,
                max(arrayReduce('max', arrayMap(poly -> arrayReduce('max', arrayMap(ring -> arrayReduce('max', arrayMap(pt -> pt.2, ring)), poly)), geom))) as max_lat
            FROM guardian.geo_zones
            WHERE id IN (1, 2)
        )
    ),
    geo_polys AS (
        SELECT arrayJoin(geom) AS poly FROM guardian.geo_zones WHERE id IN (1, 2)
    )
SELECT
    bbox
FROM bounding_box
LIMIT 1
SQL;

        $rows = DB::connection('clickhouse-cloud')->select($sql);

        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('bbox', $rows[0]);
    }

    // ---------------------------------------------------------------
    // system.columns: mirrors clickhouseTableHasColumn()
    // ---------------------------------------------------------------

    public function testSystemColumnsQueryWithBindings(): void
    {
        $bindings = ['guardian', 'hits_hot', 'org_id'];

        $rows = DB::connection('clickhouse-cloud')->select(
            'SELECT count() AS total FROM system.columns WHERE database = :0 AND table = :1 AND name = :2',
            $bindings
        );

        $this->assertNotEmpty($rows);
        $this->assertGreaterThan(0, $rows[0]['total']);
    }

    public function testSystemColumnsQueryForNonexistentColumn(): void
    {
        $bindings = ['guardian', 'hits_hot', 'nonexistent_column_xyz'];

        $rows = DB::connection('clickhouse-cloud')->select(
            'SELECT count() AS total FROM system.columns WHERE database = :0 AND table = :1 AND name = :2',
            $bindings
        );

        $this->assertEquals(0, $rows[0]['total']);
    }
}
