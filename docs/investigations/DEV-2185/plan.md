# DEV-2185 — Implementation Plan

**Scout Map perf: zoom-gate + bounding-box fetch for the 15/30/60-day path layers AND the direct/staging order layers (plus the Direct Hit Density layer) so they only render/fetch when zoomed in — fixing the 30/60-day browser crash.**

Route: FEATURE. Base: `main`. Gate: CLEAN (all design decisions resolved by Dalton 2026-07-14).

## Sizing verdict — ONE PR (do not split)

14 source files is above the usual >6 line, but this is a **single coherent concern**: the long-window (15/30/60-day) path/order/hit-density layers have no zoom floor and no viewport scoping, and the fix is one interdependent set of ACs. The file count is inflated by three structural facts, not by scope sprawl:

1. **Two backend query classes, two techniques** — the driver path (`KitHistoricPositionsQuery`, raw `sc_positions` points → simple lat/lng WHERE) and the admin/Bird's-Eye path (`KitScanPathsQuery`, `kit_scan_paths` `path_points Array(Tuple)` → `arrayExists`).
2. **Shared frontend** — both surfaces mount the same `ScannerMap.vue` / `stores/map.js` / `ScoutHome.vue`, so one frontend change lands on both; only the backend is done twice.
3. **Tests** — new CH-query unit tests + controller feature tests for each surface.

Splitting would fragment one perf fix across PRs with a shared frontend that can't be meaningfully half-shipped. Keep as one PR, structured into 5 well-scoped subtasks.

## Resolved design decisions (must be implemented exactly)

- **(a)** ONE shared zoom floor const = **12** (new local const in `ScannerMap.vue`, mirroring `RANGE_HIT_MARKERS_MIN_ZOOM=12`), applied to the historical scan-path PathLayer, the direct/staging order GeoJsonLayer, AND the Direct Hit Density circle layer. The gate must skip the **FETCH** (network call), not just the render — scan-paths has no server-side row cap. → **t5**
- **(b)** Selecting 15/30/60 **snaps** the map to zoom ≥ 12 (so the layer is visible), and the existing `fitToScanPaths()` zoom-OUT is **dropped/neutralized**. Wire the snap into `DateRangeControl.vue` `selectRange()` using the existing focus-request nonce pattern. → **t5**
- **(c)** Admin bbox on `KitScanPathsQuery` (`path_points Array(Tuple(Float64,Float64))`) = **`arrayExists` any-point-in-bbox, pushed down in ClickHouse**. No MV/point-level restructure. → **t2**
- **(d)** **DROP** the long-range (15/30/60) background prefetch entirely (`prefetchAllRangePaths` in `stores/map.js` + its `usePrefetch.js` trigger). On-demand bbox-scoped fetch replaces it. → **t5**
- **(e)** The Direct Hit Density circle layer (`'hits'` / `HitDirectHotspotQuery`) ALSO gets the shared zoom floor (folded into (a)). → **t5**
- **(f)** Geohash heatmap for long windows = **DEFERRED** (out of scope this pass). → not planned.

## Subtask DAG (ordered)

Parallel groups: `[t1, t2]` → `[t3, t4]` → `[t5]`.

Backend bbox params (query classes → controllers, additive/backward-compatible) land before the frontend wiring that consumes them.

### t1 — Driver historical path query bbox (parallel with t2)
- **Files:** `app/Clickhouse/Queries/KitHistoricPositionsQuery.php`, `app/Support/Scout/HistoricPathBuilder.php` (verify-only), new `tests/Unit/Clickhouse/Queries/KitHistoricPositionsQueryTest.php`
- Optional/additive lat/lng WHERE on `tupleElement(geo,2)`/`tupleElement(geo,1)` before the 3s decimation groupBy. Omitting bbox reproduces today's rows exactly.
- Segment-break safety: filter against the outward-snapped (0.02deg) viewport so edge points that anchor a segment survive; `HistoricPathBuilder::build()` needs no change under this margin approach (edit only if post-build render filtering is chosen instead).
- **test_filter:** `KitHistoricPositionsQueryTest`

### t2 — Admin path query bbox, `arrayExists` (parallel with t1) — CORRECTNESS constraint
- **Files:** `app/Clickhouse/Queries/KitScanPathsQuery.php`, new `tests/Unit/Clickhouse/Queries/KitScanPathsQueryTest.php`
- Optional/additive `arrayExists(p -> ... , path_points)` any-point-in-bbox, pushed down, alongside the existing `length(path_points) >= 2` guard.
- **blast_radius: `kit-user-path-non-scout-backward-compat`** — `app/Http/Controllers/KitUserPathController.php:44` shares this class and MUST be unaffected when no bbox is passed. This tag is a **backward-compat correctness gate**, not a sensitive-glob approval gate; no file matches the sensitive globs and **no human pre-approval line is required**.
- **test_filter:** `KitScanPathsQueryTest`

### t3 — Driver scanPaths controller wiring + cache key (depends on t1; parallel with t4)
- **Files:** `app/Http/Controllers/Api/ClarityScoutMapController.php`, `tests/Feature/Http/Controllers/Api/ClarityScoutMapControllerTest.php`
- Optional numeric bbox validation (NOT required), outward-snap on the 0.02deg grid via a small **private** helper (this controller does not `use ScoutMapHelpers` — mirror its existing private `getDateRange`/`mapCacheTtl`; no forced dedup), pass into `KitHistoricPositionsQuery`, fold snapped bbox into the `Cache::remember` key so two viewports at the same range don't share a stale entry.
- **test_filter:** `ClarityScoutMapControllerTest`

### t4 — Bird's-Eye org-wide scanPaths controller wiring (depends on t2; parallel with t3)
- **Files:** `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`, `app/Http/Controllers/Api/Concerns/ScoutMapHelpers.php` (optional), `tests/Feature/Http/Controllers/Api/ClarityScoutBirdsEyeControllerTest.php`
- Optional numeric bbox validation (do NOT reuse `validateHeatmapRequest`, which marks bbox required), reuse `snapBbox()` from the trait, pass into `KitScanPathsQuery`. The CH query is cached via `KitScanPathsQuery::query(cache:true)` whose key derives from the args array, so bbox differentiates the cache automatically — verify and add an explicit component only if needed.
- **test_filter:** `ClarityScoutBirdsEyeControllerTest`

### t5 — Frontend: zoom-gate + bbox fetch + drop prefetch + snap-to-zoom + rebuild assets (depends on t3, t4)
- **Files:** `ScannerMap.vue`, `stores/map.js`, `composables/usePrefetch.js`, `views/ScoutHome.vue`, `components/DateRangeControl.vue`, `stores/dashboard.js` (verify-only) + `public/build/*` (rebuilt).
- One cohesive chunk (`ScannerMap.vue` + `stores/map.js` are edited together). Implements (a),(b),(d),(e): shared zoom floor gating fetch+render for path/order/hit-density layers; `fetchScanPaths(range, bounds)` + AbortController + bbox params (reusing `VIEW_SNAP_STEP=0.02`); drop `prefetchAllRangePaths` + its `usePrefetch.js` trigger; snap-to-zoom via the focus-request nonce; neutralize `fitToScanPaths()` zoom-out; `ScoutHome.vue` passes current map bounds at range-watch time.
- Finish with `npm run build` and commit the rebuilt Scout bundles; **revert the boost-doc regen side effects** (`CLAUDE.md`/`AGENTS.md`/copilot/junie + `boost.json`) before committing.
- **UI impact: yes → UAT needed.** No test_filter — platform has **no JS test runner**; frontend behavior is verified via browser/agent QA, not unit tests.

## Test strategy

- **Backend (PHPUnit, required):** each query class gets a new unit test under `tests/Unit/Clickhouse/Queries/` following `HotlistAddressGeohashQueryTest` (RequiresClickhouse trait) — asserting the bbox restricts rows AND that omitting bbox preserves current behavior (backward-compat, esp. for `KitUserPathController`). Each controller test gains bbox-excludes-out-of-viewport coverage (mirror `test_heatmap_orders_bbox_fallback_excludes_cells_outside_the_viewport`), a cache-key regression (driver), and a multi-kit org-wide scenario (admin — the crash trigger). Existing bbox-less scan-paths tests must stay green.
- **Frontend (no JS runner):** zoom-gate render/fetch-skip, bbox-refetch-on-pan, prefetch-drop, and snap-to-zoom are verified via agent/human browser QA (network tab for the fetch-skip and bbox params; devtools memory for the 30/60-day no-crash on a dense multi-kit org). See prep-context `qa_hint`.

## Completeness

All `needs-change`/`buggy` sites from the prep exhaustiveness sweep are covered:

| Site | Subtask |
|---|---|
| `ScannerMap.vue:1174-1193` scan-path PathLayer (buggy) | t5 |
| `ScannerMap.vue:1089-1111` order GeoJsonLayer (needs-change) | t5 |
| `map.js:166-185` fetchScanPaths (needs-change) | t5 |
| `map.js:222-227` + `usePrefetch.js` prefetch (buggy) | t5 |
| `dashboard.js:85-92` prefetchAllRanges (needs-change, verify) | t5 |
| `ClarityScoutMapController.php:39-112` (needs-change) | t3 |
| `ClarityScoutBirdsEyeController.php:219-263` (needs-change) | t4 |
| `KitHistoricPositionsQuery.php` (needs-change) | t1 |
| `KitScanPathsQuery.php` (needs-change) | t2 |
| `KitUserPathController.php:44` (safe) | honored as backward-compat constraint in t2 (not changed) |

Reference-only sites (the zoom-gate idiom at `ScannerMap.vue:425-509`, the bbox-fetch pattern at `map.js:232-306`, `heatmapHits/heatmapOrders`) are patterns to mirror, not changes. `excluded_sites` is empty.
