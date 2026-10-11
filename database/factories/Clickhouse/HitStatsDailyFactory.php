<?php

namespace Database\Factories\Clickhouse;

use App\Clickhouse\Models\HitStatsDaily;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HitStatsDaily>
 */
class HitStatsDailyFactory extends Factory
{
    protected $model = HitStatsDaily::class;

    public function definition(): array
    {
        return [
            'date' => now()->startOfDay(),
            'org_id' => Org::factory(),
            'live_hit_count' => fake()->numberBetween(0, 50),
            'historical_hit_count' => fake()->numberBetween(0, 50),
            'staging_live_hit_count' => 0,
            'staging_historical_hit_count' => 0,
            '_version' => now(),
        ];
    }

    public function forOrg(Org|int $org): self
    {
        return $this->state(['org_id' => $org instanceof Org ? $org->id : $org]);
    }

    public function forDate(string $date): self
    {
        return $this->state(['date' => $date]);
    }
}
