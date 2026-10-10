<?php

namespace App\Clickhouse\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LprAssist extends ClickhouseModel
{
    protected $table = 'lpr_assists';

    // Note: LPR assists requires a JOIN with recoveries, so we use static methods
    // for the complex queries. The model still extends ClickhouseModel for consistency.

    protected static function buildWhereClause(
        int $orgId,
        string $start,
        string $end,
        ?string $timezone = 'UTC',
        ?int $branchId = null,
        ?int $clientOrgId = null,
        ?array $clientOrgIds = null
    ): string {
        // Truncate to calendar dates in the caller timezone.
        // Do not convert to UTC first — that shifts the calendar day for non-UTC windows
        // (e.g. America/Chicago end-of-day becomes the next UTC date).
        $tz = $timezone ?? 'UTC';
        $startDate = Carbon::parse($start, $tz)->toDateString();
        $endDate = Carbon::parse($end, $tz)->toDateString();
        // recovery_id is the hotlist id. Filter on the hotlist's CURRENT branch, not the
        // branch_id copied onto recoveries at write time, so hotlists enriched later count.
        $branchFilter = $branchId
            ? "AND la.recovery_id IN (SELECT id FROM clarity.hotlist FINAL WHERE org_id = {$orgId} AND _peerdb_is_deleted = 0 AND ifNull(branch_id, 0) = {$branchId})"
            : '';
        $clientFilter = $clientOrgId ? "AND r.client_org_id = {$clientOrgId}" : '';
        $clientIdsFilter = '';
        if (! empty($clientOrgIds)) {
            $clientIdsStr = implode(',', array_map('intval', $clientOrgIds));
            $clientIdsFilter = "AND r.client_org_id IN ({$clientIdsStr})";
        }

        return "
            r.org_id = {$orgId}
            AND r._peerdb_is_deleted = 0
            AND r.repo_date >= '{$startDate}'
            AND r.repo_date <= '{$endDate}'
            {$branchFilter}
            {$clientFilter}
            {$clientIdsFilter}
        ";
    }

    protected static function buildQuery(
        int $orgId,
        string $start,
        string $end,
        ?string $timezone,
        ?int $branchId,
        ?int $clientOrgId,
        string $select,
        string $groupBy = '',
        ?array $clientOrgIds = null
    ): string {
        $where = self::buildWhereClause($orgId, $start, $end, $timezone, $branchId, $clientOrgId, $clientOrgIds);

        return "
            SELECT {$select}
            FROM clarity.lpr_assists la
            JOIN clarity.recoveries r FINAL ON r.id = la.recovery_id
            WHERE {$where}
            {$groupBy}
        ";
    }

    public static function getTotal(
        int $orgId,
        string $start,
        string $end,
        ?string $timezone = 'UTC',
        ?int $branchId = null,
        ?int $clientOrgId = null
    ): int {
        $sql = self::buildQuery(
            $orgId,
            $start,
            $end,
            $timezone,
            $branchId,
            $clientOrgId,
            'count(DISTINCT la.recovery_id) as total'
        );

        return (int) (DB::connection('clarity_clickhouse')->selectOne($sql)['total'] ?? 0);
    }

    public static function groupedByClient(
        int $orgId,
        string $start,
        string $end,
        ?string $timezone = 'UTC',
        ?int $branchId = null
    ): array {
        $sql = self::buildQuery(
            $orgId,
            $start,
            $end,
            $timezone,
            $branchId,
            null,
            'r.client_org_id, count(DISTINCT la.recovery_id) as total',
            'GROUP BY r.client_org_id'
        );
        $results = DB::connection('clarity_clickhouse')->select($sql);

        return collect($results)->pluck('total', 'client_org_id')->toArray();
    }

    public static function groupedByClientIds(
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

        $sql = self::buildQuery(
            $orgId,
            $start,
            $end,
            $timezone,
            $branchId,
            null,
            'r.client_org_id, count(DISTINCT la.recovery_id) as total',
            'GROUP BY r.client_org_id',
            $clientOrgIds
        );
        $results = DB::connection('clarity_clickhouse')->select($sql);

        return collect($results)->pluck('total', 'client_org_id')->toArray();
    }

    public static function byMonth(
        int $orgId,
        string $start,
        string $end,
        ?string $timezone = 'UTC',
        ?int $branchId = null,
        ?int $clientOrgId = null
    ): array {
        $monthSelect = 'toDate(toStartOfMonth(r.repo_date)) as month';

        $sql = self::buildQuery(
            $orgId,
            $start,
            $end,
            $timezone,
            $branchId,
            $clientOrgId,
            "{$monthSelect}, count(DISTINCT la.recovery_id) as total",
            'GROUP BY month ORDER BY month'
        );
        $results = DB::connection('clarity_clickhouse')->select($sql);

        return collect($results)->pluck('total', 'month')->toArray();
    }

    public static function byClientAndMonth(
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

        $monthSelect = 'toDate(toStartOfMonth(r.repo_date)) as month';

        $sql = self::buildQuery(
            $orgId,
            $start,
            $end,
            $timezone,
            $branchId,
            null,
            "r.client_org_id, {$monthSelect}, count(DISTINCT la.recovery_id) as total",
            'GROUP BY r.client_org_id, month ORDER BY r.client_org_id, month',
            $clientOrgIds
        );
        $results = DB::connection('clarity_clickhouse')->select($sql);

        // Group results by client_org_id => [month => total]
        $grouped = [];
        foreach ($results as $row) {
            $row = (array) $row;

            $clientOrgId = $row['client_org_id'];
            $month = $row['month'];
            $grouped[$clientOrgId][$month] = (int) $row['total'];
        }

        return $grouped;
    }
}
