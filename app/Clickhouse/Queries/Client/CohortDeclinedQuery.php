<?php

namespace App\Clickhouse\Queries\Client;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CohortDeclinedQuery
{
    /**
     * Count unique hotlists that were both opened AND declined (Open/Hold → status 4)
     * within the given date range. Mirrors CohortClosedQuery but targets the
     * declined transition (new_status=4) instead of the closed one (new_status=5).
     */
    public static function total(
        int $orgId,
        string $start,
        string $end,
        ?string $timezone = 'UTC',
        ?int $branchId = null,
        ?int $clientOrgId = null
    ): int {
        $dates = self::parseDates($start, $end, $timezone);
        $clientFilter = $clientOrgId ? 'AND client_org_id = '.intval($clientOrgId) : '';
        $branchWhere = self::buildBranchWhere($branchId);

        $sql = "
            WITH cohort AS (
                SELECT hotlist_id
                FROM zod.public_hotlist_status_log
                WHERE _peerdb_is_deleted = 0
                  AND created_at >= '{$dates['start']}'
                  AND created_at <= '{$dates['end']}'
                  AND new_status IN (1, 4)
                GROUP BY hotlist_id
                HAVING maxIf(toUInt8(1), new_status = 1) = 1
                   AND maxIf(toUInt8(1), new_status = 4 AND old_status IN (1, 2)) = 1
            )
            SELECT count() AS total
            FROM cohort
            INNER JOIN (
                SELECT id FROM (
                    SELECT id,
                           argMax(org_id, _version)             AS org_id,
                           argMax(client_org_id, _version)      AS client_org_id,
                           argMax(branch_id, _version)          AS branch_id,
                           argMax(_peerdb_is_deleted, _version) AS is_deleted
                    FROM hotlist
                    WHERE id IN (SELECT hotlist_id FROM cohort)
                    GROUP BY id
                )
                WHERE is_deleted = 0
                  AND org_id = {$orgId}
                  {$clientFilter}
                  {$branchWhere}
            ) AS h ON h.id = cohort.hotlist_id
        ";

        return (int) (DB::connection('clarity_clickhouse')->selectOne($sql)['total'] ?? 0);
    }

    /**
     * Count unique declined hotlists grouped by client_org_id.
     */
    public static function groupedByClient(
        int $orgId,
        array $clientOrgIds,
        string $start,
        string $end,
        ?string $timezone = 'UTC',
        ?int $branchId = null
    ): array {
        if (empty($clientOrgIds)) {
            return [];
        }

        $dates = self::parseDates($start, $end, $timezone);
        $clientIdsStr = implode(',', array_map('intval', $clientOrgIds));
        $branchWhere = self::buildBranchWhere($branchId);

        $sql = "
            WITH cohort AS (
                SELECT hotlist_id
                FROM zod.public_hotlist_status_log
                WHERE _peerdb_is_deleted = 0
                  AND created_at >= '{$dates['start']}'
                  AND created_at <= '{$dates['end']}'
                  AND new_status IN (1, 4)
                GROUP BY hotlist_id
                HAVING maxIf(toUInt8(1), new_status = 1) = 1
                   AND maxIf(toUInt8(1), new_status = 4 AND old_status IN (1, 2)) = 1
            )
            SELECT h.client_org_id, count() AS total
            FROM cohort
            INNER JOIN (
                SELECT id, client_org_id FROM (
                    SELECT id,
                           argMax(org_id, _version)             AS org_id,
                           argMax(client_org_id, _version)      AS client_org_id,
                           argMax(branch_id, _version)          AS branch_id,
                           argMax(_peerdb_is_deleted, _version) AS is_deleted
                    FROM hotlist
                    WHERE id IN (SELECT hotlist_id FROM cohort)
                    GROUP BY id
                )
                WHERE is_deleted = 0
                  AND org_id = {$orgId}
                  AND client_org_id IN ({$clientIdsStr})
                  {$branchWhere}
            ) AS h ON h.id = cohort.hotlist_id
            GROUP BY h.client_org_id
        ";

        $results = DB::connection('clarity_clickhouse')->select($sql);

        return collect($results)->pluck('total', 'client_org_id')->toArray();
    }

    /**
     * Monthly trend of cohort-declined hotlists.
     * Two queries: identify cohort hotlist ids, then bucket declines by month.
     */
    public static function byMonth(
        int $orgId,
        string $start,
        string $end,
        ?string $timezone = 'UTC',
        ?int $branchId = null,
        ?int $clientOrgId = null
    ): array {
        $dates = self::parseDates($start, $end, $timezone);
        $clientFilter = $clientOrgId ? 'AND client_org_id = '.intval($clientOrgId) : '';
        $branchWhere = self::buildBranchWhere($branchId);

        $cohortSql = "
            WITH cohort AS (
                SELECT hotlist_id
                FROM zod.public_hotlist_status_log
                WHERE _peerdb_is_deleted = 0
                  AND created_at >= '{$dates['start']}'
                  AND created_at <= '{$dates['end']}'
                  AND new_status IN (1, 4)
                GROUP BY hotlist_id
                HAVING maxIf(toUInt8(1), new_status = 1) = 1
                   AND maxIf(toUInt8(1), new_status = 4 AND old_status IN (1, 2)) = 1
            )
            SELECT cohort.hotlist_id AS hotlist_id
            FROM cohort
            INNER JOIN (
                SELECT id FROM (
                    SELECT id,
                           argMax(org_id, _version)             AS org_id,
                           argMax(client_org_id, _version)      AS client_org_id,
                           argMax(branch_id, _version)          AS branch_id,
                           argMax(_peerdb_is_deleted, _version) AS is_deleted
                    FROM hotlist
                    WHERE id IN (SELECT hotlist_id FROM cohort)
                    GROUP BY id
                )
                WHERE is_deleted = 0
                  AND org_id = {$orgId}
                  {$clientFilter}
                  {$branchWhere}
            ) AS h ON h.id = cohort.hotlist_id
        ";

        $cohortIds = DB::connection('clarity_clickhouse')->select($cohortSql);

        if (empty($cohortIds)) {
            return [];
        }

        $idsStr = implode(',', array_column($cohortIds, 'hotlist_id'));

        $monthlySql = "
            SELECT
                toDate(toStartOfMonth(created_at)) AS month,
                countDistinct(hotlist_id) AS total
            FROM zod.public_hotlist_status_log
            WHERE _peerdb_is_deleted = 0
              AND created_at >= '{$dates['start']}'
              AND created_at <= '{$dates['end']}'
              AND new_status = 4
              AND old_status IN (1, 2)
              AND hotlist_id IN ({$idsStr})
            GROUP BY month
            ORDER BY month
        ";

        return DB::connection('clarity_clickhouse')->select($monthlySql);
    }

    private static function parseDates(string $start, string $end, ?string $timezone): array
    {
        return [
            'start' => Carbon::parse($start, $timezone ?? 'UTC')->utc()->toDateTimeString(),
            'end' => Carbon::parse($end, $timezone ?? 'UTC')->utc()->toDateTimeString(),
        ];
    }

    private static function buildBranchWhere(?int $branchId): string
    {
        if (! $branchId) {
            return '';
        }

        return 'AND branch_id = '.intval($branchId);
    }
}
