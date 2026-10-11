<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HotlistStatusEnum;
use App\Models\Address;
use App\Models\Hotlist;
use App\Models\Integrations;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\OrgType;

/**
 * @extends Factory<Hotlist>
 */
class HotlistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integration_id' => Integrations::factory(),
            'remote_order_id' => fake()->numberBetween(1000000, 5000000),
            'status' => 1,
            'org_id' => Org::factory(),
            'client_org_id' => Org::factory()->create(['type' => OrgType::Client->value]),
            'lienholder_org_id' => Org::factory()->create(['type' => OrgType::LienHolder->value]),
            'order_type' => 1,
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
        ];
    }

    public function repossessed(): self
    {
        return $this->state(['status' => HotlistStatusEnum::Repossessed]);
    }

    public function accepted(): self
    {
        return $this->state(['order_type' => 2]);
    }

    public function declined(): self
    {
        return $this->state(['order_type' => 4]);
    }

    public function involuntary(): self
    {
        return $this->state(['order_type' => 1]);
    }

    public function forClientOrg(Org $clientOrg): self
    {
        return $this->state(['client_org_id' => $clientOrg->id]);
    }

    public function rdn(): self
    {
        return $this->state(['integration_id' => IntegrationEnum::RDN->value]);
    }

    public function plate(string $plate, string $state): self
    {
        return $this->state(fn ($attributes) => [
            'plate' => $plate,
            'us_state' => $state,
        ]);
    }

    public function forOrg(Org $org): self
    {
        return $this->state([
            'org_id' => $org->id,
            'integration_id' => Integrations::factory()->forOrg($org),
        ]);
    }

    public function isStaged(): self
    {
        return $this->state(['is_staged' => true]);
    }

    public function notStaged(): self
    {
        return $this->state(['is_staged' => false]);
    }

    public function withAddress(int $count = 1): self
    {
        $this->afterCreating(
            fn (Hotlist $hotlist) => Address::factory()
                ->count($count)
                ->create()
                ->each(
                    fn ($address) => $address->hotlist()->attach($hotlist->id)
                )
        );

        return $this;
    }
}
