<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HitTypeEnum;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hit>
 */
class HitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hotlist_id' => Hotlist::factory(),
            'scan_id' => Scan::factory()->scannedToday(),
            'hit_type' => HitTypeEnum::Live->value,
            'kit_id' => Kit::first()->id ?? Kit::factory(),
        ];
    }

    public function live(): self
    {
        return $this->state(['hit_type' => HitTypeEnum::Live->value]);
    }

    public function historical(): self
    {
        return $this->state(['hit_type' => HitTypeEnum::Historical->value]);
    }

    public function stagingLive(): self
    {
        return $this->state(['hit_type' => HitTypeEnum::Staging_Live->value]);
    }

    public function stagingHistorical(): self
    {
        return $this->state(['hit_type' => HitTypeEnum::Staging_Historical->value]);
    }

    public function missedOpportunity(): self
    {
        return $this->state(['hit_type' => HitTypeEnum::Missed_Opportunity->value]);
    }

    public function probableMatch(): self
    {
        return $this->state(['hit_type' => HitTypeEnum::Probable_Match->value]);
    }

    public function forOrg(Org $org, array $scanAttributes = [], array $hotlistAttributes = []): self
    {
        $scan = Scan::factory()
            ->forOrg($org)
            ->create(\array_merge(
                ['scanned_at' => now()],
                $scanAttributes,
            ));

        return $this->state([
            'hotlist_id' => Hotlist::factory(state: $hotlistAttributes)->forOrg($org),
            'scan_id' => $scan->id,
            'kit_id' => $scan->kit_id,
        ]);
    }
}
