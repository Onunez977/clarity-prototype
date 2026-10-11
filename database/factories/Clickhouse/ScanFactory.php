<?php

namespace Database\Factories\Clickhouse;

use App\Clickhouse\Models\Scan;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scan>
 */
class ScanFactory extends Factory
{
    protected $model = Scan::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->randomNumber(5, true),
            'kit_id' => fake()->randomNumber(5, true),
            'ocr' => strtoupper(fake()->bothify('???###')),
            'us_state' => fake()->randomElement(['IN', 'NY', 'FL', 'TX', 'CA']),
            'camera_name' => fake()->word(),
            'guid' => fake()->uuid(),
            'scanned_at' => now(),
            'created_at' => now(),
            'lng' => fake()->longitude(-90, -80),
            'lat' => fake()->latitude(30, 45),
            'kit_name' => fake()->userName(),
            'org_id' => Org::factory(),
            'disposition' => null,
            'address' => fake()->address(),
            '_peerdb_synced_at' => now(),
            '_version' => 1,
            '_peerdb_is_deleted' => 0,
        ];
    }

    public function forOrg(Org|int $org): self
    {
        return $this->state(['org_id' => $org instanceof Org ? $org->id : $org]);
    }

    public function forKit(int $kitId): self
    {
        return $this->state(['kit_id' => $kitId]);
    }

    public function hot(): self
    {
        return $this->newInstance()->state(function () {
            return [];
        });
    }

    public function deleted(): self
    {
        return $this->state(['_peerdb_is_deleted' => 1]);
    }
}
