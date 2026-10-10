<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Org;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    public function definition(): array
    {
        // A small square at a distinct DFW-area location so factory-made zones
        // never stack on the same coordinates (which would make them
        // indistinguishable on the map).
        $lng = $this->faker->randomFloat(4, -97.4, -96.6);
        $lat = $this->faker->randomFloat(4, 32.6, 33.2);

        return [
            'org_id' => Org::factory(),
            'name' => ucwords($this->faker->unique()->words(2, true)).' Zone',
            'description' => $this->faker->optional()->sentence(),
            'color' => Zone::DEFAULT_COLOR,
            'is_no_scan_zone' => false,
            'definition' => [
                'polygons' => [self::squarePolygon($lng, $lat)],
                'zip_codes' => [],
            ],
        ];
    }

    /**
     * A closed square GeoJSON polygon ring (~0.02° ≈ 1.4 mi) anchored at the
     * given lower-left corner.
     *
     * @return array{type: string, coordinates: array<int, array<int, array<int, float>>>}
     */
    public static function squarePolygon(float $lng, float $lat, float $size = 0.02): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [$lng, $lat],
                [$lng, $lat + $size],
                [$lng + $size, $lat + $size],
                [$lng + $size, $lat],
                [$lng, $lat],
            ]],
        ];
    }

    /**
     * Place the zone's geometry at an explicit location (for deterministic,
     * visually-distinct seeded data).
     */
    public function atLocation(float $lng, float $lat): static
    {
        return $this->state(fn (array $attrs): array => [
            'definition' => [
                'polygons' => [self::squarePolygon($lng, $lat)],
                'zip_codes' => $attrs['definition']['zip_codes'] ?? [],
            ],
        ]);
    }

    public function forOrg(Org $org): static
    {
        return $this->state(['org_id' => $org->id]);
    }

    /**
     * @param  array<int, int>  $userIds
     */
    public function assignedTo(array $userIds): static
    {
        return $this->afterCreating(fn (Zone $zone) => $zone->users()->sync($userIds));
    }

    public function noScan(): static
    {
        return $this->state([
            'is_no_scan_zone' => true,
            'color' => Zone::NO_SCAN_COLOR,
        ]);
    }

    public function trashed(): static
    {
        return $this->afterCreating(fn (Zone $zone) => $zone->delete());
    }

    /**
     * Build the PostGIS `geom` from the definition — only needed by tests that
     * read geometry; the Zones list never selects it.
     */
    public function withGeometry(): static
    {
        return $this->afterCreating(fn (Zone $zone) => $zone->rebuildGeometryFromDefinition());
    }
}
