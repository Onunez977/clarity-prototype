<?php

namespace App\Models;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property int $user_id
 * @property string|null $invitable_type
 * @property int|null $invitable_id
 * @property array<array-key, mixed>|null $meta
 * @property Carbon|null $accepted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $notification_sent_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|\Eloquent|null $invitable
 * @property-read User $user
 *
 * @method static \Database\Factories\InviteFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereAcceptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereInvitableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereInvitableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Invite extends Model
{
    use HasFactory;

    protected $table = 'invites';

    protected $fillable = [
        'type',
        'user_id',
        'invitable_id',
        'invitable_type',
        'meta',
        'accepted_at',
        'approved_at',
        'notification_sent_at',
        'expires_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'accepted_at' => 'datetime',
        'approved_at' => 'datetime',
        'notification_sent_at' => 'datetime',
        'expires_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitable(): MorphTo
    {
        return $this->morphTo();
    }

    public function accept(): void
    {
        $this->update(['accepted_at' => now()]);

        Notification::make()
            ->title('Invite Accepted')
            ->success()
            ->send();
    }

    public function decline(): void
    {
        $this->update(['expires_at' => now()]);

        Notification::make()
            ->title('Invite Declined')
            ->warning()
            ->send();
    }
}
