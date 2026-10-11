<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\HasMonthGrouping;
use App\Enums\HotlistStatusEnum;
use Carbon\Carbon;

class HotlistStatusAggregate extends ClickhouseModel
{
    use HasMonthGrouping;

    protected $table = 'hotlist_status_aggregate';

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeBetween($query, $start, $end, ?string $timezone = 'UTC')
    {
        return $query
            ->where('status_hour', '>=', Carbon::parse($start, $timezone)->utc()->toDateTimeString())
            ->where('status_hour', '<=', Carbon::parse($end, $timezone)->utc()->toDateTimeString());
    }

    /**
     * @param  bool  $applyJoin  ignored; signature parity with HotlistStatusTransition for HotlistStatusSource consumers.
     */
    public function scopeForBranch($query, ?int $branchId, bool $applyJoin = true)
    {
        return $query->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    }

    public function scopeForClient($query, ?int $clientOrgId)
    {
        return $query->when($clientOrgId, fn ($q) => $q->where('client_org_id', $clientOrgId));
    }

    /**
     * @param  bool  $applyJoin  ignored; signature parity with HotlistStatusTransition for HotlistStatusSource consumers.
     */
    public function scopeForClients($query, array $clientOrgIds, bool $applyJoin = true)
    {
        return $query->whereIntegerInRaw('client_org_id', $clientOrgIds);
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
        return $query->selectRaw('uniqMerge(unique_hotlists) as total');
    }

    public function scopeGroupByClient($query)
    {
        return $query
            ->groupBy('client_org_id')
            ->selectRaw('client_org_id, uniqMerge(unique_hotlists) as total');
    }

    public function scopeByMonth($query)
    {
        $monthSelect = $this->getMonthSelectSql('status_hour');

        return $query
            ->selectRaw("{$monthSelect}, uniqMerge(unique_hotlists) as total")
            ->groupBy('month')
            ->orderBy('month');
    }

    public function scopeGroupByClientAndMonth($query)
    {
        $monthSelect = $this->getMonthSelectSql('status_hour');

        return $query
            ->selectRaw("client_org_id, {$monthSelect}, uniqMerge(unique_hotlists) as total")
            ->groupBy('client_org_id', 'month')
            ->orderBy('client_org_id')
            ->orderBy('month');
    }
}
