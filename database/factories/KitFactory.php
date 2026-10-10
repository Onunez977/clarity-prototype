<?php

namespace Database\Factories;

use App\Models\Kit;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kit>
 */
class KitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Org::factory(),
            'kit_type' => fake()->numberBetween(1, 2),
            'text_phone' => '',
            'name' => fake()->userName(),
            'kit_mapping' => fake()->text(64),
        ];
    }

    public function forOrg(Org $org): self
    {
        return $this->state(['org_id' => $org->id]);
    }
}
