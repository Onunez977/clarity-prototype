<?php

namespace Database\Factories;

use App\Dto\HotlistJsonData;
use App\Models\HotlistJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotlistJson>
 */
class HotlistJsonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'json' => new HotlistJsonData(
                branch_id: null
            ),
        ];
    }

    /**
     * Set the branch_id in the JSON data.
     */
    public function withBranch(int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'json' => new HotlistJsonData(branch_id: $branchId),
        ]);
    }
}
