# DEV-2234 — Plan

**Approach:** Option B (full removal) of the Scout Debug map control, **keeping** `config('app.scanner_debug_map_enabled')` in place solely for `scanPaths()` debug coloring (per Dalton's gate decision). Single-node fast path — one cohesive removal, interdependent files, no sensitive-glob blast radius.

## Subtask t1 (complexity: complex, single node)

### Frontend (verified via `npm run build` + browser QA — no JS test runner)
- **Delete** `resources/js/clarity-scout/components/DebugMapControls.vue`.
- `DashboardOverlay.vue`: remove the import, the `<DebugMapControls v-if="debugMapEnabled" @change="handleDebugChange">` block, the `debugMapEnabled` const, and `handleDebugChange()`.
- `stores/map.js`: remove `mapType`, `debugPrecision`, `toggleMapType()`, `setDebug()`, the `if (debugPrecision.value !== 7) params.precision = …` lines in `fetchHitsHeatmap`/`fetchOrdersHeatmap`, and drop those names from the returned object.
- `ScannerMap.vue`: drop `mapTypeId: mapStore.mapType` from the `Map()` init (defaults to roadmap); remove the `watch(mapStore.mapType)` and `watch(mapStore.debugPrecision)` watchers.
- `clarity-scout.blade.php:25` + `clarity-scout-birds-eye.blade.php:17`: remove the `debugMapEnabled` config injection.

### Backend (TDD)
- `ScoutMapHelpers.php`: delete `resolveDebugPrecision()` and the `'precision' => ['sometimes','integer','between:6,9']` validation rule.
- `ClarityScoutMapController.php` (`heatmapHits`, `heatmapOrders`) **and** `ClarityScoutBirdsEyeController.php` (`heatmapHits`, `heatmapOrders`): replace `resolveDebugPrecision($request)` with a hardcoded `$precision = 7;` (NOT deletion — CH classes fall back to 6 when the param is absent) and drop the inline precision rule.
- `scanPaths()` and its `$debug`/`:debug` cache key: **UNCHANGED**.

### Config / docs
- `config/app.php`: **keep** `scanner_debug_map_enabled`; reword its doc-comment to say it now only gates the scan-path per-hour debug coloring.
- `okf/concepts/scout-dashboard-stats.md`: update the section naming `resolveDebugPrecision()` (method deleted).

### Tests
- `ClarityScoutMapControllerTest.php`: remove the honor/ignore-precision-when-debug + range-validation tests; **keep** the precision-7-default pin.
- `ClarityScoutBirdsEyeControllerTest.php`: **add** a precision-defaults-to-7 test (no current coverage).
- `ClarityScoutShellViewTest.php`: flip the `debugMapEnabled` `assertSee` → `assertDontSee`.

## Excluded (must NOT change)
- The `?? 6` `sanitizePrecision` fallback in `HitDirectHotspotQuery` / `HotlistAddressGeohashQuery` — shared with non-Scout callers; unreachable from Scout once precision is hardcoded to 7.
- `scanPaths()` / `HistoricPathBuilder` / `HistoricPathBuilderTest` — kept per the keep-flag decision.
- `colorMapEnabled`/`toggleColorMap` (DEV-2060), `trailArrowsEnabled` (DEV-2061) — separate user features.

## Acceptance criteria mapping
1. Debug control never renders → component + wiring deleted.
2. No dead references (scope B) → all popover/precision/satellite symbols removed; flag intentionally retained for scanPaths (not dead).
3. Default roadmap + precision-7 unchanged → roadmap is the default basemap once `mapType` is gone; precision hardcoded to 7 at all four call sites.
