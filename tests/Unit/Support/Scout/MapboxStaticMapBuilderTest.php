<?php

namespace Tests\Unit\Support\Scout;

use App\Support\Scout\MapboxStaticMapBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MapboxStaticMapBuilderTest extends TestCase
{
    #[Test]
    public function returns_null_when_mapbox_token_is_unset(): void
    {
        config()->set('services.mapbox.token', null);

        $url = (new MapboxStaticMapBuilder)->build(
            tripSegments: [[[-97.7, 30.3], [-97.71, 30.31]]],
            zonePolygons: [],
        );

        $this->assertNull($url);
    }

    #[Test]
    public function includes_path_polyline_when_coordinates_provided(): void
    {
        config()->set('services.mapbox.token', 'pk.test');

        $url = (new MapboxStaticMapBuilder)->build(
            tripSegments: [[[-97.7, 30.3], [-97.71, 30.31]]],
            zonePolygons: [],
        );

        $this->assertNotNull($url);
        $this->assertStringContainsString('geojson(', $url);
        $this->assertStringContainsString('LineString', urldecode($url));
        $this->assertStringContainsString('access_token=pk.test', $url);
    }

    #[Test]
    public function renders_each_drive_segment_as_its_own_line(): void
    {
        config()->set('services.mapbox.token', 'pk.test');

        $url = (new MapboxStaticMapBuilder)->build(
            tripSegments: [
                [[-97.70, 30.30], [-97.71, 30.31]],
                [[-97.80, 30.40], [-97.81, 30.41]],
            ],
            zonePolygons: [],
        );

        $this->assertNotNull($url);
        // Two separate LineStrings — no single connector drawn across the gap.
        $this->assertSame(2, substr_count(urldecode($url), '"LineString"'));
    }

    #[Test]
    public function skips_segments_with_fewer_than_two_points(): void
    {
        config()->set('services.mapbox.token', 'pk.test');

        $url = (new MapboxStaticMapBuilder)->build(
            tripSegments: [[[-97.70, 30.30]], [[-97.80, 30.40], [-97.81, 30.41]]],
            zonePolygons: [],
        );

        $this->assertNotNull($url);
        $this->assertSame(1, substr_count(urldecode($url), '"LineString"'));
    }

    #[Test]
    public function encodes_zone_polygons_with_fill_and_stroke_properties(): void
    {
        config()->set('services.mapbox.token', 'pk.test');

        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[[-97.7, 30.3], [-97.7, 30.4], [-97.6, 30.4], [-97.6, 30.3], [-97.7, 30.3]]],
        ];

        $url = (new MapboxStaticMapBuilder)->build(
            tripSegments: [],
            zonePolygons: [['name' => 'Z1', 'geojson' => $polygon]],
        );

        $this->assertNotNull($url);
        $decoded = urldecode($url);
        $this->assertStringContainsString('Polygon', $decoded);
        $this->assertStringContainsString('fill-opacity', $decoded);
        $this->assertStringContainsString('stroke', $decoded);
    }

    #[Test]
    public function falls_back_to_pin_when_overlays_exceed_url_length_budget(): void
    {
        config()->set('services.mapbox.token', 'pk.test');

        // Build many huge polygons so the URL exceeds the 7,500-char cap.
        $polygons = [];
        for ($i = 0; $i < 50; $i++) {
            $coords = [];
            for ($j = 0; $j < 200; $j++) {
                $coords[] = [-97.7 + ($j * 0.001), 30.3 + ($i * 0.001)];
            }
            $coords[] = $coords[0]; // close ring
            $polygons[] = [
                'name' => 'Z'.$i,
                'geojson' => ['type' => 'Polygon', 'coordinates' => [$coords]],
            ];
        }

        $url = (new MapboxStaticMapBuilder)->build(
            tripSegments: [],
            zonePolygons: $polygons,
        );

        $this->assertNotNull($url);
        $this->assertLessThanOrEqual(7500, strlen($url));
    }
}
