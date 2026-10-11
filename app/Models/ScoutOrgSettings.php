<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScoutMapLayerEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-org Scout driver Map Layers availability (DEV-2199).
 *
 * @property int $org_id
 * @property array<string, bool> $map_layers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Org $org
 *
 * @method static \Database\Factories\ScoutOrgSettingsFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class ScoutOrgSettings extends Model
{
    use HasFactory;

    protected $connection = 'platform';

    protected $table = 'platform.scout_org_settings';

    protected $primaryKey = 'org_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'org_id',
        'map_layers',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'map_layers' => 'array',
        ];
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'org_id');
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (ScoutMapLayerEnum::cases() as $case) {
            $defaults[$case->value] = true;
        }

        return $defaults;
    }

    /**
     * Org-stored availability, ignoring the global teammate experimental flag.
     *
     * @return list<string>
     */
    public static function storedEnabledLayers(int $orgId): array
    {
        return self::enabledKeys(self::layersForOrg($orgId));
    }

    /**
     * Driver-facing availability: stored org preference, then AND teammates
     * with the global experimental flag (impersonators still bypass that flag).
     *
     * @return list<string>
     */
    public static function enabledLayersFor(User $user, ?bool $isImpersonating = null): array
    {
        $impersonating = $isImpersonating ?? $user->isScoutForceImpersonator();
        $layers = self::layersForOrg((int) $user->org_id);

        $teammatesAllowed = $impersonating
            || (bool) config('app.experimental.clarity_scout_teammate_overlay');

        if (! $teammatesAllowed) {
            $layers[ScoutMapLayerEnum::Teammates->value] = false;
        }

        return self::enabledKeys($layers);
    }

    public static function isLayerEnabled(User $user, ScoutMapLayerEnum $layer, ?bool $isImpersonating = null): bool
    {
        return in_array($layer->value, self::enabledLayersFor($user, $isImpersonating), true);
    }

    /**
     * @param  list<string>  $enabled
     * @return array<string, bool>
     */
    public static function mapFromEnabled(array $enabled): array
    {
        $map = [];

        foreach (ScoutMapLayerEnum::cases() as $case) {
            $map[$case->value] = in_array($case->value, $enabled, true);
        }

        return $map;
    }

    /**
     * @return array<string, bool>
     */
    private static function layersForOrg(int $orgId): array
    {
        $stored = self::query()->find($orgId)?->map_layers;

        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $layers
     * @return list<string>
     */
    private static function enabledKeys(array $layers): array
    {
        $enabled = [];

        foreach (ScoutMapLayerEnum::cases() as $case) {
            if (($layers[$case->value] ?? false) === true) {
                $enabled[] = $case->value;
            }
        }

        return $enabled;
    }
}
