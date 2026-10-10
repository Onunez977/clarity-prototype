<?php

declare(strict_types=1);

namespace Tests\Feature\RdnMetrics\Clickhouse;

use App\Clickhouse\Queries\RdnScanHourCountQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('rdn-metrics')]
#[Group('clickhouse')]
class RdnScanHourCountQueryTest extends TestCase
{
    use RequiresClickhouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupRequiresClickhouse();
    }

    public function test_aggregates_scan_count_across_kits_for_the_given_hour(): void
    {
        $orgId = 100;
        $hourStart = CarbonImmutable::parse('2026-05-01T00:00:00Z');

        // Seed two kit-rows for the same hour with unique scan IDs. uniqExactMerge
        // should report the total distinct count across the kits.
        $this->seedScanReportHourly($orgId, kitId: 1, hour: $hourStart, distinctScanIds: range(1, 12)); // 12 distinct
        $this->seedScanReportHourly($orgId, kitId: 2, hour: $hourStart, distinctScanIds: range(13, 20)); // 8 distinct

        // Seed a row outside the hour — should NOT count.
        $this->seedScanReportHourly($orgId, kitId: 1, hour: $hourStart->subHour(), distinctScanIds: range(100, 105));

        // Seed a row for a different org — should NOT count.
        $this->seedScanReportHourly(orgId: 999, kitId: 1, hour: $hourStart, distinctScanIds: range(200, 209));

        $count = RdnScanHourCountQuery::handle([
            'org_id' => $orgId,
            'hour' => $hourStart->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(20, $count);
    }

    public function test_returns_zero_when_no_rows_exist(): void
    {
        $count = RdnScanHourCountQuery::handle([
            'org_id' => 12345,
            'hour' => '2026-05-01 00:00:00',
        ]);

        $this->assertSame(0, $count);
    }

    public function test_throws_on_malformed_hour(): void
    {
        // The strict Y-m-d H:i:s regex guards the only interpolated string in
        // this query. Anything that isn't an exact datetime string must throw
        // before the SQL is built, so a buggy future caller can't slip a SQL
        // fragment past addslashes().
        $this->expectException(\InvalidArgumentException::class);

        RdnScanHourCountQuery::handle([
            'org_id' => 1,
            'hour' => "2026-05-01 00:00:00'; DROP TABLE x; --",
        ]);
    }

    /**
     * Seed one row of clarity.scan_report_hourly with an AggregateFunction(uniqExact, Int64)
     * for scan_count, using INSERT ... SELECT to apply uniqExactState across the supplied ids.
     *
     * @param  array<int, int>  $distinctScanIds
     */
    protected function seedScanReportHourly(int $orgId, int $kitId, CarbonImmutable $hour, array $distinctScanIds): void
    {
        $idsLiteral = implode(',', array_map('intval', $distinctScanIds));
        $hourLiteral = $hour->format('Y-m-d H:i:s');

        DB::connection('clarity_clickhouse')->statement(<<<SQL
            INSERT INTO clarity.scan_report_hourly (org_id, kit_id, scan_hour, scan_count)
            SELECT
                {$orgId} AS org_id,
                {$kitId} AS kit_id,
                toStartOfHour(toDateTime('{$hourLiteral}')) AS scan_hour,
                uniqExactState(toInt64(scan_id)) AS scan_count
            FROM (SELECT arrayJoin([{$idsLiteral}]) AS scan_id)
            SQL
        );
    }
}
