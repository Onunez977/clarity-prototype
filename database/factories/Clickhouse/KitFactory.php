<?php

namespace Database\Factories\Clickhouse;

use App\Clickhouse\Models\Kit;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kit>
 */
class KitFactory extends Factory
{
    protected $model = Kit::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->randomNumber(5, true),
            'org_id' => Org::factory(),
            'kit_type' => fake()->numberBetween(1, 2),
            'text_phone' => '',
            'name' => fake()->userName(),
            'kit_mapping' => fake()->text(64),
            '_peerdb_is_deleted' => 0,
            '_peerdb_version' => 1,
        ];
    }

    public function forOrg(Org $org): self
    {
        return $this->state(['org_id' => $org->id]);
    }

    public function deleted(): self
    {
        return $this->state(['_peerdb_is_deleted' => 1]);
    }
}
