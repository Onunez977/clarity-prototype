<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Clickhouse\Queries\HitDirectHotspotQuery;
use App\Clickhouse\Queries\HotlistAddressGeohashQuery;
use App\Http\Controllers\Api\ClarityScoutMapController;
use App\Models\OrgRunArea;
use App\Support\Scout\Geohash;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Map helpers shared by the Scout map surfaces — date-range ladder, viewport
 * snapping, GeoJSON assembly, and run-area clip loading.
 *
 * Extracted for Scout Bird's Eye (DEV-2116) so the org-wide admin controller
 * reuses the exact aggregation/encoding the driver map uses. Scoped to the pure,
 * org-agnostic helpers; driver-only zone-clipping (assigned-zone semantics) stays
 * on {@see ClarityScoutMapController}.
 */
trait ScoutMapHelpers
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function getDateRange(string $range, string $timezone): array
    {
        $now = Carbon::now($timezone);

        return match ($range) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7' => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            'last_15' => [$now->copy()->subDays(15)->startOfDay(), $now->copy()->endOfDay()],
            'last_30' => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            'last_60' => [$now->copy()->subDays(60)->startOfDay(), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /**
     * Range-based cache TTL ladder; drops to 10s under admin impersonation so
     * admins see near-live data (mirrors ClickhouseQuery::query).
     */
    protected function mapCacheTtl(string $range): Carbon
    {
        if (session()?->has('admin_impersonator') ?? false) {
            return now()->addSeconds(10);
        }

        return match ($range) {
            'last_60' => now()->addMinutes(15),
            'last_30' => now()->addMinutes(5),
            'last_15' => now()->addMinutes(3),
            default => now()->addMinutes(2),
        };
    }

    /**
     * Round the viewport out to a coarse grid so nearby pans/zooms collapse to
     * one envelope (identical cache key + SQL window). Snapping OUTWARD keeps the
     * rendered viewport fully covered.
     *
     * @return array{north: float, south: float, east: float, west: float}
     */
    protected function snapBbox(Request $request, float $step = 0.02): array
    {
        return [
            'north' => ceil(((float) $request->input('north')) / $step) * $step,
            'south' => floor(((float) $request->input('south')) / $step) * $step,
            'east' => ceil(((float) $request->input('east')) / $step) * $step,
            'west' => floor(((float) $request->input('west')) / $step) * $step,
        ];
    }

    /**
     * Optional viewport bbox for the historical path branches (scanPaths / teammatePaths):
     * unlike the heatmap endpoints, a bbox-less call must keep working exactly as before
     * (DEV-2185), so the four params are validated but never required. Snapped outward on the
     * same grid as {@see snapBbox} so the fetch and cache key stay consistent.
     *
     * @return array{north: float, south: float, east: float, west: float}|null
     */
    protected function resolveOptionalBbox(Request $request): ?array
    {
        $request->validate([
            'north' => ['sometimes', 'numeric'],
            'south' => ['sometimes', 'numeric'],
            'east' => ['sometimes', 'numeric'],
            'west' => ['sometimes', 'numeric'],
        ]);

        if (! $request->filled('north') || ! $request->filled('south') || ! $request->filled('east') || ! $request->filled('west')) {
            return null;
        }

        return $this->snapBbox($request);
    }

    protected function validateHeatmapRequest(Request $request): void
    {
        $request->validate([
            'north' => ['required', 'numeric'],
            'south' => ['required', 'numeric'],
            'east' => ['required', 'numeric'],
            'west' => ['required', 'numeric'],
        ]);
    }

    /**
     * Raw geohash rows for a single order layer, scoped inside the query (direct
     * = the org itself; staging = its approved providers). A null run area means
     * none is materialized — the query no-ops its containment clip and the layer
     * falls back to viewport-bbox density.
     *
     * @param  array{id: int, version: string, rings: list<list<array{0: float, 1: float}>>}|null  $runArea
     * @return array<int, mixed>
     */
    protected function orderRows(int $orgId, bool $staging, int $precision, ?array $runArea, Request $request): array
    {
        return HotlistAddressGeohashQuery::query([
            'org_id' => $orgId,
            'staging' => $staging,
            'tiers' => ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'],
            ...$this->snapBbox($request),
            'precision' => $precision,
            'run_area_id' => $runArea['id'] ?? null,
            'run_area_version' => $runArea['version'] ?? null,
            '_run_area_rings' => $runArea['rings'] ?? null,
            'cacheTTL' => now()->addMinutes(5),
        ], cache: true);
    }

    /**
     * @return array{id: int, version: string, rings: list<list<array{0: float, 1: float}>>}|null
     */
    protected function loadRunAreaForClip(int $orgId): ?array
    {
        $row = OrgRunArea::query()
            ->withGeoJson()
            ->where('org_id', $orgId)
            ->first();

        if ($row === null || $row->geometry_geojson === null) {
            return null;
        }

        $geojson = json_decode((string) $row->geometry_geojson, true);
        $rings = $this->outerRingsFromGeoJson($geojson);

        if ($rings === []) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'version' => (string) ($row->materialized_at?->getTimestamp() ?? 0),
            'rings' => $rings,
        ];
    }

    /**
     * @return list<list<array{0: float, 1: float}>>
     */
    protected function outerRingsFromGeoJson(mixed $geojson): array
    {
        if (! is_array($geojson)) {
            return [];
        }

        $type = $geojson['type'] ?? null;
        $coordinates = $geojson['coordinates'] ?? null;

        if (! is_array($coordinates)) {
            return [];
        }

        $polygons = match ($type) {
            'Polygon' => [$coordinates],
            'MultiPolygon' => $coordinates,
            default => [],
        };

        $rings = [];

        foreach ($polygons as $polygon) {
            if (! is_array($polygon) || ! isset($polygon[0]) || ! is_array($polygon[0])) {
                continue;
            }

            $outer = [];

            foreach ($polygon[0] as $point) {
                if (is_array($point) && isset($point[0], $point[1])) {
                    $outer[] = [(float) $point[0], (float) $point[1]];
                }
            }

            if ($outer !== []) {
                $rings[] = $outer;
            }
        }

        return $rings;
    }

    protected function emptyFeatureCollection(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [],
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>
     */
    protected function toGeoJson(array $rows, string $type): array
    {
        $features = [];

        foreach ($rows as $row) {
            $geohash = $row->geohash ?? ($row['geohash'] ?? null);
            $countField = $type === 'hits' ? 'hit_count' : 'address_count';
            $count = (int) ($row->$countField ?? ($row[$countField] ?? 0));

            if (! $geohash) {
                continue;
            }

            $bounds = Geohash::decodeBounds($geohash);

            if (! $bounds) {
                continue;
            }

            if ($type === 'hits') {
                $color = HitDirectHotspotQuery::getTierColor($count);
                $tier = HitDirectHotspotQuery::getTier($count);
                $label = HitDirectHotspotQuery::getTierLabel($count);
            } else {
                $isStaging = $type === 'staging';
                $color = HotlistAddressGeohashQuery::getTierColor($count, staging: $isStaging);
                $tier = HotlistAddressGeohashQuery::getTier($count);
                $label = match (HotlistAddressGeohashQuery::getTier($count)) {
                    'tier_1' => '1 address',
                    'tier_2_4' => '2-4 addresses',
                    'tier_5_9' => '5-9 addresses',
                    'tier_10_24' => '10-24 addresses',
                    'tier_25_plus' => '25+ addresses',
                    default => '',
                };
            }

            $features[] = $this->geohashFeature($geohash, $count, $color, $tier, $label);
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    /**
     * Build a single FeatureCollection from already-summed per-geohash counts
     * (DEV-2031): one cell per geohash, shaded once by the combined ramp.
     *
     * @param  array<string, int>  $countsByGeohash
     * @return array<string, mixed>
     */
    protected function unionToGeoJson(array $countsByGeohash): array
    {
        $features = [];

        foreach ($countsByGeohash as $geohash => $count) {
            $bounds = Geohash::decodeBounds((string) $geohash);

            if (! $bounds) {
                continue;
            }

            $count = (int) $count;

            $features[] = $this->geohashFeature(
                (string) $geohash,
                $count,
                HotlistAddressGeohashQuery::getCombinedTierColor($count),
                HotlistAddressGeohashQuery::getCombinedTier($count),
                HotlistAddressGeohashQuery::getCombinedTierLabel($count),
            );
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function geohashFeature(string $geohash, int $count, mixed $color, mixed $tier, string $label): array
    {
        $bounds = Geohash::decodeBounds($geohash);

        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$bounds['min_lng'], $bounds['min_lat']],
                    [$bounds['max_lng'], $bounds['min_lat']],
                    [$bounds['max_lng'], $bounds['max_lat']],
                    [$bounds['min_lng'], $bounds['max_lat']],
                    [$bounds['min_lng'], $bounds['min_lat']],
                ]],
            ],
            'properties' => [
                'geohash' => $geohash,
                'count' => $count,
                'color' => $color,
                'tier' => $tier,
                'label' => $label,
            ],
        ];
    }
}
