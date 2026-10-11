<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VehicleTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $org_id
 * @property string $kit_mapping
 * @property int $kit_type
 * @property string $name
 * @property string|null $text_phone
 * @property float|null $fixed_latitude
 * @property float|null $fixed_longitude
 * @property-read VehicleTypeEnum|null $vehicle_type
 * @property-read User|null $assigned_user
 * @property-read KitSettings|null $settings
 * @property-read Org|null $org
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Scan> $scan
 * @property-read int|null $scan_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Scan> $scans
 * @property-read int|null $scans_count
 *
 * @method static \Database\Factories\KitFactory factory($count = null, $state = [])
 * @method static Builder<static>|Kit forOrg(int $org_id)
 * @method static Builder<static>|Kit newModelQuery()
 * @method static Builder<static>|Kit newQuery()
 * @method static Builder<static>|Kit query()
 * @method static Builder<static>|Kit whereFixedLatitude($value)
 * @method static Builder<static>|Kit whereFixedLongitude($value)
 * @method static Builder<static>|Kit whereId($value)
 * @method static Builder<static>|Kit whereKitMapping($value)
 * @method static Builder<static>|Kit whereKitType($value)
 * @method static Builder<static>|Kit whereName($value)
 * @method static Builder<static>|Kit whereOrgId($value)
 * @method static Builder<static>|Kit whereTextPhone($value)
 *
 * @mixin \Eloquent
 */
class Kit extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    public $timestamps = false;

    public $table = 'kits';

    protected $fillable = [
        'name',
        'org_id',
        'kit_type',
        'text_phone',
        'kit_mapping',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'org_id', 'id');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(KitSettings::class, 'kit_id');
    }

    protected function vehicleType(): Attribute
    {
        return Attribute::get(fn () => $this->settings?->vehicle_type);
    }

    public function getAssignedUserAttribute(): ?User
    {
        return $this->settings?->user;
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class, 'kit_id', 'id');
    }

    /**
     * @deprecated Use the `scans` instead
     */
    public function scan(): HasMany
    {
        return $this->scans();
    }

    public function scopeForOrg(Builder $query, int $org_id): void
    {
        $query->where('org_id', $org_id);
    }

    /**
     * Exclude soft-deleted/deactivated kits — the same `kit_metadata.deleted_at`
     * exclusion {@see getKits} applies for the Clarity dropdowns. Shared so the
     * Scout org map (ClarityScoutBirdsEyeController) and the Scout Users admin
     * list (ScoutUsersResource) resolve the identical set of active kits.
     */
    public function scopeExcludingDeleted(Builder $query): void
    {
        $query->leftJoin('kit_metadata', fn (JoinClause $join) => $join->on('kits.id', '=', 'kit_metadata.kit_id'))
            ->whereNull('kit_metadata.deleted_at')
            ->select('kits.*');
    }

    /**
     * Method to get the geospatial data for a kit or Collection of kits
     * Geospatial JSON files are generated from this query
     */
    public static function geospatial(Carbon $start, Carbon $end, int|array $kit_id): Builder
    {
        $kits = Arr::wrap($kit_id);

        $q = Scan::query()
            ->selectRaw(
                '
                         scans.kit_id as kit_id,
                         scans.scanned_at as scan_date_local,
                         kits.name as kit_name',
            );
        $q->selectRaw('st_x(geo) as longitude');
        $q->selectRaw('st_y(geo) as latitude');
        $q->selectRaw('extract(month from scanned_at) as month');
        $q->selectRaw('extract(year from scanned_at) as year');
        //        $q->selectRaw('CASE WHEN timezones.tzid IS NULL THEN scans.scanned_at
        //            ELSE  scans.scanned_at::timestamp AT TIME ZONE timezones.tzid::text AT TIME ZONE \'UTC\'
        //       END as scan_date_utc');
        $q->withoutGlobalScopes();
        $q->join('kits', 'kits.id', '=', 'scans.kit_id');
        $q->whereIntegerInRaw('kits.id', $kits);
        $q->whereRaw('st_x(geo) <> 0');
        $q->scannedBetween($start, $end);
        $q->orderBy('scans.scanned_at');

        return $q;
    }

    public static function geospatialGeoJsonObject(Carbon $start, Carbon $end, int|array $kit_id, string $layerId = 'kit'): \Illuminate\Database\Query\Builder
    {
        $kits = Arr::wrap($kit_id);

        $sub_query = Scan::withoutGlobalscopes()
            ->select('kit_id', 'geo', 'kits.name')
            ->join('kits', 'kits.id', '=', 'scans.kit_id')
            ->whereIntegerInRaw('kit_id', $kits)
            ->whereRaw('st_x(geo) <> 0')
            ->scannedBetween($start, $end)
            ->groupBy('kit_id', 'geo', 'scanned_at', 'kits.name')
            ->orderBy('scanned_at')
            ->toBase();

        return DB::connection('zod')->query()
            ->selectRaw('
                kit_id,
                jsonb_build_object(
                    \'kit_id\',sub.kit_id,
                    \'version\',1,
                    \'name\', sub.name,
                    \'color\', CASE
   when kit_id % 42 = 0 then json_build_array(128,0,0)
   when kit_id % 42 = 1 then json_build_array(165,42,42)
   when kit_id % 42 = 2 then json_build_array(220,20,60)
   when kit_id % 42 = 3 then json_build_array(255,99,71)
   when kit_id % 42 = 4 then json_build_array(205,92,92)
   when kit_id % 42 = 5 then json_build_array(233,150,122)
   when kit_id % 42 = 6 then json_build_array(255,69,0)
   when kit_id % 42 = 7 then json_build_array(255,140,0)
   when kit_id % 42 = 8 then json_build_array(255,165,0)
   when kit_id % 42 = 9 then json_build_array(255,215,0)
   when kit_id % 42 = 10 then json_build_array(189,183,107)
   when kit_id % 42 = 11 then json_build_array(128,128,0)
   when kit_id % 42 = 12 then json_build_array(154,205,50)
   when kit_id % 42 = 13 then json_build_array(107,142,35)
   when kit_id % 42 = 14 then json_build_array(0,100,0)
   when kit_id % 42 = 15 then json_build_array(34,139,34)
   when kit_id % 42 = 16 then json_build_array(0,255,0)
   when kit_id % 42 = 17 then json_build_array(46,139,87)
   when kit_id % 42 = 18 then json_build_array(60,179,113)
   when kit_id % 42 = 19 then json_build_array(47,79,79)
   when kit_id % 42 = 20 then json_build_array(0,128,128)
   when kit_id % 42 = 21 then json_build_array(0,139,139)
   when kit_id % 42 = 22 then json_build_array(0,255,255)
   when kit_id % 42 = 23 then json_build_array(0,191,255)
   when kit_id % 42 = 24 then json_build_array(25,25,112)
   when kit_id % 42 = 25 then json_build_array(0,0,205)
   when kit_id % 42 = 26 then json_build_array(0,0,255)
   when kit_id % 42 = 27 then json_build_array(65,105,225)
   when kit_id % 42 = 28 then json_build_array(138,43,226)
   when kit_id % 42 = 29 then json_build_array(75,0,130)
   when kit_id % 42 = 30 then json_build_array(72,61,139)
   when kit_id % 42 = 31 then json_build_array(106,90,205)
   when kit_id % 42 = 32 then json_build_array(147,112,219)
   when kit_id % 42 = 33 then json_build_array(153,50,204)
   when kit_id % 42 = 34 then json_build_array(128,0,128)
   when kit_id % 42 = 35 then json_build_array(255,0,255)
   when kit_id % 42 = 36 then json_build_array(218,112,214)
   when kit_id % 42 = 37 then json_build_array(199,21,133)
   when kit_id % 42 = 38 then json_build_array(255,20,147)
   when kit_id % 42 = 39 then json_build_array(139,69,19)
   when kit_id % 42 = 40 then json_build_array(210,105,30)
   when kit_id % 42 = 41 then json_build_array(244,164,96)

           end,
                    \'layerId\', \''.$layerId.'\',
                   \'path\', json_agg(json_build_array(st_x(sub.geo),st_y(sub.geo)))
                ) as geojson
            ')
            ->fromSub($sub_query, 'sub')
            ->groupBy('sub.kit_id', 'sub.name');
    }

    public function getCameraName(int $org_id_viewing_kit): string
    {
        return ($this->org_id != $org_id_viewing_kit) ? 'Commercial' : $this->name;
    }

    public static function kitsCacheKey(int $org_id, bool $withDeleted = true): string
    {
        return 'org-kits:v2:'.$org_id.'-'.($withDeleted ? 'all' : 'active');
    }

    public static function sharedKitsCacheKey(int $org_id): string
    {
        return 'org-shared-kits:v2:'.$org_id;
    }

    public static function forgetCachedKits(int $org_id): void
    {
        cache()->forget(self::kitsCacheKey($org_id, withDeleted: true));
        cache()->forget(self::kitsCacheKey($org_id, withDeleted: false));
        cache()->forget(self::sharedKitsCacheKey($org_id));
    }

    public static function getKits(int $org_id, bool $withDeleted = true, bool $withShared = false): Collection
    {
        $kits = cache()->remember(
            self::kitsCacheKey($org_id, $withDeleted),
            now()->endOfHour(),
            fn () => self::query()
                ->where('org_id', $org_id)
                ->when(! $withDeleted, fn ($query) => $query
                    ->leftJoin('kit_metadata', fn (JoinClause $join) => $join
                        ->on('kits.id', '=', 'kit_metadata.kit_id')
                    )
                    ->whereNull('kit_metadata.deleted_at')
                )
                ->select(['id', 'name', 'org_id'])
                ->orderBy('name')
                ->get()
        );

        if ($withShared) {
            $kits = $kits
                ->merge(self::getSharedKits($org_id))
                ->sortBy('name');
        }

        return $kits;
    }

    public static function getSharedKits(int $org_id): Collection
    {
        return cache()->remember(
            self::sharedKitsCacheKey($org_id),
            now()->endOfHour(),
            function () use ($org_id) {
                $shared_kit_ids = Shared::query()
                    ->where('shared_type', Kit::class)
                    ->where('shared_with_type', Org::class)
                    ->where('shared_with_id', $org_id)
                    ->pluck('shared_id');

                return self::query()
                    ->whereIntegerInRaw('id', $shared_kit_ids->all())
                    ->select(['id', 'name', 'org_id'])
                    ->orderBy('name')
                    ->get();
            }
        );
    }
}
