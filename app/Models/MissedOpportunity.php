<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\MissedOpportunityFilterable;
use App\Enums\HitTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use Spatie\Activitylog\Models\Activity;

/**
 * @property int $id
 * @property int $hotlist_id
 * @property int $scan_id
 * @property string $hit_type
 * @property Carbon $created_at
 * @property int $kit_id
 * @property HitTypeEnum $type
 * @property-read Collection<int, Activity> $activity
 * @property-read int|null $activity_count
 * @property-read mixed $hit_date
 * @property-read Hotlist|null $hotlist
 * @property-read mixed $is_commercial
 * @property-read Kit|null $kit
 * @property-read mixed $latitude
 * @property-read mixed $longitude
 * @property-read Scan|null $scan
 * @property-read Scan|null $scans
 *
 * @method static Builder<static>|MissedOpportunity coordinates(array $coordinates)
 * @method static Builder<static>|MissedOpportunity createdBetween(array $value)
 * @method static Builder<static>|MissedOpportunity createdGte(string $term)
 * @method static Builder<static>|MissedOpportunity createdLte(string $term)
 * @method static \Database\Factories\MissedOpportunityFactory factory($count = null, $state = [])
 * @method static Builder<static>|MissedOpportunity forOrg(array|int $org_id)
 * @method static Builder<static>|MissedOpportunity forOrgKits(\App\Models\Org $org)
 * @method static Builder<static>|MissedOpportunity hitType(\App\Enums\HitTypeEnum|string $HitType)
 * @method static Builder<static>|MissedOpportunity newModelQuery()
 * @method static Builder<static>|MissedOpportunity newQuery()
 * @method static Builder<static>|MissedOpportunity orderIds(array|string $ids)
 * @method static Builder<static>|MissedOpportunity plateNumber(string $plate_number)
 * @method static Builder<static>|MissedOpportunity query()
 * @method static Builder<static>|MissedOpportunity ranked()
 * @method static Builder<static>|MissedOpportunity scannedGte(string $term)
 * @method static Builder<static>|MissedOpportunity scannedLte(string $term)
 * @method static Builder<static>|MissedOpportunity status(\App\Enums\HotlistStatusEnum|int $status)
 * @method static Builder<static>|MissedOpportunity term($term)
 * @method static Builder<static>|MissedOpportunity vin(string $vin)
 * @method static Builder<static>|MissedOpportunity vl6(string $vl6)
 * @method static Builder<static>|MissedOpportunity whereCreatedAt($value)
 * @method static Builder<static>|MissedOpportunity whereHitType($value)
 * @method static Builder<static>|MissedOpportunity whereHotlistId($value)
 * @method static Builder<static>|MissedOpportunity whereId($value)
 * @method static Builder<static>|MissedOpportunity whereKitId($value)
 * @method static Builder<static>|MissedOpportunity whereScanId($value)
 *
 * @mixin \Eloquent
 */
class MissedOpportunity extends Hit implements MissedOpportunityFilterable
{
    use HasFactory;

    public static function boot(): void
    {
        parent::boot();

        // Override "only_basic" global scope.
        static::addGlobalScope('only_basic', function (Builder $builder) {
            $builder->whereIn('hits.hit_type', [
                HitTypeEnum::Missed_Opportunity->value,
                HitTypeEnum::Missed_Opportunity_Historical->value,
            ]);
        });
    }

    public function getMorphClass(): string
    {
        return 'missed_opportunity';
    }

    public function scopeForOrgKits(Builder $query, Org $org): Builder
    {
        return $query
            ->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id')
            ->where('hotlist.integration_id', '!=', IntegrationEnum::NICB->value)
            ->whereIntegerInRaw('hits.kit_id', $org->getOrgKits()->pluck('id'));
    }

    public function scopeForVin(Builder $query, string $vin): Builder
    {
        return $query->where('hotlist.vin', \trim($vin));
    }

    public function scopeForClients(Builder $query, array $clientIds): Builder
    {
        return $query->whereIntegerInRaw('hotlist.client_org_id', $clientIds);
    }

    public function scopeForLienholders(Builder $query, array $lienholderIds): Builder
    {
        return $query->whereIntegerInRaw('hotlist.lienholder_org_id', $lienholderIds);
    }

    public function scopeForDispositions(Builder $query, ?array $dispositions): Builder
    {
        return $query->where(function (Builder $subQuery) use ($dispositions): Builder {
            if ($dispositions === []) {
                $subQuery->whereNull('scans_metadata.meta->disposition');
            }

            foreach ($dispositions as $disposition) {
                $subQuery->orWhereJsonContains('scans_metadata.meta', [
                    'disposition' => $disposition,
                ]);
            }

            return $subQuery;
        });
    }

    public function scopeForHitType(Builder $query, string $hitType): Builder
    {
        return $query->where('hits.hit_type', $hitType);
    }

    public function scopeForCreatedBetween(Builder $query, $start = null, $end = null): Builder
    {
        return $query
            ->when($start && $end, fn (Builder $q): Builder => $q->whereBetween('hits.created_at', [convertToUtc($start), convertToUtc($end)]))
            ->when($start && ! $end, fn (Builder $q): Builder => $q->where('hits.created_at', '>=', convertToUtc($start)))
            ->when($end && ! $start, fn (Builder $q): Builder => $q->where('hits.created_at', '<=', convertToUtc($end)));
    }
}
