<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Scan;
use App\Models\ScanMetaData;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\IO\Generator\WKB\WKBGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Scan>
 */
class ScanFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Scan $scan) {
            if (! $scan->scanMetaData()->exists()) {
                ScanMetaData::factory()->for($scan)->create();
            }
        });
    }

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
            'ocr' => strtoupper(fake()->bothify('###??##?')),
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
            'geo' => Point::makeGeodetic($lat, $long),
            'kit_id' => Kit::factory(),
            'is_visible' => true,
            'scanned_at' => fake()->dateTimeBetween('-1 day'),
            'created_at' => now(),
            'guid' => Str::uuid()->toString(),
            'camera_name' => fake()->word,
        ];
    }

    public function withMeta(array $attributes = []): self
    {
        return $this->afterCreating(function ($scan) use ($attributes) {
            $scan->scanMetaData()->updateOrCreate(
                ['scan_id' => $scan->id],
                $attributes
            );
        });
    }

    public function forOrg(Org $org): self
    {
        return $this->state(['kit_id' => Kit::factory()->forOrg($org)]);
    }

    public function forHotlist(Hotlist $hotlist): self
    {
        return $this->state(fn () => [
            'ocr' => $hotlist->plate,
            'us_state' => $hotlist->us_state,
        ]);
    }

    public function scannedToday(): self
    {
        return $this->state(['scanned_at' => now()]);
    }

    public function notVisible(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'is_visible' => false,
            ];
        });
    }

    public function belongsToKit(Kit $kit): self
    {
        return $this->state(function (array $attributes) use ($kit) {
            return [
                'kit_id' => $kit->id,
            ];
        });
    }

    public function inRangeOf(Point $point, int $radius_in_meters = 161): self
    {
        return $this->state(function (array $attributes) use ($point, $radius_in_meters) {
            $geo = (new WKBGenerator)->generate($point);

            $new = DB::select('select ST_Project(?, ?,0) as geo', [$geo, $radius_in_meters]);

            return [
                'geo' => $new[0]->geo,
            ];
        });
    }
}
