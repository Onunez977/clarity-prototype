---
type: Pattern
title: Scout Real-Time Tracking
description: How Scout tracks scanner kits live — scanner GPS → clarity.sc_positions (ClickHouse) → polled/broadcast → Vue/deck.gl, plus offline detection, zone broadcasts, and the admin Bird's Eye fan-out.
resource: resources/js/clarity-scout/stores/map.js
tags: [scout, clickhouse, realtime, broadcasting, vue, geo]
timestamp: 2026-07-06T00:00:00Z
---

# Scout Real-Time Tracking

[Scout](scout.md) shows a driver (and, in Bird's Eye, an admin) where kits are **right now**, the trail they have driven, and the hit/order density around them. The "live" feel is built from two mechanisms: **HTTP polling** of ClickHouse-backed position endpoints, and **WebSocket broadcasts** for zone changes. There is no socket stream of GPS — positions are polled.

## Data pipeline

```
scanner GPS  →  clarity.sc_positions (ClickHouse)  →  ClickhouseQuery classes  →  /api/scout/map/* JSON  →  Pinia store → deck.gl layers
```

- `clarity.sc_positions` (ClickHouse) is the near-live kit GPS feed, keyed by **`kit_mapping`** (the kit's GL mapping), with `geo` (a Point tuple, lng/lat), `timestamp_utc`, plus heading/speed. See [ClickHouse integration](clickhouse-integration.md).
- **Case sensitivity:** `platform.kits` lowercases `kit_mapping` while `sc_positions` keeps the scanner's original casing, and ClickHouse `=` is case-sensitive — so position reads must `lower()`-normalize the mapping. See [ClickHouse query patterns](clickhouse-query-patterns.md).
- **24h staleness cutoff:** positions older than 24h are discarded so a stale dot can't "teleport" the driver.
- **Graceful degradation:** if a `sc_positions` read fails, the endpoints log a warning and return empty (no dot / keep current trail) rather than 500ing the map.

### ClickHouse query classes (`app/Clickhouse/Queries/`)

| Class | Purpose |
|-------|---------|
| `KitHistoricPositionsQuery` | One kit's historic GPS, decimated to ~1 point / 3s, looked up by `kit_mapping` |
| `OrgLivePositionsQuery` | Latest fix per kit across the whole org in one round-trip (`argMax`) |
| `OrgLivePathsQuery` | Org-wide live trails (decimated, segmentable) — drives Bird's Eye driver paths |
| `KitScanPathsQuery` | Today's scan paths from the `clarity.kit_scan_paths` materialized view (per-hour colors) |
| `HitDirectHotspotQuery` | Geohash-aggregated hit density for the honey-hole heatmap (this is the Scout heatmap; `HitDensityGeohashQuery` is the *non-Scout* repo heatmap used by `KitUserPathController`) |
| `HotlistAddressGeohashQuery` | Order (Direct + Staging) address density |
| `ScoutCheckInRollupQuery` | Direct/staging check-in roll-up counts |

Trails are segmented by `app/Support/Scout/HistoricPathBuilder.php` on hour boundaries and on a power-off gap (a break of more than ~500 ft), so the line doesn't draw a straight jump across a parked interval.

> **Full SQL for every query class** — SELECT/WHERE/GROUP BY, `FINAL`, org scoping, dedup, and the code-comment gotchas — lives in **[Scout ClickHouse queries](scout-clickhouse-queries.md)**. This page covers the runtime (polling, offline, broadcasts) that consumes them; [dashboard stats](scout-dashboard-stats.md) covers what the numbers mean.

### Geohash heatmaps (honey-hole & orders)

Both density layers tessellate points into **geohash cells** — `geohashEncode(lng, lat, precision)` grouped and counted. In production the precision is **forced to 7** by the controller: `resolveDebugPrecision()` returns `7` whenever the debug-map flag (`SCANNER_DEBUG_MAP_ENABLED`) is off, discarding any client-supplied value; the query-class `?? 6` fallback is never reached on the prod path. Deeper precision = smaller cells. Cells are **zone-clipped** on the driver map (via `Zone::intersectingGeohashes()` / `zone_ids`) or **run-area-clipped** on Bird's Eye (via `pointInPolygon` on the decoded point). The clip runs on `clarity_clickhouse`, which carries `validate_polygons=0` so a non-simple ring can't throw the query to empty.

- **Hit density (honey-hole) — `HitDirectHotspotQuery`.** Reads `clarity.hits_cold FINAL`, semi-joined to `clarity.hotlist FINAL WHERE status = Open` so only *currently-open* orders shade a cell (DEV-2117 — the hit row's own `hotlist_status` is frozen at write time and never back-updated). Repeat scans dedup to one per (hour × ~38 m geohash-8 cell), then re-aggregate at display precision. Tiers **1 / 2–4 / 5+** (green→yellow→red). *(Not to be confused with `HitDensityGeohashQuery` — the 5-tier ramp with a `PIN_LIMIT = 5000` pins mode — which is the separate **non-Scout** repo heatmap on `KitUserPathController`, scoped by `hotlist_org_id`.)*

- **Order density — `HotlistAddressGeohashQuery`.** `count(DISTINCT lat, lng)` of open-order addresses (`status = Open`, `length(plate) > 3`), **Direct vs Staging** split. Direct is scoped by `org_id` (purple ramp); Staging resolves the forwarder's `Org::stagingOrgIds()` **and** requires `is_staged = true` — without it the staging layer over-counts, since forwarder orgs don't stage every order (DEV-2080) — and uses a green ramp. Addresses come from the `zod` ClickHouse database via `a.id IN (subquery)` (never a JOIN); every precision computes the geohash dynamically from the raw rows — the precision-6 materialized view diverged and was retired.

## Driver-side polling

The driver PWA polls rather than streams positions. `composables/useKitPositionPolling.js` runs a **`POLL_MS = 10_000`** (10 s) loop built on **chained `setTimeout`** (not `setInterval`) with an `inFlight` guard, so a slow ClickHouse response can't stack overlapping requests — each tick fires only after the previous settles. It pauses on `visibilitychange` when the tab is hidden and does an immediate catch-up on return.

Per tick:
- **Always:** `fetchKitPosition()` (the live dot) + `fetchLivePath()` (the trail).
- **In Live ("today") mode:** also `fetchStats('today')` every tick, and `fetchRangeHits('today')` **every 12th tick** (`HITS_REFRESH_EVERY = 12`, ~2 min) to rebuild hit markers.

**Incremental live path:** `/api/scout/map/live-path` is cursor-based — the client sends `since` (the `timestamp_utc` of its last point) and receives only newer points. The cursor is `timestamp_utc` because `sc_positions` has no surrogate id; the first poll (no `since`) seeds from the start of the local day. The client-side stitching of those points onto the trail, the per-user kit-position `localStorage` cache (24 h TTL), and the Pinia stores that hold this state are detailed in [Scout frontend](scout-frontend.md); fetches use `AbortController` so a new request cancels the stale one.

## Offline detection

`resources/js/clarity-scout/composables/useConnectionStatus.js` combines **two** signals because Pusher's WebSocket state alone misses silent drops (the heartbeat can lag ~26s):

- `navigator.onLine` + the `online`/`offline` window events (immediate), **and**
- the Laravel Echo / Pusher connection state (`echoState`).

A **5-second grace window** suppresses brief blips; sustained offline flips `isOffline` and records `offlineSince`, surfacing the offline banner. Offline states tracked: `disconnected`, `unavailable`, `failed`.

The **service-worker offline cache** (three tiers, network-first `GET /api/scout/*` trimmed to 50 entries, and the `CLEAR_API_CACHE` PII purge on session transitions) is a distinct layer from this connection signal — it is documented in [Scout PWA & offline](scout-pwa-and-offline.md). Note the admin `/scout/org-map/api/*` responses are **not** SW-cached.

## Zone broadcasts (WebSocket)

Zone changes *are* pushed in real time, so a driver's assigned-zone list and toasts update without a refresh:

- Events: `app/Events/Scout/ZoneAssignmentsChanged.php` (a driver added to / removed from a zone) and `app/Events/Scout/ZoneUpdated.php` (a zone's geometry/color/name/no-scan changed). Both broadcast as `.zones.changed`.
- Channel: the **private** `clarity.scout.user.{userId}` channel (`routes/channels.php`). Authorization re-runs the Scout gate per subscription — it resolves the user via the **Sanctum** guard, allows admin force-login tokens (the `clarity-scout-force` ability), and otherwise requires a kit, the `clarity-scout` permission (after `setPermissionsTeamId`), and `Feature::active(CLARITY_SCOUT)`.
- Emitted by the zone services: `app/Services/Zones/ZoneAssignmentSyncer.php` (per added/removed user on assignment sync) and `app/Services/Zones/ZoneBroadcaster.php` (metadata change / delete / restore).
- Vue listener: `resources/js/clarity-scout/composables/useZoneRealtime.js` — refetches zones, persists a bell notification, and shows a toast. Broadcast auth goes through `POST /api/broadcasting/auth` (Sanctum); the Echo client is configured in `resources/js/clarity-scout/lib/echo.js`.

## Admin Bird's Eye fan-out

The admin org map reuses the same Vue app in `adminMode` (DEV-2116). `composables/useBirdsEyePolling.js` (self-gates on `adminMode`, `POLL_MS = 10_000`, live-mode + visible only) polls the mirrored admin endpoints — `driver-positions`, `driver-paths`, `drivers`, and `dashboard/stats` — so the same `map.js` store renders **every** driver's dot and trail at once. Live dots/trails come from the org-wide `OrgLivePositionsQuery` / `OrgLivePathsQuery` (one query each), and each driver's trail carries its **own** cursor so laggier drivers' points aren't starved. The full surface — endpoint mirror, `kit_ids` scope intersection, the Kit(zod)/KitSettings(platform) enrollment lookup, and every way it differs from the driver map — is in **[Scout Bird's Eye](scout-birds-eye.md)**.

Two recent dashboard-display changes affecting **both** surfaces:
- **DEV-2137:** the **Live Hits Pending Repo** list now renders on driver *and* admin (the old `!adminMode` hide was removed), date-scoped by the same `range` filter, with **Direct / Staging Live pills** derived from each hit's `hit_type` (`L` = Direct Live, `S` = Staging Live).
- **DEV-2138:** the **Repos stat tile is pulled** from `MapStatsBar.vue` / `ProfilePanel.vue` on **all** breakpoints and ranges (the `stats` payload still returns `repos_affected`, so the backend metric is unaffected) until the direct/staging repo split is resolved.

## Caching note (impersonation)

Map/dashboard responses are cached, but when an InsightLPR staffer is impersonating (`admin_impersonator` session key) the TTL drops to ~3s (near-live) versus the normal 2–15 min per range, so staff see fresh data while debugging.

## Where it lives in code

- ClickHouse queries: `app/Clickhouse/Queries/` (see table above)
- Path segmentation / geohash: `app/Support/Scout/HistoricPathBuilder.php`, `app/Support/Scout/Geohash.php`
- Driver API: `app/Http/Controllers/Api/ClarityScoutMapController.php`, `ClarityScoutDashboardController.php`, `ClarityScoutZonesController.php`
- Admin API: `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`
- Broadcast events / channel: `app/Events/Scout/ZoneUpdated.php`, `app/Events/Scout/ZoneAssignmentsChanged.php`, `routes/channels.php`
- Vue: `resources/js/clarity-scout/stores/map.js`, `composables/useKitPositionPolling.js`, `useConnectionStatus.js`, `useZoneRealtime.js`, `useBirdsEyePolling.js`, `lib/echo.js`

## See also

- [Scout](scout.md) — feature hub, gating, the three surfaces
- [Scout ClickHouse queries](scout-clickhouse-queries.md) — the full SQL of every query class this runtime consumes
- [Scout Bird's Eye](scout-birds-eye.md) — the admin org-map surface driven by `useBirdsEyePolling`
- [Scout dashboard stats & map counting](scout-dashboard-stats.md) — how the stat tiles and heatmaps are counted
- [Scout API](scout-api.md) — the endpoints these composables call
- [Zone](zone.md) — the geometry/assignment the broadcasts reflect
- [ClickHouse integration](clickhouse-integration.md) / [ClickHouse query patterns](clickhouse-query-patterns.md) — `sc_positions`, `kit_mapping` casing, `FINAL`, caching
- [Data Architecture](data-architecture.md) — ClickHouse read side vs PostgreSQL
