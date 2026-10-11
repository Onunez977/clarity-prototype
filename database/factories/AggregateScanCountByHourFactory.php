<?php

namespace Database\Factories;

use App\Models\AggregateScanCountByHour;
use App\Models\Kit;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AggregateScanCountByHour>
 */
class AggregateScanCountByHourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $time = now()
            ->subDays(rand(0, 2))
            ->subMinutes(rand(1, 55))
            ->subSeconds(rand(1, 55));

        return [
            'timeblock' => timeblock($time),
            'kit_id' => Kit::factory(),
            'scan_count' => fake()->numberBetween(1, 1000),
        ];
    }

    public function forOrg(Org $org): self
    {
        return $this->state(['kit_id' => Kit::factory()->forOrg($org)]);
    }
}
