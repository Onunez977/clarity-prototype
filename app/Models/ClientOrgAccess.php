<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_org_id
 * @property int $repo_org_id
 * @property int|null $granted_by_user_id
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Org $clientOrg
 * @property-read Org $repoOrg
 * @property-read User|null $grantedBy
 *
 * @method static \Database\Factories\ClientOrgAccessFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class ClientOrgAccess extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    protected $table = 'platform.client_org_access';

    protected $fillable = [
        'client_org_id',
        'repo_org_id',
        'granted_by_user_id',
        'granted_at',
        'revoked_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        self::addGlobalScope('active', function (Builder $query) {
            $query->whereNull($query->qualifyColumn('revoked_at'));
        });
    }

    public function scopeWithRevoked(Builder $query): Builder
    {
        return $query->withoutGlobalScope('active');
    }

    public function clientOrg(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'client_org_id');
    }

    public function repoOrg(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'repo_org_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
