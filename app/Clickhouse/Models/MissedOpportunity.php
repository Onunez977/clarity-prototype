<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\FiltersCdcDeletes;
use App\Clickhouse\Concerns\HasMonthGrouping;
use App\Contracts\MissedOpportunityFilterable;
use Carbon\Carbon;
use Database\Factories\Clickhouse\MissedOpportunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;

class MissedOpportunity extends ClickhouseModel implements MissedOpportunityFilterable
{
    use FiltersCdcDeletes;
    use HasFactory;
    use HasMonthGrouping;

    protected $table = 'missed_opportunities';

    protected static function newFactory(): MissedOpportunityFactory
    {
        return MissedOpportunityFactory::new();
    }

    public function scopeForOrg($query, int $orgId)
    {
        // Stash for forBranch: CH rejects correlated IN subqueries.
        $query->getQuery()->missedOpportunityOrgId = $orgId;

        return $query->where('hotlist_org_id', $orgId);
    }

    public function scopeNotNicb($query)
    {
        return $query->where('integration_id', '!=', IntegrationEnum::NICB->value);
    }

    public function scopeBetween($query, $start, $end, ?string $timezone = 'UTC')
    {
        return $query
            ->where('scanned_at', '>=', Carbon::parse($start, $timezone)->utc()->toDateTimeString())
            ->where('scanned_at', '<=', Carbon::parse($end, $timezone)->utc()->toDateTimeString());
    }

    public function scopeSelectTotal($query)
    {
        return $query->selectRaw('countDistinct(hotlist_id) as total');
    }

    /**
     * Branch via the hotlist's CURRENT branch (read-time join to clarity.hotlist FINAL),
     * not the branch_id copied onto the row at write time. ifNull makes NULL branch_id
     * (unenriched) miss a real branch id the same way 0 does. No created_at bound:
     * historical (F) hits predate the hotlist.
     */
    public function scopeForBranch($query, ?int $branchId)
    {
        if (! $branchId) {
            return $query;
        }

        $orgId = $query->getQuery()->missedOpportunityOrgId
            ?? throw new \InvalidArgumentException('forBranch requires forOrg first (no correlated IN on CH).');

        return $query->whereRaw(
            'hotlist_id IN (SELECT id FROM hotlist FINAL WHERE org_id = ? AND _peerdb_is_deleted = 0 AND ifNull(branch_id, 0) = ?)',
            [$orgId, $branchId]
        );
    }

    public function scopeForClient($query, ?int $clientOrgId)
    {
        return $query->when($clientOrgId, fn ($q) => $q->where('client_org_id', $clientOrgId));
    }

    public function scopeGroupByClient($query)
    {
        return $query
            ->groupBy('client_org_id')
            ->selectRaw('client_org_id, countDistinct(hotlist_id) as total');
    }

    public function scopeByMonth($query)
    {
        $monthSelect = $this->getMonthSelectSql('scanned_at');

        return $query
            ->selectRaw("{$monthSelect}, countDistinct(hotlist_id) as total")
            ->groupBy('month')
            ->orderBy('month');
    }

    public function scopeForVin(Builder $query, string $vin): Builder
    {
        $query->where('hotlist_vin', trim($vin));

        return $query;
    }

    public function scopeForClients(Builder $query, array $ids): Builder
    {
        return $query->whereIntegerInRaw('client_org_id', $ids);
    }

    public function scopeForLienholders(Builder $query, array $ids): Builder
    {
        return $query->whereIntegerInRaw('lienholder_org_id', $ids);
    }

    public function scopeForKits($query, array $kitIds)
    {
        return $query->whereIntegerInRaw('kit_id', $kitIds);
    }

    public function scopeForDispositions(Builder $query, ?array $dispositions): Builder
    {
        if ($dispositions === []) {
            return $query->whereNull('disposition');
        }

        $safe = array_map(fn ($d) => "'".addslashes($d)."'", $dispositions);

        return $query->whereRaw('disposition IN ('.implode(',', $safe).')');
    }

    public function scopeExcludeDisposition(Builder $query, string $disposition): Builder
    {
        return $query->whereRaw("(disposition IS NULL OR disposition != '".addslashes($disposition)."')");
    }

    public function scopeForHitType(Builder $query, string $hitType): Builder
    {
        return $query->where('hit_type', $hitType);
    }

    public function scopeForCreatedBetween(Builder $query, $start = null, $end = null): Builder
    {
        return $query
            ->when($start && $end, fn ($q) => $q->whereBetween('hit_created_at', [convertToUtc($start), convertToUtc($end)]))
            ->when($start && ! $end, fn ($q) => $q->where('hit_created_at', '>=', convertToUtc($start)))
            ->when($end && ! $start, fn ($q) => $q->where('hit_created_at', '<=', convertToUtc($end)));
    }

    public function scopeGroupByClientAndMonth($query)
    {
        $monthSelect = $this->getMonthSelectSql('scanned_at');

        return $query
            ->selectRaw("client_org_id, {$monthSelect}, countDistinct(hotlist_id) as total")
            ->groupBy('client_org_id', 'month')
            ->orderBy('client_org_id')
            ->orderBy('month');
    }
}
