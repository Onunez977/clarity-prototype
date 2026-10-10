<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ScanFilterable;
use App\Enums\HitTypeEnum;
use App\Filament\Tables\Filters\PlateFilter;
use App\Jobs\SyncDispositionToClickhouse;
use App\Models\Concerns\ClustersByLocation;
use App\Models\Scopes\ScanTimezoneScope;
use App\Models\Scopes\ShowVisibleScan;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Database\Eloquent\HasPostgisColumns;
use Clickbar\Magellan\Database\PostgisFunctions\ST;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;

/**
 * @property int $id
 * @property string $ocr
 * @property string $us_state
 * @property string $geo
 * @property int $kit_id
 * @property bool $is_visible
 * @property Carbon $scanned_at
 * @property Carbon $created_at
 * @property string $camera_name
 * @property string $guid
 * @property array<array-key, mixed>|null $meta
 * @property-read mixed $address
 * @property-read mixed $ai_state
 * @property-read mixed $car_image
 * @property-read mixed $car_image_url
 * @property-read mixed $coordinates
 * @property-read mixed $disposition
 * @property-read Attribute $images
 * @property-read Collection<int, Hit> $historicalHits
 * @property-read int|null $historical_hits_count
 * @property-read Collection<int, Hit> $hits
 * @property-read int|null $hits_count
 * @property-read Hotlist|null $hotlist
 * @property-read mixed $is_not_plate
 * @property-read Kit|null $kit
 * @property-read mixed $lat
 * @property-read mixed $latitude
 * @property-read Collection<int, Hit> $liveHits
 * @property-read int|null $live_hits_count
 * @property-read mixed $lng
 * @property-read mixed $longitude
 * @property-read Org|null $org
 * @property-read mixed $plate_image
 * @property-read mixed $plate_image_url
 * @property-read mixed $scan_date
 * @property-read ScanMetaData|null $scanMetaData
 * @property-read mixed $simple_address
 * @property-read Collection<int, Hit> $stagingHistoricalHits
 * @property-read int|null $staging_historical_hits_count
 * @property-read Collection<int, Hit> $stagingLiveHits
 * @property-read int|null $staging_live_hits_count
 * @property-read mixed $vehicle
 * @property-read mixed $vehicle_color
 * @property-read mixed $vehicle_image
 * @property-read mixed $vehicle_make
 * @property-read mixed $vehicle_type
 *
 * @method static Builder<static>|Scan coordinates(array $coordinates)
 * @method static \Database\Factories\ScanFactory factory($count = null, $state = [])
 * @method static Builder<static>|Scan forOrg(int|array $org_id, bool $withSharedKits = false)
 * @method static Builder<static>|Scan newModelQuery()
 * @method static Builder<static>|Scan newQuery()
 * @method static Builder<static>|Scan query()
 * @method static Builder<static>|Scan scanTimezone()
 * @method static Builder<static>|Scan visible()
 * @method static Builder<static>|Scan whereCameraName($value)
 * @method static Builder<static>|Scan whereCreatedAt($value)
 * @method static Builder<static>|Scan whereGeo($value)
 * @method static Builder<static>|Scan whereGuid($value)
 * @method static Builder<static>|Scan whereId($value)
 * @method static Builder<static>|Scan whereIsVisible($value)
 * @method static Builder<static>|Scan whereKitId($value)
 * @method static Builder<static>|Scan whereMeta($value)
 * @method static Builder<static>|Scan whereOcr($value)
 * @method static Builder<static>|Scan whereScannedAt($value)
 * @method static Builder<static>|Scan whereUsState($value)
 *
 * @mixin \Eloquent
 */
class Scan extends Model implements ScanFilterable
{
    use ClustersByLocation;
    use HasFactory;
    use HasPostgisColumns;

    protected $connection = 'zod';

    protected $table = 'scans';

    public $timestamps = false;

    protected $casts = [
        'meta' => 'array',
        'is_visible' => 'boolean',
        'scanned_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected $fillable = [
        'ocr',
        'us_state',
        'geo',
        'kit_id',
        'is_visible',
        'scanned_at',
        'camera_name',
        'guid',
        'meta',
        'created_at',
    ];

    protected $appends = [
        'lat',
        'lng',
    ];

    protected array $postgisColumns = [
        'geo' => [
            'type' => 'geometry',
            'srid' => 4326,
        ],
    ];

    public static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new ShowVisibleScan);
        static::addGlobalScope(new ScanTimezoneScope);
    }

    public function scanMetaData(): HasOne
    {
        return $this->hasOne(ScanMetaData::class, 'scan_id', 'id');
    }

    public function org(): HasOneThrough
    {
        return $this->hasOneThrough(
            Org::class,
            Kit::class,
            'id',
            'id',
            'kit_id',
            'org_id'
        );
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class, 'kit_id', 'id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(Hit::class, 'scan_id', 'id');
    }

    public function liveHits(): HasMany
    {
        return $this->hits()->where('hit_type', HitTypeEnum::Live->value);
    }

    public function historicalHits(): HasMany
    {
        return $this->hits()->where('hit_type', HitTypeEnum::Historical->value);
    }

    public function stagingLiveHits(): HasMany
    {
        return $this->hits()->where('hit_type', HitTypeEnum::Staging_Live->value);
    }

    public function stagingHistoricalHits(): HasMany
    {
        return $this->hits()->where('hit_type', HitTypeEnum::Staging_Historical->value);
    }

    public function hotlist(): HasOneThrough
    {
        return $this->hasOneThrough(
            Hotlist::class,
            Hit::class,
            'scan_id',
            'id',
            'id',
            'hotlist_id'
        );
    }

    public function scannedAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->setTimezone($this->timezone ?? 'UTC'),
        );
    }

    protected function createdAt(): Attribute
    {
        return new Attribute(
            get: function ($value) {
                $temp = explode('.', (string) $value);
                // If created_at had milliseconds the array count would be 2
                if (count($temp) == 2) {
                    unset($temp[count($temp) - 1]);
                    // remove the millisecond part
                } else {
                    $temp = [$value];
                    // created_at didnt have milliseconds set it back to original
                }

                return Carbon::parse(implode('.', $temp))->utc();
            },
        );
    }

    public function coordinates(): Attribute
    {
        return Attribute::make(get: fn () => [
            'latitude' => $this->geo->getLatitude(),
            'longitude' => $this->geo->getLongitude(),
        ]);
    }

    public function lat(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['lat'] ?? $this->geo?->getY()
        );
    }

    public function latitude(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['lat'] ?? $this->geo?->getLatitude()
        );
    }

    public function longitude(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['lng'] ?? $this->geo?->getLongitude()
        );
    }

    public function lng(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['lng'] ?? $this->geo?->getX()
        );
    }

    public function vehicle(): Attribute
    {
        return new Attribute(
            get: fn () => $this->hotlist->vehicle ?? \trim($this->vehicle_make.' '.$this->vehicle_color.' '.$this->vehicle_type) ?? '',
        );
    }

    public function vehicleColor(): Attribute
    {
        return new Attribute(
            get: fn () => $this->scanMetaData?->meta->vehicle?->color ?? null,
        );
    }

    public function vehicleType(): Attribute
    {
        return new Attribute(
            get: fn () => $this->scanMetaData?->meta->vehicle?->type ?? null,
        );
    }

    public function vehicleMake(): Attribute
    {
        return new Attribute(
            get: fn () => $this->scanMetaData?->meta->vehicle?->make ?? null,
        );
    }

    public function plateImage(): Attribute
    {
        return new Attribute(
            get: fn () => scan_img($this->guid, VehicleImageTypeEnum::Plate->value),
        );
    }

    public function plateImageUrl(): Attribute
    {
        return $this->plateImage();
    }

    public function vehicleImage(): Attribute
    {
        return new Attribute(
            get: fn () => scan_img($this->guid, VehicleImageTypeEnum::Car->value),
        );
    }

    public function carImageUrl(): Attribute
    {
        return $this->vehicleImage();
    }

    public function getImagesAttribute(): Attribute
    {
        return new Attribute(
            get: fn () => [
                $this->vehicleImage,
                $this->plateImage,
            ],
        );
    }

    public function carImage(): Attribute
    {
        return new Attribute(
            get: fn () => $this->vehicleImage,
        );
    }

    public function isNotPlate(): Attribute
    {
        return new Attribute(
            get: fn () => (bool) $this->scanMetaData?->meta->notPlate ?? false,
        );
    }

    public function aiState(): Attribute
    {
        return new Attribute(
            get: fn () => $this->scanMetaData?->meta->plateState ?? null,
        );
    }

    public function scanDate(): Attribute
    {
        return new Attribute(
            get: fn () => $this->scannedAt,
        );
    }

    public function address(): Attribute
    {
        return new Attribute(
            get: fn () => $this->scanMetaData?->meta->address ?? null,
        );
    }

    public function simpleAddress(): Attribute
    {
        return new Attribute(
            get: fn () => $this->scanMetaData?->meta->simpleAddress ?? $this->scanMetaData?->meta->address ?? null,
        );
    }

    public function disposition(): Attribute
    {
        return new Attribute(
            get: fn () => $this->scanMetaData?->meta->disposition ?? null,
        );
    }

    public function updateOrCreateMetadata(): ScanMetaData
    {
        $this->loadMissing('scanMetaData');

        if ($this->scanMetaData) {
            return $this->scanMetaData;
        }

        return ScanMetaData::firstOrCreate(
            ['scan_id' => $this->id],
            ['meta' => []]
        );
    }

    public static function scanCountByKitNameAggregate(Carbon $from): Builder
    {
        return Scan::query()
            ->withoutGlobalScope(ScanTimezoneScope::class)
            ->selectRaw('count(*) as count, kits.name, kits.id')
            ->join('kits', 'kits.id', '=', 'scans.kit_id')
            ->where('scans.created_at', '>=', $from)
            ->groupBy('kits.name')
            ->groupBy('kits.id');
    }

    public function scopeScanTimezone(Builder $query): void
    {
        $columns = Arr::wrap($query->getQuery()->columns);
        if (empty($columns)) {
            $query->select(['scans.id as id', 'scans.*', 'timezones.tzid as timezone']);
        } else {
            $query->select($columns, 'timezones.tzid as timezone');
        }

        //        $builder->selectRaw('case when timezones.tzid is null then
        //	scans.scanned_at at time zone \'America/Chicago\' at time zone \'utc\'
        //	 else
        //	scans.scanned_at at time zone tzid at time zone \'utc\'
        //	 end as scanned_at_utc');

        $query->leftJoin('timezones', function (JoinClause $clause) {
            $clause->on(DB::raw('ST_Within(scans.geo, timezones.geom)'), '=', DB::raw('TRUE'));
        });
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', DB::raw('true'));
    }

    public function scopeForOrg(Builder $query, int|array $org_id, bool $withSharedKits = false): void
    {
        $kitIds = collect(Arr::wrap($org_id))
            ->flatMap(fn (int $id) => Kit::getKits($id, withShared: $withSharedKits)->pluck('id'))
            ->unique()
            ->values();

        $query->whereIntegerInRaw('kit_id', $kitIds);
    }

    public function scopeCoordinates(Builder $query, array $coordinates): void
    {
        $lat = $coordinates['latitude'];
        $lng = $coordinates['longitude'];
        $point = Point::makeGeodetic($lat, $lng);

        $query->stWhere(ST::dWithinGeometry('scans.geo', $point, .0002), DB::raw('true'));

        // return $builder->stWhere(ST::distanceSphere('scans.geo',$point), '<=', 30);
    }

    /**
     * Delegates to PlateFilter::modifyQuery() which handles LIKE + optional FlexSearch variants.
     */
    public function scopeForPlate(Builder $query, string $plate, bool $flexSearch = false): Builder
    {
        return PlateFilter::modifyQuery(
            query: $query,
            column: 'ocr',
            plate: $plate,
            flexSearch: $flexSearch,
        );
    }

    /**
     * Requires scans_metadata join to exist on the query.
     */
    public function scopeForDisposition(Builder $query, string $disposition): Builder
    {
        return $query->whereJsonContains('scans_metadata.meta', [
            'disposition' => $disposition,
        ]);
    }

    /**
     * Exclude scans with the given disposition (include NULLs).
     * Requires scans_metadata join to exist on the query.
     */
    public function scopeExcludeDisposition(Builder $query, string $disposition): Builder
    {
        return $query->where(fn (Builder $sub) => $sub
            ->whereNull('scans_metadata.meta->disposition')
            ->orWhere('scans_metadata.meta->disposition', '!=', $disposition)
        );
    }

    public function scopeBetween(Builder $query, $start = null, $end = null, ?string $timezone = null): Builder
    {
        return $query
            ->when($start, fn (Builder $q) => $q->where('scanned_at', '>=', convertToUtc($start)))
            ->when($end, fn (Builder $q) => $q->where('scanned_at', '<=', convertToUtc($end)));
    }

    public function meta_color(): ?string
    {
        return $this->vehicle_color;
    }

    public function getMorphClass(): string
    {
        return 'scan';
    }

    public function isCommercial(): bool
    {
        return ! $this->is_shared && $this->kit->org_id !== auth()->user()->org_id;
    }

    public function updateDisposition(string $disposition, $user, ?string $reason): void
    {
        $metadata = $this->updateOrCreateMetadata();
        $meta = json_decode($metadata->getRawOriginal('meta') ?? '{}', true);
        $meta['disposition'] = $disposition;

        $metadata->updateQuietly(['meta' => $meta]);

        $this->syncDispositionToClickhouse($disposition);

        activity('Disposition')
            ->performedOn($this)
            ->causedBy($user)
            ->withProperties([
                'scan_id' => $this->id,
                'disposition' => $disposition,
                'reason' => $reason ?? 'N/A',
            ])
            ->log('Scan disposition updated');

        // If Disposition reason is anything other than "Other,"
        // add to Scans Validation for human review
        if ($reason !== 'other') {
            try {
                $existingValidation = ScanValidation::query()
                    ->where('scan_id', $this->id)
                    ->first();

                if (! $existingValidation) {
                    // create
                    $validation = ScanValidation::create([
                        'scan_id' => $this->id,
                        'source' => 4,
                        'batch' => 'platform',
                        'not_a_plate' => $reason === 'not a plate',
                        'not_readable' => $reason === 'plate too blurry',
                    ]);

                    activity('Scan Validation')
                        ->performedOn($validation)
                        ->causedBy($user)
                        ->withProperties([
                            'scan_id' => $this->id,
                            'source' => 4,
                            'batch' => 'platform',
                            'not_a_plate' => $reason === 'not a plate',
                            'not_readable' => $reason === 'plate too blurry',
                        ])
                        ->log('Scan validation created');
                } elseif ($existingValidation->validated_at === null) {
                    // update
                    $existingValidation->update([
                        'source' => 4,
                        'batch' => 'platform',
                        'not_a_plate' => $reason === 'not a plate',
                        'not_readable' => $reason === 'plate too blurry',
                    ]);

                    activity('Scan Validation')
                        ->performedOn($existingValidation)
                        ->causedBy($user)
                        ->withProperties([
                            'scan_id' => $this->id,
                            'source' => 4,
                            'batch' => 'platform',
                            'not_a_plate' => $reason === 'not a plate',
                            'not_readable' => $reason === 'plate too blurry',
                        ])
                        ->log('Scan validation updated');
                }
            } catch (\Exception $exception) {
                //
            }
        }
    }

    private function syncDispositionToClickhouse(string $disposition): void
    {
        SyncDispositionToClickhouse::dispatch($this->id, $disposition);
    }

    public function updateAddress(string $address, ?string $simpleAddress = null): void
    {
        $metadata = $this->updateOrCreateMetadata();
        $meta = json_decode($metadata->getRawOriginal('meta') ?? '{}', true);
        $changed = false;

        if (($meta['address'] ?? null) !== $address) {
            $meta['address'] = $address;
            $changed = true;
        }

        if ($simpleAddress && ($meta['simpleAddress'] ?? null) !== $simpleAddress) {
            $meta['simpleAddress'] = $simpleAddress;
            $changed = true;
        }

        if ($changed) {
            $metadata->updateQuietly(['meta' => $meta]);
        }
    }
}
