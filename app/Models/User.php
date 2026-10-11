<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeatureEnum;
use App\Models\Concerns\LogsActivity;
use App\Notifications\ScoutOnboardingNotification;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Concerns\HasFeatures;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $email
 * @property int|null $org_id
 * @property-read int|null $kit_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $two_factor_enabled
 * @property array<array-key, mixed>|null $dti
 * @property string|null $remember_token
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $clarity_user_id
 * @property string|null $mobile_phone
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Activity> $causerActivities
 * @property-read int|null $causer_activities_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Kit|null $kit
 * @property-read KitSettings|null $kitSettings
 * @property-read Org|null $org
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read mixed $timezone
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, $guard = null, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereClarityUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDti($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereOrgId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTrashed()
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens;
    use HasFactory;
    use HasFeatures;
    use HasRoles;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'org_id',
        'password',
        'dti',
        'mobile_phone',
        'clarity_user_id',
    ];

    protected $casts = [
        'dti' => 'array',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $with = [
        'org',
    ];

    protected $appends = [
        'timezone',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function kitSettings(): HasOne
    {
        return $this->hasOne(KitSettings::class, 'user_id');
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'zone_user')->withTimestamps();
    }

    public function getKitAttribute(): ?Kit
    {
        return $this->kitSettings?->kit;
    }

    protected function kitId(): Attribute
    {
        return Attribute::get(fn () => $this->kitSettings?->kit_id);
    }

    public function alertable(): MorphMany
    {
        return $this->morphMany(AlertSetting::class, 'alertable');
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public function getMorphClass(): string
    {
        return 'user';
    }

    public function formattedMobilePhone(): Attribute
    {
        return Attribute::make(get: fn () => \trim(\preg_replace('/\D/', '', $this->mobile_phone ?? '')));
    }

    public function timezone(): Attribute
    {
        return Attribute::make(get: fn () => $this->dti['timeZone'] ?? $this->dti['timezone'] ?? 'America/Chicago');
    }

    public function getUserKits(bool $withDeleted = true, bool $withShared = false): Collection
    {
        return Kit::getKits(org_id: $this->org_id, withDeleted: $withDeleted, withShared: $withShared);
    }

    protected function accessibleRepoOrgIds(): Attribute
    {
        return Attribute::make(
            get: function (): array {
                $org = $this->org;

                if (! $org) {
                    return [];
                }

                if ($org->isRepo()) {
                    return [$this->org_id];
                }

                if ($org->isClient()) {
                    return ClientOrgAccess::query()
                        ->where('client_org_id', $this->org_id)
                        ->pluck('repo_org_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                }

                return [];
            },
        )->shouldCache();
    }

    public function isProEnabled(FeatureEnum|string|null $feature = null): bool
    {
        if ($this->hasRole(RoleEnum::CLIENT->value)) {
            return false;
        }

        $check = $this->org->isProEnabled($feature);

        // [LAUNCH 2026] Pro Trial
        if (
            ! $check
            && config('app.env') === 'production'
            && now(tz: $this->timezone)->isBetween('2026-02-01', '2026-04-01')
        ) {
            if (
                ($feature instanceof FeatureEnum && $feature === FeatureEnum::INVITES)
                || $feature === 'invites'
            ) {
                return false;
            }

            return true;
        }

        return $check;
    }

    public function isInsightLpr(): bool
    {
        return Str::endsWith($this->email, '@insightlpr.com');
    }

    /**
     * Whether the current request is an admin impersonating this driver via the
     * Scout force-login token. Exact-membership check (not tokenCan, which the
     * normal token's '*' ability would satisfy). Single source for the auth
     * payload flag and the teammate-overlay data gate — they must not diverge.
     */
    public function isScoutForceImpersonator(): bool
    {
        return in_array('clarity-scout-force', $this->currentAccessToken()?->abilities ?? [], true);
    }

    /**
     * Queue the Scout set-password invite email.
     * Links expire after ScoutOnboardingNotification::$linkTtlDays (3); resend
     * does not revoke a still-valid prior link (accepted product tradeoff).
     */
    public function sendScoutOnboardingNotification(): void
    {
        $this->notify(new ScoutOnboardingNotification);
    }

    /**
     * Soft-delete-aware email collision copy for Scout/Users forms (DEV-2593).
     * Returns null when the email is free to use within the acting org.
     * Out-of-org collisions stay generic (no cross-tenant name leak).
     *
     * @param  bool  $scoutContext  When false, avoid Scout-only guidance (Users resource).
     */
    public static function scoutEmailCollisionMessage(
        string $email,
        ?int $ignoreUserId = null,
        ?int $orgId = null,
        bool $scoutContext = true,
    ): ?string {
        $orgId ??= auth()->user()?->org_id;

        $collision = static::withTrashed()
            ->where('email', $email)
            ->when($ignoreUserId, fn ($query) => $query->where('id', '!=', $ignoreUserId))
            ->when($orgId, fn ($query) => $query->where('org_id', $orgId))
            ->orderBy('id')
            ->first();

        if (! $collision) {
            $foreignExists = static::withTrashed()
                ->where('email', $email)
                ->when($ignoreUserId, fn ($query) => $query->where('id', '!=', $ignoreUserId))
                ->exists();

            return $foreignExists ? 'This email address is already in use.' : null;
        }

        $label = $collision->name ?: $collision->email ?: ('user #'.$collision->id);

        if ($collision->trashed()) {
            return $scoutContext
                ? "This email belongs to {$label} (deleted). Restore that user from Scout Users or Users, then re-enable Scout access — or use a different email."
                : "This email belongs to {$label} (deleted). Restore that user from Users, or use a different email.";
        }

        if (! $collision->email_verified_at) {
            return $scoutContext
                ? "This email belongs to {$label}. Resend the Scout invite from Scout Users, or use a different email."
                : "This email belongs to {$label}. That account already exists — edit it instead, or use a different email.";
        }

        return $scoutContext
            ? "This email belongs to {$label}. Re-enable Scout access from Scout Users if it was disabled, or use a different email."
            : "This email belongs to {$label}. That account already exists — edit it instead, or use a different email.";
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->org_id || ! $this->org) {
            return false;
        }

        return match ($panel->getId()) {
            'client' => $this->org->isClient()
                && $this->org->isClientPortalEnabled()
                && $this->accessible_repo_org_ids !== [],
            default => $this->org->isRepo()
                && $this->org->enable_genetech_hotlist,
        };
    }

    public function getFilamentName(): string
    {
        return $this->name ?? $this->username ?? $this->email ?? 'No Name (ID: '.$this->id.')';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['username', 'two_factor_enabled']);
    }
}
