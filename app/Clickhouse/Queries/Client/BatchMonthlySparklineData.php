<?php

namespace App\Clickhouse\Queries\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Clickhouse\Queries\ClickhouseQuery;
use Carbon\Carbon;

class BatchMonthlySparklineData extends ClickhouseQuery
{
    public static function handle(array $arguments = []): array
    {
        $orgId = $arguments['org_id'];
        $clientOrgIds = $arguments['client_org_ids'] ?? [];
        $branchId = $arguments['branch_id'] ?? null;
        $months = $arguments['months'] ?? 3;
        $timezone = $arguments['timezone'] ?? 'UTC';

        if (empty($clientOrgIds)) {
            return [];
        }

        $endDate = Carbon::now($timezone)->endOfMonth();
        $startDate = Carbon::now($timezone)->startOfMonth()->subMonths($months - 1);

        $assignments = self::queryAssignmentsByClientAndMonth($orgId, $clientOrgIds, $branchId, $startDate, $endDate);
        $repossessions = self::queryReposByClientAndMonth($orgId, $clientOrgIds, $branchId, $startDate, $endDate);
        $lprAssists = self::queryLprAssistsByClientAndMonth($orgId, $clientOrgIds, $branchId, $startDate, $endDate, $timezone);
        $missedOpportunities = self::queryMissedByClientAndMonth($orgId, $clientOrgIds, $branchId, $startDate, $endDate);

        $monthLabels = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthLabels[] = Carbon::now($timezone)->startOfMonth()->subMonths($i)->format('Y-m-d');
        }

        $result = [];
        foreach ($clientOrgIds as $clientOrgId) {
            $result[$clientOrgId] = [
                'assignments' => self::normalizeData($assignments[$clientOrgId] ?? [], $monthLabels),
                'repossessions' => self::normalizeData($repossessions[$clientOrgId] ?? [], $monthLabels),
                'lpr_assists' => self::normalizeData($lprAssists[$clientOrgId] ?? [], $monthLabels),
                'missed_opportunities' => self::normalizeData($missedOpportunities[$clientOrgId] ?? [], $monthLabels),
            ];
        }

        return $result;
    }

    private static function queryAssignmentsByClientAndMonth(
        int $orgId,
        array $clientOrgIds,
        ?int $branchId,
        Carbon $start,
        Carbon $end
    ): array {
        // applyJoin false: groupByClientAndMonth's filtered h is the single FINAL scan.
        $results = Clickhouse\HotlistStatusSource::query($branchId)
            ->forOrg($orgId)
            ->between($start, $end)
            ->forBranch($branchId, applyJoin: false)
            ->forClients($clientOrgIds, applyJoin: false)
            ->openAssignments()
            ->groupByClientAndMonth()
            ->get();

        return self::groupResultsByClient($results);
    }

    private static function queryReposByClientAndMonth(
        int $orgId,
        array $clientOrgIds,
        ?int $branchId,
        Carbon $start,
        Carbon $end
    ): array {
        $results = Clickhouse\HotlistStatusSource::query($branchId)
            ->forOrg($orgId)
            ->between($start, $end)
            ->forBranch($branchId, applyJoin: false)
            ->forClients($clientOrgIds, applyJoin: false)
            ->repossessions()
            ->groupByClientAndMonth()
            ->get();

        return self::groupResultsByClient($results);
    }

    private static function queryMissedByClientAndMonth(
        int $orgId,
        array $clientOrgIds,
        ?int $branchId,
        Carbon $start,
        Carbon $end
    ): array {
        $results = Clickhouse\MissedOpportunity::query()
            ->forOrg($orgId)
            ->between($start, $end)
            ->forBranch($branchId)
            ->whereIntegerInRaw('client_org_id', $clientOrgIds)
            ->groupByClientAndMonth()
            ->get();

        return self::groupResultsByClient($results);
    }

    private static function queryLprAssistsByClientAndMonth(
        int $orgId,
        array $clientOrgIds,
        ?int $branchId,
        Carbon $start,
        Carbon $end,
        string $timezone
    ): array {
        return Clickhouse\LprAssist::byClientAndMonth(
            orgId: $orgId,
            clientOrgIds: $clientOrgIds,
            start: $start->toDateTimeString(),
            end: $end->toDateTimeString(),
            timezone: $timezone,
            branchId: $branchId,
        );
    }

    private static function groupResultsByClient($results): array
    {
        $grouped = [];
        foreach ($results as $row) {
            $clientOrgId = $row->client_org_id;
            $month = $row->month;
            $grouped[$clientOrgId][$month] = (int) $row->total;
        }

        return $grouped;
    }

    private static function normalizeData(array $data, array $monthLabels): array
    {
        return \array_map(fn ($month) => (int) ($data[$month] ?? 0), $monthLabels);
    }
}
