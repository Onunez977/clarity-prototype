<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $org_id
 * @property int|null $client_org_id
 * @property Carbon $hour_timestamp
 * @property string|null $branch_id
 * @property int|null $order_type
 * @property int $hotlist_count
 * @property-read Org $org
 * @property-read Branch|null $branch
 * @property-read Org|null $clientOrg
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour whereBranchId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour whereClientOrgId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour whereHotlistCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour whereHourTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour whereOrderType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AggregateRdnHotlistCountByHour whereOrgId($value)
 *
 * @mixin \Eloquent
 *
 * @codeCoverageIgnore
 */
class AggregateRdnHotlistCountByHour extends Model
{
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'aggregate_rdn_hotlist_count_by_hour';

    protected $primaryKey = null;

    protected $fillable = [
        'org_id',
        'client_org_id',
        'hour_timestamp',
        'branch_id',
        'order_type',
        'hotlist_count',
    ];

    protected $casts = [
        'hour_timestamp' => 'datetime',
        'org_id' => 'integer',
        'client_org_id' => 'integer',
        'order_type' => 'integer',
        'hotlist_count' => 'integer',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'org_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function clientOrg(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'client_org_id');
    }
}
