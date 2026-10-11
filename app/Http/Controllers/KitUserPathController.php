<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Clickhouse\Queries\HitDensityGeohashQuery;
use App\Clickhouse\Queries\HotlistAddressGeohashQuery;
use App\Clickhouse\Queries\KitScanPathsQuery;
use App\Enums\FeatureEnum;
use App\Filament\Tables\Filters\KitUsersFilter;
use App\Http\Requests\KitUserPathGeojsonRequest;
use App\Http\Requests\KitUserPathHitDensityRequest;
use App\Http\Requests\KitUserPathRequest;
use App\Models\Kit;
use App\Support\Scout\Geohash;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * Kit User Path renders from scan data only —
 * `clarity.kit_scan_paths` via {@see KitScanPathsQuery}, global across every
 * org and kit. This is a DEV-2451 revert to its pre-`sc_positions` behavior
 * and is interim, pending DEV-2450, which restores enriched/continuous
 * rendering. Scout Bird's Eye deliberately still renders continuous GPS via
 * `KitRangePathsQuery` — the two surfaces intentionally diverge again after
 * DEV-2374 had aligned them.
 *
 * @codeCoverageIgnore
 */
class KitUserPathController extends Controller
{
    /**
     * Max rendered points before a selection is refused. Rows arrive from
     * ClickHouse as a pre-encoded JSON string per (kit, day) bucket
     * ({@see KitScanPathsQuery::encodedPaths()}) rather than a decoded array
     * per point — cost is O(buckets), and this is a backstop against a
     * pathological selection, not the old ~1.5KB/point memory ceiling.
     * protected so a test can lower it via subclass.
     *
     * With tier-1 gone, this is now evaluated against undecimated scan
     * points rather than the old dedup-thinned ones: measured against prod
     * `kit_scan_paths`, a 30-day All-Kits selection exceeds it for 2 of 263
     * orgs (422). Accepted/deliberate for DEV-2451, pending DEV-2450.
     */
    protected const MAX_RENDER_POINTS = 1500000;

    public function paths(KitUserPathRequest $request)
    {
        $validated = $request->safe()->only(['kits', 'date_toggles', 'address']);

        $kitIds = KitUsersFilter::format($validated['kits']);

        $start = match ($validated['date_toggles']) {
            'yesterday' => now($request->user()->timezone)->subDay()->startOfDay(),
            '7_days' => now($request->user()->timezone)->subDays(7)->startOfDay(),
            '15_days' => now($request->user()->timezone)->subDays(15)->startOfDay(),
            '30_days' => now($request->user()->timezone)->subDays(30)->startOfDay(),
            default => now($request->user()->timezone)->subDays(7)->startOfDay(),
        };

        $end = match ($validated['date_toggles']) {
            'yesterday' => now($request->user()->timezone)->subDay()->endOfDay(),
            default => now($request->user()->timezone)->endOfDay(),
        };

        // kit_scan_paths wants LOCAL wall-clock strings.
        $localStart = $start->toDateTimeString();
        $localEnd = $end->toDateTimeString();

        try {
            $byId = $this->kitsById($kitIds);

            if ($byId === []) {
                return response()->json([]);
            }

            $result = $this->scanPathRows(
                $byId,
                $localStart,
                $localEnd,
                (int) $request->user()->org_id,
                (string) $request->user()->timezone,
                $validated['date_toggles'],
            );

            if ($result['status'] === 'over_budget') {
                return response()->json(['error' => 'Selection too large to render — narrow the kits or shorten the date range.'], 422);
            }

            if ($result['status'] === 'unavailable') {
                return response()->json(['error' => 'Scan-path route unavailable'], 503);
            }

            return response('['.implode(',', $result['rows']).']', 200, ['Content-Type' => 'application/json']);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());

            return response()->json(['error' => 'Failed to fetch data'], 500);
        }
    }

    /**
     * Reads clarity.kit_scan_paths for every kit in $byId, PER-KIT for the
     * WHOLE window (PR #320's original allocation). Rows arrive pre-encoded
     * per (kit, day) bucket and are spliced in verbatim — decoding them into
     * PHP arrays just to re-encode them is the per-point memory cost this
     * endpoint exists to avoid.
     *
     * @param  array<int, Kit>  $byId
     * @return array{status: 'ok'|'over_budget'|'unavailable', rows: list<string>}
     */
    private function scanPathRows(
        array $byId,
        string $localStart,
        string $localEnd,
        int $orgId,
        string $timezone,
        string $layerId,
    ): array {
        $budgetArguments = [
            'org_id' => $orgId,
            'kit_ids' => array_keys($byId),
            'start' => $localStart,
            'end' => $localEnd,
            'timezone' => $timezone,
        ];

        try {
            $pointCount = KitScanPathsQuery::countPoints($budgetArguments);
        } catch (\Throwable $exception) {
            report($exception);
            Log::warning('kit user path scan-path budget count failed', [
                'org_id' => $orgId,
                'eligible_kits' => count($byId),
                'exception' => $exception->getMessage(),
            ]);

            return ['status' => 'unavailable', 'rows' => []];
        }

        if ($pointCount > static::MAX_RENDER_POINTS) {
            return ['status' => 'over_budget', 'rows' => []];
        }

        // Accumulate into a local list and return only after the read
        // completes — a mid-loop throw must not ship partial rows.
        $rows = [];

        try {
            // encodedPaths(), not handle()/query(): handle() decodes
            // path_points into a PHP array per point; query() swallows
            // Throwable to [] in production/testing. encodedPaths() hands
            // back one pre-encoded JSON string per (kit, day) bucket.
            $encodedRows = KitScanPathsQuery::encodedPaths($budgetArguments);

            foreach ($encodedRows as $row) {
                $kit = $byId[$row['kit_id']] ?? null;

                if ($kit === null) {
                    continue;
                }

                $rows[] = $this->scanPathRow($kit, $row['path_json'], $layerId, $row['trip_date']);
            }
        } catch (\Throwable $exception) {
            report($exception);
            Log::warning('kit user path scan-path read failed', [
                'org_id' => $orgId,
                'eligible_kits' => count($byId),
                'exception' => $exception->getMessage(),
            ]);

            return ['status' => 'unavailable', 'rows' => []];
        }

        return ['status' => 'ok', 'rows' => $rows];
    }

    /**
     * kit_scan_paths rows arrive pre-encoded from
     * {@see KitScanPathsQuery::encodedPaths()} — spliced in verbatim, never
     * decoded/re-encoded. name/color are taken from the resolved Kit, never
     * the ClickHouse row.
     */
    private function scanPathRow(Kit $kit, string $pathJson, string $layerId, string $tripDate): string
    {
        return '{"kit_id":'.((int) $kit->id)
            .',"name":'.json_encode((string) $kit->name, JSON_INVALID_UTF8_SUBSTITUTE)
            .',"color":'.json_encode(KitScanPathsQuery::getColor((int) $kit->id))
            .',"path":'.$pathJson
            .',"layerId":'.json_encode($layerId)
            .',"trip_date":'.json_encode($tripDate)
            .'}';
    }

    /**
     * Resolve the given (already org-scoped) kit ids into a kit_id => Kit
     * map. clarity.kit_scan_paths is keyed by kit_id alone, so this is the
     * full eligible set.
     *
     * @param  list<int>  $kitIds
     * @return array<int, Kit>
     */
    private function kitsById(array $kitIds): array
    {
        $byId = [];

        foreach (Kit::query()->whereIn('id', $kitIds)->get() as $kit) {
            $byId[(int) $kit->id] = $kit;
        }

        return $byId;
    }

    public function geojson(KitUserPathGeojsonRequest $request)
    {
        $north = (float) $request->input('north');
        $south = (float) $request->input('south');
        $east = (float) $request->input('east');
        $west = (float) $request->input('west');

        $staging = $request->boolean('staging', false);
        $tiers = $request->input('tiers', []);

        try {
            $rows = HotlistAddressGeohashQuery::query([
                'org_id' => $request->user()->org_id,
                'staging' => $staging,
                'tiers' => $tiers,
                'north' => $north,
                'south' => $south,
                'east' => $east,
                'west' => $west,
            ]);

            $features = [];
            foreach ($rows as $row) {
                $geohash = $row->geohash ?? ($row['geohash'] ?? null);
                $count = (int) ($row->address_count ?? ($row['address_count'] ?? 0));

                if (! $geohash) {
                    continue;
                }

                $bounds = Geohash::decodeBounds($geohash);

                if (! $bounds) {
                    continue;
                }

                $features[] = [
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
                        'color' => HotlistAddressGeohashQuery::getTierColor($count, $staging),
                        'tier' => HotlistAddressGeohashQuery::getTier($count),
                        'staging' => $staging,
                        'label' => match (HotlistAddressGeohashQuery::getTier($count)) {
                            'tier_1' => '1 address',
                            'tier_2_4' => '2-4 addresses',
                            'tier_5_9' => '5-9 addresses',
                            'tier_10_24' => '10-24 addresses',
                            'tier_25_plus' => '25+ addresses',
                            default => '',
                        },
                    ],
                ];
            }

            return response()->json([
                'type' => 'FeatureCollection',
                'features' => $features,
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());

            return response()->json(['error' => 'Failed to generate GeoJSON'], 500);
        }
    }

    public function hitDensity(KitUserPathHitDensityRequest $request)
    {
        if (Feature::for($request->user())->inactive(FeatureEnum::REPO_HEATMAP->slug()) && session()->missing('admin_impersonator')) {
            return response()->json([]);
        }

        $north = (float) $request->input('north');
        $south = (float) $request->input('south');
        $east = (float) $request->input('east');
        $west = (float) $request->input('west');

        $tiers = $request->input('tiers', []);

        try {
            $rows = HitDensityGeohashQuery::query([
                'org_id' => $request->user()->org_id,
                'tiers' => $tiers,
                'north' => $north,
                'south' => $south,
                'east' => $east,
                'west' => $west,
            ]);

            $features = [];
            foreach ($rows as $row) {
                $geohash = $row->geohash ?? ($row['geohash'] ?? null);
                $count = (int) ($row->hit_count ?? ($row['hit_count'] ?? 0));

                if (! $geohash) {
                    continue;
                }

                $bounds = Geohash::decodeBounds($geohash);

                if (! $bounds) {
                    continue;
                }

                $features[] = [
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
                        'color' => HitDensityGeohashQuery::getTierColor($count),
                        'tier' => HitDensityGeohashQuery::getTier($count),
                        'label' => HitDensityGeohashQuery::getTierLabel($count),
                    ],
                ];
            }

            return response()->json([
                'type' => 'FeatureCollection',
                'features' => $features,
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());

            return response()->json(['error' => 'Failed to generate hit density GeoJSON'], 500);
        }
    }
}
