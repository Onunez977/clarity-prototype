<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Clickhouse\Models\Hit as ClickhouseHit;
use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Clickhouse\Models\KitTrip;
use App\Clickhouse\Queries\HitDirectHotspotQuery;
use App\Clickhouse\Queries\KitHistoricPositionsQuery;
use App\Clickhouse\Queries\KitScanPathsQuery;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Enums\ScoutMapLayerEnum;
use App\Http\Controllers\Api\Concerns\ScoutMapHelpers;
use App\Http\Controllers\Controller;
use App\Models\Kit;
use App\Models\ScoutOrgSettings;
use App\Models\User;
use App\Models\Zone;
use App\Support\Scout\HistoricPathBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClarityScoutMapController extends Controller
{
    use ScoutMapHelpers;

    /**
     * Cap on individual hit markers returned for the main-map date-range view.
     * A driver can rack up hundreds of hits over a wide range; the honey-hole
     * density layer carries the fuller picture, so we render the most recent N
     * as tappable logo markers and flag when the list was capped (DEV-2016).
     */
    private const MAX_MAP_HITS = 100;

    public function scanPaths(Request $request): JsonResponse
    {
        $user = $request->user();
        $timezone = $user->timezone;
        $range = $request->query('range');

        try {
            // No range param = today's paths for the standalone Map page. Tagged
            // is_today so the client paints them red (the "current shift" color).
            if (! $range) {
                $data = KitScanPathsQuery::query([
                    'org_id' => $user->org_id,
                    'kit_ids' => [$user->kit_id],
                    'start' => now($timezone)->startOfDay()->toDateTimeString(),
                    'end' => now($timezone)->endOfDay()->toDateTimeString(),
                    'timezone' => $timezone,
                    'layer' => 'today',
                    'cacheTTL' => now()->addMinutes(5),
                ], cache: true);

                return response()->json(array_map(
                    static fn ($row) => [
                        'path' => $row['path'] ?? ($row->path ?? []),
                        'is_today' => true,
                    ],
                    is_array($data) ? $data : $data->toArray(),
                ));
            }

            // Range provided = historical drive overlay, rebuilt from raw scanner
            // GPS (clarity.sc_positions) as per-hour line strings rather than one
            // continuous line — see DEV-2018 / {@see HistoricPathBuilder}. The
            // client colors each path by recency from trip_date.
            [$start, $end] = $this->getDateRange($range, $timezone);
            $debug = config('app.scanner_debug_map_enabled') === true;
            $bbox = $this->resolveOptionalBbox($request);

            $cacheKey = "scanner:density:{$user->org_id}:{$user->kit_id}:{$range}"
                .($debug ? ':debug' : '')
                .($bbox !== null ? ':bbox='.implode(',', [$bbox['north'], $bbox['south'], $bbox['east'], $bbox['west']]) : '');
            $ttl = match ($range) {
                'last_60' => now()->addMinutes(15),
                'last_30' => now()->addMinutes(5),
                'last_15' => now()->addMinutes(3),
                default => now()->addMinutes(2),
            };

            $data = Cache::remember($cacheKey, $ttl, function () use ($user, $start, $end, $timezone, $debug, $bbox) {
                try {
                    $positions = KitHistoricPositionsQuery::query([
                        'kit_mapping' => $user->kit?->kit_mapping ?? '',
                        'start' => $start->toDateTimeString(),
                        'end' => $end->toDateTimeString(),
                        'timezone' => $timezone,
                        ...($bbox ?? []),
                    ]);
                } catch (\Throwable $e) {
                    // Degrade like kitPosition: an empty overlay beats a
                    // 500 when sc_positions is unreachable or unbridged locally.
                    Log::warning('scan-paths historic read failed; returning empty overlay', [
                        'kit_id' => $user->kit_id,
                        'org_id' => $user->org_id,
                        'exception' => $e->getMessage(),
                    ]);

                    return [];
                }

                return HistoricPathBuilder::build($positions, $timezone, $debug);
            });

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return response()->json(['error' => 'Failed to fetch scan paths'], 500);
        }
    }

    /**
     * DEV-2199: STAGING-ONLY, config-gated overlay of OTHER org drivers' driven
     * lines (today + last 7 days), snapshot from {@see KitScanPathsQuery} —
     * the same org-wide reader {@see ClarityScoutBirdsEyeController::scanPaths}
     * uses, re-scoped here to the driver-authenticated map. Off by default
     * (prod guard); when on, the org's Scout kit set (all non-deleted kits,
     * mirroring {@see ClarityScoutBirdsEyeController::orgScoutKits} — enrollment
     * doesn't gate visibility) is resolved minus the caller's own kit, then
     * clipped to the client viewport. The per-kit color/name the query emits is
     * dead here (a single flat color is applied client-side), so only the raw
     * path is returned — no live position, no per-kit metric.
     */
    public function teammatePaths(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ScoutOrgSettings::isLayerEnabled($user, ScoutMapLayerEnum::Teammates)) {
            return response()->json([]);
        }

        $timezone = $user->timezone;

        $kitIds = array_values(array_diff(
            $this->orgKitIds((int) $user->org_id),
            [(int) $user->kit_id]
        ));

        if ($kitIds === []) {
            return response()->json([]);
        }

        [$start, $end] = $this->getDateRange('last_7', $timezone);
        $bbox = $this->resolveOptionalBbox($request);

        try {
            $rows = KitScanPathsQuery::query([
                'org_id' => (int) $user->org_id,
                'kit_ids' => $kitIds,
                'start' => $start->toDateTimeString(),
                'end' => $end->toDateTimeString(),
                'timezone' => $timezone,
                'layer' => 'teammates',
                'cacheTTL' => $this->mapCacheTtl('last_7'),
                ...($bbox ?? []),
            ], cache: true);
        } catch (\Throwable $e) {
            Log::warning('teammate-paths read failed; returning empty overlay', [
                'kit_id' => $user->kit_id,
                'org_id' => $user->org_id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([]);
        }

        return response()->json(array_map(
            static fn ($row) => ['path' => $row['path'] ?? ($row->path ?? [])],
            is_array($rows) ? $rows : $rows->toArray(),
        ));
    }

    /**
     * The org's Scout kit ids (all non-deleted kits — enrollment doesn't gate
     * visibility, mirroring {@see ClarityScoutBirdsEyeController::orgScoutKits}),
     * minus per-kit labelling (teammatePaths discards color/name, so the
     * cross-connection kit_settings resolve those callers need doesn't apply
     * here). Short-lived cache, dropping to 10s under admin impersonation, to
     * match the sibling org-kit reader without re-querying `zod` on every pan.
     *
     * @return array<int, int>
     */
    private function orgKitIds(int $orgId): array
    {
        $ttl = (session()?->has('admin_impersonator') ?? false)
            ? now()->addSeconds(10)
            : now()->addSeconds(10);

        return Cache::remember(
            'scanner:teammate-org-kit-ids:'.$orgId,
            $ttl,
            static fn (): array => Kit::query()
                ->forOrg($orgId)
                ->excludingDeleted()
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all()
        );
    }

    /**
     * DEV-2012: the live drive line, built from raw scanner GPS
     * (clarity.sc_positions) so it follows the road through the intermediate
     * points rather than hopping straight between 20s polls. Stateless and
     * incremental — the client passes the last timestamp it holds as `since`
     * and we return only the newer points (sc_positions has no surrogate id;
     * it's ORDER BY (kit_mapping, timestamp_utc), so timestamp_utc is the
     * cursor). First poll (no `since`) seeds from the start of the local day.
     * Segmented via {@see HistoricPathBuilder} so a power-off gap breaks the
     * line instead of drawing a connector.
     */
    public function livePath(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['sometimes', 'date'],
        ]);

        $user = $request->user();
        $timezone = $user->timezone;
        $mapping = $user->kit?->kit_mapping;

        if (! $mapping) {
            return response()->json(['segments' => [], 'cursor' => null]);
        }

        $since = $validated['since'] ?? null;

        // The cursor and now() are absolute instants, so bound the query in UTC
        // (timezone 'UTC') to avoid double-converting; HistoricPathBuilder still
        // gets the user's timezone for hour-of-day segmentation.
        $start = $since ? Carbon::parse($since)->utc() : now($timezone)->startOfDay()->utc();
        $end = now('UTC');

        try {
            $positions = KitHistoricPositionsQuery::query([
                'kit_mapping' => $mapping,
                'start' => $start->toDateTimeString(),
                'end' => $end->toDateTimeString(),
                'timezone' => 'UTC',
            ]);
        } catch (\Throwable $e) {
            // Degrade like scanPaths/kitPosition: keep the client's current line
            // (echo its cursor) rather than 500 when sc_positions is unreachable.
            Log::warning('live-path historic read failed; returning empty batch', [
                'kit_id' => $user->kit_id,
                'org_id' => $user->org_id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['segments' => [], 'cursor' => $since]);
        }

        $segments = HistoricPathBuilder::build($positions, $timezone);
        $cursor = $positions === [] ? $since : end($positions)['timestamp_utc'];

        return response()->json([
            'segments' => array_map(static fn ($segment) => ['path' => $segment['path']], $segments),
            'cursor' => $cursor,
        ]);
    }

    /**
     * Individual Live + Staging Live hits with coordinates for the selected date
     * range, rendered as tappable company-logo markers on the main map (DEV-2016,
     * DEV-2052). Capped at MAX_MAP_HITS most-recent across both types; the
     * honey-hole density layer covers the rest. The marker payload carries
     * hit_type so the client can cluster and label Direct vs Staging.
     *
     * @return JsonResponse{data: array<int, array{plate: string, time: string, status: string, hit_type: string, lat: float, lng: float, plate_image: ?string, vehicle_image: ?string, vehicle_year: ?string, vehicle_make: ?string, vehicle_model: ?string, hotlist_us_state: ?string, disposition: ?string}>, capped: bool}
     */
    public function hits(Request $request): JsonResponse
    {
        $user = $request->user();
        $range = $request->query('range', 'today');
        $timezone = $user->timezone;

        try {
            $payload = Cache::remember(
                "scanner:map-hits-v2:{$user->org_id}:{$user->kit_id}:{$range}",
                $this->mapCacheTtl($range),
                function () use ($user, $range, $timezone) {
                    [$start, $end] = $this->getDateRange($range, $timezone);

                    // Mirror the dashboard stats' hot/cold split: the hot table covers
                    // anything within ~90 days. Markers won't equal the Direct stat, though
                    // — the map excludes non-open hits below; the stat bar does not.
                    $hitModel = ClickhouseHit::forWindow($start);

                    // Scope by kit_org_id, not forOrg() (hotlist_org_id): Staging Live hits
                    // match a staging org's hotlist, so their hotlist_org_id differs from the
                    // kit's org and forOrg() would drop them (DEV-2053). actionableDisposition()
                    // is the base-Hits-page rule (NULL/New Hit or Accessible, DEV-2059
                    // correction) — distinct from the analytics validDisposition() rule that
                    // stats/heatmap keep.
                    $scopedHits = fn () => $hitModel->newQuery()
                        ->final()
                        ->forKitOrg($user->org_id)
                        ->where('kit_id', $user->kit_id)
                        ->forHitTypes([HitTypeEnum::Live->value, HitTypeEnum::Staging_Live->value])
                        ->forCreatedBetween($start, $end)
                        ->actionableDisposition();

                    // Markers must reflect the order's CURRENT status, sourced from the
                    // hotlist (FINAL), NOT the hit row's denormalized hotlist_status. That
                    // column is frozen at hit-write time and never back-updated, so a plate
                    // repossessed after its hit — especially Staging Live, which is almost
                    // always stamped Open and rarely re-scanned — would otherwise stay pinned
                    // (DEV-2113). Scope the hotlist lookup to the candidate hits' orders so we
                    // never scan the org's whole open-order set.
                    $candidateHotlistIds = $scopedHits()->distinct()->pluck('hotlist_id')->unique()->values()->all();

                    $openHotlistIds = $candidateHotlistIds === []
                        ? []
                        : ClickhouseHotlist::query()
                            ->whereIntegerInRaw('id', $candidateHotlistIds)
                            ->where('status', HotlistStatusEnum::Open->value)
                            ->pluck('id')
                            ->all();

                    // Apply the open-status constraint BEFORE the cap so the limit counts
                    // only currently-open hits.
                    $hits = $openHotlistIds === []
                        ? collect()
                        : $scopedHits()
                            ->whereIntegerInRaw('hotlist_id', $openHotlistIds)
                            ->orderByDesc('hit_created_at')
                            ->limit(self::MAX_MAP_HITS + 1)
                            ->get();

                    $capped = $hits->count() > self::MAX_MAP_HITS;

                    $data = $hits
                        ->take(self::MAX_MAP_HITS)
                        ->filter(fn ($hit) => $hit->lat && $hit->lng)
                        ->map(fn ($hit) => [
                            'plate' => $hit->ocr ?: 'N/A',
                            'scan_id' => $hit->scan_id,
                            'time' => Carbon::parse($hit->hit_created_at, 'UTC')->toISOString(),
                            // Every surviving marker belongs to a currently-Open hotlist (the
                            // filter above), so label from that fact — not the frozen, possibly
                            // stale, per-row hotlist_status (DEV-2113).
                            'status' => HotlistStatusEnum::Open->title(),
                            'hit_type' => $hit->hit_type,
                            // DEV-2059 F1: the CH flat column — null for New Hit, else the
                            // driver-set string (e.g. 'Accessible') — so the hit-detail modal
                            // can seed the disposition control from the saved value on re-open
                            // instead of resetting to the placeholder every time.
                            'disposition' => $hit->disposition,
                            'lat' => (float) $hit->lat,
                            'lng' => (float) $hit->lng,
                            'plate_image' => scan_img($hit->scan_guid, 'p'),
                            'vehicle_image' => scan_img($hit->scan_guid, 'c'),
                            'vehicle_year' => $hit->vehicle_year,
                            'vehicle_make' => $hit->vehicle_make,
                            'vehicle_model' => $hit->vehicle_model,
                            'hotlist_us_state' => $hit->hotlist_us_state,
                        ])
                        ->values()
                        ->all();

                    return [
                        'data' => $data,
                        'capped' => $capped,
                    ];
                }
            );

            return response()->json($payload);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to load map hits.'], 500);
        }
    }

    public function heatmapHits(Request $request): JsonResponse
    {
        $this->validateHeatmapRequest($request);

        $user = $request->user();

        if (! ScoutOrgSettings::isLayerEnabled($user, ScoutMapLayerEnum::Hits)) {
            return response()->json($this->emptyFeatureCollection());
        }

        $precision = 7;
        $clipZoneIds = $this->resolveClipZoneIds($request, $user);

        try {
            $rows = HitDirectHotspotQuery::query([
                'org_id' => $user->org_id,
                ...$this->snapBbox($request),
                'precision' => $precision,
                'cacheTTL' => now()->addMinutes(5),
            ], cache: true);

            return response()->json($this->clipToZone($this->toGeoJson($rows, 'hits'), $clipZoneIds, (int) $user->org_id));
        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return response()->json(['error' => 'Failed to fetch hit density'], 500);
        }
    }

    /**
     * Direct + Staging order density as a single unioned layer (DEV-2031). When
     * more than one order layer is active they must combine into one set of
     * geohash cells with summed per-cell counts — shaded once — rather than
     * stacking as overlapping polygons. Staging is always clipped to the
     * driver's assigned zones (no zones → nothing shown — the deliberate AC#4
     * nudge). Direct is clipped to assigned zones when the driver has any, and
     * falls back to org-wide density (run-area/bbox) when they have none
     * (DEV-2278). The Direct Hit Density circle layer stays separate
     * (heatmapHits).
     */
    public function heatmapOrders(Request $request): JsonResponse
    {
        $request->validate([
            'north' => ['required', 'numeric'],
            'south' => ['required', 'numeric'],
            'east' => ['required', 'numeric'],
            'west' => ['required', 'numeric'],
            'layers' => ['required', 'array'],
            'layers.*' => ['in:direct,staging'],
        ]);

        $user = $request->user();
        $layers = array_values(array_unique((array) $request->input('layers', [])));
        $enabled = ScoutOrgSettings::enabledLayersFor($user);
        $layers = array_values(array_filter(
            $layers,
            function (string $layer) use ($enabled): bool {
                $orgLayer = $layer === 'direct'
                    ? ScoutMapLayerEnum::Hotlist->value
                    : ScoutMapLayerEnum::Staging->value;

                return in_array($orgLayer, $enabled, true);
            }
        ));
        $precision = 7;

        if ($layers === []) {
            return response()->json($this->emptyFeatureCollection());
        }

        // Run area, when materialized, clips density to the org's territory; absent
        // or unmaterialized, the layer falls back to viewport-bbox-only density (the
        // query no-ops the clip when rings are null) rather than returning empty.
        $runArea = $this->loadRunAreaForClip($user->org_id);

        $hasAssignedZones = $user->zones()->exists();

        try {
            $countsByGeohash = [];

            foreach ($layers as $layer) {
                $staging = $layer === 'staging';
                $rows = $this->orderRows((int) $user->org_id, $staging, $precision, $runArea, $request);

                if ($staging || $hasAssignedZones) {
                    $rows = $this->clipRowsToAssignedZones($rows, $user);
                }

                foreach ($rows as $row) {
                    $geohash = $row->geohash ?? ($row['geohash'] ?? null);

                    if (! $geohash) {
                        continue;
                    }

                    $count = (int) ($row->address_count ?? ($row['address_count'] ?? 0));
                    $countsByGeohash[$geohash] = ($countsByGeohash[$geohash] ?? 0) + $count;
                }
            }

            return response()->json($this->unionToGeoJson($countsByGeohash));
        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return response()->json(['error' => 'Failed to fetch order density'], 500);
        }
    }

    public function kitPosition(Request $request): JsonResponse
    {
        $user = $request->user();

        $vehicleType = $user->kitSettings?->vehicle_type?->value;

        // The 24-hour cutoff prevents the dot from teleporting to wherever the
        // kit was last seen days ago when a dormant kit comes back online —
        // better to show no dot than a misleading one.
        $cutoff = now()->subHours(24)->toDateTimeString();

        // Primary source: clarity.sc_positions — the scanner's own GPS feed
        // (~1s cadence, seconds-fresh while driving), carrying heading + speed.
        // Scoped by kit_mapping, which is 1:1 with a kit (the table has no
        // org_id/kit_id). This is the truest live position; the kit_scan_paths
        // (≤15-min MV) / kit_trips reads below are only a fallback for kits not
        // reporting to sc_positions (or a bridged/test env without the table).
        try {
            $mapping = $user->kit?->kit_mapping;

            if ($mapping) {
                $pos = DB::clickhouse('sc_positions')
                    ->selectRaw('tupleElement(geo, 1) AS lng, tupleElement(geo, 2) AS lat, timestamp_utc, speed, heading')
                    // Case-insensitive: platform lowercases kit_mapping, the scanner
                    // feed keeps its own casing, and ClickHouse `=` is case-sensitive.
                    ->whereRaw('lower(kit_mapping) = ?', [strtolower($mapping)])
                    ->where('timestamp_utc', '>=', $cutoff)
                    ->orderByDesc('timestamp_utc')
                    ->first();

                $lat = $pos->lat ?? ($pos['lat'] ?? null);
                $lng = $pos->lng ?? ($pos['lng'] ?? null);
                $ts = $pos->timestamp_utc ?? ($pos['timestamp_utc'] ?? null);

                if ($lat !== null && $lng !== null && $ts) {
                    $scannedAt = Carbon::parse($ts, 'UTC');
                    $speed = (float) ($pos->speed ?? ($pos['speed'] ?? 0));

                    // Heading only when moving — at rest the reported bearing
                    // jitters and would spin the icon (mirrors the browser-GPS gate).
                    $heading = $speed > 1 ? (float) ($pos->heading ?? ($pos['heading'] ?? 0)) : null;

                    return response()->json([
                        'lat' => (float) $lat,
                        'lng' => (float) $lng,
                        'scanned_at' => $scannedAt->toIso8601String(),
                        'minutes_ago' => (int) abs(now()->diffInMinutes($scannedAt)),
                        'vehicle_type' => $vehicleType,
                        'heading' => $heading,
                        'source' => 'scan',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // A thrown primary read means the cluster is unreachable, not that
            // the kit simply has no recent row. The fallback tables live on the
            // same cluster, so retrying them would re-pay the full timeout on
            // every 10s poll — degrade straight to the null payload instead.
            Log::warning('kit-position sc_positions read failed; returning empty position', [
                'kit_id' => $user->kit_id,
                'org_id' => $user->org_id,
                'exception' => $e->getMessage(),
            ]);

            return $this->emptyKitPosition($vehicleType);
        }

        // Fallback, reached only when the primary read SUCCEEDED but had no recent
        // row (kits not reporting to sc_positions, or the first 15 min of history):
        // the kit_scan_paths MV, then the most recent trip's end point.
        try {
            $bucket = DB::clickhouse('kit_scan_paths', final: true)
                ->select(['timestamp'])
                ->selectRaw('arrayElement(path_points, length(path_points)) AS last_point')
                ->where('org_id', $user->org_id)
                ->where('kit_id', $user->kit_id)
                ->where('timestamp', '>=', $cutoff)
                ->orderByDesc('timestamp')
                ->first();

            $lastPoint = $bucket->last_point ?? ($bucket['last_point'] ?? null);
            $bucketTs = $bucket->timestamp ?? ($bucket['timestamp'] ?? null);

            if ($lastPoint && $bucketTs) {
                [$lng, $lat] = $lastPoint;
                $scannedAt = Carbon::parse($bucketTs, 'UTC');

                return response()->json([
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'scanned_at' => $scannedAt->toIso8601String(),
                    'minutes_ago' => (int) abs(now()->diffInMinutes($scannedAt)),
                    'vehicle_type' => $vehicleType,
                    'heading' => null,
                    'source' => 'scan',
                ]);
            }

            // Fallback: most recent trip's end_point within the same 24-hour window.
            // Helpful when kit_scan_paths has no row yet (first 15 min of a kit's
            // history) but a trip exists.
            $trip = KitTrip::query()
                ->selectRaw('tupleElement(end_point, 1) AS lng, tupleElement(end_point, 2) AS lat, trip_end')
                ->forOrg($user->org_id)
                ->forKit($user->kit_id)
                ->where('trip_end', '>=', $cutoff)
                ->orderByDesc('trip_end')
                ->first();

            $tripLat = $trip->lat ?? ($trip['lat'] ?? null);
            $tripLng = $trip->lng ?? ($trip['lng'] ?? null);
            $tripEnd = $trip->trip_end ?? ($trip['trip_end'] ?? null);

            if ($tripLat && $tripLng && $tripEnd) {
                $endedAt = Carbon::parse($tripEnd, 'UTC');

                return response()->json([
                    'lat' => (float) $tripLat,
                    'lng' => (float) $tripLng,
                    'scanned_at' => $endedAt->toIso8601String(),
                    'minutes_ago' => (int) abs(now()->diffInMinutes($endedAt)),
                    'vehicle_type' => $vehicleType,
                    'heading' => null,
                    'source' => 'scan',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('kit-position fallback read failed; returning empty position', [
                'kit_id' => $user->kit_id,
                'org_id' => $user->org_id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->emptyKitPosition($vehicleType);
    }

    private function emptyKitPosition(mixed $vehicleType): JsonResponse
    {
        return response()->json([
            'lat' => null,
            'lng' => null,
            'scanned_at' => null,
            'minutes_ago' => null,
            'vehicle_type' => $vehicleType,
            'heading' => null,
            'source' => 'scan',
        ]);
    }

    /**
     * Clip aggregate geohash rows to the driver's assigned zones. Staging always
     * calls this (no zones → nothing shown); Direct only calls this when the
     * driver has assigned zones (DEV-2278). Mirrors the geohash-intersection
     * clip used by {@see clipToZone} but on raw rows.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, mixed>
     */
    private function clipRowsToAssignedZones(array $rows, User $user): array
    {
        $assigned = $user->zones()->pluck('zones.id')->map(fn ($id) => (int) $id)->all();

        if ($assigned === [] || $rows === []) {
            return [];
        }

        $geohashes = array_values(array_filter(array_map(
            static fn ($row) => $row->geohash ?? ($row['geohash'] ?? null),
            $rows,
        )));

        if ($geohashes === []) {
            return [];
        }

        $inZone = array_flip(Zone::intersectingGeohashes($geohashes, $assigned, (int) $user->org_id));

        return array_values(array_filter(
            $rows,
            static fn ($row) => isset($inZone[$row->geohash ?? ($row['geohash'] ?? '')]),
        ));
    }

    private function validateHeatmapRequest(Request $request): void
    {
        $request->validate([
            'north' => ['required', 'numeric'],
            'south' => ['required', 'numeric'],
            'east' => ['required', 'numeric'],
            'west' => ['required', 'numeric'],
            'zone_ids' => ['sometimes', 'array'],
            'zone_ids.*' => ['integer'],
        ]);
    }

    /**
     * Resolve the optional `zone_ids` clip for a honey-hole heatmap. A driver may
     * only clip to zones they're assigned to — anything else is a tampering
     * attempt and 403s. Multiple assigned zones form a union clip (handled by
     * {@see Zone::intersectingGeohashes}). Returns [] when no clip was requested.
     *
     * @return array<int, int>
     */
    private function resolveClipZoneIds(Request $request, $user): array
    {
        if (! $request->filled('zone_ids')) {
            return [];
        }

        $requested = array_values(array_unique(array_map('intval', (array) $request->input('zone_ids'))));
        $assigned = $user->zones()->pluck('zones.id')->map(fn ($id) => (int) $id)->all();

        foreach ($requested as $zoneId) {
            abort_unless(in_array($zoneId, $assigned, true), 403);
        }

        return $requested;
    }

    /**
     * Clip an aggregate geohash FeatureCollection to the given zone(s). Pin-mode
     * (debug) collections have no geohash to clip on and pass through unchanged.
     *
     * @param  array<string, mixed>  $geojson
     * @param  array<int, int>  $zoneIds
     * @return array<string, mixed>
     */
    private function clipToZone(array $geojson, array $zoneIds, int $orgId): array
    {
        $features = $geojson['features'] ?? [];

        if ($zoneIds === [] || $features === [] || ($features[0]['geometry']['type'] ?? null) === 'Point') {
            return $geojson;
        }

        $geohashes = array_values(array_filter(array_map(
            static fn ($f) => $f['properties']['geohash'] ?? null,
            $features
        )));

        $inZone = array_flip(Zone::intersectingGeohashes($geohashes, $zoneIds, $orgId));

        $geojson['features'] = array_values(array_filter(
            $features,
            static fn ($f) => isset($inZone[$f['properties']['geohash'] ?? ''])
        ));

        return $geojson;
    }
}
