<?php

namespace Database\Factories;

use App\Models\Scan;
use App\Models\ScanMetaData;
use Illuminate\Database\Eloquent\Factories\Factory;
use InsightLPR\BlackAdam\Enum\ScanMetaType;

/**
 * @extends Factory<ScanMetaData>
 */
class ScanMetaDataFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'meta' => [
                '1' => 'Sedan',
                '2' => 'Blue',
                '3' => 'Toyota',
                '4' => 'False',
                '5' => 'AL',
                '6' => fake()->address(),
            ],
        ];
    }

    public function color(string $value = 'Red'): self
    {
        return $this->state(function ($attributes) use ($value) {
            $attributes['meta'] = ['2' => $value] + $attributes['meta'];

            return $attributes;
        });

    }

    public function isPlate(bool $value = true): self
    {
        return $this->state(function ($attributes) use ($value) {
            $attributes['meta'] = ['4' => $value ? 'False' : 'True'] + $attributes['meta'];

            return $attributes;
        });

    }

    public function mlPlateState(string $value): self
    {
        return $this->state(function ($attributes) use ($value) {
            $attributes['meta'] = [(string) ScanMetaType::MLPlateState->value => $value ? 'False' : 'True'] + $attributes['meta'];

            return $attributes;
        });
    }
}
