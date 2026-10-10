<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

class RdnHitHourCountQuery extends ClickhouseQuery
{
    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /**
     * @return array{lives:int,hh:int,staging_lives:int,aa:int}
     */
    public static function handle(array $arguments = []): array
    {
        $orgId = (int) $arguments['org_id'];
        $rangeStart = (string) $arguments['range_start'];
        $rangeEnd = (string) $arguments['range_end'];

        // Strict allowlist for the interpolated strings. addslashes() is not a
        // SQL escape; this guards against any future caller passing
        // user-influenced input through to ClickHouse.
        if (preg_match(self::DATETIME_PATTERN, $rangeStart) !== 1) {
            throw new \InvalidArgumentException("Invalid range_start datetime: {$rangeStart}");
        }
        if (preg_match(self::DATETIME_PATTERN, $rangeEnd) !== 1) {
            throw new \InvalidArgumentException("Invalid range_end datetime: {$rangeEnd}");
        }

        $sql = <<<SQL
            SELECT
                countIf(hit_type = 'L') AS lives,
                countIf(hit_type = 'H') AS hh,
                countIf(hit_type = 'S') AS staging_lives,
                countIf(hit_type = 'Z') AS aa
            FROM clarity.hits_hot FINAL
            WHERE hotlist_org_id = {$orgId}
              AND hit_created_at >= toDateTime('{$rangeStart}')
              AND hit_created_at <  toDateTime('{$rangeEnd}')
            SQL;

        $rows = DB::connection('clarity_clickhouse')->select($sql);

        if (empty($rows)) {
            return ['lives' => 0, 'hh' => 0, 'staging_lives' => 0, 'aa' => 0];
        }

        return [
            'lives' => (int) $rows[0]['lives'],
            'hh' => (int) $rows[0]['hh'],
            'staging_lives' => (int) $rows[0]['staging_lives'],
            'aa' => (int) $rows[0]['aa'],
        ];
    }
}
