<?php

declare(strict_types=1);

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $gid
 * @property string|null $tzid
 * @property Point|string|null $geom
 * @property string|null $original_geom
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timezone newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timezone newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timezone query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timezone whereGeom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timezone whereGid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timezone whereOriginalGeom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timezone whereTzid($value)
 *
 * @mixin \Eloquent
 */
class Timezone extends Model
{
    protected $table = 'timezones';

    protected $connection = 'zod';

    public $timestamps = false;

    protected array $postgisColumns = [
        'geom' => [
            'type' => 'geometry',
            'srid' => 4326,
        ],
    ];
}
