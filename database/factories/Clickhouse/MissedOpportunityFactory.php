<?php

namespace Database\Factories\Clickhouse;

use App\Clickhouse\Models\MissedOpportunity;
use App\Enums\HitTypeEnum;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MissedOpportunity>
 */
class MissedOpportunityFactory extends Factory
{
    protected $model = MissedOpportunity::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->randomNumber(5, true),
            'hotlist_id' => fake()->unique()->randomNumber(5, true),
            'scan_id' => fake()->unique()->randomNumber(5, true),
            'hit_type' => HitTypeEnum::Missed_Opportunity->value,
            'hit_created_at' => now(),
            'ocr' => strtoupper(fake()->bothify('???###')),
            'scanned_at' => now(),
            'camera_name' => fake()->word(),
            'scan_us_state' => fake()->randomElement(['IN', 'NY', 'FL', 'TX', 'CA']),
            'scan_guid' => fake()->uuid(),
            'kit_id' => fake()->randomNumber(5, true),
            'kit_name' => fake()->userName(),
            'kit_org_id' => 0,
            'hotlist_us_state' => fake()->randomElement(['IN', 'NY', 'FL', 'TX', 'CA']),
            'hotlist_plate' => strtoupper(fake()->bothify('???###')),
            'hotlist_status' => 1,
            'hotlist_vin' => fake()->bothify('1HGCM82633A#####'),
            'hotlist_org_id' => Org::factory(),
            'remote_order_id' => (string) fake()->numberBetween(1000000, 5000000),
            'integration_id' => 1,
            'client_org_id' => null,
            'branch_id' => 0,
            'lienholder_org_id' => null,
            'client_org_name' => null,
            'lienholder_org_name' => null,
            'repo_date' => null,
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
        ];
    }

    public function forOrg(Org $org): self
    {
        return $this->state([
            'hotlist_org_id' => $org->id,
            'kit_org_id' => $org->id,
        ]);
    }

    public function forKit(int $kitId, string $kitName): self
    {
        return $this->state([
            'kit_id' => $kitId,
            'kit_name' => $kitName,
        ]);
    }

    public function deleted(): self
    {
        return $this->state(['_peerdb_is_deleted' => 1]);
    }
}
