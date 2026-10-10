<?php

namespace App\Models;

use App\Enums\HotlistStatusEnum;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Datatype\RemoteOrderId;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;

/**
 * @property int $id
 * @property int $recovery_id
 * @property IntegrationEnum|null $integration_id
 * @property string|null $remote_order_id
 * @property HotlistStatusEnum|null $status
 * @property int $org_id
 * @property int|null $client_org_id
 * @property int|null $lienholder_org_id
 * @property int|null $order_type
 * @property bool|null $is_staged
 * @property string|null $plate
 * @property string|null $us_state
 * @property string|null $vin
 * @property string|null $vehicle_year
 * @property string|null $vehicle_make
 * @property string|null $vehicle_model
 * @property int|null $lookback_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $address_id
 * @property string|null $house_number
 * @property string|null $road
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property Point|string|null $geo
 * @property string|null $repo_date
 * @property int|null $kit_id
 * @property int|null $count
 * @property string|null $hotlist_scan_count
 * @property string|null $hotlist_scan_percentage
 * @property-read Org|null $client
 * @property-read Collection<int, Hit> $hits
 * @property-read int|null $hits_count
 * @property-read Kit|null $kit
 * @property-read Org|null $lienholder
 * @property-read Org|null $org
 * @property-read Collection<int, PlateLog> $plate_log
 * @property-read int|null $plate_log_count
 * @property-read mixed $remote_link
 * @property-read mixed $remote_name
 * @property-read Collection<int, Scan> $scans
 * @property-read int|null $scans_count
 *
 * @method static \Database\Factories\RecoveriesKitUserScansReportFactory factory($count = null, $state = [])
 * @method static Builder<static>|RecoveriesKitUserScansReport forOrg($org_id)
 * @method static Builder<static>|RecoveriesKitUserScansReport hotlistId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport kits(array $value)
 * @method static Builder<static>|RecoveriesKitUserScansReport newModelQuery()
 * @method static Builder<static>|RecoveriesKitUserScansReport newQuery()
 * @method static Builder<static>|RecoveriesKitUserScansReport orderIds(array|string $ids)
 * @method static Builder<static>|RecoveriesKitUserScansReport plateNumber(string $value)
 * @method static Builder<static>|RecoveriesKitUserScansReport query()
 * @method static Builder<static>|RecoveriesKitUserScansReport recoveryDateBetween($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport vin(string $value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereAddressId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereCity($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereClientOrgId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereCount($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereCreatedAt($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereGeo($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereHotlistScanCount($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereHotlistScanPercentage($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereHouseNumber($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereIntegrationId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereIsStaged($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereKitId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereLienholderOrgId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereLookbackDays($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereOrderType($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereOrgId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport wherePlate($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport wherePostcode($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereRecoveryId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereRemoteOrderId($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereRepoDate($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereRoad($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereState($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereStatus($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereUpdatedAt($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereUsState($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereVehicleMake($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereVehicleModel($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereVehicleYear($value)
 * @method static Builder<static>|RecoveriesKitUserScansReport whereVin($value)
 *
 * @mixin \Eloquent
 */
class RecoveriesKitUserScansReport extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    protected $primaryKey = 'id';

    public $table = 'aggregate_recoveries_kituser_scans_report';

    public $casts = [
        'status' => HotlistStatusEnum::class,
        'integration_id' => IntegrationEnum::class,
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'client_org_id', 'id');
    }

    public function lienholder(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'lienholder_org_id', 'id');
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class, 'kit_id', 'id');
    }

    public function hotlistMeta(): BelongsTo
    {
        return $this->belongsTo(HotlistMeta::class, 'recovery_id', 'hotlist_id');
    }

    public function plate_log(): HasMany
    {
        return $this->hasMany(PlateLog::class, 'hotlist_id', 'id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(Hit::class, 'hotlist_id', 'id');
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

    public function remoteName(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::replace('_', ' ', IntegrationEnum::tryFrom($this->integration_id)?->name)
        );
    }

    public function remoteLink(): Attribute
    {
        return Attribute::make(
            get: fn () => IntegrationEnum::tryFrom($this->integration_id)?->remoteLink(new RemoteOrderId($this->remote_order_id))
        );
    }

    public function scopeForOrg(Builder $query, $org_id): void
    {
        $query->where($this->getTable().'.org_id', $org_id);
    }

    public function scopeVin(Builder $query, string $value): void
    {
        $query->where('vin', $value);
    }

    public function scopePlateNumber(Builder $query, string $value): void
    {
        $query->where('plate', $value);
    }

    public function scopeKits(Builder $query, array $value): void
    {
        if (! isset($value['ids'])) {
            $value = ['ids' => $value];
        }

        $query->whereIntegerInRaw('kit_id', $value);
    }

    public function scopeOrderIds(Builder $query, string|array $ids): void
    {
        if (! is_array($ids)) {
            $ids = explode(',', $ids);
        }

        $ids = Arr::map($ids, fn ($id) => trim($id));

        $query->whereIn('remote_order_id', $ids);
    }

    public function scopeRecoveryDateBetween(Builder $query, $value): void
    {
        $query->whereBetween('repo_date', $value);
    }

    public function scopeHotlistId(Builder $query, $value): void
    {
        $query->where('id', $value);
    }

    public static function reportAggregateQuery(Builder $query): \Illuminate\Database\Query\Builder
    {
        return DB::connection('zod')
            ->query()
            ->withExpression('dataset', $query)
            ->withExpression('dataset2', function ($subQuery) {
                $subQuery->select('id', DB::raw('sum(dataset.count) as hotlist_scan_count'))
                    ->from('dataset')
                    ->groupBy('id');
            })
            ->selectRaw('
                dataset.id,
                dataset.recovery_id,
                dataset.integration_id,
                dataset.remote_order_id,
                dataset.status,
                dataset.org_id,
                dataset.client_org_id,
                dataset.lienholder_org_id,
                dataset.order_type,
                dataset.is_staged,
                dataset.plate,
                dataset.us_state,
                dataset.vin,
                dataset.vehicle_year,
                dataset.vehicle_make,
                dataset.vehicle_model,
                dataset.lookback_days,
                dataset.created_at,
                dataset.updated_at,
                dataset.address_id,
                dataset.house_number,
                dataset.road,
                dataset.city,
                dataset.state,
                dataset.postcode,
                dataset.geo,
                dataset.repo_date,
                dataset.kit_id,
                dataset.count,
                dataset2.hotlist_scan_count,
                (dataset.count / NULLIF(dataset2.hotlist_scan_count,0)) * 100 as hotlist_scan_percentage'
            )
            ->from('dataset')
            ->join('dataset2', 'dataset.id', '=', 'dataset2.id');
    }
}
