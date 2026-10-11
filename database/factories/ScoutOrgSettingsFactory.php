<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Org;
use App\Models\ScoutOrgSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScoutOrgSettings>
 */
class ScoutOrgSettingsFactory extends Factory
{
    protected $model = ScoutOrgSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Org::factory(),
            'map_layers' => ScoutOrgSettings::defaults(),
        ];
    }

    /**
     * @param  list<string>  $enabled
     */
    public function layers(array $enabled): static
    {
        return $this->state(fn (): array => [
            'map_layers' => ScoutOrgSettings::mapFromEnabled($enabled),
        ]);
    }
}
