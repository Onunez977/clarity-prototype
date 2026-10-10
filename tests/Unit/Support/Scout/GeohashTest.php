<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Scout;

use App\Support\Scout\Geohash;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('clarity-scout')]
class GeohashTest extends TestCase
{
    public function test_encode_matches_the_canonical_geohash_example(): void
    {
        // The classic reference point lat=57.64911, lng=10.40744 → "u4pruydqqvj".
        // Args are (lng, lat) to match ClickHouse's geohashEncode and our usage.
        $this->assertSame('u4pru', Geohash::encode(10.40744, 57.64911, 5));
        $this->assertSame('u4pruydqqvj', Geohash::encode(10.40744, 57.64911, 11));
    }

    public function test_encode_then_decode_contains_the_original_point(): void
    {
        $lng = -97.715;
        $lat = 30.305;

        $bounds = Geohash::decodeBounds(Geohash::encode($lng, $lat, 7));

        $this->assertNotNull($bounds);
        $this->assertGreaterThanOrEqual($bounds['min_lng'], $lng);
        $this->assertLessThanOrEqual($bounds['max_lng'], $lng);
        $this->assertGreaterThanOrEqual($bounds['min_lat'], $lat);
        $this->assertLessThanOrEqual($bounds['max_lat'], $lat);
    }

    public function test_decode_bounds_returns_null_for_invalid_alphabet(): void
    {
        // 'a', 'i', 'l', 'o' are not in the geohash base32 alphabet.
        $this->assertNull(Geohash::decodeBounds('aio'));
    }

    public function test_cell_size_shrinks_as_precision_grows(): void
    {
        $p6 = Geohash::cellSize(6);
        $p7 = Geohash::cellSize(7);

        $this->assertLessThan($p6['lat'], $p7['lat']);
        $this->assertLessThan($p6['lng'], $p7['lng']);

        // Precision 7 has equal lng/lat bit counts → square-ish ~0.00137° cells.
        $this->assertEqualsWithDelta(0.001373, $p7['lat'], 0.0001);
        $this->assertEqualsWithDelta(0.001373, $p7['lng'], 0.0001);
    }

    public function test_cell_polygon_is_a_closed_ring(): void
    {
        $polygon = Geohash::cellPolygon(Geohash::encode(-97.715, 30.305, 7));

        $this->assertNotNull($polygon);
        $ring = $polygon[0];
        $this->assertCount(5, $ring);
        $this->assertSame($ring[0], $ring[4], 'ring must close on itself');
    }

    public function test_enumerate_cells_covers_a_small_bbox(): void
    {
        $bbox = ['min_lng' => -97.72, 'min_lat' => 30.30, 'max_lng' => -97.70, 'max_lat' => 30.32];

        $result = Geohash::enumerateCells($bbox, 7, 100_000);

        $this->assertFalse($result['capped']);
        // ~0.02° / ~0.00137° ≈ 15 cells per side → a few hundred cells.
        $this->assertGreaterThan(100, count($result['cells']));
        $this->assertSame($result['cells'], array_unique($result['cells']));

        // Estimate should be in the same ballpark as the real enumeration.
        $this->assertGreaterThanOrEqual(count($result['cells']), Geohash::estimateCellCount($bbox, 7));
    }

    public function test_enumerate_cells_flags_capped_when_over_the_limit(): void
    {
        $bbox = ['min_lng' => -98.0, 'min_lat' => 30.0, 'max_lng' => -97.0, 'max_lat' => 31.0];

        $result = Geohash::enumerateCells($bbox, 7, 50);

        $this->assertTrue($result['capped']);
        $this->assertCount(50, $result['cells']);
    }
}
