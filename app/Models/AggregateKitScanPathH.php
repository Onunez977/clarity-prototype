<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Database\Eloquent\HasPostgisColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $kit_id
 * @property Point|string|null $geo
 * @property string|null $timestamp
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateKitScanPathH newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateKitScanPathH newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateKitScanPathH query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateKitScanPathH whereGeo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateKitScanPathH whereKitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateKitScanPathH whereTimestamp($value)
 *
 * @mixin \Eloquent
 */
class AggregateKitScanPathH extends Model
{
    use HasPostgisColumns;

    protected $connection = 'zod';

    protected $primaryKey = ['kit_id', 'timestamp'];

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'aggregate_kit_scan_path_h';

    protected array $postgisColumns = [
        'geo' => [
            'type' => 'geometry',
            'srid' => 4326,
        ],
    ];

    public function getIncrementing(): false
    {
        return false;
    }
}
