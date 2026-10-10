<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $timeblock
 * @property int $kit_id
 * @property int $scan_count
 * @property int $id
 * @property string|null $scanned_at
 * @property-read Kit|null $kit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateScanCountUniqueByHour newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateScanCountUniqueByHour newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateScanCountUniqueByHour query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateScanCountUniqueByHour whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateScanCountUniqueByHour whereKitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateScanCountUniqueByHour whereScanCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateScanCountUniqueByHour whereScannedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateScanCountUniqueByHour whereTimeblock($value)
 *
 * @mixin \Eloquent
 */
class AggregateScanCountUniqueByHour extends Model
{
    protected $connection = 'zod';

    public $timestamps = false;

    protected $table = 'aggregate_scan_count_unq_h';

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }
}
