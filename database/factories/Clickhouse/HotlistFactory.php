<?php

namespace Database\Factories\Clickhouse;

use App\Clickhouse\Models\Hotlist;
use App\Enums\HotlistStatusEnum;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;

/**
 * @extends Factory<Hotlist>
 */
class HotlistFactory extends Factory
{
    protected $model = Hotlist::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->randomNumber(5, true),
            'org_id' => Org::factory(),
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => (string) fake()->numberBetween(1000000, 5000000),
            'status' => HotlistStatusEnum::Open->value,
            'order_type' => 1,
            'is_staged' => false,
            'plate' => strtoupper(fake()->bothify('???###')),
            'us_state' => fake()->randomElement(['IN', 'NY', 'FL', 'TX', 'CA']),
            'vin' => fake()->bothify('1HGCM82633A#####'),
            'vehicle_year' => fake()->year,
            'vehicle_make' => fake()->randomElement(['Honda', 'Ford', 'Mazda', 'Toyota', 'GMC']),
            'vehicle_model' => fake()->randomElement(['Element', 'Rambo', 'Civic', 'Accord', 'X57']),
            'lookback_days' => 270,
            'created_at' => now(),
            'updated_at' => now(),
            'client_org_id' => null,
            'client_org_name' => null,
            'lienholder_org_id' => null,
            'lienholder_org_name' => null,
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
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
