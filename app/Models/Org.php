<?php

declare(strict_types=1);

namespace App\Models;

use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Enums\FeatureEnum;
use App\Enums\ProTierEnum;
use App\Services\RDN;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\OrgType;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;

/**
 * @property int $id
 * @property string $name
 * @property int|null $address_id
 * @property int $type
 * @property string|null $rdn_id
 * @property string|null $rdn_api_key
 * @property string|null $irepo_id
 * @property bool $enable_genetech_hotlist
 * @property bool $enable_matrix_hotlist
 * @property bool $silent_hit_generation
 * @property string|null $ls_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Hotlist> $hotlists
 * @property-read int|null $hotlists_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Kit> $kits
 * @property-read int|null $kits_count
 * @property-read OrgMeta|null $meta
 * @property-read ScoutOrgSettings|null $scoutOrgSettings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Scan> $scans
 * @property-read int|null $scans_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org active()
 * @method static \Database\Factories\OrgFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org isHotlistEnabled()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereAddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereEnableGenetechHotlist($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereEnableMatrixHotlist($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereIrepoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereLsId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereRdnApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereRdnId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereSilentHitGeneration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Org whereType($value)
 *
 * @mixin \Eloquent
 */
class Org extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    public $timestamps = false;

    public $fillable = [
        'name',
        'enable_genetech_hotlist',
        'enable_matrix_hotlist',
    ];

    protected $casts = [
        'silent_hit_generation' => 'bool',
        'enable_genetech_hotlist' => 'bool',
        'enable_matrix_hotlist' => 'bool',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hotlists(): HasMany
    {
        return $this->hasMany(Hotlist::class);
    }

    public function kits(): HasMany
    {
        return $this->hasMany(Kit::class);
    }

    public function scans(): HasManyThrough
    {
        return $this->hasManyThrough(Scan::class, Kit::class, 'org_id', 'kit_id');
    }

    public function meta(): HasOne
    {
        return $this->hasOne(OrgMeta::class, 'id', 'id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function runArea(): HasOne
    {
        return $this->hasOne(OrgRunArea::class);
    }

    public function scoutOrgSettings(): HasOne
    {
        return $this->hasOne(ScoutOrgSettings::class, 'org_id');
    }

    public function repoOrgsAccessible(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'platform.client_org_access',
            'client_org_id',
            'repo_org_id',
        )->wherePivotNull('revoked_at');
    }

    public function clientOrgsWithAccess(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'platform.client_org_access',
            'repo_org_id',
            'client_org_id',
        )->wherePivotNull('revoked_at');
    }

    public function isClient(): bool
    {
        return $this->type === OrgType::Client->value;
    }

    public function hasRdnAccount(): bool
    {
        return ! empty($this->rdn_id);
    }

    /**
     * Whether RDN::__construct can build a SOAP client for this org. rdn_id and
     * rdn_api_key are independent populations - hasRdnAccount() answers the first
     * question, this one answers "can we actually call the API".
     */
    public function hasRdnApiKey(): bool
    {
        return ! empty($this->rdn_api_key);
    }

    public function isRepo(): bool
    {
        return $this->type === OrgType::Repo->value;
    }

    public function isLienHolder(): bool
    {
        return $this->type === OrgType::LienHolder->value;
    }

    public function scopeIsHotlistEnabled(Builder $query): Builder
    {
        return $query->where('enable_genetech_hotlist', true)
            ->orWhere('enable_matrix_hotlist', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('type', OrgType::Repo->value)->where('enable_genetech_hotlist', true);
    }

    public function isClientPortalEnabled(): bool
    {
        return (bool) ($this->meta?->properties['enable_client_portal'] ?? false);
    }

    public function rdnRateLimit(): int
    {
        return $this->positiveMetaInt('rdn_rate_limit', 'services.rdn.default_rate_limit');
    }

    /**
     * A safety ceiling on one run's fan-out, not an allocation: the dispatcher
     * materialises at most this many Hotlist models in a single get() and spends one RDN
     * SOAP call per row. Restricting candidates to RDN keeps fleet volume inside what
     * {low} drains (see the note in config/services.php), so the ceiling's only job is to
     * stop a single pathological org from taking a whole run. Per-org overridable.
     */
    public function rdnCaseInfoMaxPerRun(): int
    {
        return $this->positiveMetaInt('rdn_case_info_max_per_run', 'services.rdn.case_info_max_per_run');
    }

    /**
     * A zero, negative, or non-numeric override would invert the meaning of the setting
     * (block everything / select nothing), so anything but a positive integer falls back.
     */
    private function positiveMetaInt(string $property, string $configKey): int
    {
        $configured = $this->meta?->properties[$property] ?? null;

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return max(1, (int) config($configKey));
    }

    /**
     * Otto is included with Clarity Pro Lite and every higher tier.
     */
    public function ottoEnabled(): bool
    {
        return match ($this->proTier()) {
            ProTierEnum::PRO_LITE, ProTierEnum::CLARITY_PRO, ProTierEnum::CLARITY_PRO_PLUS => true,
            ProTierEnum::BASIC => false,
        };
    }

    public function getOrgKits(bool $withDeleted = true): Collection
    {
        return Kit::getKits(org_id: $this->id, withDeleted: $withDeleted);
    }

    public function getHotlistClients(): Collection
    {
        try {
            return cache()->flexible(
                'hotlist-clients:'.$this->id,
                [now()->endOfDay(), now()->addDay()->endOfDay()],
                fn () => ClickhouseHotlist::query()
                    ->where('org_id', $this->id)
                    ->whereNotNull('client_org_id')
                    ->whereNotNull('client_org_name')
                    ->where('client_org_name', '!=', '')
                    ->select('client_org_id', 'client_org_name')
                    ->distinct()
                    ->orderBy('client_org_name')
                    ->pluck('client_org_name', 'client_org_id')
            );
        } catch (ClickhouseException $exception) {
            if (config('app.debug')) {
                throw $exception;
            }

            Log::channel('db-error')->error('Hotlist client cache warm (ClickHouse) failed', [
                'org_id' => $this->id,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ]);

            logDatadogCount('clarity.query_timeout', 1, [
                'category' => 'database_timeout',
                'item' => 'hotlist_clients_cache_warm',
            ]);

            return collect();
        }
    }

    public function getHotlistLienholders(): Collection
    {
        try {
            return cache()->flexible(
                'hotlist-lienholders:'.$this->id,
                [now()->endOfDay(), now()->addDay()->endOfDay()],
                fn () => ClickhouseHotlist::query()
                    ->where('org_id', $this->id)
                    ->whereNotNull('lienholder_org_id')
                    ->whereNotNull('lienholder_org_name')
                    ->where('lienholder_org_name', '!=', '')
                    ->select('lienholder_org_id', 'lienholder_org_name')
                    ->distinct()
                    ->orderBy('lienholder_org_name')
                    ->pluck('lienholder_org_name', 'lienholder_org_id')
            );
        } catch (ClickhouseException $exception) {
            if (config('app.debug')) {
                throw $exception;
            }

            Log::channel('db-error')->error('Hotlist lienholder cache warm (ClickHouse) failed', [
                'org_id' => $this->id,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ]);

            logDatadogCount('clarity.query_timeout', 1, [
                'category' => 'database_timeout',
                'item' => 'hotlist_lienholders_cache_warm',
            ]);

            return collect();
        }
    }

    public function hasHotlistClient(int $clientId): bool
    {
        return $this->hotlists()->where('client_org_id', $clientId)->exists();
    }

    public function getRdnClients(string $type): array
    {
        if (empty($this->rdn_api_key) || ! \in_array(\strtolower($type), ['local', 'global'])) {
            return [];
        }

        return cache()->remember(
            'rdn-clients:'.$this->id.':'.$type,
            now()->endOfDay(),
            fn () => RDN::make($this)
                ->getClientList()
                ->filter(fn ($client) => \strtolower($client->type) === \strtolower($type))
                ->mapWithKeys(fn ($client) => [$client->client_id => $client->client_name])
                ->sortBy(fn ($clientName) => $clientName)
                ->toArray()
        );
    }

    public function proBillingCycle(): ?string
    {
        return $this->meta?->properties['platform_repo_pro_billing'] ?? null;
    }

    public function proTier(): ProTierEnum
    {
        if (! $this->isProEnabled()) {
            return ProTierEnum::BASIC;
        }

        $tierValue = $this->meta?->properties['platform_repo_pro_tier'] ?? null;

        if ($tierValue) {
            $tier = ProTierEnum::tryFrom($tierValue) ?? ProTierEnum::BASIC;
        } else {
            // Backward compat: existing pro orgs map to Clarity Pro
            $tier = ProTierEnum::CLARITY_PRO;
        }

        // Check for pending downgrade that has passed its effective date
        $downgradeAt = $this->meta?->properties['platform_repo_pro_downgrade_effective_at'] ?? null;
        $downgradeTo = $this->meta?->properties['platform_repo_pro_downgrade_target_tier'] ?? null;

        if ($downgradeAt && $downgradeTo && Carbon::parse($downgradeAt)->isPast()) {
            return ProTierEnum::tryFrom($downgradeTo) ?? ProTierEnum::BASIC;
        }

        return $tier;
    }

    public function isProEnabled(FeatureEnum|string|null $feature = null): bool
    {
        $pro = cache()->remember(
            'org_pro_enabled_'.$this->id,
            now()->addMinutes(30),
            function () {
                $pro = $this->meta?->properties['platform_repo_pro'] ?? false;

                if ($end = $this->meta?->properties['platform_repo_pro_end'] ?? null) {
                    if (Carbon::parse($end)->isPast()) {
                        $pro = false;
                    }
                }

                return $pro;
            }
        );

        if ($feature) {
            if ($feature instanceof FeatureEnum) {
                $featureValue = $feature->value;
            } else {
                try {
                    $formattedFeature = Str::of($feature)->snake()->upper()->toString();

                    $featureValue = FeatureEnum::{$formattedFeature};
                    $featureValue = $featureValue?->value ?? $feature;
                } catch (\Throwable $e) {
                    // Not a valid feature.
                    $featureValue = $feature;
                }
            }

            // Check individual flag first, then fall back to tier-based resolution
            $individualFlag = $this->meta?->properties[$featureValue] ?? null;
            if ($individualFlag !== null) {
                return $pro && $individualFlag;
            }

            // Tier-based fallback
            $tier = $this->proTier();
            try {
                $enum = $feature instanceof FeatureEnum ? $feature : FeatureEnum::from($featureValue);

                return \in_array($enum, $tier->features(), true);
            } catch (\ValueError) {
                return false;
            }
        }

        return $pro;
    }

    public function stagingOrgIds(): Collection
    {
        return cache()->remember(
            'staging_org_ids:'.$this->id,
            now()->addHours(2),
            fn () => ApprovalList::query()
                ->where('agent_org_id', $this->id)
                ->pluck('staging_org_id')
        );
    }
}
