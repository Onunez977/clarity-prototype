<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $hotlist_id
 * @property Carbon $order_date
 * @property Carbon|null $repo_date
 * @property Carbon|null $close_date
 * @property string|null $monthly_payments
 * @property string|null $balance_on_account
 * @property string|null $amount_past_due
 * @property string|null $additional_info
 * @property-read Hotlist|null $hotlist
 * @property mixed $reason
 *
 * @method static \Database\Factories\HotlistMetaFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereAdditionalInfo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereAmountPastDue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereBalanceOnAccount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereCloseDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereHotlistId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereMonthlyPayments($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereOrderDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotlistMeta whereRepoDate($value)
 *
 * @mixin \Eloquent
 */
class HotlistMeta extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    public $timestamps = false;

    public $table = 'hotlist_meta';

    protected $fillable = [
        'additional_info',
        'close_date',
        'order_date',
    ];

    protected $casts = [
        'order_date' => 'date',
        'repo_date' => 'date',
        'close_date' => 'date',
    ];

    public function hotlist(): BelongsTo
    {
        return $this->belongsTo(Hotlist::class, 'hotlist_id', 'id');
    }

    public function reason(): Attribute
    {
        return
            Attribute::make(
                get: function () {
                    return $this->attributes['additional_info'];
                },
                set: function ($value) {
                    $this->attributes['additional_info'] = $value;
                }
            );
    }
}
