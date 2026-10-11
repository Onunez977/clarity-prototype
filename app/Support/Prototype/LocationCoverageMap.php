<?php

declare(strict_types=1);

namespace App\Support\Prototype;

use App\Enums\OrgLocationType;
use App\Models\OrgCoverageZone;
use App\Models\OrgLocation;
use Illuminate\Support\Collection;

/**
 * Display data for the Configuration locations map and list.
 * The SVG plot is the readable map when Google tiles are unavailable.
 */
final class LocationCoverageMap
{
    /**
     * @return array{
     *     hasKey: bool,
     *     summary: string,
     *     scope: string,
     *     scopeLabel: string,
     *     rows: list<array<string, mixed>>,
     *     markers: list<array<string, mixed>>,
     *     shapes: list<array<string, mixed>>,
     *     svg: array{width: int, height: int, pins: list<array<string, mixed>>, circles: list<array<string, mixed>>, polygons: list<array<string, mixed>>}
     * }
     */
    public static function present(int $orgId): array
    {
        $locations = OrgLocation::query()
            ->where('org_id', $orgId)
            ->orderByRaw("case type when 'main_office' then 0 when 'storage_facility' then 1 else 2 end")
            ->orderBy('name')
            ->get();

        $coverage = OrgCoverageZone::query()->where('org_id', $orgId)->first();
        $scope = $coverage?->scope === 'location' ? 'location' : 'company';
        $shapes = self::shapes($locations, $coverage, $scope);
        $summary = self::summary($locations, $coverage, $scope, $shapes);

        $rows = [];
        foreach ($locations as $location) {
            $type = $location->typeEnum();
            $rows[] = [
                'id' => $location->id,
                'name' => $location->name,
                'type' => $type->value,
                'type_label' => $type->label(),
                'color' => $type->color(),
                'pin' => $type->pin(),
                'address' => $location->address,
                'phone' => $location->phone,
                'notes' => $location->notes,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'coverage' => $scope === 'company'
                    ? 'Included in company-wide zone'
                    : self::locationSummary($location),
            ];
        }

        return [
            'hasKey' => filled(config('services.google.maps.key')),
            'summary' => $summary,
            'scope' => $scope,
            'scopeLabel' => $scope === 'company' ? 'Company-wide zone' : 'Per location',
            'rows' => $rows,
            'markers' => array_values(array_filter(array_map(
                fn (OrgLocation $location) => $location->latitude === null || $location->longitude === null ? null : [
                    'id' => $location->id,
                    'name' => $location->name,
                    'type' => $location->typeEnum()->label(),
                    'color' => $location->typeEnum()->color(),
                    'pin' => $location->typeEnum()->pin(),
                    'lat' => (float) $location->latitude,
                    'lng' => (float) $location->longitude,
                ],
                $locations->all(),
            ))),
            'shapes' => $shapes,
            'svg' => self::project($locations, $shapes),
        ];
    }

    /**
     * @param  Collection<int, OrgLocation>  $locations
     * @return list<array<string, mixed>>
     */
    private static function shapes(Collection $locations, ?OrgCoverageZone $coverage, string $scope): array
    {
        if ($scope === 'company') {
            return self::companyShapes($locations, $coverage);
        }

        $shapes = [];
        foreach ($locations as $location) {
            $shape = self::locationShape($location);
            if ($shape !== null) {
                $shapes[] = $shape;
            }
        }

        return $shapes;
    }

    /**
     * @param  Collection<int, OrgLocation>  $locations
     * @return list<array<string, mixed>>
     */
    private static function companyShapes(Collection $locations, ?OrgCoverageZone $coverage): array
    {
        if ($coverage === null) {
            return [];
        }

        if ($coverage->mode === 'polygon' && self::ring($coverage->polygon) !== []) {
            return [[
                'kind' => 'polygon',
                'color' => '#FF6666',
                'label' => 'Company-wide zone',
                'path' => self::ring($coverage->polygon),
            ]];
        }

        $center = $locations->firstWhere('id', $coverage->center_location_id);
        if ($coverage->mode === 'radius' && $coverage->radius_miles && $center?->latitude !== null && $center->longitude !== null) {
            return [[
                'kind' => 'circle',
                'color' => '#FF6666',
                'label' => 'Company-wide zone',
                'lat' => (float) $center->latitude,
                'lng' => (float) $center->longitude,
                'radiusMiles' => (float) $coverage->radius_miles,
            ]];
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function locationShape(OrgLocation $location): ?array
    {
        $color = $location->typeEnum()->color();

        if ($location->coverage_mode === 'polygon' && self::ring($location->coverage_polygon) !== []) {
            return [
                'kind' => 'polygon',
                'color' => $color,
                'label' => $location->name,
                'path' => self::ring($location->coverage_polygon),
            ];
        }

        if ($location->coverage_mode === 'radius' && $location->coverage_radius_miles && $location->latitude !== null && $location->longitude !== null) {
            return [
                'kind' => 'circle',
                'color' => $color,
                'label' => $location->name,
                'lat' => (float) $location->latitude,
                'lng' => (float) $location->longitude,
                'radiusMiles' => (float) $location->coverage_radius_miles,
            ];
        }

        return null;
    }

    /**
     * @param  Collection<int, OrgLocation>  $locations
     * @param  list<array<string, mixed>>  $shapes
     */
    private static function summary(Collection $locations, ?OrgCoverageZone $coverage, string $scope, array $shapes): string
    {
        if ($scope === 'location') {
            $count = count($shapes);

            return $count === 0
                ? 'Per location: no coverage set yet'
                : 'Per location: '.$count.' '.($count === 1 ? 'zone' : 'zones');
        }

        if ($coverage?->mode === 'polygon' && self::ring($coverage->polygon) !== []) {
            $points = self::pointCount($coverage->polygon);

            return 'Company-wide: drawn area ('.$points.' points)';
        }

        $center = $locations->firstWhere('id', $coverage?->center_location_id);
        if ($coverage?->mode === 'radius' && $coverage->radius_miles && $center) {
            return 'Company-wide: '.self::miles((float) $coverage->radius_miles).' around '.$center->name;
        }

        return 'Company-wide: no zone set yet';
    }

    public static function locationSummary(OrgLocation $location): string
    {
        if ($location->coverage_mode === 'radius' && $location->coverage_radius_miles) {
            return self::miles((float) $location->coverage_radius_miles).' radius';
        }

        if ($location->coverage_mode === 'polygon' && self::ring($location->coverage_polygon) !== []) {
            return 'Drawn area ('.self::pointCount($location->coverage_polygon).' points)';
        }

        return 'No coverage';
    }

    public static function miles(float $miles): string
    {
        $formatted = rtrim(rtrim(number_format($miles, 1, '.', ''), '0'), '.');

        return $formatted.' '.((float) $formatted === 1.0 ? 'mile' : 'miles');
    }

    /**
     * @param  array<string, mixed>|null  $polygon
     */
    public static function pointCount(?array $polygon): int
    {
        $ring = self::ring($polygon);
        if ($ring === []) {
            return 0;
        }

        $first = $ring[0];
        $last = $ring[array_key_last($ring)];
        $closed = $first[0] === $last[0] && $first[1] === $last[1];

        return $closed ? count($ring) - 1 : count($ring);
    }

    /**
     * @param  array<string, mixed>|null  $polygon
     * @return list<array{0: float, 1: float}>
     */
    public static function ring(?array $polygon): array
    {
        $ring = $polygon['coordinates'][0] ?? null;
        if (! is_array($ring)) {
            return [];
        }

        $points = [];
        foreach ($ring as $point) {
            if (! is_array($point) || count($point) < 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                continue;
            }
            $points[] = [(float) $point[0], (float) $point[1]];
        }

        return count($points) >= 4 ? $points : [];
    }

    /**
     * @param  Collection<int, OrgLocation>  $locations
     * @param  list<array<string, mixed>>  $shapes
     * @return array{width: int, height: int, pins: list<array<string, mixed>>, circles: list<array<string, mixed>>, polygons: list<array<string, mixed>>}
     */
    private static function project(Collection $locations, array $shapes): array
    {
        $width = 800;
        $height = 340;
        $pad = 36;
        $points = [];

        foreach ($locations as $location) {
            if ($location->latitude !== null && $location->longitude !== null) {
                $points[] = [(float) $location->longitude, (float) $location->latitude];
            }
        }

        foreach ($shapes as $shape) {
            if ($shape['kind'] === 'polygon') {
                foreach ($shape['path'] as $point) {
                    $points[] = $point;
                }
            }
            if ($shape['kind'] === 'circle') {
                $lat = (float) $shape['lat'];
                $miles = (float) $shape['radiusMiles'];
                $dLat = $miles / 69;
                $dLng = $miles / max(1, 69 * cos(deg2rad($lat)));
                $points[] = [(float) $shape['lng'] - $dLng, $lat - $dLat];
                $points[] = [(float) $shape['lng'] + $dLng, $lat + $dLat];
            }
        }

        if ($points === []) {
            return ['width' => $width, 'height' => $height, 'pins' => [], 'circles' => [], 'polygons' => []];
        }

        $lngs = array_column($points, 0);
        $lats = array_column($points, 1);
        $minLng = min($lngs);
        $maxLng = max($lngs);
        $minLat = min($lats);
        $maxLat = max($lats);
        if ($maxLng - $minLng < 0.05) {
            $minLng -= 0.04;
            $maxLng += 0.04;
        }
        if ($maxLat - $minLat < 0.05) {
            $minLat -= 0.04;
            $maxLat += 0.04;
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

        $circles = [];
        $polygons = [];
        foreach ($shapes as $shape) {
            if ($shape['kind'] === 'circle') {
                $lat = (float) $shape['lat'];
                [$cx, $cy] = $xy((float) $shape['lng'], $lat);
                $dLat = ((float) $shape['radiusMiles']) / 69;
                $dLng = ((float) $shape['radiusMiles']) / max(1, 69 * cos(deg2rad($lat)));
                [$east] = $xy((float) $shape['lng'] + $dLng, $lat);
                [, $north] = $xy((float) $shape['lng'], $lat + $dLat);
                $circles[] = [
                    'cx' => round($cx, 1),
                    'cy' => round($cy, 1),
                    'rx' => round(abs($east - $cx), 1),
                    'ry' => round(abs($north - $cy), 1),
                    'color' => $shape['color'],
                    'label' => $shape['label'],
                ];
            }
            if ($shape['kind'] === 'polygon') {
                $commands = [];
                foreach ($shape['path'] as $point) {
                    [$x, $y] = $xy($point[0], $point[1]);
                    $commands[] = round($x, 1).','.round($y, 1);
                }
                $polygons[] = [
                    'points' => implode(' ', $commands),
                    'color' => $shape['color'],
                    'label' => $shape['label'],
                ];
            }
        }

        $pins = [];
        foreach ($locations as $location) {
            if ($location->latitude === null || $location->longitude === null) {
                continue;
            }
            [$x, $y] = $xy((float) $location->longitude, (float) $location->latitude);
            $type = $location->typeEnum();
            $pins[] = [
                'x' => round($x, 1),
                'y' => round($y, 1),
                'color' => $type->color(),
                'pin' => $type->pin(),
                'label' => $location->name,
            ];
        }

        return compact('width', 'height') + [
            'pins' => $pins,
            'circles' => $circles,
            'polygons' => $polygons,
        ];
    }
}
