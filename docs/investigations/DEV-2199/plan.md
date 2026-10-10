# DEV-2199 — Implementation Plan

**Feature:** Configurable Scout "teammate scan overlay" map layer (today + 7 days).
**Route:** feature · **Base:** main · **Mode:** normal · **Gate:** CLEAN (Dalton interview 2026-07-20).
**STAGING ONLY — do not enable in prod.** QA: Yolanda.

## Shape (single node, backend-first TDD → frontend → build)

A config-gated (global env flag, off by default), driver-only Map Layers toggle overlaying
*other* org drivers' historical driven lines (today + last 7 days) in **cyan `#0891B2`**,
snapshot from the `clarity.kit_scan_paths` MV, limited to the current viewport under the
DEV-2185 zoom (≥12) + bbox guardrails. Reuses `KitScanPathsQuery` + the Bird's-Eye org-kit
set (DEV-2182), re-scoped to the driver-authenticated map. Metrics/hits untouched.

### Backend
1. **Global flag** — `env('CLARITY_SCOUT_TEAMMATE_OVERLAY_ENABLED', false)` in the config
   file where Scout/experimental flags already live (verify home first). `config()` only.
2. **`me()`** (`ClarityScoutAuthController`) — add `teammate_overlay_enabled => (bool) config(<key>)`,
   mirroring `trips_enabled`.
3. **Endpoint** (`ClarityScoutMapController::teammatePaths`) — flag off ⇒ empty/gated; else org
   Scout kit set (Bird's-Eye `orgScoutKits`/`getKits`, cross-connection resolve-then-key,
   **exclude self**) → `KitScanPathsQuery(org_id, kit_ids, last_7, viewport bbox)` → flat path
   payload; graceful CH-failure degrade. Use the controller's own private
   `getDateRange`/`snapBbox`/`resolveOptionalBbox`.
4. **Route** — `GET map/teammate-paths` in the `throttle:120,1` driver group (Sanctum +
   `ClarityScoutMiddleware`). Never in the admin `scout/org-map` group.
5. **Tests (TDD)** — `ClarityScoutMapControllerTest` (flag-off gated; org kits minus self;
   `last_7`; bbox narrows; graceful CH-failure; no live-position/metric fields; `RequiresClickhouse`)
   + `ClarityScoutAuthControllerTest` (me() field reflects config). Name methods with `teammate`.

### Frontend
6. `stores/auth.js` — persist `teammateOverlayEnabled` (mirror `tripsEnabled`).
7. `stores/map.js` — `teammatePaths` ref + `fetchTeammatePaths(bounds)` (AbortController); wire
   `'teammates'` into `activeHeatmaps`/`refreshActiveHeatmaps`; `!adminMode`; snapshot fetch only
   (never on the 10s live-position poll).
8. `DashboardOverlay.vue` — `heatmapLayers` entry + cyan legend swatch, `v-if` on
   `authStore.teammateOverlayEnabled && !mapStore.adminMode`.
9. `ScannerMap.vue` — `'teammate-paths'` deck.gl `PathLayer` (cyan `rgb(8,145,178)`), gated
   `pathLayersEligible()` + `activeHeatmaps.has('teammates')` + `!adminMode`.

### Build
10. `npm run build`; revert Boost-regenerated doc churn; commit `public/build`.

## Guardrails
- KitScanPathsQuery reused **unchanged** (KitUserPathController is another consumer).
- No `org_meta` / `Org` accessor / migration / zone-membership / polygon-clip / position-dots.
- `!adminMode`-gate every new UI + fetch (shared driver/admin components).
- PHP 8.3-compatible. No JS test runner — frontend correctness via `npm run build` + agent/human QA.

## Excluded (see plan.json `excluded_sites`)
org_meta accessor · zone_user membership + polygon clip · OrgLive* queries · position dots ·
per-view DayOverlay/TripOverlay edits (shared list surfaces them) · KitUserPathController.
