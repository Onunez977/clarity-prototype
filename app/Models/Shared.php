<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $shared_type
 * @property int $shared_id
 * @property string $shared_with_type
 * @property int $shared_with_id
 * @property Carbon|null $expires_at
 * @property int $status_id
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|\Eloquent $shared
 * @property-read Model|\Eloquent $sharedWith
 *
 * @method static \Database\Factories\SharedFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereSharedId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereSharedType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereSharedWithId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereSharedWithType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereStatusId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shared withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Shared extends Pivot
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'shared';

    public $timestamps = false;

    protected $fillable = [
        'shared_id',
        'shared_type',
        'shared_with_id',
        'shared_with_type',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        self::addGlobalScope('expires', function ($query) {
            $query->where(function ($query) {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
        });
    }
}
