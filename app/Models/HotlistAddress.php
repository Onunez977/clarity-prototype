<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $hotlist_id
 * @property int $address_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistAddress newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistAddress newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistAddress query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistAddress whereAddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistAddress whereHotlistId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistAddress whereId($value)
 *
 * @mixin \Eloquent
 */
class HotlistAddress extends Pivot
{
    use HasFactory;

    protected $connection = 'zod';

    public $timestamps = false;

    public $table = 'hotlist_address';
}
