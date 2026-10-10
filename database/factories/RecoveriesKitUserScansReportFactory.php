<?php

namespace Database\Factories;

use App\Models\Kit;
use App\Models\Org;
use App\Models\RecoveriesKitUserScansReport;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\OrgType;

/**
 * @extends Factory<RecoveriesKitUserScansReport>
 */
class RecoveriesKitUserScansReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lat = fake()->latitude(31, 48);
        $long = fake()->longitude(-123, -75);

        return [
            'recovery_id' => fake()->numberBetween(1000, 5000),
            'integration_id' => 1, // Integration::factory(),
            'remote_order_id' => fake()->numberBetween(1000000, 5000000),
            'status' => 1,
            'org_id' => Org::factory(),
            'client_org_id' => Org::factory()->create(['type' => OrgType::Client->value]),
            'lienholder_org_id' => Org::factory()->create(['type' => OrgType::LienHolder->value]),
            'order_type' => null,
            'is_staged' => false,
            'plate' => strtoupper(fake()->bothify('???###')),
            'us_state' => fake()->randomElement([
                'IN',
                'NY',
                'FL',
                'TX',
                'CA',
                'NJ',
                'WA',
                'IL',
                'OH',
                'PA',
                'MA',
                'MD',
            ]),
            'vin' => fake()->bothify('1HGCM82633A#####'),
            'vehicle_year' => fake()->year,
            'vehicle_make' => fake()->randomElement(['Honda', 'Ford', 'Mazda', 'Toyota', 'GMC']),
            'vehicle_model' => fake()->randomElement(['Element', 'Rambo', 'Civic', 'Accord', 'X57', 'X60']),
            'lookback_days' => 270,
            'address_id' => null, // Address::factory(),
            'house_number' => Str::limit(fake()->buildingNumber(), 3, ''),
            'road' => fake()->streetName(),
            'city' => fake()->city(),
            'state' => fake()->randomElement([
                'IN',
                'FL',
                'TX',
                'NY',
                'CA',
                'TN',
            ]),
            'postcode' => Str::limit(fake()->postcode(), 5, ''),
            'geo' => Point::makeGeodetic($lat, $long),
            'repo_date' => now(),
            'kit_id' => Kit::factory(),
            'count' => fake()->randomNumber(),
            'hotlist_scan_count' => fake()->randomNumber(),
            'hotlist_scan_percentage' => null,
        ];
    }

    public function forOrg(Org $org): self
    {
        return $this->state([
            'org_id' => $org->id,
            'kit_id' => Kit::factory()->forOrg($org),
        ]);
    }

    public function forClientOrg(Org $clientOrg): self
    {
        return $this->state([
            'client_org_id' => $clientOrg->id,
        ]);
    }

    public function forRecovery(int $hotlistId): self
    {
        return $this->state([
            'recovery_id' => $hotlistId,
        ]);
    }

    public function repoDate(\DateTimeInterface|string $date): self
    {
        return $this->state([
            'repo_date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date,
        ]);
    }

    public function voluntary(): self
    {
        return $this->state([
            'order_type' => 2,
        ]);
    }

    public function involuntary(): self
    {
        return $this->state([
            'order_type' => 1,
        ]);
    }
}
