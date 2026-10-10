<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\FiltersCdcDeletes;
use App\Clickhouse\Scopes\ApplyFinal;
use App\Contracts\HotlistFilterable;
use App\Enums\HotlistStatusEnum;
use App\Filament\Tables\Filters\PlateFilter;
use Carbon\Carbon;
use Database\Factories\Clickhouse\HotlistFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Datatype\RemoteOrderId;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;

class Hotlist extends ClickhouseModel implements HotlistFilterable
{
    use FiltersCdcDeletes;
    use HasFactory;

    protected $table = 'hotlist';

    protected static function newFactory(): HotlistFactory
    {
        return HotlistFactory::new();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new ApplyFinal);
    }

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    }

    public function scopeForClient($query, ?int $clientOrgId)
    {
        return $query->when($clientOrgId, fn ($q) => $q->where('client_org_id', $clientOrgId));
    }

    public function scopeOpen($query)
    {
        return $query->where('status', HotlistStatusEnum::Open->value);
    }

    public function scopeActive($query)
    {
        return $query->whereIntegerInRaw('status', [
            HotlistStatusEnum::Open->value,
            HotlistStatusEnum::Hold->value,
        ]);
    }

    public function scopeCreatedAfter($query, string $date)
    {
        return $query->where('created_at', '>=', $date);
    }

    public function scopeBetween($query, $start, $end, ?string $timezone = 'UTC')
    {
        return $query
            ->where('created_at', '>=', Carbon::parse($start, $timezone)->utc()->toDateTimeString())
            ->where('created_at', '<=', Carbon::parse($end, $timezone)->utc()->toDateTimeString());
    }

    public function scopeForStatus(Builder $query, int $status): Builder
    {
        $enum = HotlistStatusEnum::tryFrom($status);

        if ($enum === HotlistStatusEnum::Other) {
            return $query->whereIntegerInRaw('status', [HotlistStatusEnum::Open->value, HotlistStatusEnum::Repossessed->value], 'and', true);
        }

        return $query->where('status', $status);
    }

    public function scopeForVin(Builder $query, string $vin): Builder
    {
        return $query->where('vin', 'LIKE', '%'.trim($vin).'%');
    }

    public function scopeForPlate(Builder $query, string $plate, bool $flexSearch = false): Builder
    {
        return PlateFilter::modifyQuery($query, 'plate', $plate, $flexSearch);
    }

    public function scopeForOrderId(Builder $query, string $orderId): Builder
    {
        return $query->where('remote_order_id', trim($orderId));
    }

    public function scopeForClients(Builder $query, array $clientIds): Builder
    {
        return $query->whereIntegerInRaw('client_org_id', $clientIds);
    }

    public function scopeDirect($query)
    {
        return $query->where('is_staged', 0);
    }

    public function scopeStaged($query)
    {
        return $query->where('is_staged', 1);
    }

    /**
     * Direct assignments for the org, plus staged assignments from approved staging orgs.
     *
     * @param  list<int>  $stagingOrgIds
     */
    public function scopeVisibleToOrg(Builder $query, int $orgId, array $stagingOrgIds): Builder
    {
        return $query->where(function (Builder $inner) use ($orgId, $stagingOrgIds): void {
            $inner->forOrg($orgId);

            if ($stagingOrgIds !== []) {
                $inner->orWhere(function (Builder $staged) use ($stagingOrgIds): void {
                    $staged->staged()->whereIntegerInRaw('org_id', $stagingOrgIds);
                });
            }
        });
    }

    public function scopeHasPlate(Builder $query): Builder
    {
        return $query->whereRaw('length(plate) >= 3');
    }

    public function scopeShortPlate(Builder $query): Builder
    {
        return $query->whereRaw("length(coalesce(plate, '')) < 3");
    }

    public function scopeMissingPlate(Builder $query): Builder
    {
        return $query->whereRaw("(plate = '' OR plate IS NULL)");
    }

    public function vehicle(): Attribute
    {
        return Attribute::make(
            get: fn () => trim($this->vehicle_year.' '.$this->vehicle_make.' '.$this->vehicle_model)
        );
    }

    public function remoteName(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::replace('_', ' ', IntegrationEnum::tryFrom($this->attributes['integration_id'] ?? null)?->name)
        );
    }

    public function remoteLink(): Attribute
    {
        return Attribute::make(
            get: fn () => IntegrationEnum::tryFrom($this->attributes['integration_id'] ?? null)?->remoteLink(new RemoteOrderId($this->attributes['remote_order_id'] ?? ''))
        );
    }
}
