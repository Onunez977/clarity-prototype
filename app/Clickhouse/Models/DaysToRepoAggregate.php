<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\HasMonthGrouping;
use Carbon\Carbon;

class DaysToRepoAggregate extends ClickhouseModel
{
    use HasMonthGrouping;

    protected $table = 'client_days_to_repo_all';

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeBetween($query, $start, $end, ?string $timezone = 'UTC')
    {
        return $query
            ->where('hour_timestamp', '>=', Carbon::parse($start, $timezone)->utc()->toDateTimeString())
            ->where('hour_timestamp', '<=', Carbon::parse($end, $timezone)->utc()->toDateTimeString());
    }

    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    }

    public function scopeForClient($query, ?int $clientOrgId)
    {
        return $query->when($clientOrgId, fn ($q) => $q->where('client_org_id', $clientOrgId));
    }

    public function scopeByMonth($query)
    {
        $monthSelect = $this->getMonthSelectSql('hour_timestamp');

        return $query
            ->selectRaw("{$monthSelect}, SUM(sum_days_to_repo_all) as total_days, SUM(count_repos_all) as total_count")
            ->groupBy('month')
            ->orderBy('month');
    }
}
