<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
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
            'branch_id' => fake()->randomNumber(4),
            'udbsaid' => fake()->bothify('??##########'),
            'zip_code' => fake()->postcode(),
            'branch_name' => fake()->company().' Branch',
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'phone' => fake()->phoneNumber(),
            'fax' => fake()->phoneNumber(),
        ];
    }

    public function forOrg(Org $org): self
    {
        return $this->state([
            'org_id' => $org->id,
        ]);
    }
}
