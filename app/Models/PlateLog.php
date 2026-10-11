<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @codeCoverageIgnore
 *
 * @property int $id
 * @property int $hotlist_id
 * @property string $old_plate
 * @property string $old_state
 * @property string $new_plate
 * @property string $new_state
 * @property string $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog whereHotlistId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog whereNewPlate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog whereNewState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog whereOldPlate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlateLog whereOldState($value)
 *
 * @mixin \Eloquent
 */
class PlateLog extends Model
{
    protected $connection = 'zod';

    public $timestamps = false;

    public $table = 'hotlist_plate_log';
}
