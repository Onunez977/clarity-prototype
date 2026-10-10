import { defineStore } from 'pinia';
import { ref, computed, reactive } from 'vue';
import { authHeaders, handleUnauthorized, buildUrl } from '../lib/fetch';
import { setSelectedKitIds, setFollowedKitId, selectedKitIds } from '../lib/adminScope';
import { useDashboardStore } from './dashboard';
import { useToast } from '../composables/useToast';

const KIT_POSITION_KEY_PREFIX = 'clarity-scout-kit-position:';
// Don't resurrect a dot older than this on reload — matches the backend's 24h
// cutoff philosophy (a day-old location is misleading, better to show nothing).
const KIT_POSITION_MAX_AGE_MS = 24 * 60 * 60 * 1000;
// Persists the user's basemap color preference (DEV-2060). Shared across all
// users on a device — it's a display choice, not per-user data like the dot.
const COLOR_MAP_KEY = 'clarity-scout-color-map';
// Persists the user's live-trail direction-arrow preference (DEV-2061 toggle).
// Device-wide display choice like COLOR_MAP_KEY. Defaults off.
const TRAIL_ARROWS_KEY = 'clarity-scout-trail-arrows';

// Meters between two [lng, lat] points (haversine). Decides whether a new
// live-path batch continues the current segment or starts a fresh one, and lets
// the renderer connect the trail tail to the live dot only within reach.
export function metersBetween(a, b) {
    const R = 6_371_000;
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(b[1] - a[1]);
    const dLng = toRad(b[0] - a[0]);
    const s =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(a[1])) * Math.cos(toRad(b[1])) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
}

// Match HistoricPathBuilder's 500ft break threshold: a gap larger than this
// between the trail's tail and the next point is a power-off, so it starts its
// own segment rather than drawing a connector across it.
export const SEGMENT_BREAK_METERS = 500 * 0.3048;

// Per-driver retained-point cap for the Bird's Eye trails (M7). The admin poll
// appends new segments every ~10s, so without a bound each driver's trail grows
// for the whole shift and the cost is multiplied across every driver on screen.
// Retain only the most recent points, dropping the oldest, so a shift's worth of
// live trails stays bounded.
export const DRIVER_TRAIL_MAX_POINTS = 5000;

// DEV-2374: historical Org Map continuous GPS is fetched in kit-group chunks so
// a full-org yesterday/last_N load never becomes one mega-request. Size is 1 —
// KitRangePathsQuery's arrayFold/arraySplit pipeline OOMs easily on multi-kit
// chunks under load (local QA and dense prod fleets). Progress UI still shows
// kit N of M; one kit per request keeps failures isolated.
const HISTORICAL_SCAN_PATH_CHUNK_SIZE = 1;

// Parallel 1-kit HTTP calls. Server still refuses multi-kit chunks (OOM in
// KitRangePathsQuery assembly). Concurrency 5 ≈ 26 Houston kits in ~6 waves
// instead of 26 serial Cloud round-trips.
const HISTORICAL_SCAN_PATH_CONCURRENCY = 5;

// Transient chunk failures: a single retry with backoff. Aggressive retries on
// MEMORY_LIMIT_EXCEEDED pile onto an already-saturated ClickHouse and make the
// rest of the fan-out fail too.
const HISTORICAL_SCAN_PATH_CHUNK_RETRIES = 1;

// Fleet-wide render budget across ALL chunks (matches server
// MAX_HISTORICAL_RENDER_POINTS). Without this, N chunks × 1.5M each can melt
// the browser even when every individual request is "fine".
export const HISTORICAL_SCAN_PATH_FLEET_MAX_POINTS = 1_500_000;

/** Sum path vertex counts on scan-path rows (fallback if the render-points header is absent). */
export function countScanPathPoints(rows) {
    let total = 0;
    for (const row of rows ?? []) {
        if (Array.isArray(row?.path)) {
            total += row.path.length;
        }
    }
    return total;
}

/**
 * Parse X-Scout-Render-Points. Headers.get() returns null when absent, and
 * Number(null) === 0 — so a missing header must NOT look like a zero-point chunk
 * (that would skip the path-length fallback and never debit the fleet budget).
 */
export function parseRenderPointsHeader(rawHeader, rows) {
    if (rawHeader == null || rawHeader === '') {
        return countScanPathPoints(rows);
    }
    const headerPts = Number(rawHeader);
    if (Number.isFinite(headerPts) && headerPts >= 0) {
        return headerPts;
    }
    return countScanPathPoints(rows);
}

/** True when a lat/lng sits inside bounds expanded by padDeg. */
export function pointInPaddedBounds(lat, lng, bounds, padDeg = 0) {
    if (lat == null || lng == null || !bounds) {
        return false;
    }

    return lat >= bounds.south - padDeg
        && lat <= bounds.north + padDeg
        && lng >= bounds.west - padDeg
        && lng <= bounds.east + padDeg;
}

// Merge a fresh batch of server-built segments onto an existing trail, returning
// a new segment array. The server already broke gaps within the batch; the only
// cross-poll decision is the single boundary between the trail's tail and the
// batch's head: within the break threshold → continue the tail segment, else
// append as a new segment. The boundary point is deduped (the server's >= cursor
// bound re-includes the exact cursor bucket). Returns the existing array
// unchanged when there is nothing new to merge (so a ref set to it does not
// trigger). Pure: does not mutate `existing`. Shared by the single-kit live
// trail (mergeLiveSegments) and the per-driver Bird's Eye trails so the two
// merge policies cannot diverge.
export function mergeSegments(existing, newSegments) {
    const incoming = (newSegments ?? [])
        .map((s) => (Array.isArray(s?.path) ? s.path : []))
        .filter((p) => p.length > 0);
    if (incoming.length === 0) {
        return existing;
    }

    if (existing.length === 0) {
        return incoming.map((path) => ({ path }));
    }

    const segments = existing.map((s) => ({ path: [...s.path] }));
    const tail = segments[segments.length - 1].path;
    const tailPoint = tail[tail.length - 1];
    const [head, ...rest] = incoming;

    let headPoints = head;
    if (tailPoint && head[0] && metersBetween(tailPoint, head[0]) < 10) {
        headPoints = head.slice(1);
    }

    if (tailPoint && headPoints[0] && metersBetween(tailPoint, headPoints[0]) <= SEGMENT_BREAK_METERS) {
        tail.push(...headPoints);
    } else if (headPoints.length > 0) {
        segments.push({ path: headPoints });
    }

    for (const path of rest) {
        segments.push({ path });
    }

    return segments;
}

// Bound a trail to the most recent `maxPoints`, dropping the oldest points
// (whole oldest segments first, then a partial slice of the boundary segment).
// Returns the same array reference when already within the cap. Pure.
export function capSegments(segments, maxPoints) {
    let total = 0;
    for (const s of segments) {
        total += s.path?.length ?? 0;
    }
    if (total <= maxPoints) {
        return segments;
    }

    let overflow = total - maxPoints;
    const result = [];
    for (const s of segments) {
        const len = s.path?.length ?? 0;
        if (overflow >= len) {
            overflow -= len;
            continue;
        }
        if (overflow > 0) {
            result.push({ path: s.path.slice(overflow) });
            overflow = 0;
        } else {
            result.push(s);
        }
    }
    return result;
}

export const useMapStore = defineStore('map', () => {
    const pathsByRange = ref({});
    const currentRange = ref(null);
    // DEV-2016: individual hits (logo markers) for the selected date range on the
    // main map, distinct from the aggregated honey-hole density layer (hitHeatmap).
    const rangeHits = ref(null);          // [{ lat, lng, plate, time, status, hit_type, plate_image, vehicle_image, vehicle_year, vehicle_make, vehicle_model, hotlist_us_state }]
    const rangeHitsCapped = ref(false);   // true when the API capped the list (MAX_MAP_HITS)
    const rangeHitsLoading = ref(false);  // DEV-2597: true while /map/hits is in flight for a range
    const hitHeatmap = ref(null);
    // DEV-2031: Direct + Staging orders render as ONE unioned geohash layer
    // (summed per-cell counts, single shade) rather than two stacked layers.
    // The server combines whichever order layers are active; this holds that
    // single merged FeatureCollection (null when neither order layer is on).
    const ordersHeatmap = ref(null);
    // DEV-2199: OTHER org drivers' driven lines (today + last 7 days), a snapshot
    // overlay driver-only and gated !adminMode at both the fetch and render sides
    // (Bird's Eye never populates this). [{ path: [[lng,lat], ...] }, ...].
    const teammatePaths = ref([]);
    const kitPosition = ref(null);
    // The live trail: an array of segments [{ path: [[lng,lat], ...] }] tracing
    // the current drive, built from raw scanner GPS (clarity.sc_positions) so it
    // follows the road through the intermediate points rather than hopping
    // straight between 20s polls (DEV-2012). Grown incrementally by the live-path
    // poll (fetchLivePath/mergeLiveSegments), which fetches only the points newer
    // than liveCursor each tick. Segmented (not one flat line) so a power-off gap
    // breaks the line instead of drawing a connector. Replaced (not mutated) on
    // each merge so deck.gl's PathLayer sees a fresh data reference. Cleared when
    // Live mode is turned off. [lng,lat] order matches deck.gl convention.
    const liveTrail = ref([]);
    // Cursor for the incremental live-path poll: the timestamp_utc of the last
    // sc_positions point we hold. Sent as `since` on the next poll so the server
    // returns only newer points. Null until the first batch lands.
    const liveCursor = ref(null);
    // --- Bird's Eye admin mode (DEV-2116) ---------------------------------
    // All-driver collections, populated ONLY in admin mode by the admin poll.
    // Empty/unused in driver mode, so the single-kit kitPosition/liveTrail above
    // are untouched. driverPositions = latest dot per driver; driverTrailsByKit =
    // live trail segments keyed by kit_id. The kit_ids selector scope is applied
    // automatically by buildUrl (lib/adminScope), so these fetches need no params.
    const adminMode = ref(typeof window !== 'undefined' && window.__CLARITY_SCOUT_CONFIG__?.birdsEye === true);
    const drivers = ref([]);
    const driverPositions = ref([]);
    // DEV-2311: has the first driver-positions fetch resolved? Gates the
    // "No kits reporting" notice so an empty array during mount can't flash it.
    const positionsLoaded = ref(false);
    const driverTrailsByKit = reactive({});
    // Repaint signal for driverTrailsByKit (M7). The map is a reactive object
    // mutated in place per kit, so a shallow watch on it never fires and a deep
    // watch walks every point each poll; instead bump this counter whenever a
    // trail actually changes and let ScannerMap watch the counter cheaply.
    const driverTrailsVersion = ref(0);
    // Per-driver live cursor (kit_id -> last-seen UTC timestamp). Per-driver, not
    // one shared cursor: fleet fixes ingest at different latencies, so a shared
    // cursor would freeze every laggier driver's trail while its dot kept moving.
    const driverLiveCursors = reactive({});
    // Hovered driver (admin) — its trail draws red; all others draw black.
    const hoveredDriverKitId = ref(null);
    // Followed driver (admin) — clicked to "follow": camera tracks them, trail
    // stays red, and a name plate floats above their marker. null = not following.
    const followedDriverKitId = ref(null);
    // Show a name plate above every shown driver (admin display toggle, persisted).
    const NAMEPLATES_KEY = 'clarity-scout-org-map-nameplates';
    const driverNameplatesEnabled = ref(localStorage.getItem(NAMEPLATES_KEY) !== '0');
    // localStorage key for persisting the last-known dot, set per-user via
    // hydrateKitPosition so one operator's position can't leak to the next on a
    // shared device. Null until hydrated (then persistence is a no-op).
    let kitPositionKey = null;
    // One in-flight AbortController per heatmap layer (DEV perf F5). A rapid pan
    // aborts the prior request before issuing the next so overlapping fetches
    // don't stack and resolve last-writer-wins.
    let hitsAbortController = null;
    let ordersAbortController = null;
    const activeHeatmaps = reactive(new Set());
    // DEV-2600: Orders legend band visibility on the unioned layer. All five
    // bands start on. Not persisted — a reload shows every band.
    const ORDER_TIERS = ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'];
    const visibleOrderTiers = reactive(new Set(ORDER_TIERS));
    const DRIVER_LAYER_TYPES = ['hits', 'hotlist', 'staging', 'teammates'];
    const driverMapLayers = ref([...DRIVER_LAYER_TYPES]);
    const driverMapLayersSaving = ref(false);
    const isFullscreen = ref(false);
    const loading = ref(false);
    // Historical Org Map path fetch progress (kit chunks). Replaced as a whole
    // object so the map overlay can show "2/5" without deep-watching.
    const scanPathsProgress = ref({ active: false, done: 0, total: 0 });
    const zones = ref({ assigned: [] });
    const zonesVisible = ref(true);
    // DEV-2060: user-facing color toggle for the roadmap basemap. false (default)
    // = the desaturated monochrome style, true = standard full-color Google Maps
    // styling. Persisted to localStorage so the choice survives a reload.
    const colorMapEnabled = ref(localStorage.getItem(COLOR_MAP_KEY) === '1');
    // DEV-2061: user-facing toggle for the Live-only direction arrows along the
    // trail. false (default) = hidden, true = shown (still gated on Live mode +
    // zoom by the map). Persisted to localStorage so the choice survives a reload.
    const trailArrowsEnabled = ref(localStorage.getItem(TRAIL_ARROWS_KEY) === '1');
    // Viewport-follows-driver flag. True = the map pans to keep the kit dot
    // centered as it moves; flipped off the moment the driver pans the map or
    // focuses a zone, and back on only via the My Location button.
    const followKit = ref(true);
    // A focus request consumed by ScannerMap to fitBounds() onto a zone. The
    // nonce makes repeated taps on the same zone re-trigger the watcher.
    const zoneFocusRequest = ref(null);
    // A focus request consumed by ScannerMap to recenter on a single hit and
    // drop a one-off logo marker (DEV-1965 — tapping a Live Hits Pending
    // Repossession row). The nonce re-triggers the watcher on repeat taps.
    const hitFocusRequest = ref(null);
    // A recenter request consumed by ScannerMap when the My Location button (now
    // housed in the bottom bar) is tapped. Mirrors the hit/zone focus pattern.
    const kitRecenterRequest = ref(null);
    // DEV-2185: a request consumed by ScannerMap to snap the camera to the
    // shared PATH_LAYERS_MIN_ZOOM floor when a long historical range (15/30/60)
    // is picked, so the layer is immediately visible instead of landing hidden
    // below the gate. Mirrors the zone/hit/recenter focus-request pattern.
    const pathZoomSnapRequest = ref(null);
    let focusNonce = 0;
    // DEV-2185: the current map viewport, kept up to date by ScannerMap on every
    // idle. Lets ScoutHome's range-select fetch (and the admin driver-selector
    // re-fetch below) carry a bbox from the first request, not just on the next
    // pan/zoom.
    const mapBounds = ref(null);
    // DEV-2185: whether the last SETTLED viewport is at/above the shared zoom
    // floor. Pushed by ScannerMap only from the idle path (getBounds() is valid
    // then) — NOT synchronously after a zoom-snap, where getBounds() lags
    // setZoom(). The imperative fetch callers gate on this + mapBounds so they
    // never fire a wide/unbounded request; when it's false the deferred idle
    // path (refreshScanPaths) issues the one scoped fetch after the map settles.
    const pathLayersEligible = ref(false);

    // --- Persistent-map view mode + trip payload ---
    // The map is mounted once and switches between 'dashboard' (scan paths /
    // live dot) and 'trip' (a day's or single trip's path lines)
    // purely via store state, so the single ScannerMap instance is never torn
    // down on navigation. Trip data that used to arrive as props now lives here.
    const viewMode = ref('dashboard'); // 'dashboard' | 'trip'
    const tripPaths = ref(null);       // [{ path, color, tripIndex }] (day view)
    const tripPath = ref(null);        // [[lng,lat], ...] (single trip)
    const tripHits = ref(null);        // [{ lat, lng, plate }]
    // The hit-details sheet is cluster-aware: a tap on a single marker/row sets a
    // one-element list; a tap on a map cluster sets the whole cluster so the sheet
    // can cycle (Prev/Next + timestamp jump). selectedHit is the current element.
    const selectedHits = ref([]);      // [{ plate, time|hit_time, status?, lat, lng, ... }]
    const selectedHitIndex = ref(0);
    const selectedHit = computed(() => selectedHits.value[selectedHitIndex.value] ?? null);
    const hoveredTripIndex = ref(null);
    const selectedTripIndex = ref(null);
    const activeTripIndex = computed(() => hoveredTripIndex.value ?? selectedTripIndex.value);

    // Live vs Historical is an in-session view state, not a mode toggle (DEV-1959,
    // unified map): Live is always the default on login (kit dot + live trail);
    // picking a day-range pill switches to Historical scan paths, and the Live
    // button returns. Not persisted — every login is Live.
    const liveMode = ref(true);

    // DEV-2185: show the "zoom in to view path history" hint when a historical
    // range is active (not Live, not a trip view) but the scan-path layer is
    // gated-hidden by the shared zoom floor. DEV-2314: hoisted here from
    // ScannerMap so it can drive the floating pill in the bottom cluster (above
    // the stat sheet, by the date range filters) rather than a map overlay.
    const showZoomHint = computed(() =>
        !liveMode.value && viewMode.value !== 'trip' && !pathLayersEligible.value
    );

    // Live is the boot default, so force the hit-hotspot layer on up front.
    activeHeatmaps.add('hits');

    // Last layer actually painted — used while a new range/pan generation is
    // in flight so switching Yesterday/7d/15d does not blank to [] (computed
    // key miss) or drip 1-kit replacements.
    const lastPaintedScanPaths = ref([]);

    // When currentRange is set (dashboard mode), read from cache.
    // When null (standalone Map page), read from pathsByRange['__default'].
    // Missing key + in-flight fetch → keep the previous complete layer.
    const scanPaths = computed(() => {
        const key = currentRange.value ?? '__default';
        const forRange = pathsByRange.value[key];
        if (forRange !== undefined) {
            return forRange;
        }

        return lastPaintedScanPaths.value;
    });

    // DEV-2185: bounds (north/south/east/west) is optional and bbox-scopes the
    // request, mirroring fetchHitsHeatmap/fetchOrdersHeatmap; an in-flight
    // request is aborted before the next one is issued so a rapid range switch
    // or pan doesn't stack overlapping fetches.
    let scanPathsAbortController = null;
    // Monotonic generation so a superseded fetch cannot publish over the latest pan.
    let scanPathsGeneration = 0;
    // When historical fetch ran before the driver roster landed, retry once
    // fetchDrivers populates kit ids (RN5 M3).
    let pendingHistoricalScanPaths = null;

    function kitIdsForHistoricalScanPaths() {
        if (selectedKitIds.value?.length) {
            return selectedKitIds.value.map((id) => Number(id));
        }
        if (drivers.value.length) {
            return drivers.value.map((d) => Number(d.kit_id)).filter((id) => Number.isFinite(id));
        }

        return null;
    }

    async function runPool(items, concurrency, worker) {
        const results = new Array(items.length);
        let cursor = 0;
        const run = async () => {
            while (true) {
                const index = cursor++;
                if (index >= items.length) {
                    return;
                }
                results[index] = await worker(items[index], index);
            }
        };
        const n = Math.max(1, Math.min(concurrency, items.length));
        await Promise.all(Array.from({ length: n }, () => run()));

        return results;
    }

    function chunkKitIds(ids, size) {
        const chunks = [];
        for (let i = 0; i < ids.length; i += size) {
            chunks.push(ids.slice(i, i + size));
        }
        return chunks;
    }

    /**
     * One scan-paths HTTP call. Never throws on HTTP errors (except abort).
     * @param {number} [retries] attempts for transient failures (5xx / network);
     *   422 budget refusals return immediately.
     */
    async function fetchScanPathChunk(params, signal, retries = 1) {
        let last = { ok: false, status: 0, rows: [], points: 0 };

        for (let attempt = 1; attempt <= retries; attempt++) {
            if (signal.aborted) {
                throw new DOMException('Aborted', 'AbortError');
            }

            try {
                const response = await fetch(buildUrl('/map/scan-paths', params), {
                    headers: authHeaders(),
                    signal,
                });
                handleUnauthorized(response);
                if (response.status === 422) {
                    let message = '';
                    try {
                        const body = await response.json();
                        message = typeof body?.error === 'string' ? body.error : '';
                    } catch {
                        // ignore non-JSON 422 bodies
                    }
                    return { ok: false, status: 422, rows: [], points: 0, message };
                }
                if (!response.ok) {
                    last = { ok: false, status: response.status, rows: [], points: 0 };
                } else {
                    const data = await response.json();
                    const rows = Array.isArray(data) ? data : [];
                    const points = parseRenderPointsHeader(
                        response.headers.get('X-Scout-Render-Points'),
                        rows,
                    );
                    return { ok: true, status: response.status, rows, points };
                }
            } catch (e) {
                if (e.name === 'AbortError') {
                    throw e;
                }
                console.warn('Historical scan-paths chunk failed', e);
                last = { ok: false, status: 0, rows: [], points: 0 };
            }

            // 4xx (other than 408/429) won't improve on retry.
            if (
                last.status >= 400
                && last.status < 500
                && last.status !== 408
                && last.status !== 429
            ) {
                return last;
            }

            if (attempt < retries) {
                const delayMs = 250 * attempt;
                await new Promise((resolve, reject) => {
                    const timer = setTimeout(resolve, delayMs);
                    const onAbort = () => {
                        clearTimeout(timer);
                        reject(new DOMException('Aborted', 'AbortError'));
                    };
                    if (signal.aborted) {
                        onAbort();
                        return;
                    }
                    signal.addEventListener('abort', onAbort, { once: true });
                });
            }
        }

        return last;
    }

    async function fetchScanPaths(range = null, bounds = null) {
        const cacheKey = range ?? '__default';
        const generation = ++scanPathsGeneration;
        scanPathsAbortController?.abort();
        scanPathsAbortController = new AbortController();
        const signal = scanPathsAbortController.signal;

        const setProgress = (done, total) => {
            if (generation !== scanPathsGeneration) {
                return;
            }
            scanPathsProgress.value = { active: true, done, total };
        };
        const clearProgress = () => {
            if (generation !== scanPathsGeneration) {
                return;
            }
            scanPathsProgress.value = { active: false, done: 0, total: 0 };
        };

        // Any non-today range on Bird's Eye is historical continuous GPS (server
        // whitelist collapses unknowns to today, which is not chunked).
        const historical = adminMode.value && range != null && range !== 'today';
        const kitIds = historical ? kitIdsForHistoricalScanPaths() : null;

        // Historical MUST be kit-chunked (AC). No roster yet → defer until
        // fetchDrivers lands (RN5 M3); never fire an org-wide mega-request.
        // Only defer when we have a settled bbox — replaying null bounds would
        // bypass the DEV-2185 zoom/bbox guard (RN6 M2).
        if (historical && !kitIds?.length) {
            if (bounds && pathLayersEligible.value) {
                pendingHistoricalScanPaths = { range, bounds };
            }
            console.warn('Historical scan-paths deferred: driver roster not loaded yet');
            return null;
        }
        pendingHistoricalScanPaths = null;

        const publish = (rows) => {
            if (generation !== scanPathsGeneration) {
                return;
            }
            pathsByRange.value = {
                ...pathsByRange.value,
                [cacheKey]: rows,
            };
            lastPaintedScanPaths.value = rows;
        };

        try {
            // today / non-historical: single request (still replace — not accumulated).
            if (!historical) {
                setProgress(0, 1);
                const result = await fetchScanPathChunk({
                    ...(range ? { range } : {}),
                    ...(bounds ?? {}),
                }, signal, HISTORICAL_SCAN_PATH_CHUNK_RETRIES);
                if (generation !== scanPathsGeneration) {
                    return null;
                }
                if (!result.ok) {
                    // 422 = over budget; other failures leave the layer intact
                    // (RN5 M1 — do not toast "too large" / wipe on 500).
                    if (result.status === 422) {
                        useToast().show(
                            'Selection too large to render — zoom in or narrow the kits.',
                            { type: 'info', duration: 6000 },
                        );
                    } else {
                        console.error('Failed to fetch scan paths:', result.status);
                        useToast().show("Couldn't load paths — try again.", {
                            type: 'info',
                            duration: 6000,
                        });
                    }
                    clearProgress();
                    return pathsByRange.value[cacheKey] ?? [];
                }
                publish(result.rows);
                clearProgress();
                return result.rows;
            }

            // Parallel 1-kit requests; assemble off-screen; one replace when
            // the generation finishes so pan/range-switch does not drip 1-kit
            // layers (flash of more → less).
            const chunks = chunkKitIds(kitIds, HISTORICAL_SCAN_PATH_CHUNK_SIZE);
            let completed = 0;
            setProgress(0, chunks.length);

            const settled = await runPool(chunks, HISTORICAL_SCAN_PATH_CONCURRENCY, async (chunk) => {
                const result = await fetchScanPathChunk({
                    ...(range ? { range } : {}),
                    ...(bounds ?? {}),
                    kit_ids: chunk,
                    max_points: HISTORICAL_SCAN_PATH_FLEET_MAX_POINTS,
                }, signal, HISTORICAL_SCAN_PATH_CHUNK_RETRIES);
                completed += 1;
                setProgress(completed, chunks.length);

                return result;
            });

            if (generation !== scanPathsGeneration) {
                return null;
            }

            const generationRows = [];
            let remainingBudget = HISTORICAL_SCAN_PATH_FLEET_MAX_POINTS;
            let anyRows = false;
            let anySuccess = false;
            let budgetTruncated = false;
            let loadError = false;
            let refusalMessage = '';

            for (const result of settled) {
                if (!result.ok) {
                    if (result.status === 422) {
                        budgetTruncated = true;
                        if (typeof result.message === 'string' && result.message !== '') {
                            refusalMessage = result.message;
                        }
                    } else {
                        loadError = true;
                        console.warn(
                            'Historical scan-paths chunk failed; continuing',
                            result.status,
                        );
                    }
                    continue;
                }

                anySuccess = true;

                if (result.points > remainingBudget) {
                    budgetTruncated = true;
                    continue;
                }

                if (result.rows.length) {
                    generationRows.push(...result.rows);
                    anyRows = true;
                    remainingBudget = Math.max(
                        0,
                        HISTORICAL_SCAN_PATH_FLEET_MAX_POINTS - countScanPathPoints(generationRows),
                    );
                }
            }

            if (budgetTruncated) {
                useToast().show(
                    refusalMessage
                        || (anyRows
                            ? 'Showing a partial fleet — zoom in or narrow kits to load more paths.'
                            : 'Selection too large to render — zoom in or narrow the kits.'),
                    { type: 'info', duration: 6000 },
                );
            } else if (loadError && !anyRows) {
                useToast().show("Couldn't load paths — try again.", {
                    type: 'info',
                    duration: 6000,
                });
            } else if (loadError && anyRows) {
                console.warn(
                    'Historical scan-paths: some kits failed; showing successful chunks only',
                );
            }

            // Publish a complete generation, or a proven-empty viewport.
            // All-fail / all-422 with no rows keeps the previous layer.
            if (anyRows || (anySuccess && !budgetTruncated)) {
                publish(generationRows);
            }

            clearProgress();
            return pathsByRange.value[cacheKey] ?? lastPaintedScanPaths.value;
        } catch (e) {
            // A superseded request was aborted — leave the current layer intact.
            if (e.name === 'AbortError') {
                return null;
            }
            console.error('Failed to fetch scan paths:', e);
            if (generation === scanPathsGeneration && !pathsByRange.value[cacheKey]) {
                pathsByRange.value = { ...pathsByRange.value, [cacheKey]: [] };
            }
            clearProgress();
            return [];
        }
    }

    // DEV-2199: teammate overlay snapshot fetch — mirrors fetchScanPaths' abort
    // pattern. Driver-only; a caller in admin mode gets an empty layer rather
    // than hitting the driver-scoped endpoint (Bird's Eye has no equivalent
    // toggle). Called on toggle/pan/range-change only — never the 10s
    // live-position poll.
    let teammatePathsAbortController = null;

    async function fetchTeammatePaths(bounds = null) {
        if (adminMode.value) {
            teammatePaths.value = [];
            return teammatePaths.value;
        }
        teammatePathsAbortController?.abort();
        teammatePathsAbortController = new AbortController();
        const signal = teammatePathsAbortController.signal;
        try {
            const response = await fetch(buildUrl('/map/teammate-paths', bounds ?? {}), {
                headers: authHeaders(),
                signal,
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch teammate paths');
            teammatePaths.value = await response.json();
            return teammatePaths.value;
        } catch (e) {
            // A superseded request was aborted — leave the current layer intact.
            if (e.name === 'AbortError') return null;
            console.error('Failed to fetch teammate paths:', e);
            teammatePaths.value = [];
            return teammatePaths.value;
        }
    }

    function setMapBounds(bounds) {
        mapBounds.value = bounds;
    }

    function setPathLayersEligible(eligible) {
        pathLayersEligible.value = eligible;
    }

    function requestPathZoomSnap() {
        pathZoomSnapRequest.value = { nonce: ++focusNonce };
    }

    function clearPathZoomSnap() {
        pathZoomSnapRequest.value = null;
    }

    function setRange(range) {
        currentRange.value = range;
    }

    // DEV-2016: fetch the individual Live hits for a date range so the main map can
    // render tappable logo markers. A null range (Live mode) clears them.
    async function fetchRangeHits(range = null) {
        if (! range) {
            rangeHits.value = null;
            rangeHitsCapped.value = false;
            rangeHitsLoading.value = false;
            return [];
        }
        rangeHitsLoading.value = true;
        try {
            const response = await fetch(buildUrl('/map/hits', { range }), {
                headers: authHeaders(),
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch map hits');
            const json = await response.json();
            // DEV-2059 F2: suppress any hit whose HIDE disposition was just saved
            // but hasn't landed in ClickHouse yet (see hiddenScanIds above).
            rangeHits.value = applyHiddenScanIdsFilter(json.data ?? []);
            rangeHitsCapped.value = !!json.capped;
            return rangeHits.value;
        } catch (e) {
            console.error('Failed to fetch map hits:', e);
            rangeHits.value = [];
            rangeHitsCapped.value = false;
            return [];
        } finally {
            rangeHitsLoading.value = false;
        }
    }

    function clearRangeHits() {
        rangeHits.value = null;
        rangeHitsCapped.value = false;
        rangeHitsLoading.value = false;
    }

    // The Direct Hit Density circle layer (DEV-2030) — fetched on its own; it
    // stays OUT of the order union (DEV-2031 AC#5). Order layers go through
    // fetchOrdersHeatmap instead.
    async function fetchHitsHeatmap(bounds) {
        hitsAbortController?.abort();
        hitsAbortController = new AbortController();
        const signal = hitsAbortController.signal;
        try {
            const params = { ...bounds };
            // DEV-1967: when the driver has one or more assigned zones, clip the
            // hit-density layer to the union of those zones so hotspots surface
            // within their assigned area. No zones → no clip (all data renders).
            const assignedZoneIds = (zones.value?.assigned ?? [])
                .map(z => z?.id)
                .filter(id => id != null);
            if (assignedZoneIds.length >= 1) {
                params.zone_ids = assignedZoneIds;
            }

            const response = await fetch(buildUrl('/map/heatmap/hits', params), {
                headers: authHeaders(),
                signal,
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch hits heatmap');
            hitHeatmap.value = await response.json();
            return hitHeatmap.value;
        } catch (e) {
            // A superseded request was aborted — leave the current layer intact.
            if (e.name === 'AbortError') return null;
            console.error('Failed to fetch hits heatmap:', e);
            return null;
        }
    }

    // DEV-2031: fetch the unioned Direct + Staging order layer. Sends whichever
    // order layers are currently active (hotlist → direct, staging) so the server
    // combines them into one set of summed, single-shaded cells. Toggling either
    // layer re-runs this; neither active clears the layer. Zone-clipping is now
    // resolved server-side (Staging → assigned zones), so no zone_ids are sent.
    async function fetchOrdersHeatmap(bounds) {
        const layers = ['hotlist', 'staging']
            .filter(k => activeHeatmaps.has(k))
            .map(k => (k === 'hotlist' ? 'direct' : 'staging'));

        if (layers.length === 0) {
            ordersAbortController?.abort();
            ordersHeatmap.value = null;
            return null;
        }

        ordersAbortController?.abort();
        ordersAbortController = new AbortController();
        const signal = ordersAbortController.signal;

        try {
            const params = { ...bounds, layers };

            const response = await fetch(buildUrl('/map/heatmap/orders', params), {
                headers: authHeaders(),
                signal,
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch orders heatmap');
            ordersHeatmap.value = await response.json();
            return ordersHeatmap.value;
        } catch (e) {
            // A superseded request was aborted — leave the current layer intact.
            if (e.name === 'AbortError') return null;
            console.error('Failed to fetch orders heatmap:', e);
            return null;
        }
    }

    // Last-writer-wins setter for kitPosition. The dot is driven entirely by the
    // kit-position poll (sc_positions), so writes simply replace the current
    // position — except an all-null payload, which is held below.
    function setKitPosition(next) {
        if (!next) {
            kitPosition.value = null;
            return;
        }
        const prev = kitPosition.value;

        // Don't blank a good position with an all-null scan payload (no recent
        // scan in 24h) — keep showing the last known dot and the "YOU" legend.
        if (next.source === 'scan' && next.lat == null && prev?.lat != null) {
            return;
        }

        kitPosition.value = { ...next, fetched_at: Date.now() };
        persistKitPosition(next);
        // The dot and the live trail are separate concerns now: the dot is this
        // kit-position poll; the trail is the live-path poll (fetchLivePath),
        // which draws through the intermediate sc_positions points (DEV-2012).
    }

    // Write the last good position to localStorage so a page reload (or a
    // ClickHouse/GPS outage) doesn't blank the map — it repaints from cache,
    // marked stale, until a fresh source updates it. No-op until hydrated.
    function persistKitPosition(pos) {
        if (!kitPositionKey || pos?.lat == null || pos?.lng == null) return;
        try {
            localStorage.setItem(kitPositionKey, JSON.stringify({
                lat: pos.lat,
                lng: pos.lng,
                scanned_at: pos.scanned_at ?? new Date().toISOString(),
                vehicle_type: pos.vehicle_type ?? null,
            }));
        } catch { /* private mode / quota — best-effort only */ }
    }

    // Seed kitPosition from the per-user cache on dashboard mount, before the
    // network fetches, so the dot shows immediately on reload. Always treated as
    // a stale scan (source 'scan' → no live pulse); a real fix/scan overrides it.
    function hydrateKitPosition(userId) {
        kitPositionKey = `${KIT_POSITION_KEY_PREFIX}${userId ?? 'anon'}`;
        if (kitPosition.value?.lat != null) return; // a live source already won
        try {
            const raw = localStorage.getItem(kitPositionKey);
            if (!raw) return;
            const cached = JSON.parse(raw);
            const scannedAt = cached.scanned_at ? new Date(cached.scanned_at) : null;
            if (cached.lat == null || !scannedAt) return;
            const ageMs = Date.now() - scannedAt.getTime();
            if (ageMs > KIT_POSITION_MAX_AGE_MS) return;
            kitPosition.value = {
                lat: cached.lat,
                lng: cached.lng,
                scanned_at: cached.scanned_at,
                minutes_ago: Math.max(0, Math.floor(ageMs / 60000)),
                vehicle_type: cached.vehicle_type ?? null,
                source: 'scan',
                fetched_at: Date.now(),
            };
        } catch { /* private mode / bad JSON — ignore */ }
    }

    async function fetchKitPosition() {
        try {
            const response = await fetch(buildUrl('/map/kit-position'), {
                headers: authHeaders(),
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch kit position');
            const data = await response.json();
            setKitPosition(data);
            return data;
        } catch (e) {
            console.error('Failed to fetch kit position:', e);
            return null;
        }
    }


    // Merge a fresh batch of server-built segments onto the single-kit live trail
    // (see mergeSegments for the boundary/dedup policy). Replaced, not mutated, so
    // deck.gl's PathLayer sees a fresh data reference.
    function mergeLiveSegments(newSegments) {
        liveTrail.value = mergeSegments(liveTrail.value, newSegments);
    }

    // Incremental live-path poll (DEV-2012). Sends the last point we hold as
    // `since` so the server returns only newer sc_positions points; first poll
    // (null cursor) seeds from the start of the local day. Degrades to a no-op
    // on error — the existing trail stays put — mirroring fetchKitPosition.
    async function fetchLivePath() {
        try {
            const params = liveCursor.value ? { since: liveCursor.value } : {};
            const response = await fetch(buildUrl('/map/live-path', params), {
                headers: authHeaders(),
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch live path');
            const data = await response.json();
            mergeLiveSegments(data?.segments);
            if (data?.cursor) liveCursor.value = data.cursor;
            return data;
        } catch (e) {
            console.error('Failed to fetch live path:', e);
            return null;
        }
    }

    async function fetchZones() {
        try {
            const response = await fetch(buildUrl('/map/zones'), {
                headers: authHeaders(),
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch zones');
            const data = await response.json();
            const tag = (features) => (features ?? []).map(f => ({
                ...f,
                properties: { ...(f.properties ?? {}), is_assigned: true },
            }));
            zones.value = {
                assigned: tag(data?.assigned),
            };
            return zones.value;
        } catch (e) {
            console.error('Failed to fetch zones:', e);
            zones.value = { assigned: [] };
            return zones.value;
        }
    }

    function toggleZones() {
        zonesVisible.value = !zonesVisible.value;
    }

    function toggleColorMap() {
        colorMapEnabled.value = !colorMapEnabled.value;
        try {
            localStorage.setItem(COLOR_MAP_KEY, colorMapEnabled.value ? '1' : '0');
        } catch { /* private mode — ignore */ }
    }

    function toggleTrailArrows() {
        trailArrowsEnabled.value = !trailArrowsEnabled.value;
        try {
            localStorage.setItem(TRAIL_ARROWS_KEY, trailArrowsEnabled.value ? '1' : '0');
        } catch { /* private mode — ignore */ }
    }

    function setFollowKit(on) {
        followKit.value = on;
    }

    function requestZoneFocus(zoneId) {
        zoneFocusRequest.value = { zoneId, nonce: ++focusNonce };
    }

    function clearZoneFocus() {
        zoneFocusRequest.value = null;
    }

    // Look up an assigned zone's GeoJSON feature by id. Ids are compared
    // loosely since the broadcast payload arrives as a number while feature
    // ids may serialize as either — keeps the toast/map lookups robust.
    function findZoneFeature(zoneId) {
        return (zones.value.assigned ?? []).find((f) => String(f.id) === String(zoneId)) ?? null;
    }

    function toggleHeatmap(type) {
        if (activeHeatmaps.has(type)) {
            activeHeatmaps.delete(type);
        } else {
            activeHeatmaps.add(type);
        }
    }

    function toggleOrderTier(tier) {
        if (visibleOrderTiers.has(tier)) {
            visibleOrderTiers.delete(tier);
        } else {
            visibleOrderTiers.add(tier);
        }
    }

    function pruneHeatmapsTo(layers) {
        if (adminMode.value) {
            return;
        }

        const allowed = new Set(layers);
        for (const type of [...activeHeatmaps]) {
            if (!allowed.has(type)) {
                activeHeatmaps.delete(type);
            }
        }
    }

    async function fetchDriverMapLayers() {
        if (!adminMode.value) {
            return;
        }

        try {
            const response = await fetch(buildUrl('/settings/map-layers'), {
                headers: authHeaders(),
            });
            handleUnauthorized(response);
            if (!response.ok) {
                throw new Error('Failed to fetch driver map layers');
            }
            const json = await response.json();
            driverMapLayers.value = Array.isArray(json.enabled) ? json.enabled : [...DRIVER_LAYER_TYPES];
        } catch (e) {
            console.error('Failed to fetch driver map layers:', e);
        }
    }

    async function toggleDriverMapLayer(type) {
        const prev = [...driverMapLayers.value];
        const next = prev.includes(type)
            ? prev.filter((layer) => layer !== type)
            : [...prev, type];
        driverMapLayers.value = next;
        driverMapLayersSaving.value = true;

        try {
            const response = await fetch(buildUrl('/settings/map-layers'), {
                method: 'PUT',
                headers: authHeaders(),
                body: JSON.stringify({ enabled: next }),
            });
            handleUnauthorized(response);
            if (!response.ok) {
                throw new Error('Failed to save driver map layers');
            }
            const json = await response.json();
            if (Array.isArray(json.enabled)) {
                driverMapLayers.value = json.enabled;
            }
        } catch (e) {
            driverMapLayers.value = prev;
            console.error('Failed to save driver map layers:', e);
            useToast().show('Could not update driver map layers. Please try again.', { type: 'error' });
        } finally {
            driverMapLayersSaving.value = false;
        }
    }

    async function refreshActiveHeatmaps(bounds) {
        if (!bounds) return;
        const tasks = [];
        if (activeHeatmaps.has('hits')) tasks.push(fetchHitsHeatmap(bounds));
        // Always run the orders fetch — it derives its own active order set and
        // clears the layer when neither Direct nor Staging is on.
        tasks.push(fetchOrdersHeatmap(bounds));
        // DEV-2199: driver-only snapshot layer — never fetched in admin mode.
        if (!adminMode.value && activeHeatmaps.has('teammates')) tasks.push(fetchTeammatePaths(bounds));
        await Promise.all(tasks);
    }

    function setFullscreen(value) {
        isFullscreen.value = value;
    }

    // --- View mode / trip payload actions ---
    function setViewMode(mode) {
        viewMode.value = mode;
    }

    function enterTripMode({ paths = null, path = null, hits = null }) {
        tripPaths.value = paths;
        tripPath.value = path;
        tripHits.value = hits;
        hoveredTripIndex.value = null;
        selectedTripIndex.value = null;
        viewMode.value = 'trip';
    }

    function setTripData({ paths, path, hits }) {
        if (paths !== undefined) tripPaths.value = paths;
        if (path !== undefined) tripPath.value = path;
        if (hits !== undefined) tripHits.value = hits;
    }

    function exitTripMode() {
        tripPaths.value = null;
        tripPath.value = null;
        tripHits.value = null;
        clearSelectedHit();
        hoveredTripIndex.value = null;
        selectedTripIndex.value = null;
        viewMode.value = 'dashboard';
    }

    function setHoveredTripIndex(i) {
        hoveredTripIndex.value = i;
    }

    function setSelectedTripIndex(i) {
        selectedTripIndex.value = i;
    }

    function setSelectedHit(hit) {
        selectedHits.value = hit ? [hit] : [];
        selectedHitIndex.value = 0;
    }

    // DEV-1965: a Live Hits Pending Repossession row was tapped — recenter the
    // map on the hit and drop a one-off marker, then open the modal. Only
    // requests the recenter when the hit carries coordinates; the marker is
    // auto-cleared when the modal closes (selectedHit → null).
    function focusHit(hit) {
        setSelectedHit(hit);
        if (hit?.lat != null && hit?.lng != null) {
            hitFocusRequest.value = { lat: hit.lat, lng: hit.lng, nonce: ++focusNonce };
        }
    }

    function clearHitFocus() {
        hitFocusRequest.value = null;
    }

    function requestKitRecenter() {
        kitRecenterRequest.value = { nonce: ++focusNonce };
    }

    function clearKitRecenter() {
        kitRecenterRequest.value = null;
    }

    // A map cluster was tapped: show the whole group so the sheet can cycle through it.
    function setSelectedHitCluster(hits, index = 0) {
        selectedHits.value = Array.isArray(hits) ? hits : [];
        selectedHitIndex.value = selectedHits.value.length ? Math.min(Math.max(index, 0), selectedHits.value.length - 1) : 0;
    }

    function selectHitAt(index) {
        if (index >= 0 && index < selectedHits.value.length) {
            selectedHitIndex.value = index;
        }
    }

    // Prev/Next wrap around the cluster (modulo); no-ops on a single/empty selection.
    function nextHit() {
        const n = selectedHits.value.length;
        if (n > 1) selectedHitIndex.value = (selectedHitIndex.value + 1) % n;
    }

    function prevHit() {
        const n = selectedHits.value.length;
        if (n > 1) selectedHitIndex.value = (selectedHitIndex.value - 1 + n) % n;
    }

    function clearSelectedHit() {
        selectedHits.value = [];
        selectedHitIndex.value = 0;
    }

    // DEV-2059 correction: dispositions that keep a hit visible on the map /
    // recent-hits list — mirrors the backend's actionable rule (New Hit or
    // Accessible; New Hit also travels as a NULL disposition, which never
    // reaches here since there's no "clear" option). Everything else (Moving,
    // Inaccessible, Invalid Scan, and any analytics-only value like Valid) is a
    // HIDE disposition.
    const KEEP_VISIBLE_DISPOSITIONS = ['New Hit', 'Accessible'];

    // DEV-2059 F2: how long a HIDE-dispositioned scan_id stays suppressed after
    // save, regardless of which surface hid it. 3 minutes is comfortably longer
    // than a typical PG -> ClickHouse sync (SyncDispositionToClickhouse) plus any
    // read-side cache TTL, so by the time it elapses the server's own raw result
    // should already agree the hit is gone.
    const HIDDEN_SCAN_TTL_MS = 180_000;

    // DEV-2059 F2: session-scoped map of scan_id -> hiddenAt (Date.now()) for
    // hits whose HIDE disposition was just saved but hasn't landed in
    // ClickHouse yet — SyncDispositionToClickhouse runs async, so the NEXT
    // poll(s) would otherwise still return the hit (re-adding it right after the
    // optimistic removal below). Suppressed client-side via
    // applyHiddenScanIdsFilter for HIDDEN_SCAN_TTL_MS. A TTL, not "prune when
    // absent from the current poll's raw list", because the two surfaces that
    // call the shared filter disagree on what "absent" means: the map endpoint
    // (ClarityScoutMapController::hits()) server-side filters out any hit
    // without lat/lng, so a 0,0-coord hit (a real, common class in this data) is
    // NEVER in the map's raw poll — pruning on that absence would delete the
    // suppression before the CH sync actually lands, letting the same hit
    // flicker back in via the OTHER surface (recent-hits, which has no coord
    // filter). Never persisted (plain reactive Map, no localStorage) — a fresh
    // page load always trusts the server.
    const hiddenScanIds = reactive(new Map());

    // Shared poll-merge filter for every surface that can show a just-hidden hit
    // (the map's rangeHits and the dashboard store's recentHits — see
    // stores/dashboard.js). Called with each poll's RAW (pre-filter) result:
    // drops any entry whose TTL has elapsed (see HIDDEN_SCAN_TTL_MS for why this
    // is time-based rather than inferred from either surface's raw list), then
    // filters the raw list down to hits not currently (within-TTL) hidden.
    function applyHiddenScanIdsFilter(rawHits) {
        const raw = rawHits ?? [];
        if (hiddenScanIds.size === 0) {
            return raw;
        }
        const now = Date.now();
        for (const [id, hiddenAt] of [...hiddenScanIds]) {
            if (now - hiddenAt >= HIDDEN_SCAN_TTL_MS) {
                hiddenScanIds.delete(id);
            }
        }
        return hiddenScanIds.size === 0 ? raw : raw.filter((h) => !hiddenScanIds.has(h.scan_id));
    }

    // DEV-2059: write a driver-set disposition for the selected hit's underlying
    // scan. The server-side pipeline (Scan::updateDisposition ->
    // SyncDispositionToClickhouse) already makes a HIDE-disposition hit drop off
    // every read surface, but that only takes effect on the NEXT poll — remove it
    // here too so the driver isn't left staring at a hit they just closed out.
    // A KEEP disposition (New Hit / Accessible) must NOT be removed — the old
    // "remove on any successful save" behavior caused a flicker where an
    // Accessible hit vanished, then reappeared on the next poll. Only hits
    // carrying a scan_id (map markers / recent hits) can reach this; trip/day
    // markers have no control that calls it.
    async function updateSelectedHitDisposition(scanId, disposition, reason = null) {
        try {
            const payload = { scan_id: scanId, disposition };
            if (reason) {
                payload.reason = reason;
            }

            const response = await fetch(buildUrl('/scan/disposition'), {
                method: 'POST',
                headers: authHeaders(),
                body: JSON.stringify(payload),
            });
            handleUnauthorized(response);
            if (!response.ok) {
                throw new Error(`Failed to update disposition (${response.status})`);
            }

            if (KEEP_VISIBLE_DISPOSITIONS.includes(disposition)) {
                // Stays visible — sync the disposition on the local hit object (when
                // it carries one) so a subsequent poll, which re-derives from the
                // same allowlist, doesn't disagree and flicker the hit away.
                const patch = (h) => (h.scan_id === scanId ? { ...h, disposition } : h);

                if (rangeHits.value) {
                    rangeHits.value = rangeHits.value.map(patch);
                }
                selectedHits.value = selectedHits.value.map(patch);

                const dashboard = useDashboardStore();
                if (dashboard.recentHits) {
                    dashboard.recentHits = dashboard.recentHits.map(patch);
                }

                // A KEEP disposition must NEVER stay suppressed (DEV-2059 F2) — cover
                // the edge case where a previously-hidden scan_id somehow gets a KEEP
                // disposition applied next; it must show up again immediately.
                hiddenScanIds.delete(scanId);

                return true;
            }

            // DEV-2059 F2: this HIDE disposition's CH sync hasn't landed yet — mark
            // the id (with a timestamp) so the poll-merges (fetchRangeHits below,
            // and the dashboard store's fetchRecentHits) keep suppressing it even
            // after a poll re-adds it, for HIDDEN_SCAN_TTL_MS (expired in
            // applyHiddenScanIdsFilter).
            hiddenScanIds.set(scanId, Date.now());

            if (rangeHits.value) {
                rangeHits.value = rangeHits.value.filter((h) => h.scan_id !== scanId);
            }

            const remaining = selectedHits.value.filter((h) => h.scan_id !== scanId);
            if (remaining.length !== selectedHits.value.length) {
                if (remaining.length === 0) {
                    clearSelectedHit();
                } else {
                    selectedHitIndex.value = Math.min(selectedHitIndex.value, remaining.length - 1);
                    selectedHits.value = remaining;
                }
            }

            // DEV-2059 F1: the disposition control is also reachable from the
            // DashboardOverlay "Live Hits Pending Repossession" cards, so prune
            // that surface too or a dispositioned hit lingers there until the
            // next poll.
            const dashboard = useDashboardStore();
            if (dashboard.recentHits) {
                dashboard.recentHits = dashboard.recentHits.filter((h) => h.scan_id !== scanId);
            }

            return true;
        } catch (e) {
            console.error('Failed to update scan disposition:', e);
            useToast().show('Could not update disposition. Please try again.', { type: 'error' });
            return false;
        }
    }

    // Purge the live dot + its per-user localStorage cache. Retained as a
    // reset/teardown helper; no longer called on the range switch — the dot
    // persists across date ranges now (DEV-2310).
    function clearKitPosition() {
        setKitPosition(null);
        liveTrail.value = [];
        liveCursor.value = null;
        try {
            if (kitPositionKey) localStorage.removeItem(kitPositionKey);
        } catch { /* private mode */ }
    }

    // Single switch for Historical <-> Live, owning the side effects so the
    // toggle UI and the hits layer can't drift. The dot + live trail persist
    // across the switch; only the hits heatmap and range hits are gated here.
    function setLiveMode(on) {
        liveMode.value = on;

        if (on) {
            // Hot spots are part of the Live picture — force the hit hotspot layer
            // on so the glyphs show without the driver toggling it (and it can't be
            // toggled off; see DashboardOverlay).
            activeHeatmaps.add('hits');
            // Drop any stale historical-range pins, then load today's hits so the
            // driver sees their own hits on the route in Live (DEV-2016 markers,
            // today range). The kit-position poll refreshes them as the drive goes on.
            clearRangeHits();
            fetchRangeHits('today');
        } else {
            activeHeatmaps.delete('hits');
        }
    }

    // --- Bird's Eye admin fetches (DEV-2116) ------------------------------
    // DEV-2293: an optional range annotates each roster entry with
    // active_in_range (has the kit scanned in that window?) without shrinking
    // the roster itself — store.drivers stays the FULL org list so
    // followedDriverName/noKitsReporting and the nameplate toggle keep working.
    async function fetchDrivers(range = null) {
        try {
            const response = await fetch(buildUrl('/drivers', range ? { range } : {}), { headers: authHeaders() });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch drivers');
            drivers.value = (await response.json()).data ?? [];
        } catch (e) {
            console.error('Failed to fetch drivers:', e);
        }
        // RN5 M3: historical paths deferred until roster exists — retry once,
        // only with a settled eligible bbox (RN6 M2).
        if (pendingHistoricalScanPaths && drivers.value.length) {
            const pending = pendingHistoricalScanPaths;
            pendingHistoricalScanPaths = null;
            if (pending.bounds && pathLayersEligible.value) {
                fetchScanPaths(pending.range, pending.bounds);
            }
        } else if (pendingHistoricalScanPaths && drivers.value.length === 0) {
            // Roster resolved empty / failed — drop the defer so we don't retry forever.
            pendingHistoricalScanPaths = null;
        }
        return drivers.value;
    }

    async function fetchDriverPositions() {
        try {
            const response = await fetch(buildUrl('/map/driver-positions'), { headers: authHeaders() });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch driver positions');
            driverPositions.value = (await response.json()).data ?? [];
        } catch (e) {
            console.error('Failed to fetch driver positions:', e);
        }
        positionsLoaded.value = true;
        return driverPositions.value;
    }

    // Incremental like fetchLivePath, but per driver: sends each driver's own
    // cursor as a `sinces` map, appends the new segments, and advances that
    // driver's cursor from the response. Reset on selection change so a
    // newly-selected driver's trail starts clean.
    async function fetchDriverPaths() {
        try {
            const sinces = {};
            for (const [kitId, ts] of Object.entries(driverLiveCursors)) {
                if (ts) sinces[kitId] = ts;
            }
            const params = Object.keys(sinces).length ? { sinces } : null;
            const response = await fetch(buildUrl('/map/driver-paths', params), { headers: authHeaders() });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to fetch driver paths');
            const json = await response.json();
            let changed = false;
            for (const d of json.drivers ?? []) {
                const existing = driverTrailsByKit[d.kit_id] ?? [];
                const merged = capSegments(mergeSegments(existing, d.segments), DRIVER_TRAIL_MAX_POINTS);
                if (merged !== existing) {
                    driverTrailsByKit[d.kit_id] = merged;
                    changed = true;
                }
                if (d.cursor) driverLiveCursors[d.kit_id] = d.cursor;
            }
            if (changed) driverTrailsVersion.value++;
        } catch (e) {
            console.error('Failed to fetch driver paths:', e);
        }
    }

    function resetDriverLive() {
        driverPositions.value = [];
        Object.keys(driverTrailsByKit).forEach((k) => delete driverTrailsByKit[k]);
        Object.keys(driverLiveCursors).forEach((k) => delete driverLiveCursors[k]);
        driverTrailsVersion.value++;
    }

    function setHoveredDriver(kitId) {
        hoveredDriverKitId.value = kitId ?? null;
    }

    // Follow works like the driver app's recenter button: clicking a driver
    // (re)centers + follows them; a manual map drag clears it (see ScannerMap's
    // dragstart). Setting always follows — re-clicking the same driver re-centers.
    // Following a driver also scopes the stats box to just them (cleared on
    // un-follow → stats fall back to the selected set). Re-fetch stats so the
    // tiles update immediately.
    function setFollowedDriver(kitId) {
        followedDriverKitId.value = kitId ?? null;
        setFollowedKitId(followedDriverKitId.value);
        const dashboard = useDashboardStore();
        dashboard.fetchStats().catch(() => {});
        dashboard.fetchRecentHits(dashboard.range).catch(() => {});
    }

    function clearFollowedDriver() {
        followedDriverKitId.value = null;
        setFollowedKitId(null);
        const dashboard = useDashboardStore();
        dashboard.fetchStats().catch(() => {});
        dashboard.fetchRecentHits(dashboard.range).catch(() => {});
    }

    // Single source of truth for "whose stats are these" on Bird's Eye — every
    // admin surface that labels them reads this instead of re-deriving it, so
    // they can't drift apart.
    const noun = (n) => (n === 1 ? 'driver' : 'drivers');
    const statsScope = computed(() => {
        const totalCount = drivers.value.length;
        if (followedDriverKitId.value != null) {
            const id = followedDriverKitId.value;
            const d = drivers.value.find((x) => x.kit_id === id)
                ?? (driverPositions.value ?? []).find((p) => p.kit_id === id);
            const driverName = d ? (d.driver_name || d.name || `Kit ${id}`) : `Kit ${id}`;
            return { state: 'followed', driverName, selectedCount: 1, totalCount, driverNoun: noun(1) };
        }
        if (selectedKitIds.value?.length) {
            const selectedCount = selectedKitIds.value.length;
            return { state: 'subset', driverName: null, selectedCount, totalCount, driverNoun: noun(selectedCount) };
        }
        return { state: 'aggregate', driverName: null, selectedCount: totalCount, totalCount, driverNoun: noun(totalCount) };
    });

    function toggleDriverNameplates() {
        driverNameplatesEnabled.value = ! driverNameplatesEnabled.value;
        try {
            localStorage.setItem(NAMEPLATES_KEY, driverNameplatesEnabled.value ? '1' : '0');
        } catch { /* private mode */ }
    }

    // Driver selector → narrow the org-wide view to the chosen kit_ids (null =
    // everyone). buildUrl auto-applies the scope, so we just record it, reset the
    // live trails (a newly-selected driver starts clean), and re-pull the scoped
    // layers + stats for the current view.
    async function setSelectedKits(ids) {
        setSelectedKitIds(ids);
        resetDriverLive();
        // Kit scope changed — drop accumulated historical corridors so deselected
        // kits do not linger on the map (DEV-2374 option 2).
        pathsByRange.value = {};
        lastPaintedScanPaths.value = [];
        const dashboard = useDashboardStore();
        const tasks = [
            fetchDriverPositions(),
            fetchDriverPaths(),
            currentRange.value ? fetchRangeHits(currentRange.value) : Promise.resolve(),
            dashboard.fetchStats(),
            dashboard.fetchRecentHits(dashboard.range),
        ];
        // DEV-2185: only re-pull the scan-path overlay when the settled viewport
        // is at/above the zoom floor and its bbox is known — otherwise the layer
        // is hidden anyway, and a wide/unbounded fetch is the crash trigger. The
        // idle path refetches the scoped overlay once the admin zooms/pans in.
        if (mapBounds.value && pathLayersEligible.value) {
            tasks.push(fetchScanPaths(currentRange.value, mapBounds.value));
        }
        await Promise.all(tasks);
    }

    return {
        adminMode,
        drivers,
        driverPositions,
        positionsLoaded,
        driverTrailsByKit,
        driverTrailsVersion,
        hoveredDriverKitId,
        setHoveredDriver,
        followedDriverKitId,
        setFollowedDriver,
        clearFollowedDriver,
        statsScope,
        driverNameplatesEnabled,
        toggleDriverNameplates,
        fetchDrivers,
        fetchDriverPositions,
        fetchDriverPaths,
        resetDriverLive,
        setSelectedKits,
        scanPaths,
        rangeHits,
        rangeHitsCapped,
        rangeHitsLoading,
        fetchRangeHits,
        clearRangeHits,
        pathsByRange,
        hitHeatmap,
        ordersHeatmap,
        teammatePaths,
        fetchTeammatePaths,
        kitPosition,
        activeHeatmaps,
        ORDER_TIERS,
        visibleOrderTiers,
        driverMapLayers,
        driverMapLayersSaving,
        pruneHeatmapsTo,
        fetchDriverMapLayers,
        toggleDriverMapLayer,
        isFullscreen,
        loading,
        scanPathsProgress,
        currentRange,
        fetchScanPaths,
        setRange,
        mapBounds,
        setMapBounds,
        pathLayersEligible,
        setPathLayersEligible,
        pathZoomSnapRequest,
        requestPathZoomSnap,
        clearPathZoomSnap,
        fetchHitsHeatmap,
        fetchOrdersHeatmap,
        fetchKitPosition,
        setKitPosition,
        hydrateKitPosition,
        liveTrail,
        liveCursor,
        fetchLivePath,
        mergeLiveSegments,
        toggleHeatmap,
        toggleOrderTier,
        setFullscreen,
        zones,
        zonesVisible,
        colorMapEnabled,
        trailArrowsEnabled,
        fetchZones,
        toggleZones,
        toggleColorMap,
        toggleTrailArrows,
        followKit,
        setFollowKit,
        zoneFocusRequest,
        requestZoneFocus,
        clearZoneFocus,
        hitFocusRequest,
        focusHit,
        clearHitFocus,
        kitRecenterRequest,
        requestKitRecenter,
        clearKitRecenter,
        findZoneFeature,
        refreshActiveHeatmaps,
        viewMode,
        tripPaths,
        tripPath,
        tripHits,
        selectedHit,
        selectedHits,
        selectedHitIndex,
        hoveredTripIndex,
        selectedTripIndex,
        activeTripIndex,
        liveMode,
        showZoomHint,
        setViewMode,
        enterTripMode,
        setTripData,
        exitTripMode,
        setHoveredTripIndex,
        setSelectedTripIndex,
        setSelectedHit,
        setSelectedHitCluster,
        selectHitAt,
        nextHit,
        prevHit,
        clearSelectedHit,
        updateSelectedHitDisposition,
        hiddenScanIds,
        applyHiddenScanIdsFilter,
        clearKitPosition,
        setLiveMode,
    };
});
