<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScanMetaDataCast;
use App\Dto\ScanMetaDataDto;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $scan_id
 * @property ScanMetaDataDto $meta
 * @property string|null $vehicle_color
 * @property string|null $vehicle_make
 * @property string|null $vehicle_type
 * @property bool|null $is_plate
 * @property string|null $plate_state
 * @property-read Scan|null $scan
 *
 * @method static \Database\Factories\ScanMetaDataFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData whereIsPlate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData wherePlateState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData whereScanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData whereVehicleColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData whereVehicleMake($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanMetaData whereVehicleType($value)
 *
 * @mixin \Eloquent
 */
class ScanMetaData extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    protected $table = 'scans_metadata';

    public $timestamps = false;

    protected $primaryKey = 'scan_id';

    protected $casts = [
        'meta' => ScanMetaDataCast::class,
        'is_plate' => 'boolean',
    ];

    protected $fillable = [
        'scan_id',
        'meta',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'scan_id');
    }
}
