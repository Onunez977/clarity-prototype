<?php

namespace App\Clickhouse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AffiliateStatsHourly extends ClickhouseModel
{
    protected $table = 'affiliate_stats_hourly';

    protected $primaryKey = 'kit_id';

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public static function affiliateStatsQuery(int $orgId, array $kitIds, Carbon $start, Carbon $end): Builder
    {
        $kitFilter = '';
        if (! empty($kitIds)) {
            $kitFilter = 'AND kit_id IN ('.implode(', ', array_map('intval', $kitIds)).')';
        }

        return self::query()->fromSub(sprintf("
            SELECT
                h.kit_id AS kit_id,
                k.name AS name,
                sum(hourly_scans) AS scan_total,
                sum(hourly_unique_scans) AS unique_scans,
                countIf(hourly_scans >= 50) AS total_hours,
                uniqExact(scan_day) AS total_days
            FROM (
                SELECT
                    kit_id,
                    scan_hour,
                    toDate(scan_hour) AS scan_day,
                    max(scan_count) AS hourly_scans,
                    max(unique_plates) AS hourly_unique_scans
                FROM clarity.affiliate_stats_hourly FINAL
                WHERE org_id = %d
                    AND scan_hour BETWEEN '%s' AND '%s'
                    %s
                GROUP BY kit_id, scan_hour
            ) AS h
            INNER JOIN clarity.kits AS k FINAL ON k.id = h.kit_id AND k._peerdb_is_deleted = 0
            GROUP BY h.kit_id, k.name
            HAVING scan_total > 0",
            $orgId,
            $start->toDateTimeString(),
            $end->toDateTimeString(),
            $kitFilter,
        ), 'affiliate_stats_hourly');
    }
}
