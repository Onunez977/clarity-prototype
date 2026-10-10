<?php

namespace App\Clickhouse\Queries\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Clickhouse\Queries\ClickhouseQuery;
use Carbon\Carbon;

class ClientRankedMetricsBatch extends ClickhouseQuery
{
    public static function handle(array $arguments = []): array
    {
        $orgId = $arguments['org_id'];
        $clientOrgIds = $arguments['client_org_ids'] ?? [];
        $timezone = $arguments['timezone'] ?? 'UTC';
        $startUtc = Carbon::parse($arguments['start'], $timezone)->utc()->toDateTimeString();
        $endUtc = Carbon::parse($arguments['end'], $timezone)->utc()->toDateTimeString();
        $branchId = $arguments['branch_id'] ?? null;

        if (empty($clientOrgIds)) {
            return [];
        }

        $assignmentMetrics = self::getAssignmentMetrics($orgId, $clientOrgIds, $startUtc, $endUtc, $branchId);
        $missedMetrics = self::getMissedMetrics($orgId, $clientOrgIds, $startUtc, $endUtc, $branchId);
        $lprWindow = self::lprAssistWindow($arguments);
        $lprMetrics = self::getLprAssistMetrics(
            $orgId,
            $clientOrgIds,
            $lprWindow['start'],
            $lprWindow['end'],
            $branchId,
            $lprWindow['timezone'],
        );

        return self::mergeMetrics($clientOrgIds, $assignmentMetrics, $missedMetrics, $lprMetrics);
    }

    /**
     * LprAssist truncates repo_date in the caller timezone — keep the original window,
     * not the UTC strings used by Hotlist/Missed datetime columns.
     *
     * @param  array{start?: string, end?: string, timezone?: string}  $arguments
     * @return array{start: string, end: string, timezone: string}
     */
    public static function lprAssistWindow(array $arguments): array
    {
        return [
            'start' => $arguments['start'],
            'end' => $arguments['end'],
            'timezone' => $arguments['timezone'] ?? 'UTC',
        ];
    }

    private static function getAssignmentMetrics(int $orgId, array $clientOrgIds, string $start, string $end, ?int $branchId): array
    {
        // applyJoin false: groupByClient's filtered h is the single hotlist FINAL scan.
        $baseQuery = fn () => Clickhouse\HotlistStatusSource::query($branchId)
            ->forOrg($orgId)
            ->between($start, $end)
            ->forBranch($branchId, applyJoin: false)
            ->forClients($clientOrgIds, applyJoin: false);

        $assignments = $baseQuery()
            ->openAssignments()
            ->groupByClient()
            ->pluck('total', 'client_org_id')
            ->toArray();

        $accepted = $baseQuery()
            ->accepted()
            ->groupByClient()
            ->pluck('total', 'client_org_id')
            ->toArray();

        $closed = CohortClosedQuery::groupedByClient(
            orgId: $orgId,
            clientOrgIds: $clientOrgIds,
            start: $start,
            end: $end,
            branchId: $branchId,
        );

        $declined = CohortDeclinedQuery::groupedByClient(
            orgId: $orgId,
            clientOrgIds: $clientOrgIds,
            start: $start,
            end: $end,
            branchId: $branchId,
        );

        $repos = $baseQuery()
            ->repossessions()
            ->groupByClient()
            ->pluck('total', 'client_org_id')
            ->toArray();

        $result = [];
        foreach ($clientOrgIds as $clientOrgId) {
            $result[$clientOrgId] = [
                'assignments' => $assignments[$clientOrgId] ?? 0,
                'accepted' => $accepted[$clientOrgId] ?? 0,
                'closed' => $closed[$clientOrgId] ?? 0,
                'declined' => $declined[$clientOrgId] ?? 0,
                'repos' => $repos[$clientOrgId] ?? 0,
            ];
        }

        return $result;
    }

    private static function getMissedMetrics(int $orgId, array $clientOrgIds, string $start, string $end, ?int $branchId): array
    {
        return Clickhouse\MissedOpportunity::query()
            ->forOrg($orgId)
            ->between($start, $end)
            ->forBranch($branchId)
            ->whereIntegerInRaw('client_org_id', $clientOrgIds)
            ->groupByClient()
            ->pluck('total', 'client_org_id')
            ->toArray();
    }

    private static function getLprAssistMetrics(
        int $orgId,
        array $clientOrgIds,
        string $start,
        string $end,
        ?int $branchId,
        string $timezone,
    ): array {
        return Clickhouse\LprAssist::groupedByClientIds(
            orgId: $orgId,
            clientOrgIds: $clientOrgIds,
            start: $start,
            end: $end,
            timezone: $timezone,
            branchId: $branchId,
        );
    }

    private static function mergeMetrics(array $clientOrgIds, array $assignmentMetrics, array $missedMetrics, array $lprMetrics): array
    {
        $result = [];
        foreach ($clientOrgIds as $clientOrgId) {
            $result[$clientOrgId] = [
                'client_org_id' => $clientOrgId,
                'assignments' => $assignmentMetrics[$clientOrgId]['assignments'] ?? 0,
                'accepted' => $assignmentMetrics[$clientOrgId]['accepted'] ?? 0,
                'closed' => $assignmentMetrics[$clientOrgId]['closed'] ?? 0,
                'declined' => $assignmentMetrics[$clientOrgId]['declined'] ?? 0,
                'repos' => $assignmentMetrics[$clientOrgId]['repos'] ?? 0,
                'missed' => $missedMetrics[$clientOrgId] ?? 0,
                'lpr_assists' => $lprMetrics[$clientOrgId] ?? 0,
            ];
        }

        return $result;
    }
}
