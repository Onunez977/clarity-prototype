<?php

namespace Database\Factories;

use App\Models\Integrations;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integrations>
 */
class IntegrationsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(asText: true),
            'status' => 'active',
            'mapping_id' => Org::factory(),
            'mapping_type' => 'org',
            'batch_id' => 1,
        ];
    }

    public function forOrg(Org $org): self
    {
        return $this->state([
            'mapping_type' => 'org',
            'mapping_id' => $org->id,
        ]);
    }
}
