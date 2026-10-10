<?php

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

class KitUserRecoveryLeaderboardQuery extends ClickhouseQuery
{
    public static function handle(array $arguments = []): mixed
    {
        $orgId = (int) $arguments['org_id'];
        $start = addslashes($arguments['start']);
        $end = addslashes($arguments['end']);

        $kitFilter = '';
        if (! empty($arguments['kit_ids'])) {
            $kitIds = array_map('intval', $arguments['kit_ids']);
            $kitFilter = 'AND kit_id IN ('.implode(',', $kitIds).')';
        }

        $db = self::clarityDb();

        $sql = <<<SQL
            SELECT
                kit_id as id,
                kit_name as name,
                round(sum(hotlist_scan_percentage) / 100, 2) as total
            FROM {$db}.recovery_kit_scan_report FINAL
            WHERE _peerdb_is_deleted = 0
              AND org_id = {$orgId}
              AND repo_date >= toDate('{$start}')
              AND repo_date <= toDate('{$end}')
              {$kitFilter}
            GROUP BY kit_id, kit_name
            HAVING total > 0
            ORDER BY total DESC
            SQL;

        $rows = DB::connection('clarity_clickhouse')->select($sql);

        $result = [];
        $rank = 1;
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'total' => (float) $row['total'],
                'rank' => $rank++,
            ];
        }

        return $result;
    }
}
