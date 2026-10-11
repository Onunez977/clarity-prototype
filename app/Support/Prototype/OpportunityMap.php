<?php

declare(strict_types=1);

namespace App\Support\Prototype;

/**
 * Coordinate plot for Opportunities. Same idea as the Locations page:
 * the SVG stays readable when Google tiles are unavailable.
 */
final class OpportunityMap
{
    /**
     * @param  list<array<string, mixed>>  $circles
     * @param  list<array<string, mixed>>  $polygons
     * @param  list<array<string, mixed>>  $pins
     * @return array{width: int, height: int, circles: list<array<string, mixed>>, polygons: list<array<string, mixed>>, pins: list<array<string, mixed>>}
     */
    public static function project(array $circles, array $polygons, array $pins, int $width = 800, int $height = 340): array
    {
        $pad = 28;
        $points = [];

        foreach ($circles as $circle) {
            $lat = (float) $circle['lat'];
            $lng = (float) $circle['lng'];
            $miles = (float) $circle['radiusMiles'];
            $dLat = $miles / 69;
            $dLng = $miles / max(1, 69 * cos(deg2rad($lat)));
            $points[] = [$lng - $dLng, $lat - $dLat];
            $points[] = [$lng + $dLng, $lat + $dLat];
        }

        foreach ($polygons as $polygon) {
            foreach ($polygon['path'] as $point) {
                $points[] = [(float) $point[0], (float) $point[1]];
            }
        }

        foreach ($pins as $pin) {
            if ($pin['lat'] === null || $pin['lng'] === null) {
                continue;
            }
            $points[] = [(float) $pin['lng'], (float) $pin['lat']];
        }

        if ($points === []) {
            return ['width' => $width, 'height' => $height, 'circles' => [], 'polygons' => [], 'pins' => []];
        }

        $lngs = array_column($points, 0);
        $lats = array_column($points, 1);
        $minLng = min($lngs);
        $maxLng = max($lngs);
        $minLat = min($lats);
        $maxLat = max($lats);
        if ($maxLng - $minLng < 0.01) {
            $minLng -= 0.008;
            $maxLng += 0.008;
        }
        if ($maxLat - $minLat < 0.01) {
            $minLat -= 0.008;
            $maxLat += 0.008;
        }
        $spanLng = $maxLng - $minLng;
        $spanLat = $maxLat - $minLat;
        $innerW = $width - ($pad * 2);
        $innerH = $height - ($pad * 2);

        $xy = function (float $lng, float $lat) use ($minLng, $maxLat, $spanLng, $spanLat, $innerW, $innerH, $pad): array {
            return [
                $pad + (($lng - $minLng) / $spanLng) * $innerW,
                $pad + (($maxLat - $lat) / $spanLat) * $innerH,
            ];
        };

        $drawnCircles = [];
        foreach ($circles as $circle) {
            $lat = (float) $circle['lat'];
            [$cx, $cy] = $xy((float) $circle['lng'], $lat);
            $dLat = ((float) $circle['radiusMiles']) / 69;
            $dLng = ((float) $circle['radiusMiles']) / max(1, 69 * cos(deg2rad($lat)));
            [$east] = $xy((float) $circle['lng'] + $dLng, $lat);
            [, $north] = $xy((float) $circle['lng'], $lat + $dLat);
            $drawnCircles[] = [
                'cx' => round($cx, 1),
                'cy' => round($cy, 1),
                'rx' => max(2, round(abs($east - $cx), 1)),
                'ry' => max(2, round(abs($north - $cy), 1)),
                'color' => $circle['color'] ?? '#FF6666',
                'label' => $circle['label'] ?? '',
                'dashed' => (bool) ($circle['dashed'] ?? false),
                'opacity' => $circle['opacity'] ?? 0.12,
            ];
        }

        $drawnPolygons = [];
        foreach ($polygons as $polygon) {
            $commands = [];
            foreach ($polygon['path'] as $point) {
                [$x, $y] = $xy((float) $point[0], (float) $point[1]);
                $commands[] = round($x, 1).','.round($y, 1);
            }
            $drawnPolygons[] = [
                'points' => implode(' ', $commands),
                'color' => $polygon['color'] ?? '#FF6666',
                'label' => $polygon['label'] ?? '',
            ];
        }

        $drawnPins = [];
        foreach ($pins as $pin) {
            if ($pin['lat'] === null || $pin['lng'] === null) {
                continue;
            }
            [$x, $y] = $xy((float) $pin['lng'], (float) $pin['lat']);
            $drawnPins[] = [
                'x' => round($x, 1),
                'y' => round($y, 1),
                'color' => $pin['color'] ?? '#111827',
                'label' => $pin['label'] ?? '',
                'shape' => $pin['shape'] ?? 'dot',
            ];
        }

        return [
            'width' => $width,
            'height' => $height,
            'circles' => $drawnCircles,
            'polygons' => $drawnPolygons,
            'pins' => $drawnPins,
        ];
    }
}
