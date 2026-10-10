<?php

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

class LprVisitCountQuery extends ClickhouseQuery
{
    public static function handle(array $arguments = []): mixed
    {
        $lat = (float) $arguments['lat'];
        $lng = (float) $arguments['lng'];
        $orgId = (int) $arguments['org_id'];

        $row = DB::connection('clarity_clickhouse')->select("
            SELECT count() as lpr_visits, max(latest_scan) as last_visit
            FROM (
                SELECT toStartOfHour(scanned_at) as hour_block, max(scanned_at) as latest_scan
                FROM clarity.scans_cold
                WHERE org_id = {$orgId}
                  AND _peerdb_is_deleted = 0
                  AND scanned_at >= now() - INTERVAL 60 DAY
                  AND scanned_at <= now()
                  AND lat <> 0 AND lng <> 0
                  AND geoDistance({$lng}, {$lat}, lng, lat) <= 160.934
                GROUP BY hour_block
            )
        ");

        return [
            'lpr_visits' => (int) ($row[0]['lpr_visits'] ?? 0),
            'last_visit' => $row[0]['last_visit'] ?? null,
        ];
    }
}
