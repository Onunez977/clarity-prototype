<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

class RecoveryLeaderQuery extends ClickhouseQuery
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public static function handleSql(array $arguments = []): string
    {
        $orgId = (int) $arguments['org_id'];
        $start = (string) $arguments['start'];
        $end = (string) $arguments['end'];

        self::assertDate($start, 'start');
        self::assertDate($end, 'end');

        $clarity = static::clarityDb();

        return <<<SQL
            SELECT
                if(k.name = '', concat('Kit ', toString(k.id)), k.name) AS name,
                round(sum(sub.kit_count / sub.total_count), 2) as total
            FROM (
                SELECT
                    kit_id,
                    recovery_id,
                    toFloat64(count()) as kit_count,
                    toFloat64(sum(count()) OVER (PARTITION BY recovery_id)) as total_count
                FROM {$clarity}.recovery_scans FINAL
                WHERE _peerdb_is_deleted = 0
                  AND recovery_id IN (
                      SELECT id FROM {$clarity}.recoveries FINAL
                      WHERE org_id = {$orgId}
                        AND repo_date >= '{$start}'
                        AND repo_date <= '{$end}'
                        AND _peerdb_is_deleted = 0
                  )
                GROUP BY kit_id, recovery_id
            ) sub
            INNER JOIN {$clarity}.kits AS k ON k.id = sub.kit_id AND k._peerdb_is_deleted = 0
            GROUP BY k.id, k.name
            HAVING total > 0
            ORDER BY total DESC
            LIMIT 10
            SQL;
    }

    public static function handle(array $arguments = []): mixed
    {
        $rows = DB::connection('clarity_clickhouse')->select(self::handleSql($arguments));

        $result = [];
        foreach ($rows as $row) {
            $result[$row['name']] = (float) $row['total'];
        }

        return $result;
    }

    /**
     * `repo_date` is a calendar Date. The compared strings stay `Y-m-d`.
     * A UTC conversion would move the day.
     */
    private static function assertDate(string $value, string $field): void
    {
        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$field} date: {$value}");
        }
    }
}
