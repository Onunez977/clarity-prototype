<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AggregateRdnHotlistCountByHour;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AggregateRdnHotlistCountByHour>
 */
class AggregateRdnHotlistCountByHourFactory extends Factory
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
            'client_org_id' => Org::factory(),
            'hour_timestamp' => fake()->dateTime(),
            'branch_id' => fake()->optional()->numerify('branch-###'),
            'order_type' => fake()->optional()->numberBetween(1, 5),
            'hotlist_count' => fake()->numberBetween(0, 100),
        ];
    }

    public function forOrg(Org $org): self
    {
        return $this->state(['org_id' => $org->id]);
    }

    public function forClientOrg(Org $clientOrg): self
    {
        return $this->state(['client_org_id' => $clientOrg->id]);
    }

    public function forBranch(string $branchId): self
    {
        return $this->state(['branch_id' => $branchId]);
    }
}
