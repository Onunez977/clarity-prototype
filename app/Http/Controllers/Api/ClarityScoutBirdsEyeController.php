<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Clickhouse\Models\Hit as ClickhouseHit;
use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Clickhouse\Models\Scan as ClickhouseScan;
use App\Clickhouse\Queries\HitDirectHotspotQuery;
use App\Clickhouse\Queries\KitRangePathsQuery;
use App\Clickhouse\Queries\KitScanPathsQuery;
use App\Clickhouse\Queries\OrgLivePathsQuery;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Http\Controllers\Api\Concerns\ScoutMapHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateScoutMapLayersRequest;
use App\Models\Kit;
use App\Models\ScoutOrgSettings;
use App\Models\User;
use App\Models\Zone;
use App\Services\Scout\ResolveOrgDriverPositions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Scout Bird's Eye — the org-wide, admin-facing analogue of
 * {@see ClarityScoutMapController}. Every endpoint is scoped to the
 * authenticated admin's own org (auth()->user()->org_id) and renders ALL of the
 * org's Scout drivers at once, with an optional `kit_ids` filter from the
 * driver selector (always intersected against the org's own kits so the client
 * can never widen scope past its org). Admin-only + Scout-gated at the route
 * group; this controller assumes that gate has already run (DEV-2116).
 *
 * Live position degrades sc_positions -> kit_scan_paths (DEV-2253); live trails
 * are still sc_positions only (DEV-2228). Historical scan-paths (yesterday /
 * last_N) use continuous sc_positions via KitRangePathsQuery; today's overlay
 * still uses KitScanPathsQuery. Kit User Path is now scan-only (DEV-2451) —
 * the two surfaces deliberately diverge, pending DEV-2450. Hits and heatmaps
 * reuse the already-org-wide query classes the driver map uses.
 */
class ClarityScoutBirdsEyeController extends Controller
{
    use ScoutMapHelpers;

    private const MAX_MAP_HITS = 100;

    public function __construct(
        private readonly ResolveOrgDriverPositions $resolveOrgDriverPositions,
    ) {}

    /**
     * Per-request rendered-point backstop for historical continuous GPS.
     * Org Map fans out in kit chunks; this guards each chunk. protected so
     * tests can lower it via subclass (same seam as KitUserPathController).
     */
    protected const MAX_HISTORICAL_RENDER_POINTS = 1_500_000;

    /**
     * Hard cap on kits per historical continuous-GPS request. Client fans out
     * one kit at a time; the server must not trust omitted/expanded kit_ids
     * (full-org defaults OOMed local CH and are a Cloud DoS shape).
     */
    private const MAX_HISTORICAL_KITS_PER_REQUEST = 1;

    /**
     * Max lat/lng span (degrees) for a historical path bbox after snap.
     * Rejects statewide / country-sized viewports that explode KitRangePathsQuery
     * even for a single kit. ~2.5° ≈ 175 mi at mid-latitudes — enough for a
     * metro at PATH_LAYERS_MIN_ZOOM, not a full state.
     */
    private const MAX_HISTORICAL_BBOX_SPAN_DEGREES = 2.5;

    /**
     * Org-wide historical fan-out always uses this broad spacing; a tighter
     * ladder is calibrated for hand-picked 1–3 kit selections and at 10–25
     * kits per chunk it blanks yesterday/last_7 under the point cap
     * (DEV-2374 review). Kit User Path is scan-only now (DEV-2451) and has
     * no dedup constants of its own to compare against.
     */
    private const HISTORICAL_DEDUP_METERS = 50.0;

    /**
     * Driver selector options: all of the org's non-deleted kits, labelled by
     * assigned driver where a kit_settings row is present. Each entry also
     * carries `active_in_range` — whether the kit has a ClickHouse scan within
     * the selected `range` window (default 'today') — so the client can filter
     * the *visible* list without shrinking the roster itself; App.vue's
     * followedDriverName/noKitsReporting and the nameplate toggle need the full
     * set to stay intact regardless of scan activity (DEV-2293). Default client
     * selection is everyone.
     */
    public function drivers(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = (int) $user->org_id;
        $timezone = $user->timezone;

        // Whitelist against ScoutMapHelpers::getDateRange()'s full range ladder
        // (mirrors scanPaths()); unknown values collapse to 'today'.
        $range = in_array($request->query('range'), ['today', 'yesterday', 'last_7', 'last_15', 'last_30', 'last_60'], true)
            ? $request->query('range')
            : 'today';

        $kits = $this->resolveOrgDriverPositions->forOrg($orgId);
        $kitIds = $kits->pluck('id')->map(static fn ($id) => (int) $id)->all();

        [$start, $end] = $this->getDateRange($range, $timezone);

        try {
            // Timezone is part of the key because the active window is derived from it
            // (getDateRange), so cross-timezone admins in one org never share a mismatched
            // day window at the boundary (mirrors recentHits()).
            $cacheKey = "scanner:birds-eye-active-kits:{$orgId}:{$range}:{$timezone}";
            $activeKitIds = Cache::remember(
                $cacheKey,
                $this->mapCacheTtl($range),
                fn () => $this->activeKitIdsForRange($orgId, $kitIds, $start, $end, $timezone)
            );
        } catch (\Throwable $e) {
            // A ClickHouse hiccup must never take down the roster itself — fall
            // back to every kit reading active_in_range:false (mirrors the
            // sibling CH-touching methods' degrade-not-500 pattern below).
            Log::warning('birds-eye active-kit lookup failed; roster stays full, all inactive', [
                'org_id' => $orgId,
                'exception' => $e->getMessage(),
            ]);
            $activeKitIds = [];
        }

        $drivers = $kits
            ->map(static fn (Kit $kit) => [
                'kit_id' => (int) $kit->id,
                'name' => $kit->name,
                'driver_name' => $kit->settings?->user?->name,
                'vehicle_type' => $kit->settings?->vehicle_type?->value,
                'active_in_range' => in_array((int) $kit->id, $activeKitIds, true),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $drivers]);
    }

    /**
     * Latest live position for each in-scope driver: the primary GPS fix
     * (org-wide {@see OrgLivePositionsQuery}, keyed by lower(kit_mapping))
     * degrading to the last scan-derived point (org-wide
     * {@see OrgScanPathPositionsQuery}, keyed by kit_id) for kits that scan but
     * do not report GPS within the window (DEV-2253) — mirrors kitPosition()'s
     * single-driver fallback. Tier 1 always wins when both exist; tier 2 never
     * carries a heading (kit_scan_paths has no bearing column).
     */
    public function driverPositions(Request $request): JsonResponse
    {
        $kits = $this->scopedKits($request);

        return response()->json([
            'data' => $this->resolveOrgDriverPositions->positions((int) $request->user()->org_id, $kits),
        ]);
    }

    /**
     * Per-driver live trails, each advanced by its OWN cursor. The client echoes
     * a `sinces` map (kit_id => last-seen UTC timestamp); the response returns,
     * per driver, the new segments plus that driver's fresh cursor. A driver with
     * no cursor yet (first poll / newly-appeared) seeds from the start of the
     * local day. Cursors are per-driver on purpose: fixes ingest at wildly
     * different latencies across a fleet, so one shared cursor would freeze every
     * laggier driver's trail while its dot kept moving.
     */
    public function driverPaths(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sinces' => ['sometimes', 'array'],
            'sinces.*' => ['date'],
        ]);

        $user = $request->user();
        $timezone = $user->timezone;

        $kits = $this->scopedKits($request);
        $byMapping = $this->resolveOrgDriverPositions->kitsByMapping($kits);

        if ($byMapping === []) {
            return response()->json(['drivers' => []]);
        }

        $sinces = $validated['sinces'] ?? [];
        $defaultStart = now($timezone)->startOfDay()->utc()->toDateTimeString();

        $sincesByMapping = [];
        foreach ($byMapping as $mapping => $kit) {
            $clientSince = $sinces[$kit->id] ?? null;
            $sincesByMapping[$mapping] = $clientSince
                ? Carbon::parse($clientSince)->utc()->toDateTimeString()
                : $defaultStart;
        }

        try {
            $points = OrgLivePathsQuery::query([
                'sinces' => $sincesByMapping,
                'end' => now('UTC')->toDateTimeString(),
                'timezone' => 'UTC',
            ]);
        } catch (\Throwable $e) {
            Log::warning('birds-eye live paths read failed; returning empty batch', [
                'org_id' => $user->org_id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['drivers' => []]);
        }

        $segmentsByMapping = OrgLivePathsQuery::segmentByMapping($points, $timezone);

        // Newest returned timestamp per mapping → that driver's next cursor.
        $cursorByMapping = [];
        foreach ($points as $point) {
            $mapping = $point['mapping'];
            if (! isset($cursorByMapping[$mapping]) || $point['timestamp_utc'] > $cursorByMapping[$mapping]) {
                $cursorByMapping[$mapping] = $point['timestamp_utc'];
            }
        }

        $drivers = [];
        foreach ($byMapping as $mapping => $kit) {
            $segments = $segmentsByMapping[$mapping] ?? [];

            // No new movement for this driver — leave its cursor + trail as-is.
            if ($segments === []) {
                continue;
            }

            $drivers[] = [
                'kit_id' => (int) $kit->id,
                'segments' => $segments,
                'cursor' => $cursorByMapping[$mapping] ?? ($sinces[$kit->id] ?? null),
            ];
        }

        return response()->json(['drivers' => $drivers]);
    }

    /**
     * Drive overlays for the in-scope drivers, per kit.
     *
     * - `today`: scan-event polylines via {@see KitScanPathsQuery} (unchanged;
     *   Live trails are a separate endpoint and are not touched here).
     * - `yesterday` / `last_*`: continuous GPS via {@see KitRangePathsQuery}
     *   (DEV-2374). Kit User Path is scan-only now (DEV-2451) — the two
     *   surfaces deliberately diverge, pending DEV-2450. Requires explicit
     *   `kit_ids` (≤ {@see MAX_HISTORICAL_KITS_PER_REQUEST}) and a bounded
     *   viewport bbox — full-org / unbounded historical is rejected.
     */
    public function scanPaths(Request $request): JsonResponse|Response
    {
        $user = $request->user();
        $timezone = $user->timezone;
        // Whitelist against ScoutMapHelpers::getDateRange()'s full range ladder
        // (incl. 'yesterday', DEV-2186); unknown values collapse to 'today' so the
        // raw string can't reach the addslashes-only layerId literal in KitScanPathsQuery.
        $range = in_array($request->query('range'), ['today', 'yesterday', 'last_7', 'last_15', 'last_30', 'last_60'], true)
            ? $request->query('range')
            : 'today';

        try {
            [$start, $end] = $this->getDateRange($range, $timezone);

            if ($range === 'today') {
                $kits = $this->scopedKits($request);

                if ($kits->isEmpty()) {
                    return response()->json([]);
                }

                $bbox = $this->resolveOptionalBbox($request);

                return $this->todayScanPaths($user, $kits, $start, $end, $timezone, $range, $bbox);
            }

            $kitsOrError = $this->historicalPathKits($request);
            if ($kitsOrError instanceof JsonResponse) {
                return $kitsOrError;
            }
            $kits = $kitsOrError;

            if ($kits->isEmpty()) {
                return response()->json([]);
            }

            $bboxOrError = $this->resolveRequiredHistoricalBbox($request);
            if ($bboxOrError instanceof JsonResponse) {
                return $bboxOrError;
            }

            // DEV-2374: client SHOULD pass remaining fleet budget as max_points so
            // cooperative Org Map chunking stays under MAX_HISTORICAL_RENDER_POINTS.
            // This is still a per-request cap (not a cross-request server ledger).
            $maxPoints = min(
                static::MAX_HISTORICAL_RENDER_POINTS,
                max(0, (int) $request->query('max_points', static::MAX_HISTORICAL_RENDER_POINTS))
            );

            return $this->historicalContinuousPaths(
                $user,
                $kits,
                $start,
                $end,
                $timezone,
                $range,
                $bboxOrError,
                $maxPoints,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('birds-eye scan-paths failed: '.$e->getMessage());

            // Historical: do not 200 [] — the client treats that as a successful
            // empty chunk and can blank the layer (RN7 L3). Today keeps prior shape.
            if ($range !== 'today') {
                return response()->json(['error' => 'Failed to fetch historical paths'], 500);
            }

            return response()->json([]);
        }
    }

    /**
     * Historical continuous GPS must name kits explicitly — never default to the
     * full org roster (OOM / Cloud DoS shape when kit_ids is omitted).
     *
     * @return EloquentCollection<int, Kit>|JsonResponse
     */
    private function historicalPathKits(Request $request): EloquentCollection|JsonResponse
    {
        $requested = $request->input('kit_ids');

        if (! is_array($requested) || $requested === []) {
            return response()->json([
                'error' => 'Historical paths require kit_ids.',
            ], 422);
        }

        if (count($requested) > self::MAX_HISTORICAL_KITS_PER_REQUEST) {
            return response()->json([
                'error' => 'Too many kits in one historical path request — send one kit per chunk.',
            ], 422);
        }

        return $this->scopedKits($request);
    }

    /**
     * Required, ordered, area-capped viewport for historical continuous GPS.
     * Client zoom gating is not a production protection.
     *
     * @return array{north: float, south: float, east: float, west: float}|JsonResponse
     */
    private function resolveRequiredHistoricalBbox(Request $request): array|JsonResponse
    {
        $request->validate([
            'north' => ['required', 'numeric', 'between:-90,90'],
            'south' => ['required', 'numeric', 'between:-90,90'],
            'east' => ['required', 'numeric', 'between:-180,180'],
            'west' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $bbox = $this->snapBbox($request);

        if ($bbox['north'] <= $bbox['south'] || $bbox['east'] <= $bbox['west']) {
            return response()->json([
                'error' => 'Invalid viewport bounds.',
            ], 422);
        }

        $latSpan = $bbox['north'] - $bbox['south'];
        $lngSpan = $bbox['east'] - $bbox['west'];

        if (
            $latSpan > self::MAX_HISTORICAL_BBOX_SPAN_DEGREES
            || $lngSpan > self::MAX_HISTORICAL_BBOX_SPAN_DEGREES
        ) {
            return response()->json([
                'error' => 'Viewport too large — zoom in to load paths.',
            ], 422);
        }

        return $bbox;
    }

    /**
     * @param  EloquentCollection<int, Kit>  $kits
     * @param  array{north: float, south: float, east: float, west: float}|null  $bbox
     */
    private function todayScanPaths(
        User $user,
        EloquentCollection $kits,
        Carbon $start,
        Carbon $end,
        string $timezone,
        string $range,
        ?array $bbox,
    ): JsonResponse {
        $kitIds = $kits->pluck('id')->map(static fn ($id) => (int) $id)->all();

        $rows = KitScanPathsQuery::query([
            'org_id' => (int) $user->org_id,
            'kit_ids' => $kitIds,
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
            'timezone' => $timezone,
            'layer' => $range,
            'cacheTTL' => $this->mapCacheTtl($range),
            ...($bbox ?? []),
        ], cache: true);

        return response()->json(array_map(
            static fn ($row) => [
                'kit_id' => (int) ($row['kit_id'] ?? $row->kit_id ?? 0),
                'color' => $row['color'] ?? ($row->color ?? null),
                'name' => $row['name'] ?? ($row->name ?? null),
                'path' => $row['path'] ?? ($row->path ?? []),
                'trip_date' => $row['trip_date'] ?? ($row->trip_date ?? null),
                'is_today' => true,
            ],
            is_array($rows) ? $rows : $rows->toArray(),
        ));
    }

    /**
     * Continuous sc_positions routes for historical Org Map ranges. Kit User
     * Path is scan-only now (DEV-2451) — the two surfaces deliberately
     * diverge, pending DEV-2450.
     *
     * Response rows splice {@see KitRangePathsQuery}'s pre-encoded `path_json`
     * verbatim (O(segments) PHP memory). Viewport bbox is required by
     * {@see scanPaths()} and pushed into ClickHouse at the sc_positions read
     * (strategy B / DEV-2374) so assembly only covers GPS on screen. `$maxPoints`
     * is the remaining fleet-wide budget for this chunk
     * (≤ {@see MAX_HISTORICAL_RENDER_POINTS}).
     *
     * One {@see KitRangePathsQuery::handle()} call — render-point count is
     * derived from the assembled segments (RN7: avoid countPoints+handle double
     * pipeline). Segment payloads are not cached (viewport × chunk keys mint
     * multi-MB entries; countPoints-only caching is gone with the dual call).
     *
     * @param  EloquentCollection<int, Kit>  $kits
     * @param  array{north: float, south: float, east: float, west: float}  $bbox
     */
    private function historicalContinuousPaths(
        User $user,
        EloquentCollection $kits,
        Carbon $start,
        Carbon $end,
        string $timezone,
        string $range,
        array $bbox,
        int $maxPoints,
    ): JsonResponse|Response {
        $byMapping = $this->resolveOrgDriverPositions->kitsByMapping($kits);

        if ($byMapping === []) {
            return response()->json([]);
        }

        $mappings = array_keys($byMapping);
        sort($mappings);

        // Copy before utc() — Carbon mutates in place. Kit User Path removed
        // this same trap under DEV-2451 (it no longer touches Carbon::utc()
        // on this path); Bird's Eye still has it (DEV-2222).
        $startUtc = $start->copy()->utc()->toDateTimeString();
        $endUtc = $end->copy()->utc()->toDateTimeString();

        $dedupMeters = self::HISTORICAL_DEDUP_METERS;

        try {
            // Single assembly pass (RN7 HIGH-2). Budget check uses rendered
            // point count from these segments — not a second full pipeline.
            $segments = KitRangePathsQuery::handle([
                'mappings' => $mappings,
                'start' => $startUtc,
                'end' => $endUtc,
                'dedup_meters' => $dedupMeters,
                'timezone' => $timezone,
                ...$bbox,
            ]);
        } catch (\Throwable $exception) {
            // Do not use ClickhouseQuery::query()'s production [] swallow here —
            // a failed assembly with a non-zero count header blanks the map (RN5 M4).
            // Keep non-2xx so the client skips this kit without replacing the layer
            // with a successful empty body (companion review on soft 200 []).
            Log::error('birds-eye historical path segments failed: '.$exception->getMessage());

            return response()->json(['error' => 'Failed to fetch historical paths'], 500);
        }

        $renderPointCount = $this->countHistoricalRenderPoints($segments);

        if ($maxPoints <= 0 || $renderPointCount > $maxPoints) {
            Log::warning('birds-eye historical path selection too large', [
                'org_id' => (int) $user->org_id,
                'range' => $range,
                'kit_count' => count($mappings),
                'render_points' => $renderPointCount,
                'max_points' => $maxPoints,
            ]);

            return response()->json([
                'error' => 'Selection too large to render — narrow the kits or shorten the date range.',
            ], 422);
        }

        $rows = [];

        foreach ($segments as $segment) {
            $kit = $byMapping[$segment['mapping']] ?? null;
            $pathJson = $segment['path_json'] ?? '';

            // Guard empty/malformed path_json — raw splice would emit `"path":,`
            // and invalidate the whole chunk JSON (RN7 L1 / LOW-1).
            if ($kit === null || $pathJson === '' || ! str_starts_with($pathJson, '[')) {
                continue;
            }

            $rows[] = '{"kit_id":'.((int) $kit->id)
                .',"color":'.json_encode(KitScanPathsQuery::getColor((int) $kit->id), JSON_INVALID_UTF8_SUBSTITUTE)
                .',"name":'.json_encode((string) $kit->name, JSON_INVALID_UTF8_SUBSTITUTE)
                .',"path":'.$pathJson
                .',"trip_date":'.json_encode($segment['trip_date'], JSON_INVALID_UTF8_SUBSTITUTE)
                .',"is_today":false}';
        }

        return response('['.implode(',', $rows).']', 200, [
            'Content-Type' => 'application/json',
            // Client accumulates this across kit chunks for the fleet-wide budget.
            'X-Scout-Render-Points' => (string) $renderPointCount,
        ]);
    }

    /**
     * Count coordinates already encoded in KitRangePathsQuery segment rows.
     *
     * @param  list<array{mapping: string, trip_date: string, path_json: string}>  $segments
     */
    private function countHistoricalRenderPoints(array $segments): int
    {
        $total = 0;

        foreach ($segments as $segment) {
            $path = json_decode($segment['path_json'] ?? '', true);

            if (is_array($path)) {
                $total += count($path);
            }
        }

        return $total;
    }

    /**
     * Org-wide Live + Staging Live hit markers for the range, capped at
     * MAX_MAP_HITS most recent. Reflects each order's CURRENT hotlist status
     * (FINAL), not the frozen per-hit column (DEV-2113). Optional kit_ids filter.
     */
    public function hits(Request $request): JsonResponse
    {
        $user = $request->user();
        $range = $request->query('range', 'today');
        $timezone = $user->timezone;

        $kitIds = $this->scopedKits($request)->pluck('id')->map(static fn ($id) => (int) $id)->all();

        if ($kitIds === []) {
            return response()->json(['data' => [], 'capped' => false]);
        }

        try {
            // -v2: bumped when scan_id/disposition were added to the payload
            // (DEV-2235) so a pre-deploy cached entry (up to 15 min TTL on
            // last_60) can't serve the old shape and hide the disposition control.
            $cacheKey = 'scanner:birds-eye-hits-v2:'.$user->org_id.':'.implode('-', $kitIds).":{$range}";

            $payload = Cache::remember($cacheKey, $this->mapCacheTtl($range), function () use ($kitIds, $range, $timezone, $user) {
                [$start, $end] = $this->getDateRange($range, $timezone);

                // Scope by kit_org_id (Staging Live's hotlist_org_id differs from the
                // kit's org; forOrg() would drop them — DEV-2053), restricted to the
                // in-scope kits. actionableDisposition() is the base-Hits-page rule
                // (NULL/New Hit or Accessible, DEV-2059 correction) — distinct from
                // the analytics validDisposition() rule that stats() keeps.
                $scopedHits = fn () => ClickhouseHit::forWindow($start)->newQuery()
                    ->final()
                    ->forKitOrg((int) $user->org_id)
                    ->whereIntegerInRaw('kit_id', $kitIds)
                    ->forHitTypes([HitTypeEnum::Live->value, HitTypeEnum::Staging_Live->value])
                    ->forCreatedBetween($start, $end)
                    ->actionableDisposition();

                // CURRENT status from the hotlist (FINAL), not the frozen per-hit
                // column (DEV-2113). Scope the lookup to the candidate orders.
                $candidateHotlistIds = $scopedHits()->distinct()->pluck('hotlist_id')->unique()->values()->all();

                $openHotlistIds = $candidateHotlistIds === []
                    ? []
                    : ClickhouseHotlist::query()
                        ->whereIntegerInRaw('id', $candidateHotlistIds)
                        ->where('status', HotlistStatusEnum::Open->value)
                        ->pluck('id')
                        ->all();

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
                        'kit_id' => (int) $hit->kit_id,
                        'plate' => $hit->ocr ?: 'N/A',
                        'scan_id' => $hit->scan_id,
                        'time' => Carbon::parse($hit->hit_created_at, 'UTC')->toISOString(),
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

                return ['data' => $data, 'capped' => $capped];
            });

            return response()->json($payload);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to load map hits.'], 500);
        }
    }

    /**
     * Org-wide Direct hit density (DEV-2016). No driver-zone clip for admins.
     * Intentionally org-wide and selector-independent: the `kit_ids` driver
     * selector is NOT honored here (density is address-based, with no kit
     * dimension), and the client omits it for /map/heatmap/* accordingly (F11).
     */
    public function heatmapHits(Request $request): JsonResponse
    {
        $this->validateHeatmapRequest($request);

        $user = $request->user();
        $precision = 7;

        try {
            $rows = HitDirectHotspotQuery::query([
                'org_id' => (int) $user->org_id,
                ...$this->snapBbox($request),
                'precision' => $precision,
                'cacheTTL' => now()->addMinutes(5),
            ], cache: true);

            return response()->json($this->toGeoJson($rows, 'hits'));
        } catch (\Throwable $e) {
            Log::error('birds-eye heatmap hits failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to fetch hit density'], 500);
        }
    }

    /**
     * Org-wide Direct + Staging order density, unioned to one shaded layer
     * (DEV-2031). Clipped to the org's materialized run-area territory (DEV-2116
     * decision) — NOT driver-assigned zones, which an org-wide admin does not
     * have; absent a run area, falls back to viewport-bbox density.
     *
     * Intentionally org-wide and selector-independent: the `kit_ids` driver
     * selector is NOT honored (order density is address-based, with no kit
     * dimension), and the client omits it for /map/heatmap/* accordingly (F11).
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
        $precision = 7;

        if ($layers === []) {
            return response()->json($this->emptyFeatureCollection());
        }

        $runArea = $this->loadRunAreaForClip((int) $user->org_id);

        try {
            $countsByGeohash = [];

            foreach ($layers as $layer) {
                $rows = $this->orderRows((int) $user->org_id, $layer === 'staging', $precision, $runArea, $request);

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
        } catch (\Throwable $e) {
            Log::error('birds-eye heatmap orders failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to fetch order density'], 500);
        }
    }

    /**
     * Org-wide summary tiles (scans / direct / staging / repos / trips), summed
     * across the in-scope drivers. Mirrors the driver dashboard's dedup/semantics
     * but drops the single-kit filter (optional kit_ids narrows it).
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $orgId = (int) $user->org_id;
            $timezone = $user->timezone;
            $range = $request->query('range', 'today');

            $kitIds = $this->scopedKits($request)->pluck('id')->map(static fn ($id) => (int) $id)->all();

            if ($kitIds === []) {
                return response()->json(['data' => $this->emptyStats()]);
            }

            [$start, $end] = $this->getDateRange($range, $timezone);
            $cacheKey = "scanner:birds-eye-stats:{$orgId}:".implode('-', $kitIds).":{$range}";

            $data = Cache::remember($cacheKey, $this->statsTtl($range), function () use ($kitIds, $orgId, $start, $end, $timezone) {
                $scansCount = ClickhouseScan::forWindow($start)->newQuery()
                    ->final()
                    ->forOrg($orgId)
                    ->whereIntegerInRaw('kit_id', $kitIds)
                    ->between($start, $end, $timezone)
                    ->count();

                $repossessed = HotlistStatusEnum::Repossessed->value;
                $live = HitTypeEnum::Live->value;
                $staging = HitTypeEnum::Staging_Live->value;

                $hitCounts = ClickhouseHit::forWindow($start)->newQuery()
                    ->final()
                    ->forKitOrg($orgId)
                    ->whereIntegerInRaw('kit_id', $kitIds)
                    ->forHitTypes([$live, $staging])
                    ->forCreatedBetween($start, $end)
                    ->validDisposition()
                    ->selectRaw("uniqExactIf((ocr, toStartOfHour(hit_created_at)), hit_type = '{$live}') AS direct_hits, uniqExactIf((ocr, toStartOfHour(hit_created_at)), hit_type = '{$staging}') AS staging_hits, uniqExactIf(hotlist_id, hotlist_status = {$repossessed} AND hit_type = '{$live}') AS repos_affected")
                    ->first();

                return [
                    'scans_today' => $scansCount,
                    'direct_hits' => (int) ($hitCounts->direct_hits ?? 0),
                    'staging_hits' => (int) ($hitCounts->staging_hits ?? 0),
                    'repos_affected' => (int) ($hitCounts->repos_affected ?? 0),
                    'trip_count' => 0,
                ];
            });

            return response()->json(['data' => $data]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to load stats.'], 500);
        }
    }

    /**
     * Org-wide "Hits Pending Repossession" feed for the dashboard overlay card —
     * the org analogue of the driver dashboard's recentHits (mirrors its shape).
     * Live + Staging-Live hits across the in-scope drivers, deduped by plate.
     *
     * Reflects each order's CURRENT hotlist status (FINAL semi-join), not the
     * frozen per-hit column (DEV-2117), and respects the admin date-range filter —
     * both matching the sibling {@see hits()} on this controller.
     */
    public function recentHits(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $range = $request->query('range', 'today');

            $kitIds = $this->scopedKits($request)->pluck('id')->map(static fn ($id) => (int) $id)->all();

            if ($kitIds === []) {
                return response()->json(['data' => []]);
            }

            // The Live Hits Pending Repossession list is polled ~10s (DEV-2179).
            // Cache to that cadence so concurrent admin tabs coalesce to at most
            // one ClickHouse pass per org per interval while a new hit still
            // surfaces within a poll cycle. Mirrors the sibling hits() cache-key
            // shape; the short TTL keeps the list near-live (the endpoint was
            // previously uncached — every request hit ClickHouse directly). The
            // timezone is part of the key because the day window is derived from
            // it (getDateRange), so cross-timezone admins in one org never share a
            // mismatched 'today' window at the midnight boundary.
            // -v2: bumped alongside the scan_id/disposition payload addition
            // (DEV-2235); same stale-cache concern as hits() above.
            $cacheKey = 'scanner:birds-eye-recent-hits-v2:'.$user->org_id.':'.implode('-', $kitIds).":{$range}:{$user->timezone}";

            $data = Cache::remember($cacheKey, now()->addSeconds(10), function () use ($kitIds, $range, $user) {
                [$start, $end] = $this->getDateRange($range, $user->timezone);

                // Scope by kit_org_id (Staging Live's hotlist_org_id differs from the
                // kit's org; forOrg() would drop them — DEV-2053), restricted to the
                // in-scope kits. actionableDisposition() is the base-Hits-page rule
                // (NULL/New Hit or Accessible, DEV-2059 correction) — distinct from
                // the analytics validDisposition() rule that stats() keeps.
                $scopedHits = fn () => ClickhouseHit::forWindow($start)->newQuery()
                    ->final()
                    ->forKitOrg((int) $user->org_id)
                    ->whereIntegerInRaw('kit_id', $kitIds)
                    ->forHitTypes([HitTypeEnum::Live->value, HitTypeEnum::Staging_Live->value])
                    ->forCreatedBetween($start, $end)
                    ->actionableDisposition();

                // CURRENT status from the hotlist (FINAL), not the frozen per-hit
                // column (DEV-2117). Scope the lookup to the candidate orders.
                $candidateHotlistIds = $scopedHits()->distinct()->pluck('hotlist_id')->unique()->values()->all();

                $openHotlistIds = $candidateHotlistIds === []
                    ? []
                    : ClickhouseHotlist::query()
                        ->whereIntegerInRaw('id', $candidateHotlistIds)
                        ->where('status', HotlistStatusEnum::Open->value)
                        ->pluck('id')
                        ->all();

                // No ->limit(20): driver removed the pending-hits cap (95da4d7f);
                // admin Bird's Eye must match so multi-day ranges are not truncated
                // to today's newest plates (DEV-2595).
                $hits = $openHotlistIds === []
                    ? collect()
                    : $scopedHits()
                        ->whereIntegerInRaw('hotlist_id', $openHotlistIds)
                        ->orderByDesc('hit_created_at')
                        ->limitBy(1, 'ocr')
                        ->get();

                return $hits
                    ->map(fn ($hit) => [
                        'id' => $hit->id,
                        'scan_id' => $hit->scan_id,
                        'kit_id' => (int) $hit->kit_id,
                        'plate' => $hit->ocr ?: 'N/A',
                        'hit_time' => Carbon::parse($hit->hit_created_at, 'UTC')->toISOString(),
                        'hit_type' => $hit->hit_type,
                        'status' => HotlistStatusEnum::Open->title(),
                        // DEV-2059 F1: the CH flat column — null for New Hit, else the
                        // driver-set string (e.g. 'Accessible') — so the hit-detail modal
                        // can seed the disposition control from the saved value on re-open
                        // instead of resetting to the placeholder every time.
                        'disposition' => $hit->disposition,
                        'plate_image' => scan_img($hit->scan_guid, 'p'),
                        'vehicle_image' => scan_img($hit->scan_guid, 'c'),
                        'lat' => $hit->lat ? (float) $hit->lat : null,
                        'lng' => $hit->lng ? (float) $hit->lng : null,
                        'vehicle_year' => $hit->vehicle_year,
                        'vehicle_make' => $hit->vehicle_make,
                        'vehicle_model' => $hit->vehicle_model,
                        'hotlist_us_state' => $hit->hotlist_us_state,
                    ])
                    ->all();
            });

            return response()->json(['data' => $data]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to load recent hits.'], 500);
        }
    }

    public function mapLayers(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => ScoutOrgSettings::storedEnabledLayers((int) $request->user()->org_id),
        ]);
    }

    public function updateMapLayers(UpdateScoutMapLayersRequest $request): JsonResponse
    {
        $orgId = (int) $request->user()->org_id;
        $enabled = array_values(array_unique($request->validated('enabled')));

        ScoutOrgSettings::query()->updateOrCreate(
            ['org_id' => $orgId],
            ['map_layers' => ScoutOrgSettings::mapFromEnabled($enabled)],
        );

        return response()->json([
            'enabled' => ScoutOrgSettings::storedEnabledLayers($orgId),
        ]);
    }

    /**
     * Zones payload in the driver shape ({assigned: [...]}) but org-wide — ALL of
     * the org's zones (not one driver's assigned set), so the org map shows the
     * same zone overlay drivers see. Mirrors ClarityScoutZonesController's feature
     * shape. (Heatmap order-density clipping still uses the run area, not zones.)
     */
    public function zones(Request $request): JsonResponse
    {
        $zones = Zone::query()
            ->forOrg((int) $request->user()->org_id)
            ->withGeoJson()
            ->get()
            ->map(function (Zone $zone) {
                $geometry = $zone->geom_geojson ? json_decode($zone->geom_geojson, true) : null;
                $centroidGeo = $zone->centroid_geojson ? json_decode($zone->centroid_geojson, true) : null;
                $centroid = is_array($centroidGeo['coordinates'] ?? null) ? $centroidGeo['coordinates'] : null;

                return [
                    'type' => 'Feature',
                    'id' => $zone->id,
                    'properties' => [
                        'id' => $zone->id,
                        'name' => $zone->name,
                        'color' => $zone->color,
                        'is_no_scan_zone' => $zone->is_no_scan_zone,
                        'centroid' => $centroid,
                        'description' => $zone->description,
                    ],
                    'geometry' => $geometry,
                ];
            })
            ->all();

        return response()->json(['assigned' => $zones]);
    }

    /**
     * Distinct kit_ids among $kitIds that scanned within [$start, $end]
     * (DEV-2293), mirroring stats()' ClickHouse scan-count pattern. Callers
     * cache this themselves (keyed per org+range, unlike orgScoutKits' own
     * range-agnostic cache) so the roster and the activity lookup can expire on
     * independent schedules.
     *
     * @param  array<int, int>  $kitIds
     * @return array<int, int>
     */
    private function activeKitIdsForRange(int $orgId, array $kitIds, Carbon $start, Carbon $end, string $timezone): array
    {
        if ($kitIds === []) {
            return [];
        }

        return ClickhouseScan::forWindow($start)->newQuery()
            ->final()
            ->forOrg($orgId)
            ->whereIntegerInRaw('kit_id', $kitIds)
            ->between($start, $end, $timezone)
            ->distinct()
            ->pluck('kit_id')
            ->unique()
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The kits in scope for this request: the org's Scout kits, optionally
     * narrowed by a `kit_ids` selector that is ALWAYS intersected against the
     * org's own kits (the client can never widen past its org).
     *
     * @return EloquentCollection<int, Kit>
     */
    private function scopedKits(Request $request): EloquentCollection
    {
        $requested = $request->input('kit_ids');
        $kitIds = is_array($requested) && $requested !== [] ? $requested : null;

        return $this->resolveOrgDriverPositions->forOrg((int) $request->user()->org_id, $kitIds);
    }

    /**
     * Map lowercased kit_mapping => Kit for the in-scope kits (mappings are the
     * only key sc_positions carries). Kits without a mapping are skipped.
     *
     * @return array<string, Kit>
     */
    private function kitsByMapping(EloquentCollection $kits): array
    {
        $byMapping = [];

        foreach ($kits as $kit) {
            $mapping = $kit->kit_mapping;

            if ($mapping === null || $mapping === '') {
                continue;
            }

            $byMapping[strtolower($mapping)] = $kit;
        }

        return $byMapping;
    }

    private function statsTtl(string $range): Carbon
    {
        return $range === 'today' ? now()->addSeconds(10) : $this->mapCacheTtl($range);
    }

    /**
     * @return array{scans_today: int, direct_hits: int, staging_hits: int, repos_affected: int, trip_count: int}
     */
    private function emptyStats(): array
    {
        return [
            'scans_today' => 0,
            'direct_hits' => 0,
            'staging_hits' => 0,
            'repos_affected' => 0,
            'trip_count' => 0,
        ];
    }
}
