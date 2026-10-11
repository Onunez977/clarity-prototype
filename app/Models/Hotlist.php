<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HotlistFilterable;
use App\Enums\HotlistStatusEnum;
use App\Filament\Tables\Filters\PlateFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Datatype\RemoteOrderId;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;

/**
 * @property int $id
 * @property int $integration_id
 * @property string|null $remote_order_id
 * @property HotlistStatusEnum $status
 * @property int $org_id
 * @property int $client_org_id
 * @property int|null $lienholder_org_id
 * @property int $order_type
 * @property bool $is_staged
 * @property string $plate
 * @property string $us_state
 * @property string $vin
 * @property string $vehicle_year
 * @property string $vehicle_make
 * @property string $vehicle_model
 * @property int $lookback_days
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Address> $addresses
 * @property-read int|null $addresses_count
 * @property-read Collection<int, HotlistAddress> $addresspivot
 * @property-read int|null $addresspivot_count
 * @property-read Org|null $client
 * @property-read string $ymm
 * @property-read Collection<int, Hit> $hits
 * @property-read int|null $hits_count
 * @property-read Integrations|null $integration
 * @property-read Org|null $lienholder
 * @property-read HotlistMeta|null $meta
 * @property-read Org|null $org
 * @property-read Collection<int, PlateLog> $plate_log
 * @property-read int|null $plate_log_count
 * @property-read mixed $reason
 * @property-read mixed $remote_link
 * @property-read mixed $remote_name
 * @property-read Collection<int, Scan> $scans
 * @property-read int|null $scans_count
 * @property-read mixed $vehicle
 *
 * @method static Builder<static>|Hotlist createdBetween(?array $value = [])
 * @method static \Database\Factories\HotlistFactory factory($count = null, $state = [])
 * @method static Builder<static>|Hotlist forOrg(int $org_id)
 * @method static Builder<static>|Hotlist hasPlate(bool $has_plate = true)
 * @method static Builder<static>|Hotlist newModelQuery()
 * @method static Builder<static>|Hotlist newQuery()
 * @method static Builder<static>|Hotlist open()
 * @method static Builder<static>|Hotlist orderIds(?mixed $ids = null)
 * @method static Builder<static>|Hotlist plateNumber($plate_number)
 * @method static Builder<static>|Hotlist query()
 * @method static Builder<static>|Hotlist staged()
 * @method static Builder<static>|Hotlist status(\App\Enums\HotlistStatusEnum|int $status)
 * @method static Builder<static>|Hotlist vin(?string $vin)
 * @method static Builder<static>|Hotlist vl6(string $vl6)
 * @method static Builder<static>|Hotlist whereClientOrgId($value)
 * @method static Builder<static>|Hotlist whereCreatedAt($value)
 * @method static Builder<static>|Hotlist whereId($value)
 * @method static Builder<static>|Hotlist whereIntegrationId($value)
 * @method static Builder<static>|Hotlist whereIsStaged($value)
 * @method static Builder<static>|Hotlist whereLienholderOrgId($value)
 * @method static Builder<static>|Hotlist whereLookbackDays($value)
 * @method static Builder<static>|Hotlist whereOrderType($value)
 * @method static Builder<static>|Hotlist whereOrgId($value)
 * @method static Builder<static>|Hotlist wherePlate($value)
 * @method static Builder<static>|Hotlist whereRemoteOrderId($value)
 * @method static Builder<static>|Hotlist whereStatus($value)
 * @method static Builder<static>|Hotlist whereUpdatedAt($value)
 * @method static Builder<static>|Hotlist whereUsState($value)
 * @method static Builder<static>|Hotlist whereVehicleMake($value)
 * @method static Builder<static>|Hotlist whereVehicleModel($value)
 * @method static Builder<static>|Hotlist whereVehicleYear($value)
 * @method static Builder<static>|Hotlist whereVin($value)
 *
 * @mixin \Eloquent
 */
class Hotlist extends Model implements HotlistFilterable
{
    use HasFactory;

    protected $connection = 'zod';

    public $timestamps = true;

    public $table = 'hotlist';

    protected $fillable = [
        'remote_order_id',
        'status',
        'plate',
        'us_state',
        'vin',
        'vehicle_year',
        'vehicle_make',
        'vehicle_model',
        'integration_id',
        'org_id',
        'additional_info',
    ];

    public $casts = [
        'status' => HotlistStatusEnum::class,
        'remote_order_id' => 'string',
    ];

    public static function boot(): void
    {
        parent::boot();
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'org_id', 'id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integrations::class, 'integration_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'client_org_id', 'id');
    }

    public function lienholder(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'lienholder_org_id', 'id');
    }

    public function plate_log(): HasMany
    {
        return $this->hasMany(PlateLog::class, 'hotlist_id', 'id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(Hit::class, 'hotlist_id', 'id');
    }

    public function meta(): HasOne
    {
        return $this->hasOne(HotlistMeta::class, 'hotlist_id', 'id');
    }

    public function hotlistJson(): HasOne
    {
        return $this->hasOne(HotlistJson::class, 'hotlist_id', 'id');
    }

    public function addresses(): HasManyThrough
    {
        return $this->hasManyThrough(Address::class, HotlistAddress::class, 'hotlist_id', 'id', 'id', 'address_id');
    }

    public function addresspivot(): HasMany
    {
        return $this->hasMany(HotlistAddress::class, 'hotlist_id', 'id');
    }

    public function scans(): HasManyThrough
    {
        return $this->hasManyThrough(
            Scan::class,
            Hit::class,
            'hotlist_id',
            'id',
            'id',
            'scan_id'
        );
    }

    public function vehicle(): Attribute
    {
        return Attribute::make(
            get: fn () => \trim(\html_entity_decode($this->vehicle_year.' '.$this->vehicle_make.' '.$this->vehicle_model))
        );
    }

    public function status(): Attribute
    {
        return Attribute::make(get: fn () => HotlistStatusEnum::tryFrom($this->attributes['status'])?->value ?? HotlistStatusEnum::Other->value);
    }

    public function reason(): Attribute
    {
        $this->loadMissing('meta');

        return Attribute::make(
            get: fn () => $this->meta?->additional_info
        );
    }

    public function remoteName(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::replace('_', ' ', IntegrationEnum::tryFrom($this->attributes['integration_id'])?->name)
        );
    }

    public function remoteLink(): Attribute
    {
        return Attribute::make(
            get: fn () => IntegrationEnum::tryFrom($this->attributes['integration_id'])?->remoteLink(new RemoteOrderId($this->attributes['remote_order_id']))
        );
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('hotlist.status', HotlistStatusEnum::Open->value);
    }

    public function scopeStaged(Builder $query): void
    {
        // we need to remove the HotlistForOrg scope here because, for staging assignments, it doesn't apply
        $query->where('hotlist.is_staged', '=', true);
    }

    public function scopeForOrg(Builder $query, int $org_id): void
    {
        $query->where('org_id', $org_id);
    }

    public function scopeVin(Builder $query, ?string $vin): void
    {
        if (! empty($vin)) {
            $vins = with(explode('|', $vin), function ($vins) {
                return array_map(function ($vin) {
                    return Str::upper(trim($vin));
                }, $vins);
            });

            if (count($vins) === 1 && Str::length($vin[0]) === 6) {
                $query->vl6($vins[0]);
            } else {
                $query->whereIn('hotlist.vin', $vins);
            }
        }
    }

    public function scopeVl6(Builder $query, string $vl6): void
    {
        $query->where('hotlist.vin', 'like', '%'.Str::upper($vl6));
    }

    public function scopeCreatedBetween(Builder $query, ?array $value = []): void
    {
        if (empty($value) || count($value) !== 2) {
            //
        } else {
            $query->whereBetween('hotlist.created_at', $value);
        }
    }

    public function scopePlateNumber(Builder $query, $plate_number): void
    {
        if (! empty($plate_number)) {
            $plate_number = with(explode('|', $plate_number), function ($plate_numbers) {
                return array_map(function ($plate_number) {
                    return Str::upper(trim($plate_number));
                }, $plate_numbers);
            });

            $query->whereIn('hotlist.plate', $plate_number);
        }
    }

    public function scopeStatus(Builder $query, int|HotlistStatusEnum $status): void
    {
        $status = is_int($status) ? HotlistStatusEnum::tryFrom($status) : $status;

        $query->when($status === HotlistStatusEnum::Other, function ($subQuery) {
            return $subQuery->whereNotIn('hotlist.status', [
                HotlistStatusEnum::Open->value,
                HotlistStatusEnum::Repossessed->value,
            ]);
        })->when($status !== HotlistStatusEnum::Other, function ($subQuery) use ($status) {
            return $subQuery->where('hotlist.status', $status->value);
        });

    }

    public function scopeOrderIds(Builder $query, mixed $ids = null): void
    {
        if (! empty($ids)) {
            if (! is_array($ids)) {
                $ids = explode(',', $ids);
            }

            $ids = Arr::map($ids, fn ($id) => trim($id));

            $query->whereIn('hotlist.remote_order_id', $ids);
        }
    }

    public function scopeHasPlate(Builder $query, bool $has_plate = true): void
    {
        $query->when(
            $has_plate,
            fn ($subQuery) => $subQuery->where(fn ($nestedQuery) => $nestedQuery
                ->whereNotNull('plate')
                ->where('plate', '!=', '')
            ),
            fn ($subQuery) => $subQuery->where(fn ($nestedQuery) => $nestedQuery
                ->whereNull('plate')
                ->orWhere('plate', '=', '')
            )
        );
    }

    public function getYmmAttribute(): string
    {
        return $this->vehicle;
    }

    public function scopeForStatus(Builder $query, int $status): Builder
    {
        $query->status($status);

        return $query;
    }

    public function scopeForVin(Builder $query, string $vin): Builder
    {
        return $query->where('vin', 'like', '%'.trim($vin).'%');
    }

    public function scopeForPlate(Builder $query, string $plate, bool $flexSearch = false): Builder
    {
        return PlateFilter::modifyQuery(
            query: $query,
            column: 'plate',
            plate: $plate,
            flexSearch: $flexSearch,
        );
    }

    public function scopeForOrderId(Builder $query, string $orderId): Builder
    {
        return $query->where('remote_order_id', trim($orderId));
    }

    public function scopeForClients(Builder $query, array $clientIds): Builder
    {
        return $query->whereIntegerInRaw('client_org_id', $clientIds);
    }

    public function scopeMissingPlate(Builder $query): Builder
    {
        return $query->where('plate', '');
    }
}
