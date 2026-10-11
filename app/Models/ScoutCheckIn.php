<?php

declare(strict_types=1);

namespace App\Models;

use App\Console\Commands\ScoutRecordCheckIns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single automated check-in: the kit's GPS position landed within the
 * proximity radius of an open (Direct or Staging) order address during the
 * given hour. One row per (kit, org, place, hour, is_staged side), keyed on the
 * address geo — see `scout_check_ins_geo_hour_side_unique`. Recorded by
 * {@see ScoutRecordCheckIns}.
 *
 * @property int $id
 * @property int $kit_id
 * @property int $org_id
 * @property int $hotlist_id
 * @property int $address_id
 * @property bool $is_staged
 * @property Carbon $matched_at
 * @property Carbon $hour_bucket
 * @property float $lat
 * @property float $lng
 * @property ?float $addr_lat
 * @property ?float $addr_lng
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class ScoutCheckIn extends Model
{
    protected $connection = 'platform';

    protected $table = 'scout_check_ins';

    protected $fillable = [
        'kit_id',
        'org_id',
        'hotlist_id',
        'address_id',
        'is_staged',
        'matched_at',
        'hour_bucket',
        'lat',
        'lng',
        'addr_lat',
        'addr_lng',
    ];

    protected function casts(): array
    {
        return [
            'is_staged' => 'boolean',
            'matched_at' => 'datetime',
            'hour_bucket' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'addr_lat' => 'float',
            'addr_lng' => 'float',
        ];
    }
}
