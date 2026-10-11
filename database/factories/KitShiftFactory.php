<?php

namespace Database\Factories;

use App\Enums\ShiftEndTypeEnum;
use App\Models\Kit;
use App\Models\KitShift;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitShift>
 */
class KitShiftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Org::factory(),
            'kit_id' => Kit::factory(),
            'started_at' => now()->subHours(2),
            'ended_at' => null,
            'end_type' => null,
            'summary_sent_at' => null,
            'summary_skipped_reason' => null,
        ];
    }

    public function forKit(Kit $kit): self
    {
        return $this->state([
            'kit_id' => $kit->id,
            'org_id' => $kit->org_id,
        ]);
    }

    public function open(): self
    {
        return $this->state([
            'ended_at' => null,
            'end_type' => null,
        ]);
    }

    public function confirmed(): self
    {
        return $this->state([
            'ended_at' => now(),
            'end_type' => ShiftEndTypeEnum::Confirmed,
        ]);
    }

    public function assumed(): self
    {
        return $this->state([
            'ended_at' => now()->subHours(3),
            'end_type' => ShiftEndTypeEnum::Assumed,
        ]);
    }

    public function summarySent(): self
    {
        return $this->state([
            'summary_sent_at' => now(),
        ]);
    }
}
