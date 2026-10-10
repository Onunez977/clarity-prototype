<?php

namespace Database\Factories;

use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\WithFaker;
use InsightLPR\BlackAdam\Enum\OrgType;

/**
 * @extends Factory<Org>
 */
class OrgFactory extends Factory
{
    use WithFaker;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
            'enable_matrix_hotlist' => false,
        ];
    }

    public function client(): static
    {
        return $this->state(fn () => [
            'type' => OrgType::Client->value,
            'enable_genetech_hotlist' => false,
            'enable_matrix_hotlist' => false,
        ])->afterCreating(function (Org $org) {
            $org->meta()->create([
                'properties' => ['enable_client_portal' => true],
            ]);
        });
    }
}
