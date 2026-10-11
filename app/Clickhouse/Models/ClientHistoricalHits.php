<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\HasMonthGrouping;
use Carbon\Carbon;

class ClientHistoricalHits extends ClickhouseModel
{
    use HasMonthGrouping;

    protected $table = 'client_historical_hits_hourly';

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

    public function scopeSelectTotal($query)
    {
        return $query->selectRaw('uniqExactMerge(historical_hit_count) as total');
    }

    public function scopeGroupByClient($query)
    {
        return $query
            ->groupBy('client_org_id')
            ->selectRaw('client_org_id, uniqExactMerge(historical_hit_count) as total');
    }

    public function scopeByMonth($query)
    {
        $monthSelect = $this->getMonthSelectSql('hour_timestamp');

        return $query
            ->selectRaw("{$monthSelect}, uniqExactMerge(historical_hit_count) as total")
            ->groupBy('month')
            ->orderBy('month');
    }
}
