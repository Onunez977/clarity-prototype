<?php

declare(strict_types=1);

namespace Tests\Services\Zones;

use App\Services\Zones\KmlZoneParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class KmlZoneParserTest extends TestCase
{
    private KmlZoneParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new KmlZoneParser;
    }

    #[Test]
    public function a_single_polygon_placemark_maps_its_name_and_ring(): void
    {
        $kml = <<<'KML'
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2">
              <Document>
                <Placemark>
                  <name>North Lot</name>
                  <Polygon>
                    <outerBoundaryIs>
                      <LinearRing>
                        <coordinates>
                          -96.8000,32.8000,0 -96.7990,32.8000,0 -96.7990,32.8010,0 -96.8000,32.8010,0 -96.8000,32.8000,0
                        </coordinates>
                      </LinearRing>
                    </outerBoundaryIs>
                  </Polygon>
                </Placemark>
              </Document>
            </kml>
            KML;

        $results = $this->parser->parse($kml);

        $this->assertCount(1, $results);
        $this->assertSame('North Lot', $results[0]['name']);
        $this->assertNull($results[0]['skip_reason']);
        $this->assertSame('Polygon', $results[0]['polygon']['type']);
        $this->assertCount(1, $results[0]['polygon']['coordinates']);
        $this->assertSame(
            [-96.8, 32.8],
            $results[0]['polygon']['coordinates'][0][0],
        );
    }

    #[Test]
    public function a_missing_name_falls_back_to_a_generated_label(): void
    {
        $kml = $this->singlePolygonKml(name: null);

        $results = $this->parser->parse($kml);

        $this->assertSame('Imported Zone 1', $results[0]['name']);
    }

    #[Test]
    public function altitude_is_stripped_from_each_position(): void
    {
        $kml = $this->singlePolygonKml(name: 'With Altitude');

        $results = $this->parser->parse($kml);

        foreach ($results[0]['polygon']['coordinates'][0] as $position) {
            $this->assertCount(2, $position);
        }
    }

    #[Test]
    public function an_unclosed_ring_is_auto_closed(): void
    {
        $kml = <<<'KML'
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2">
              <Placemark>
                <name>Unclosed</name>
                <Polygon>
                  <outerBoundaryIs>
                    <LinearRing>
                      <coordinates>-96.8,32.8,0 -96.79,32.8,0 -96.79,32.81,0 -96.8,32.81,0</coordinates>
                    </LinearRing>
                  </outerBoundaryIs>
                </Polygon>
              </Placemark>
            </kml>
            KML;

        $results = $this->parser->parse($kml);

        $ring = $results[0]['polygon']['coordinates'][0];

        $this->assertCount(5, $ring);
        $this->assertSame($ring[0], $ring[count($ring) - 1]);
    }

    #[Test]
    public function an_outer_ring_with_fewer_than_four_positions_after_closing_is_skipped(): void
    {
        $kml = <<<'KML'
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2">
              <Placemark>
                <name>Too Small</name>
                <Polygon>
                  <outerBoundaryIs>
                    <LinearRing>
                      <coordinates>-96.8,32.8,0 -96.79,32.8,0</coordinates>
                    </LinearRing>
                  </outerBoundaryIs>
                </Polygon>
              </Placemark>
            </kml>
            KML;

        $results = $this->parser->parse($kml);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]['polygon']);
        $this->assertNotNull($results[0]['skip_reason']);
    }

    #[Test]
    public function a_polygon_with_a_valid_hole_produces_two_rings(): void
    {
        $kml = <<<'KML'
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2">
              <Placemark>
                <name>Donut</name>
                <Polygon>
                  <outerBoundaryIs>
                    <LinearRing>
                      <coordinates>
                        -96.80,32.80,0 -96.70,32.80,0 -96.70,32.90,0 -96.80,32.90,0 -96.80,32.80,0
                      </coordinates>
                    </LinearRing>
                  </outerBoundaryIs>
                  <innerBoundaryIs>
                    <LinearRing>
                      <coordinates>
                        -96.78,32.82,0 -96.72,32.82,0 -96.72,32.88,0 -96.78,32.88,0 -96.78,32.82,0
                      </coordinates>
                    </LinearRing>
                  </innerBoundaryIs>
                </Polygon>
              </Placemark>
            </kml>
            KML;

        $results = $this->parser->parse($kml);

        $this->assertNull($results[0]['skip_reason']);
        $this->assertCount(2, $results[0]['polygon']['coordinates']);
        $this->assertCount(5, $results[0]['polygon']['coordinates'][1]);
    }

    #[Test]
    public function an_invalid_hole_is_dropped_but_the_polygon_is_kept(): void
    {
        $kml = <<<'KML'
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2">
              <Placemark>
                <name>Bad Hole</name>
                <Polygon>
                  <outerBoundaryIs>
                    <LinearRing>
                      <coordinates>
                        -96.80,32.80,0 -96.70,32.80,0 -96.70,32.90,0 -96.80,32.90,0 -96.80,32.80,0
                      </coordinates>
                    </LinearRing>
                  </outerBoundaryIs>
                  <innerBoundaryIs>
                    <LinearRing>
                      <coordinates>-96.78,32.82,0 -96.72,32.82,0</coordinates>
                    </LinearRing>
                  </innerBoundaryIs>
                </Polygon>
              </Placemark>
            </kml>
            KML;

        $results = $this->parser->parse($kml);

        $this->assertNull($results[0]['skip_reason']);
        $this->assertCount(1, $results[0]['polygon']['coordinates']);
    }

    #[Test]
    public function a_non_polygon_placemark_is_skipped_with_a_reason(): void
    {
        $kml = <<<'KML'
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2">
              <Placemark>
                <name>Just a Pin</name>
                <Point>
                  <coordinates>-96.8,32.8,0</coordinates>
                </Point>
              </Placemark>
            </kml>
            KML;

        $results = $this->parser->parse($kml);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]['polygon']);
        $this->assertNotNull($results[0]['skip_reason']);
    }

    #[Test]
    public function multiple_placemarks_are_parsed_in_document_order(): void
    {
        $kml = <<<'KML'
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2">
              <Document>
                <Placemark>
                  <name>First</name>
                  <Polygon>
                    <outerBoundaryIs>
                      <LinearRing>
                        <coordinates>-96.8,32.8,0 -96.79,32.8,0 -96.79,32.81,0 -96.8,32.81,0 -96.8,32.8,0</coordinates>
                      </LinearRing>
                    </outerBoundaryIs>
                  </Polygon>
                </Placemark>
                <Placemark>
                  <name>Second</name>
                  <Point>
                    <coordinates>-96.8,32.8,0</coordinates>
                  </Point>
                </Placemark>
              </Document>
            </kml>
            KML;

        $results = $this->parser->parse($kml);

        $this->assertCount(2, $results);
        $this->assertSame('First', $results[0]['name']);
        $this->assertSame('Second', $results[1]['name']);
        $this->assertNull($results[1]['polygon']);
    }

    #[Test]
    public function malformed_xml_returns_an_empty_list_without_throwing(): void
    {
        $results = $this->parser->parse('<kml><Placemark><name>Oops</name>');

        $this->assertSame([], $results);
    }

    #[Test]
    public function empty_string_returns_an_empty_list(): void
    {
        $this->assertSame([], $this->parser->parse(''));
    }

    private function singlePolygonKml(?string $name): string
    {
        $nameTag = $name !== null ? "<name>{$name}</name>" : '';

        return <<<KML
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2">
              <Placemark>
                {$nameTag}
                <Polygon>
                  <outerBoundaryIs>
                    <LinearRing>
                      <coordinates>-96.8,32.8,15 -96.79,32.8,15 -96.79,32.81,15 -96.8,32.81,15 -96.8,32.8,15</coordinates>
                    </LinearRing>
                  </outerBoundaryIs>
                </Polygon>
              </Placemark>
            </kml>
            KML;
    }
}
