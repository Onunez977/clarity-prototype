<?php

declare(strict_types=1);

namespace App\Support\Scout;

/**
 * Build a Mapbox Static Images URL with optional zone polygon overlays and one
 * polyline per drive segment.
 *
 * Returns null when `services.mapbox.token` is unset (mirrors the existing
 * pattern in resources/views/mail/missed-opportunities/new-notification.blade.php
 * and resources/views/mail/missed-opportunities/new-lienholder-email.blade.php).
 *
 * Mapbox caps overlay payload size; we simplify polygons before encoding and
 * fall back to a plain map (no overlays) if the URL would exceed ~7,500 chars.
 */
final class MapboxStaticMapBuilder
{
    private const STYLE = 'mapbox/outdoors-v11';

    private const SIZE = '600x300@2x';

    private const MAX_URL_LENGTH = 7500;

    private const PATH_STROKE_COLOR = '0f29eb';

    private const ZONE_FILL_COLOR = '2563eb';

    /**
     * @param  array<int, array<int, array{0: float, 1: float}>>  $tripSegments  Each segment: ordered [lng, lat] pairs.
     * @param  array<int, array<string, mixed>>  $zonePolygons  Each entry: { name: string, geojson: array }.
     *                                                          `geojson` must be a Polygon or MultiPolygon feature/geometry.
     */
    public function build(array $tripSegments, array $zonePolygons): ?string
    {
        $token = config('services.mapbox.token');

        if (! $token) {
            return null;
        }

        $overlays = [];

        foreach ($zonePolygons as $zone) {
            $geojson = $zone['geojson'] ?? null;

            if (! is_array($geojson)) {
                continue;
            }

            // Wrap as a Feature so Mapbox respects the style properties.
            $feature = [
                'type' => 'Feature',
                'properties' => [
                    'fill' => '#'.self::ZONE_FILL_COLOR,
                    'fill-opacity' => 0.18,
                    'stroke' => '#'.self::ZONE_FILL_COLOR,
                    'stroke-width' => 2,
                    'stroke-opacity' => 0.8,
                ],
                'geometry' => isset($geojson['type']) && $geojson['type'] === 'Feature'
                    ? ($geojson['geometry'] ?? null)
                    : $geojson,
            ];

            if (! is_array($feature['geometry'])) {
                continue;
            }

            $overlays[] = 'geojson('.rawurlencode((string) json_encode($feature)).')';
        }

        foreach ($tripSegments as $segment) {
            if (count($segment) < 2) {
                continue;
            }

            $pathFeature = [
                'type' => 'Feature',
                'properties' => [
                    'stroke' => '#'.self::PATH_STROKE_COLOR,
                    'stroke-width' => 3,
                    'stroke-opacity' => 0.9,
                ],
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => $segment,
                ],
            ];

            $overlays[] = 'geojson('.rawurlencode((string) json_encode($pathFeature)).')';
        }

        return $this->assembleUrl($overlays, $token);
    }

    /**
     * @param  array<int, string>  $overlays
     */
    private function assembleUrl(array $overlays, string $token): string
    {
        $base = 'https://api.mapbox.com/styles/v1/'.self::STYLE.'/static/';
        $tail = '/auto/'.self::SIZE.'?attribution=false&logo=false&access_token='.$token;

        $url = $base.implode(',', $overlays).$tail;

        if (strlen($url) <= self::MAX_URL_LENGTH) {
            return $url;
        }

        // Drop overlays until the URL fits; worst case is a plain auto-fit map.
        while ($overlays && strlen($url) > self::MAX_URL_LENGTH) {
            array_shift($overlays);
            $url = $base.implode(',', $overlays).$tail;
        }

        if (! $overlays) {
            // Mapbox requires something before /auto; a hidden marker keeps the URL valid.
            $url = $base.'pin-s+ffffff(0,0)'.$tail;
        }

        return $url;
    }
}
