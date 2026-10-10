<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Models\Zone;
use SimpleXMLElement;
use Throwable;

/**
 * Parses a KML document into per-placemark GeoJSON Polygons, ready for
 * {@see ZoneKmlImporter} to turn into zones. Only `<Polygon>` placemarks are
 * imported — outer + inner boundary rings both supported, altitude stripped,
 * degenerate rings dropped or skipped. Never throws: malformed KML resolves
 * to an empty list, matching {@see Zone::geoJsonHasValidRing()}'s
 * "≥4 positions" ring-validity rule so imported geometry always survives
 * `rebuildGeometryFromDefinition()`.
 */
class KmlZoneParser
{
    private const NAMESPACE_URI = 'http://www.opengis.net/kml/2.2';

    /**
     * @return list<array{name: string, polygon: array{type: string, coordinates: array<int, array<int, array{0: float, 1: float}>>}|null, skip_reason: string|null}>
     */
    public function parse(string $kml): array
    {
        $placemarks = $this->extractPlacemarks($kml);

        $results = [];

        foreach ($placemarks as $index => $placemark) {
            $results[] = $this->parsePlacemark($placemark, $index + 1);
        }

        return $results;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function extractPlacemarks(string $kml): array
    {
        $previousSetting = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($kml);

            if ($xml === false) {
                return [];
            }

            $xml->registerXPathNamespace('kml', self::NAMESPACE_URI);
            $placemarks = $xml->xpath('//kml:Placemark');

            if (empty($placemarks)) {
                // KML without a namespaced root still parses fine with SimpleXML;
                // fall back to an unprefixed query for that case.
                $placemarks = $xml->xpath('//Placemark');
            }

            return $placemarks ?: [];
        } catch (Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
        }
    }

    /**
     * @return array{name: string, polygon: array{type: string, coordinates: array<int, array<int, array{0: float, 1: float}>>}|null, skip_reason: string|null}
     */
    private function parsePlacemark(SimpleXMLElement $placemark, int $position): array
    {
        $placemark->registerXPathNamespace('kml', self::NAMESPACE_URI);

        $name = $this->resolveName($placemark, $position);

        $polygons = $placemark->xpath('.//kml:Polygon');

        if (empty($polygons)) {
            return [
                'name' => $name,
                'polygon' => null,
                'skip_reason' => 'Placemark has no <Polygon> geometry.',
            ];
        }

        $polygon = $polygons[0];
        $polygon->registerXPathNamespace('kml', self::NAMESPACE_URI);

        $outerRing = $this->extractRing($polygon, './/kml:outerBoundaryIs/kml:LinearRing/kml:coordinates');

        if (! $this->isValidRing($outerRing)) {
            return [
                'name' => $name,
                'polygon' => null,
                'skip_reason' => 'Outer ring has fewer than 4 positions after closing.',
            ];
        }

        $holes = [];

        foreach ($polygon->xpath('.//kml:innerBoundaryIs/kml:LinearRing/kml:coordinates') as $innerCoordinates) {
            $hole = $this->coordinatesToRing((string) $innerCoordinates);

            if ($this->isValidRing($hole)) {
                $holes[] = $hole;
            }
        }

        return [
            'name' => $name,
            'polygon' => [
                'type' => 'Polygon',
                'coordinates' => [$outerRing, ...$holes],
            ],
            'skip_reason' => null,
        ];
    }

    private function resolveName(SimpleXMLElement $placemark, int $position): string
    {
        $nameNodes = $placemark->xpath('./kml:name');
        $name = $nameNodes !== [] ? trim((string) $nameNodes[0]) : '';

        return $name !== '' ? $name : "Imported Zone {$position}";
    }

    /**
     * @return list<array{0: float, 1: float}>|null
     */
    private function extractRing(SimpleXMLElement $polygon, string $xpath): ?array
    {
        $nodes = $polygon->xpath($xpath);

        if (empty($nodes)) {
            return null;
        }

        return $this->coordinatesToRing((string) $nodes[0]);
    }

    /**
     * Split whitespace-separated `lng,lat[,alt]` tuples, drop the altitude,
     * and force-close the ring by repeating the first position if needed.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function coordinatesToRing(string $coordinates): array
    {
        $positions = [];

        foreach (preg_split('/\s+/', trim($coordinates)) ?: [] as $tuple) {
            if ($tuple === '') {
                continue;
            }

            $parts = explode(',', $tuple);

            if (count($parts) < 2) {
                continue;
            }

            $positions[] = [(float) $parts[0], (float) $parts[1]];
        }

        if ($positions === []) {
            return [];
        }

        if ($positions[0] !== $positions[count($positions) - 1]) {
            $positions[] = $positions[0];
        }

        return $positions;
    }

    /**
     * Replicates Zone::geoJsonHasValidRing()'s ≥4-position rule per ring.
     *
     * @param  list<array{0: float, 1: float}>|null  $ring
     */
    private function isValidRing(?array $ring): bool
    {
        return is_array($ring) && count($ring) >= 4;
    }
}
