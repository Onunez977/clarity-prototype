<?php

namespace Database\Factories\Clickhouse;

use App\Clickhouse\Models\Recovery;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recovery>
 */
class RecoveryFactory extends Factory
{
    protected $model = Recovery::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->randomNumber(5, true),
            'org_id' => Org::factory(),
            'integration_id' => 1,
            'remote_order_id' => (string) fake()->numberBetween(1000000, 5000000),
            'client_org_id' => null,
            'lienholder_org_id' => null,
            'status' => 1,
            'order_type' => 1,
            'is_staged' => false,
            'plate' => strtoupper(fake()->bothify('???###')),
            'us_state' => fake()->randomElement(['IN', 'NY', 'FL', 'TX', 'CA']),
            'vin' => fake()->bothify('1HGCM82633A#####'),
            'vehicle_year' => fake()->year(),
            'vehicle_make' => fake()->randomElement(['Honda', 'Ford', 'Toyota']),
            'vehicle_model' => fake()->randomElement(['Civic', 'F150', 'Camry']),
            'lookback_days' => 270,
            'created_at' => now(),
            'updated_at' => now(),
            'branch_id' => null,
            'repo_date' => now()->toDateString(),
            'order_date' => now()->subDays(30)->toDateString(),
            '_peerdb_synced_at' => now(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ];
    }

    public function forOrg(Org|int $org): self
    {
        return $this->state(['org_id' => $org instanceof Org ? $org->id : $org]);
    }

    public function deleted(): self
    {
        return $this->state(['_peerdb_is_deleted' => 1]);
    }
}
