<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\FiltersCdcDeletes;
use App\Contracts\ScanFilterable;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\Concerns\ClustersByLocation;
use Carbon\Carbon;
use Database\Factories\Clickhouse\ScanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;

class Scan extends ClickhouseModel implements ScanFilterable
{
    use ClustersByLocation;
    use FiltersCdcDeletes;
    use HasFactory;

    protected $table = 'scans_cold';

    protected $guarded = [];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    protected static function newFactory(): ScanFactory
    {
        return ScanFactory::new();
    }

    public static function hot(): static
    {
        $instance = new static;
        $instance->setTable('scans_hot');

        return $instance;
    }

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    /**
     * Prune by the org_id leading the scans_cold / scans_hot sort key (DEV-2615).
     *
     * @param  array<int, int>  $orgIds
     */
    public function scopeForOrgs($query, array $orgIds)
    {
        $orgIds = array_values(array_unique(array_map(intval(...), $orgIds)));

        if ($orgIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIntegerInRaw('org_id', $orgIds);
    }

    /**
     * @param  array<int, int>  $kitIds
     */
    public function scopeForKits($query, array $kitIds)
    {
        return $query->whereIntegerInRaw('kit_id', $kitIds);
    }

    public function scopeForKit($query, ?int $kitId)
    {
        return $query->when($kitId, fn ($q) => $q->where('kit_id', $kitId));
    }

    public function scopeBetween(Builder $query, $start = null, $end = null, ?string $timezone = null): Builder
    {
        $tz = $timezone ?? auth()->user()?->timezone ?? 'UTC';

        return $query
            ->when($start, fn (Builder $q) => $q->where('scanned_at', '>=', Carbon::parse($start, $tz)->utc()->toDateTimeString()))
            ->when($end, fn (Builder $q) => $q->where('scanned_at', '<=', Carbon::parse($end, $tz)->utc()->toDateTimeString()));
    }

    public function scopeForPlate(Builder $query, string $plate, bool $flexSearch = false): Builder
    {
        return PlateFilter::modifyQuery($query, 'ocr', $plate, $flexSearch);
    }

    public function scopeForDisposition(Builder $query, string $disposition): Builder
    {
        return $query->where('disposition', $disposition);
    }

    /**
     * Exclude scans with the given disposition (include NULLs).
     * Uses whereRaw to avoid the ClickHouse nested-closure binding bug.
     */
    public function scopeExcludeDisposition(Builder $query, string $disposition): Builder
    {
        return $query->whereRaw("(disposition IS NULL OR disposition != '".addslashes($disposition)."')");
    }

    public function scopeInBounds($query, float $south, float $north, float $west, float $east)
    {
        return $query
            ->whereBetween('lat', [$south, $north])
            ->whereBetween('lng', [$west, $east]);
    }

    public function coordinates(): Attribute
    {
        return Attribute::make(
            get: fn () => [
                'latitude' => $this->lat,
                'longitude' => $this->lng,
            ],
        );
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

    public function plateImage(): Attribute
    {
        return Attribute::make(
            get: fn () => scan_img($this->guid, VehicleImageTypeEnum::Plate->value),
        );
    }

    public function vehicleImage(): Attribute
    {
        return Attribute::make(
            get: fn () => scan_img($this->guid, VehicleImageTypeEnum::Car->value),
        );
    }

    public function plateImageUrl(): Attribute
    {
        return $this->plateImage();
    }

    public function carImageUrl(): Attribute
    {
        return $this->vehicleImage();
    }

    public function carImage(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->vehicle_image,
        );
    }

    public function getImagesAttribute(): Attribute
    {
        return Attribute::make(
            get: fn () => [
                $this->vehicle_image,
                $this->plate_image,
            ],
        );
    }
}
