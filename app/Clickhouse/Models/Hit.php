<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\FiltersCdcDeletes;
use App\Enums\DispositionEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\Concerns\ClustersByLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;

class Hit extends ClickhouseModel
{
    use ClustersByLocation;
    use FiltersCdcDeletes;

    protected $table = 'hits_cold';

    public static function hot(): static
    {
        $instance = new static;
        $instance->setTable('hits_hot');

        return $instance;
    }

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('hotlist_org_id', $orgId);
    }

    public function scopeNotNicb($query)
    {
        return $query->where('integration_id', '!=', IntegrationEnum::NICB->value);
    }

    public function scopeForKits($query, array $kitIds)
    {
        return $query->whereIntegerInRaw('kit_id', $kitIds);
    }

    public function scopeForHitType(Builder $query, string $type): Builder
    {
        return $query->where('hit_type', $type);
    }

    public function scopeForHitTypes(Builder $query, array $types): Builder
    {
        return $query->whereIn('hit_type', $types);
    }

    public function scopeForKitOrg(Builder $query, int $orgId): Builder
    {
        return $query->where('kit_org_id', $orgId);
    }

    /**
     * The canonical "valid hit" disposition rule (DEV-2080): a hit counts when its
     * disposition is unset (NEW_HIT → NULL) or explicitly Valid. Centralized here so
     * every Scout surface applies the identical rule rather than re-deriving it.
     * Rows storing the literal 'New Hit' are excluded here; DEV-2614 left this Scout rule unchanged for scope (not affirmed as correct).
     */
    public function scopeValidDisposition(Builder $query): Builder
    {
        $valid = addslashes(DispositionEnum::VALID->value);

        return $query->whereRaw("(disposition IS NULL OR disposition IN ('{$valid}'))");
    }

    /**
     * Direct Live hits passing the canonical disposition rule — the shape the
     * dashboard, map, recent-hits and trips surfaces all want.
     */
    public function scopeLiveValid(Builder $query): Builder
    {
        return $query->forHitType(HitTypeEnum::Live->value)->validDisposition();
    }

    /**
     * The base Hits-page "actionable" disposition rule (DEV-2059 correction):
     * a hit stays actionable when its disposition is unset (New Hit → NULL),
     * explicitly 'New Hit', or 'Accessible' — mirroring HitsResource's default
     * `dispositions` filter ([NEW_HIT, ACCESSIBLE]). Distinct from the analytics
     * {@see scopeValidDisposition} rule (New Hit or Valid), which stats/heatmap
     * surfaces intentionally keep.
     */
    public function scopeActionableDisposition(Builder $query): Builder
    {
        return $query->forDispositions([DispositionEnum::NEW_HIT->value, DispositionEnum::ACCESSIBLE->value]);
    }

    /**
     * Direct Live hits passing the actionable disposition rule — the shape the
     * map markers and driver recent-hits list want (DEV-2059 correction).
     */
    public function scopeLiveActionable(Builder $query): Builder
    {
        return $query->forHitType(HitTypeEnum::Live->value)->actionableDisposition();
    }

    public function scopeForStatus(Builder $query, ?int $status): Builder
    {
        if ($status === null) {
            return $query;
        }

        $enum = HotlistStatusEnum::tryFrom($status);

        if ($enum === HotlistStatusEnum::Other) {
            return $query->whereIntegerInRaw('hotlist_status', [HotlistStatusEnum::Open->value, HotlistStatusEnum::Repossessed->value], 'and', true);
        }

        return $query->where('hotlist_status', $status);
    }

    public function scopeForPlate(Builder $query, string $plate, bool $flexSearch = false): Builder
    {
        return PlateFilter::modifyQuery($query, 'ocr', $plate, $flexSearch);
    }

    public function scopeForVin(Builder $query, string $vin): Builder
    {
        return $query->where('hotlist_vin', 'LIKE', '%'.trim($vin).'%');
    }

    public function scopeForOrderId(Builder $query, string $id): Builder
    {
        return $query->where('remote_order_id', trim($id));
    }

    public function scopeForDispositions(Builder $query, ?array $dispositions): Builder
    {
        if ($dispositions === null || $dispositions === []) {
            return $query->whereNull('disposition');
        }

        $hasNewHit = in_array(DispositionEnum::NEW_HIT->value, $dispositions);
        $safe = array_map(fn ($d) => "'".addslashes($d)."'", $dispositions);

        if ($hasNewHit) {
            return $query->whereRaw('(disposition IS NULL OR disposition IN ('.implode(',', $safe).'))');
        }

        return $query->whereRaw('disposition IN ('.implode(',', $safe).')');
    }

    /**
     * Exclude hits with the given disposition (include NULLs).
     * Uses whereRaw to avoid the ClickHouse nested-closure binding bug.
     */
    public function scopeExcludeDisposition(Builder $query, string $disposition): Builder
    {
        return $query->whereRaw("(disposition IS NULL OR disposition != '".addslashes($disposition)."')");
    }

    public function scopeForClients(Builder $query, array $ids): Builder
    {
        return $query->whereIntegerInRaw('client_org_id', $ids);
    }

    public function scopeForLienholders(Builder $query, array $ids): Builder
    {
        return $query->whereIntegerInRaw('lienholder_org_id', $ids);
    }

    public function scopeForCreatedBetween(Builder $query, $start = null, $end = null): Builder
    {
        return $query
            ->when($start && $end, fn ($q) => $q->whereBetween('hit_created_at', [convertToUtc($start), convertToUtc($end)]))
            ->when($start && ! $end, fn ($q) => $q->where('hit_created_at', '>=', convertToUtc($start)))
            ->when($end && ! $start, fn ($q) => $q->where('hit_created_at', '<=', convertToUtc($end)));
    }

    public function scopeForScannedBetween(Builder $query, $start = null, $end = null): Builder
    {
        return $query
            ->when($start && $end, fn ($q) => $q->whereBetween('scanned_at', [convertToUtc($start), convertToUtc($end)]))
            ->when($start && ! $end, fn ($q) => $q->where('scanned_at', '>=', convertToUtc($start)))
            ->when($end && ! $start, fn ($q) => $q->where('scanned_at', '<=', convertToUtc($end)));
    }

    public function latitude(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->lat,
        );
    }

    public function longitude(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->lng,
        );
    }

    public function usState(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['scan_us_state'] ?? null,
        );
    }

    public function vehicleImage(): Attribute
    {
        return Attribute::make(
            get: fn () => scan_img($this->attributes['scan_guid'] ?? null, VehicleImageTypeEnum::Car->value),
        );
    }

    public function vehicle(): Attribute
    {
        return Attribute::make(
            get: fn () => trim(html_entity_decode(implode(' ', array_filter([
                $this->attributes['vehicle_year'] ?? null,
                $this->attributes['vehicle_make'] ?? null,
                $this->attributes['vehicle_model'] ?? null,
            ])))) ?: null,
        );
    }
}
