<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

// Tests that exercise real ClickHouse SQL must `use RequiresClickhouse`.
// Skip unless CLICKHOUSE_DRIVER=clickhouse. CI sets it to `clickhouse` and
// runs the service; locally leave CLICKHOUSE_DRIVER=pgsql in .env.testing to
// skip these tests. When the driver is `clickhouse`, the trait also truncates
// the clarity.* and zod.public_* tables that factories and seeders write to so
// runs don't cross-pollute (no CDC in CI = no automatic reset). A table a test
// seeds but this list omits leaks rows into every later test class in the run.
//
// Table names are stored as clarity.X / zod.X for readability; we strip the
// prefix and truncate on the matching connection so Path A twins
// (clarity_testing_* / zod_testing_*) still reset — a hardcoded clarity.X
// qualifier would no-op when the connection database is not literally `clarity`.
trait RequiresClickhouse
{
    private const CLICKHOUSE_TABLES_TO_TRUNCATE = [
        'clarity.billing_claim_events',
        'clarity.hotlist',
        'clarity.hotlist_status_aggregate',
        'clarity.hotlist_status_transitions',
        'clarity.scans_hot',
        'clarity.scans_cold',
        'clarity.hits_hot',
        'clarity.hits_cold',
        'clarity.recoveries',
        'clarity.recovery_scans',
        'clarity.recovery_address',
        'clarity.kits',
        'clarity.kit_trips',
        'clarity.kit_scan_paths',
        'clarity.sc_positions',
        'clarity.scout_open_addresses',
        'clarity.missed_opportunities',
        'clarity.hit_stats_daily',
        'clarity.recovery_kit_scan_report',
        'clarity.scan_report',
        'clarity.scan_report_hourly',
        'zod.kit_last_scan',
        'zod.aggregate_kit_camera_name',
        'zod.public_hotlist_status_log',
        'zod.public_address',
        'zod.public_hotlist_address',
        'zod.public_hotlist',
        'zod.public_billing_events',
        'zod.public_api_keys',
        'zod.public_orgs',
    ];

    protected function setupRequiresClickhouse(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') !== 'clickhouse') {
            $this->markTestSkipped('Set CLICKHOUSE_DRIVER=clickhouse (and run ClickHouse) to execute this test.');
        }

        foreach (self::CLICKHOUSE_TABLES_TO_TRUNCATE as $qualified) {
            [$schema, $table] = array_pad(explode('.', $qualified, 2), 2, null);
            if ($table === null) {
                $table = $schema;
                $schema = 'clarity';
            }

            $connection = $schema === 'zod' ? 'public_clickhouse' : 'clarity_clickhouse';

            // TRUNCATE TABLE IF EXISTS already no-ops for absent tables; do not
            // swallow connection/permission/wrong-database failures.
            DB::connection($connection)->statement("TRUNCATE TABLE IF EXISTS {$table}");
        }
    }
}
