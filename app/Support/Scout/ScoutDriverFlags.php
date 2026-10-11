<?php

declare(strict_types=1);

namespace App\Support\Scout;

use App\Enums\FeatureEnum;
use App\Enums\ScoutMapLayerEnum;
use App\Models\ScoutOrgSettings;
use App\Models\User;
use Laravel\Pennant\Feature;

class ScoutDriverFlags
{
    /**
     * @return array{trips_enabled: bool, enabled_map_layers: list<string>, teammate_overlay_enabled: bool}
     */
    public static function for(User $user, ?bool $isImpersonating = null): array
    {
        $layers = ScoutOrgSettings::enabledLayersFor($user, $isImpersonating);

        return [
            'trips_enabled' => Feature::for($user)->active(FeatureEnum::CLARITY_SCOUT_TRIPS->slug()),
            'enabled_map_layers' => $layers,
            'teammate_overlay_enabled' => in_array(ScoutMapLayerEnum::Teammates->value, $layers, true),
        ];
    }
}
