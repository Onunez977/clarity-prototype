<?php

namespace App\Models;

use App\Enums\HotlistStatusEnum;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;

/**
 * @property int|null $id
 * @property IntegrationEnum|null $integration_id
 * @property string|null $remote_order_id
 * @property HotlistStatusEnum|null $status
 * @property int|null $org_id
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
 * @property Carbon|null $repo_date
 *
 * @method static Builder<static>|Recoveries forOrg($org_id)
 * @method static Builder<static>|Recoveries newModelQuery()
 * @method static Builder<static>|Recoveries newQuery()
 * @method static Builder<static>|Recoveries orderIds(array|string $ids)
 * @method static Builder<static>|Recoveries plateNumber($value)
 * @method static Builder<static>|Recoveries query()
 * @method static Builder<static>|Recoveries recoveryDateBetween($value)
 * @method static Builder<static>|Recoveries vin($value)
 * @method static Builder<static>|Recoveries whereAddressId($value)
 * @method static Builder<static>|Recoveries whereCity($value)
 * @method static Builder<static>|Recoveries whereClientOrgId($value)
 * @method static Builder<static>|Recoveries whereCreatedAt($value)
 * @method static Builder<static>|Recoveries whereGeo($value)
 * @method static Builder<static>|Recoveries whereHouseNumber($value)
 * @method static Builder<static>|Recoveries whereId($value)
 * @method static Builder<static>|Recoveries whereIntegrationId($value)
 * @method static Builder<static>|Recoveries whereIsStaged($value)
 * @method static Builder<static>|Recoveries whereLienholderOrgId($value)
 * @method static Builder<static>|Recoveries whereLookbackDays($value)
 * @method static Builder<static>|Recoveries whereOrderType($value)
 * @method static Builder<static>|Recoveries whereOrgId($value)
 * @method static Builder<static>|Recoveries wherePlate($value)
 * @method static Builder<static>|Recoveries wherePostcode($value)
 * @method static Builder<static>|Recoveries whereRemoteOrderId($value)
 * @method static Builder<static>|Recoveries whereRepoDate($value)
 * @method static Builder<static>|Recoveries whereRoad($value)
 * @method static Builder<static>|Recoveries whereState($value)
 * @method static Builder<static>|Recoveries whereStatus($value)
 * @method static Builder<static>|Recoveries whereUpdatedAt($value)
 * @method static Builder<static>|Recoveries whereUsState($value)
 * @method static Builder<static>|Recoveries whereVehicleMake($value)
 * @method static Builder<static>|Recoveries whereVehicleModel($value)
 * @method static Builder<static>|Recoveries whereVehicleYear($value)
 * @method static Builder<static>|Recoveries whereVin($value)
 *
 * @mixin \Eloquent
 */
class Recoveries extends Model
{
    public $casts = [
        'status' => HotlistStatusEnum::class,
        'integration_id' => IntegrationEnum::class,
        'remote_order_id' => 'string',
        'repo_date' => 'datetime:Y-m-d',
    ];
}
