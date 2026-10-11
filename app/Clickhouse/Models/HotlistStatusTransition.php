<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\HasMonthGrouping;
use App\Enums\HotlistStatusEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Exact transition rows for branch-filtered Client Analytics cards.
 *
 * Used ONLY when a branch is selected. Unfiltered cards keep reading
 * HotlistStatusAggregate. Counts with uniqExact(hotlist_id); between() buckets
 * created_at via toStartOfHour in UTC to match status_hour semantics.
 *
 * Branch/client filters are read-time joins to clarity.hotlist FINAL (NULL-safe).
 * Hotlist subqueries use toStartOfHour(created_at) <= window_end (same bucket as transitions).
 */
class HotlistStatusTransition extends ClickhouseModel
{
    use HasMonthGrouping;

    protected $table = 'hotlist_status_transitions';

    public function scopeForOrg($query, int $orgId)
    {
        // Stash for forBranch/forClient: CH rejects correlated IN subqueries.
        $query->getQuery()->hotlistStatusOrgId = $orgId;

        return $query->where('org_id', $orgId);
    }

    /**
     * Match HotlistStatusAggregate::scopeBetween status_hour semantics: compare the
     * toStartOfHour bucket of created_at in UTC.
     */
    public function scopeBetween($query, $start, $end, ?string $timezone = 'UTC')
    {
        $startUtc = Carbon::parse($start, $timezone)->utc()->toDateTimeString();
        $endUtc = Carbon::parse($end, $timezone)->utc()->toDateTimeString();

        // Stash window end for hotlist FINAL subqueries / derived h (decision 3).
        $query->getQuery()->hotlistStatusWindowEnd = $endUtc;

        return $query
            ->whereRaw('toStartOfHour(created_at) >= ?', [$startUtc])
            ->whereRaw('toStartOfHour(created_at) <= ?', [$endUtc]);
    }

    /**
     * Branch via read-time join. ifNull makes NULL branch_id (unenriched) miss a real
     * branch id the same way 0 does.
     *
     * @param  bool  $applyJoin  When false, only stash branch_id for groupBy* filtered h
     *                           (avoids a redundant hotlist FINAL IN scan).
     */
    public function scopeForBranch($query, ?int $branchId, bool $applyJoin = true)
    {
        if (! $branchId) {
            return $query;
        }

        $orgId = $query->getQuery()->hotlistStatusOrgId
            ?? throw new \InvalidArgumentException('forBranch requires forOrg first (no correlated IN on CH).');

        $query->getQuery()->hotlistStatusBranchId = $branchId;

        if (! $applyJoin) {
            return $query;
        }

        [$windowSql, $windowBindings] = $this->hotlistWindowPredicate($query);

        return $query->whereRaw(
            "hotlist_id IN (
                SELECT id FROM hotlist FINAL
                WHERE org_id = ?
                  AND _peerdb_is_deleted = 0
                  {$windowSql}
                  AND ifNull(branch_id, 0) = ?
            )",
            [$orgId, ...$windowBindings, $branchId]
        );
    }

    public function scopeForClient($query, ?int $clientOrgId)
    {
        if (! $clientOrgId) {
            return $query;
        }

        $orgId = $query->getQuery()->hotlistStatusOrgId
            ?? throw new \InvalidArgumentException('forClient requires forOrg first (no correlated IN on CH).');

        [$windowSql, $windowBindings] = $this->hotlistWindowPredicate($query);

        return $query->whereRaw(
            "hotlist_id IN (
                SELECT id FROM hotlist FINAL
                WHERE org_id = ?
                  AND _peerdb_is_deleted = 0
                  {$windowSql}
                  AND client_org_id = ?
            )",
            [$orgId, ...$windowBindings, $clientOrgId]
        );
    }

    /**
     * @param  bool  $applyJoin  When false, only stash client ids for groupBy* filtered h
     *                           (avoids a redundant hotlist FINAL IN scan).
     */
    public function scopeForClients($query, array $clientOrgIds, bool $applyJoin = true)
    {
        if ($clientOrgIds === []) {
            return $query->whereRaw('0');
        }

        $orgId = $query->getQuery()->hotlistStatusOrgId
            ?? throw new \InvalidArgumentException('forClients requires forOrg first (no correlated IN on CH).');

        $ids = array_map('intval', $clientOrgIds);
        $query->getQuery()->hotlistStatusClientIds = $ids;

        if (! $applyJoin) {
            return $query;
        }

        $idsList = implode(',', $ids);
        [$windowSql, $windowBindings] = $this->hotlistWindowPredicate($query);

        return $query->whereRaw(
            "hotlist_id IN (
                SELECT id FROM hotlist FINAL
                WHERE org_id = ?
                  AND _peerdb_is_deleted = 0
                  {$windowSql}
                  AND client_org_id IN ({$idsList})
            )",
            [$orgId, ...$windowBindings]
        );
    }

    public function scopeOpenAssignments($query)
    {
        return $query->where('new_status', HotlistStatusEnum::Open->value);
    }

    public function scopeRepossessions($query)
    {
        return $query->where('new_status', HotlistStatusEnum::Repossessed->value);
    }

    public function scopeAccepted($query)
    {
        return $query
            ->where('new_status', HotlistStatusEnum::Open->value)
            ->whereIntegerInRaw('old_status', [
                HotlistStatusEnum::Other->value,
                HotlistStatusEnum::NewFromClient->value,
            ]);
    }

    public function scopeClosedCohort($query)
    {
        return $query
            ->where('new_status', HotlistStatusEnum::Closed->value)
            ->whereIntegerInRaw('old_status', [
                HotlistStatusEnum::Open->value,
                HotlistStatusEnum::Hold->value,
            ]);
    }

    public function scopeDeclined($query)
    {
        return $query
            ->where('new_status', HotlistStatusEnum::ClosedPositive->value)
            ->whereIntegerInRaw('old_status', [
                HotlistStatusEnum::Open->value,
                HotlistStatusEnum::Hold->value,
            ]);
    }

    public function scopeAcceptedOpen($query)
    {
        return $query->where('new_status', HotlistStatusEnum::Open->value);
    }

    public function scopeAcceptedClosed($query)
    {
        return $query->where('new_status', HotlistStatusEnum::Closed->value);
    }

    public function scopeAcceptedHold($query)
    {
        return $query->where('new_status', HotlistStatusEnum::Hold->value);
    }

    public function scopeAcceptedRepos($query)
    {
        return $query->where('new_status', HotlistStatusEnum::Repossessed->value);
    }

    public function scopeSelectTotal($query)
    {
        return $query->selectRaw('uniqExact(hotlist_id) as total');
    }

    /**
     * Hotlist created_at bound matching transitions between(): toStartOfHour(created_at) <= end.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function hotlistWindowPredicate($query): array
    {
        $windowEnd = $query->getQuery()->hotlistStatusWindowEnd ?? null;
        if ($windowEnd === null) {
            return ['', []];
        }

        return ['AND toStartOfHour(created_at) <= ?', [(string) $windowEnd]];
    }

    /**
     * Single filtered hotlist FINAL derived table for the batch path (org, deleted,
     * hour-bucketed window_end, optional branch / clients). Callers that use groupBy*
     * should pass applyJoin: false to forBranch/forClients so this is the only FINAL scan.
     */
    private function filteredHotlistDerivedTable($query): string
    {
        $orgId = (int) ($query->getQuery()->hotlistStatusOrgId
            ?? throw new \InvalidArgumentException('groupBy* requires forOrg first.'));

        $parts = [
            "org_id = {$orgId}",
            '_peerdb_is_deleted = 0',
        ];

        $windowEnd = $query->getQuery()->hotlistStatusWindowEnd ?? null;
        if ($windowEnd !== null) {
            $escaped = addslashes((string) $windowEnd);
            $parts[] = "toStartOfHour(created_at) <= '{$escaped}'";
        }

        $branchId = $query->getQuery()->hotlistStatusBranchId ?? null;
        if ($branchId) {
            $parts[] = 'ifNull(branch_id, 0) = '.(int) $branchId;
        }

        $clientIds = $query->getQuery()->hotlistStatusClientIds ?? null;
        if (is_array($clientIds) && $clientIds !== []) {
            $idsList = implode(',', array_map('intval', $clientIds));
            $parts[] = "client_org_id IN ({$idsList})";
        }

        $where = implode(' AND ', $parts);

        return "(SELECT id, client_org_id FROM hotlist FINAL WHERE {$where}) AS h";
    }

    public function scopeGroupByClient($query)
    {
        return $query
            ->selectRaw(
                'h.client_org_id AS client_org_id, uniqExact(hotlist_status_transitions.hotlist_id) AS total'
            )
            ->join(DB::raw($this->filteredHotlistDerivedTable($query)), 'h.id', '=', 'hotlist_status_transitions.hotlist_id')
            ->groupBy('h.client_org_id');
    }

    public function scopeByMonth($query)
    {
        $monthSelect = $this->getMonthSelectSql('created_at');

        return $query
            ->selectRaw("{$monthSelect}, uniqExact(hotlist_id) as total")
            ->groupBy('month')
            ->orderBy('month');
    }

    public function scopeGroupByClientAndMonth($query)
    {
        $monthSelect = $this->getMonthSelectSql('created_at');

        return $query
            ->selectRaw(
                "h.client_org_id AS client_org_id, {$monthSelect}, uniqExact(hotlist_status_transitions.hotlist_id) as total"
            )
            ->join(DB::raw($this->filteredHotlistDerivedTable($query)), 'h.id', '=', 'hotlist_status_transitions.hotlist_id')
            ->groupBy('h.client_org_id', 'month')
            ->orderBy('h.client_org_id')
            ->orderBy('month');
    }
}
