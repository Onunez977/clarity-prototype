<?php

namespace App\Models;

use App\Casts\AlertSettingsCast;
use App\Dto\AlertSettingSettingsDto;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property string $alertable_type
 * @property int $alertable_id
 * @property int|null $org_id
 * @property AlertSettingSettingsDto $settings
 * @property string|null $webhook_secret
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|\Eloquent $alertable
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Org|null $org
 *
 * @method static \Database\Factories\AlertSettingFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting whereAlertableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting whereAlertableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting whereOrgId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertSetting withoutTrashed()
 *
 * @mixin \Eloquent
 */
class AlertSetting extends Model
{
    use HasFactory;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    protected $casts = [
        'settings' => AlertSettingsCast::class,
    ];

    protected $fillable = [
        'name',
        'org_id',
        'settings',
        'webhook_secret',
        'alertable_id',
        'alertable_type',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function alertable(): MorphTo
    {
        return $this->morphTo();
    }

    public function routeNotificationForSms($notification = null): Collection
    {
        return collect($this->settings->notifications['sms']);
    }

    public function routeNotificationForTwilio($notification = null): Collection
    {
        return $this->routeNotificationForSms($notification);
    }

    public function routeNotificationForMail($notification = null): Collection
    {
        return collect($this->settings->notifications['emails']);
    }

    public function routeNotificationForSlack($notification = null): Collection
    {
        return collect($this->settings->notifications['slack']);
    }

    public function routeNotificationForWebhook($notification = null): Collection
    {
        return collect([$this->settings->webhook_url])->filter();
    }

    protected static function booted(): void
    {
        static::saved(fn (self $alertSetting) => $alertSetting->flushOwnSubscriberCache());
        static::deleted(fn (self $alertSetting) => $alertSetting->flushOwnSubscriberCache());
        static::restored(fn (self $alertSetting) => $alertSetting->flushOwnSubscriberCache());
    }

    public static function subscriberCacheVersionKey(int $orgId): string
    {
        return "alert_settings:version:{$orgId}";
    }

    public static function flushSubscriberCache(int $orgId): void
    {
        Cache::forever(self::subscriberCacheVersionKey($orgId), (string) Str::ulid());
    }

    public static function subscriberCacheVersion(int $orgId): string
    {
        return Cache::rememberForever(self::subscriberCacheVersionKey($orgId), fn () => (string) Str::ulid());
    }

    public function flushOwnSubscriberCache(): void
    {
        // The reader resolves subscribers by the alertable user's org, so flush that first; org_id is denormalized.
        collect([$this->alertable?->org_id, $this->org_id])
            ->filter()
            ->unique()
            ->each(fn ($orgId) => self::flushSubscriberCache((int) $orgId));
    }

    /**
     * SQL for the jsonb array of text members stored at settings #> '{path}': array elements or object values,
     * keeping only non-empty strings and numbers. Malformed shapes yield '[]' rather than an error.
     *
     * @param  string  $path  Code-constant jsonb path such as 'notifications,emails'; never user input.
     */
    public static function normalizedListSql(string $path): string
    {
        if (! \preg_match('/\A[a-z_]+(,[a-z_]+)*\z/', $path)) {
            throw new \InvalidArgumentException("Invalid settings path [{$path}].");
        }

        $list = "settings #> '{{$path}}'";

        return "(select coalesce(jsonb_agg(to_jsonb(m.v #>> '{}')), '[]'::jsonb) from jsonb_array_elements("
            ."case jsonb_typeof({$list}) "
            ."when 'array' then {$list} "
            ."when 'object' then (select coalesce(jsonb_agg(value), '[]'::jsonb) from jsonb_each({$list})) "
            ."else '[]'::jsonb end"
            .") as m(v) where jsonb_typeof(m.v) in ('string', 'number') and (m.v #>> '{}') <> '')";
    }

    /**
     * Shape-tolerant replacement for whereJsonContains over a normalized list.
     *
     * @param  string  $path  Code-constant jsonb path such as 'notifications,emails'; never user input.
     */
    public function scopeWhereSettingsListContains(Builder $query, string $path, string $value, string $boolean = 'and', bool $caseInsensitive = false): Builder
    {
        $list = self::normalizedListSql($path);

        if ($caseInsensitive) {
            return $query->whereRaw("exists (select 1 from jsonb_array_elements_text({$list}) as c(v) where lower(c.v) = lower(?))", [$value], $boolean);
        }

        return $query->whereRaw($list.' @> ?::jsonb', [\json_encode([$value])], $boolean);
    }

    public static function alertsEmailIsSubscribedTo(string $email): Collection
    {
        return static::query()
            ->whereSettingsListContains('notifications,emails', $email, caseInsensitive: true)
            ->get();
    }

    public static function alertsSmsIsSubscribedTo(string $phone): Collection
    {
        return static::query()
            ->whereSettingsListContains('notifications,sms', \trim(\preg_replace('/\D/', '', $phone)))
            ->get();
    }

    public function removeSms(string $phone): void
    {
        $this->settings->notifications['sms'] = $this->routeNotificationForSms()
            ->filter(fn ($alertPhone) => \trim(\preg_replace('/\D/', '', $alertPhone)) !== \trim(\preg_replace('/\D/', '', $phone)))
            ->values()
            ->toArray();

        $this->saveQuietly();
        $this->flushOwnSubscriberCache();
    }

    public function removeEmail(string $email): void
    {
        $this->settings->notifications['emails'] = $this->routeNotificationForMail()
            ->filter(fn ($alertEmail) => \mb_strtolower($alertEmail) !== \mb_strtolower($email))
            ->values()
            ->toArray();

        $this->saveQuietly();
        $this->flushOwnSubscriberCache();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'settings']);
    }
}
