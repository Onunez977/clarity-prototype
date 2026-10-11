<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DispositionEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\Concerns\ClustersByLocation;
use App\Models\Concerns\HitAlertSettings;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\IO\Generator\WKT\WKTGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;
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
 * @method static Builder<static>|Hit coordinates(array $coordinates)
 * @method static Builder<static>|Hit createdBetween(array $value)
 * @method static Builder<static>|Hit createdGte(string $term)
 * @method static Builder<static>|Hit createdLte(string $term)
 * @method static \Database\Factories\HitFactory factory($count = null, $state = [])
 * @method static Builder<static>|Hit forOrg(array|int $org_id)
 * @method static Builder<static>|Hit hitType(\App\Enums\HitTypeEnum|string $HitType)
 * @method static Builder<static>|Hit newModelQuery()
 * @method static Builder<static>|Hit newQuery()
 * @method static Builder<static>|Hit orderIds(array|string $ids)
 * @method static Builder<static>|Hit plateNumber(string $plate_number)
 * @method static Builder<static>|Hit query()
 * @method static Builder<static>|Hit ranked()
 * @method static Builder<static>|Hit scannedGte(string $term)
 * @method static Builder<static>|Hit scannedLte(string $term)
 * @method static Builder<static>|Hit status(\App\Enums\HotlistStatusEnum|int $status)
 * @method static Builder<static>|Hit term($term)
 * @method static Builder<static>|Hit vin(string $vin)
 * @method static Builder<static>|Hit vl6(string $vl6)
 * @method static Builder<static>|Hit whereCreatedAt($value)
 * @method static Builder<static>|Hit whereHitType($value)
 * @method static Builder<static>|Hit whereHotlistId($value)
 * @method static Builder<static>|Hit whereId($value)
 * @method static Builder<static>|Hit whereKitId($value)
 * @method static Builder<static>|Hit whereScanId($value)
 *
 * @mixin \Eloquent
 */
class Hit extends Model
{
    use ClustersByLocation;
    use HasFactory;
    use HitAlertSettings;

    protected $connection = 'zod';

    protected $table = 'hits';

    public $timestamps = false;

    protected $casts = [
        'type' => HitTypeEnum::class,
        'created_at' => 'datetime',
    ];

    protected $appends = [
        'is_commercial',
    ];

    public static function clusterTimestampField(): string
    {
        return 'scans_scanned_at';
    }

    public static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('only_basic', function (Builder $builder) {
            $builder->whereIn('hits.hit_type', [
                HitTypeEnum::Live->value,
                HitTypeEnum::Historical->value,
                HitTypeEnum::Staging_Historical->value,
                HitTypeEnum::Staging_Live->value,
            ]);
        });
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class, 'kit_id');
    }

    public function hotlist(): BelongsTo
    {
        return $this->belongsTo(Hotlist::class, 'hotlist_id', 'id');
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'scan_id', 'id');
    }

    /**
     * @deprecated Use the `scan` instead
     */
    public function scans(): BelongsTo
    {
        return $this->scan();
    }

    public function activity(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function isCommercial(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->current_company_type != $this->scanner_company_type
        );
    }

    public function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->utc()
        );
    }

    public function type(): Attribute
    {
        return Attribute::make(
            get: fn () => HitTypeEnum::tryFrom($this->hit_type)?->name ?? $this->hit_type
        );
    }

    public function longitude(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['lng'] ?? $this->scan?->longitude
        );
    }

    public function latitude(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['lat'] ?? $this->scan?->latitude
        );
    }

    public function plateImage(): Attribute
    {
        return Attribute::make(
            get: fn () => scan_img($this->scans_guid ?? $this->scan?->guid, VehicleImageTypeEnum::Plate->value)
        );
    }

    public function hitDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->created_at->setTimezone($this->scan?->timezone ?? 'UTC')
        );
    }

    public static function getHits(): Builder
    {
        return static::select(
            [
                'hits.id as id',
                'hits.*',
                'hotlist.status',
                'hotlist.order_type',
                'hotlist.is_staged',
                'hotlist.plate',
                'hotlist.us_state',
                'hotlist.vin',
                'hotlist.vehicle_year',
                'hotlist.vehicle_make',
                'hotlist.vehicle_model',
                'scans.ocr as scan_ocr',
                'scans.us_state as us_state',
                'scans.created_at as scan_created_at',
                'scans.scanned_at as scanned_at',
            ])
            ->addSelect(DB::raw('
            CASE
                WHEN hits.hit_type IN(\'Z\',\'S\') THEN null
                ELSE hotlist.integration_id
            END as remote_id,
            CASE
                WHEN hits.hit_type IN(\'Z\',\'S\') THEN null
                ELSE hotlist.remote_order_id
            END as remote_order_id,
            CASE
                WHEN hits.hit_type IN(\'Z\',\'S\') THEN \'Staging\'
                ELSE client_org.name
            END as client_name,
            CASE
                WHEN hits.hit_type IN(\'Z\',\'S\') THEN null
                ELSE client_org.id
            END as client_id,
            CASE
                WHEN hits.hit_type IN(\'Z\',\'S\') THEN \'Staging\'
                ELSE lien_holder_org.name
            END as lien_holder_name,
             CASE
                WHEN hits.hit_type IN(\'Z\',\'S\') THEN null
                ELSE lien_holder_org.id
            END as lien_holder_id
            '))
            ->join('scans', function ($query) {
                $query->on('scans.id', '=', 'hits.scan_id');
                $query->where('scans.is_visible', '=', DB::raw('true'));
            })
//            ->leftJoin('timezones', function(JoinClause $clause) {
//                 $clause->on(DB::raw('ST_Within(scans.geo, timezones.geom)'), '=', DB::raw('TRUE'));
//             })
            ->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id')
            ->join(DB::raw('orgs as hotlist_org'), function (JoinClause $joinClause) {
                $joinClause->on('hotlist_org.id', '=', 'hotlist.org_id');
                $joinClause->where('hotlist_org.silent_hit_generation', DB::raw('false'));
            })->leftJoin(DB::raw('orgs as client_org'), function (JoinClause $joinClause) {
                $joinClause->on('client_org.id', '=', 'hotlist.client_org_id');
            })
            ->leftJoin(DB::raw('orgs as lien_holder_org'), function (JoinClause $joinClause) {
                $joinClause->on('lien_holder_org.id', '=', 'hotlist.lienholder_org_id');
            });
    }

    public function scopeStatus(Builder $query, int|HotlistStatusEnum $status): void
    {
        $status = is_int($status) ? HotlistStatusEnum::tryFrom($status) : $status;

        $query->when(! isJoined($query, 'hotlist', 'zod'), function ($subQuery) {
            $subQuery->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id');
        })->when($status === HotlistStatusEnum::Other, function ($subQuery) {
            $subQuery->whereNotIn('hotlist.status', [
                HotlistStatusEnum::Open->value,
                HotlistStatusEnum::Repossessed->value,
            ]);
        })->when($status !== HotlistStatusEnum::Other, function ($subQuery) use ($status) {
            $subQuery->where('hotlist.status', $status->value);
        });
    }

    public function scopeForOrg(Builder $query, array|int $org_id): Builder
    {
        return $query
            ->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id')
            ->whereIntegerInRaw('hotlist.org_id', Arr::wrap($org_id));
    }

    public function scopeHitType(Builder $query, string|HitTypeEnum $HitType): void
    {
        $HitType = is_string($HitType) ? HitTypeEnum::tryFrom($HitType) : $HitType;

        $query->where('hits.hit_type', $HitType->value);
    }

    public function scopePlateNumber(Builder $query, string $plate_number): void
    {
        $plate_number = with(explode('|', $plate_number), function ($plate_numbers) {
            return array_map(function ($plate_number) {
                return Str::upper(trim($plate_number));
            }, $plate_numbers);
        });

        $query->when(! isJoined($query, 'scans'), function ($subQuery) {
            $subQuery->join('scans', function ($nestedQuery) {
                $nestedQuery->on('scans.id', '=', 'hits.scan_id');
            });
        })->whereIn('scans.ocr', $plate_number);

    }

    public function scopeVin(Builder $query, string $vin): void
    {
        $vins = with(explode('|', $vin), function ($vins) {
            return array_map(function ($vin) {
                return Str::upper(trim($vin));
            }, $vins);
        });

        if (count($vins) === 1 && Str::length($vins[0]) === 6) {
            $query->vl6($vins[0]);
        } else {
            $query->when(! isJoined($query, 'hotlist'), function ($subQuery) {
                $subQuery->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id');
            })->whereIn('hotlist.vin', $vins);
        }
    }

    public function scopeVl6(Builder $query, string $vl6): void
    {
        $query->when(! isJoined($query, 'hotlist'), function ($subQuery) {
            $subQuery->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id');
        })->where('hotlist.vin', 'like', '%'.Str::upper($vl6));
    }

    public function scopeCoordinates(Builder $query, array $coordinates): void
    {
        $lat = $coordinates['latitude'];
        $lng = $coordinates['longitude'];
        $point = Point::makeGeodetic((float) $lat, (float) $lng, 4326);

        $geo = sprintf("ST_GeomFromEWKT('SRID=%s;%s')",
            4326,
            (new WKTGenerator)->generatePoint($point)
        );

        $whereRaw = sprintf('%s.ST_DWithin(%s, %s, %s)',
            config('postgis.schema'),
            'scans.geo',
            $geo,
            .002);

        $query->when(! isJoined($query, 'scans'), function ($subQuery) {
            $subQuery->join('scans', function ($nestedQuery) {
                $nestedQuery->on('scans.id', '=', 'hits.scan_id');
                $nestedQuery->where('scans.is_visible', '=', DB::raw('true'));
            });
        })->whereRaw($whereRaw);
    }

    public function scopeOrderIds(Builder $query, string|array $ids): void
    {
        if (! is_array($ids)) {
            $ids = explode(',', $ids);
        }

        $ids = Arr::map($ids, fn ($id) => trim($id));

        $query->when(! isJoined($query, 'hotlist'), function ($subQuery) {
            $subQuery->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id');
        })->whereIn('hotlist.remote_order_id', $ids);

    }

    public function scopeScannedGte(Builder $query, string $term): void
    {
        $query->when(! isJoined($query, 'scans'), function ($subQuery) {
            $subQuery->join('scans', function ($nestedQuery) {
                $nestedQuery->on('scans.id', '=', 'hits.scan_id');
                $nestedQuery->where('scans.is_visible', '=', DB::raw('true'));
            });
        })->where('scans.scanned_at', '>=', $term);
    }

    public function scopeScannedLte(Builder $query, string $term): void
    {
        $query->when(! isJoined($query, 'scans'), function ($subQuery) {
            $subQuery->join('scans', function ($nestedQuery) {
                $nestedQuery->on('scans.id', '=', 'hits.scan_id');
                $nestedQuery->where('scans.is_visible', '=', DB::raw('true'));
            });
        })->where('scans.scanned_at', '<=', $term);
    }

    public function scopeCreatedGte(Builder $query, string $term): void
    {
        $query->where('hits.created_at', '>=', $term);
    }

    public function scopeCreatedLte(Builder $query, string $term): void
    {
        $query->where('hits.created_at', '<=', $term);
    }

    public function scopeCreatedBetween(Builder $query, array $value): void
    {
        $query->whereBetween('hits.created_at', $value);
    }

    public function scopeTerm(Builder $query, $term): void
    {
        $query->when(! isJoined($query, 'hotlist'), function ($subQuery) {
            $subQuery->join('hotlist', 'hotlist.id', '=', 'hits.hotlist_id');
        })->where(function ($subQuery) use ($term) {
            $subQuery->where('hotlist.plate', '=', $term)
                ->orWhere('hotlist.vin', '=', $term);
        });
    }

    public static function scopeRanked(Builder $query): void
    {
        $query->addSelect([
            'row_number' => DB::raw('row_number() over (partition by hotlist_id order by hits.id desc) as row_number'),
            'rank_total' => DB::raw('row_number() over (partition by hotlist_id order by hits.id asc) as rank_total'),
        ]);
    }

    public function scopeForHitType(Builder $query, string $type): Builder
    {
        return $query->where('hits.hit_type', $type);
    }

    public function scopeForStatus(Builder $query, ?int $status): Builder
    {
        if ($status === null) {
            return $query;
        }

        $enum = HotlistStatusEnum::tryFrom($status);

        if ($enum === HotlistStatusEnum::Other) {
            return $query->whereNotIn('hotlist.status', [
                HotlistStatusEnum::Open->value,
                HotlistStatusEnum::Repossessed->value,
            ]);
        }

        return $query->where('hotlist.status', $status);
    }

    public function scopeForPlate(Builder $query, string $plate, bool $flexSearch = false): Builder
    {
        return PlateFilter::modifyQuery($query, 'scans.ocr', $plate, $flexSearch);
    }

    public function scopeForVin(Builder $query, string $vin): Builder
    {
        return $query->where('hotlist.vin', 'like', '%'.trim($vin).'%');
    }

    public function scopeForOrderId(Builder $query, string $id): Builder
    {
        return $query->where('hotlist.remote_order_id', trim($id));
    }

    public function scopeForKits(Builder $query, array $kitIds): Builder
    {
        return $query->whereIn('kits.id', $kitIds);
    }

    public function scopeForClients(Builder $query, array $ids): Builder
    {
        return $query->whereIntegerInRaw('hotlist.client_org_id', $ids);
    }

    public function scopeForLienholders(Builder $query, array $ids): Builder
    {
        return $query->whereIntegerInRaw('hotlist.lienholder_org_id', $ids);
    }

    public function scopeForDispositions(Builder $query, ?array $dispositions): Builder
    {
        if ($dispositions === null || $dispositions === []) {
            return $query;
        }

        return $query->where(function (Builder $subQuery) use ($dispositions): void {
            if (in_array(DispositionEnum::NEW_HIT->value, $dispositions)) {
                $subQuery->whereNull('scans_metadata.meta->disposition');
            }

            foreach ($dispositions as $disposition) {
                $subQuery->orWhereJsonContains('scans_metadata.meta', [
                    'disposition' => $disposition,
                ]);
            }
        });
    }

    /**
     * Exclude hits with the given disposition (include NULLs).
     * Requires scans_metadata join to exist on the query.
     */
    public function scopeExcludeDisposition(Builder $query, string $disposition): Builder
    {
        return $query->where(fn (Builder $sub) => $sub
            ->whereNull('scans_metadata.meta->disposition')
            ->orWhere('scans_metadata.meta->disposition', '!=', $disposition)
        );
    }

    public function scopeForCreatedBetween(Builder $query, $start = null, $end = null): Builder
    {
        return $query
            ->when($start && $end, fn ($q) => $q->whereBetween('hits.created_at', [convertToUtc($start), convertToUtc($end)]))
            ->when($start && ! $end, fn ($q) => $q->where('hits.created_at', '>=', convertToUtc($start)))
            ->when($end && ! $start, fn ($q) => $q->where('hits.created_at', '<=', convertToUtc($end)));
    }

    public function scopeForScannedBetween(Builder $query, $start = null, $end = null): Builder
    {
        return $query
            ->when($start && $end, fn ($q) => $q->whereBetween('scans.scanned_at', [convertToUtc($start), convertToUtc($end)]))
            ->when($start && ! $end, fn ($q) => $q->where('scans.scanned_at', '>=', convertToUtc($start)))
            ->when($end && ! $start, fn ($q) => $q->where('scans.scanned_at', '<=', convertToUtc($end)));
    }
}
