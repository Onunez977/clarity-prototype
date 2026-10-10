<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

class RdnScanHourCountQuery extends ClickhouseQuery
{
    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    public static function handle(array $arguments = []): int
    {
        $orgId = (int) $arguments['org_id'];
        $hour = (string) $arguments['hour'];

        // Strict allowlist for the only interpolated string in this query.
        // addslashes() is not a SQL escape; this guards against any future
        // caller passing user-influenced input through to ClickHouse.
        if (preg_match(self::DATETIME_PATTERN, $hour) !== 1) {
            throw new \InvalidArgumentException("Invalid hour datetime: {$hour}");
        }

        $sql = <<<SQL
            SELECT uniqExactMerge(scan_count) AS c
            FROM clarity.scan_report_hourly
            WHERE org_id = {$orgId}
              AND scan_hour = toStartOfHour(toDateTime('{$hour}'))
            SQL;

        $rows = DB::connection('clarity_clickhouse')->select($sql);

        return (int) ($rows[0]['c'] ?? 0);
    }
}
