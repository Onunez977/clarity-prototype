---
type: Pattern
title: Scout Frontend Architecture
description: How the Scout Vue PWA client is built — the driver and Bird's Eye entry points, the single query-param-driven page over one persistent map, the Pinia stores and composables, the deck.gl layer stack, and the fetch/adminScope libs.
resource: resources/js/clarity-scout/App.vue
tags: [scout, vue, pinia, pwa, frontend, deckgl]
timestamp: 2026-07-06T00:00:00Z
---

# Scout Frontend Architecture

[Scout](scout.md) ships a single Vue 3 SPA under `resources/js/clarity-scout/` that serves **both** the driver PWA and the admin Bird's Eye view — the same `App.vue`, stores, and components, differing only in an `adminMode` data layer (DEV-2116). This page is the map of that client: how it boots, routes, holds state, and draws the map. For the **data pipeline** those pieces consume (GPS → ClickHouse → polling/broadcast) see [real-time tracking](scout-real-time-tracking.md); for the **offline / service-worker** layer see [PWA & offline](scout-pwa-and-offline.md).

## Entry points & boot

Two Vite entries mount the *same* `App.vue` into `#clarity-scout-app`, chosen by the blade shell:

| Entry | Blade | Auth | Config flag |
|-------|-------|------|-------------|
| `main.js` | `resources/views/clarity-scout.blade.php` (`/scout`) | Sanctum bearer (driver) + Echo | — |
| `birdseye.js` | `resources/views/clarity-scout-birds-eye.blade.php` (`/scout/org-map`) | web session (admin), **no** driver token, **no** Echo | `birdsEye: true` |

Both create Pinia + the router and then run auth **before mount** so the router guard sees the session on the first navigation:

- `main.js`: if the blade injected `window.__CLARITY_SCOUT_FORCE_AUTH__` (an admin force-login — see [user assignment](scout-user-assignment.md)), it calls `auth.forceAuthenticate()` (which **purges the API cache** first — see [PWA & offline](scout-pwa-and-offline.md)); otherwise it rehydrates `scanner_token` from `localStorage` and calls `connectEcho(token)`.
- `birdseye.js`: calls `auth.enterAdminMode({ user })` — no token, no Echo.

The blade injects `window.__CLARITY_SCOUT_CONFIG__` (`googleMapsApiKey`, `debugMapEnabled`, and `birdsEye` on the admin shell). `lib/fetch.js` reads `birdsEye` **once** at module load to pick the API base URL and auth scheme — so the driver code path is byte-identical whether or not admin mode exists.

## Routing — one page, query-param screens

`router/index.js` is deliberately flat. There is **one authenticated screen**, `/scout` → `ScoutHome.vue`; every "page" (dashboard, trips, day, trip, help, profile, notifications) is **query-param state** layered on top of the single persistent map, reconciled by `ScoutHome` on each route change.

- `/scout/login` → `Login.vue` (public).
- `/scout` → `ScoutHome` (`requiresAuth`).
- `/scout/org-map` → `ScoutHome` (admin; **must precede the catch-all**, whose `requiresAuth` is satisfied by `auth.adminMode` rather than a driver token).
- Legacy multi-route URLs (`/scout/dashboard`, `/scout/trips/:date`, `/scout/help`, …) **redirect** into the `/scout?query` form for back-compat.
- `beforeEach` guard: unauthenticated → `login`; authenticated visiting `login` → `app`.

## The single persistent map

`App.vue` mounts one map instance for the whole session; overlays and panels stack on top of it rather than navigating away:

```
App.vue → MapShell.vue (mounts the map only once authenticated)
            → ScannerMap.vue (Google Maps + deck.gl overlay, the canvas)
          + DashboardOverlay / DayOverlay / TripOverlay (query-driven)
          + SidePanel-wrapped panels (Trips, Help, Profile, Notifications)
          + ScoutControlsWidget (desktop chrome) / MapStatsBar (bottom sheet)
          + OfflineBanner, Toast, End-Shift confirm
```

`useViewport` (a reactive `isCompact` below Tailwind `lg`/1024px) drives the desktop-rail-vs-mobile-bottom-sheet split; `useCollapsible` persists per-panel open/closed state (and enforces single-open accordion groups on compact).

## Pinia stores (`stores/`)

| Store | Owns |
|-------|------|
| `auth.js` | `scanner_token`/`scanner_user`/`scanner_kit` (localStorage), `isImpersonating`, `tripsEnabled`, `adminMode`; `login`/`logout`/`fetchUser`/`forceAuthenticate`/`enterAdminMode` |
| `dashboard.js` | Stats + recent-hits keyed by `range` (`today`/`last_7`/`last_15`/`last_30`/`last_60`); `setRange`, `prefetchAllRanges` (pooled) |
| `map.js` | The largest store — `kitPosition`, `liveTrail`/`liveCursor`, `scanPaths` by range, `rangeHits`, hit/order heatmaps, zones, layer toggles, follow-kit, and the **admin** fields (`driverPositions`, `driverTrailsByKit`, per-driver cursors, `followedDriverKitId`); trip-view state |
| `trips.js` | Trip history (infinite-scroll 7-day chunks), calendar filter, `tripDetail`/`dayDetail` |
| `notifications.js` | The bell list + `unreadCount`; `fetch`, `prepend` (realtime de-dupe), `markRead`, `markAllRead`. Backed by `scout.zone` `DatabaseNotification` rows — see [real-time tracking](scout-real-time-tracking.md) |

## Composables (`composables/`)

| Composable | Purpose |
|------------|---------|
| `useKitPositionPolling` | Driver 10s chained-`setTimeout` poll of `kit-position` + `live-path`; pauses on hidden tab, catch-up on return |
| `useBirdsEyePolling` | Admin analogue — polls `drivers`/`driver-positions`/`driver-paths` with per-driver cursors; no-ops for drivers |
| `useConnectionStatus` | Hybrid offline signal (`navigator.onLine` + Echo state, 5s grace) — see [real-time tracking](scout-real-time-tracking.md) |
| `useEchoStatus` | Derives the Echo connection bucket + a `showStale` flag (offline while in Live mode) |
| `useZoneRealtime` | Subscribes to `clarity.scout.user.{userId}`, refetches zones, prepends a bell notification, toasts |
| `useShift` | Module-scoped shift state; de-duped `shift/status` fetch, `endShift()` |
| `useInstallPrompt` / `useServiceWorkerUpdate` | PWA install & update lifecycle — see [PWA & offline](scout-pwa-and-offline.md) |
| `useViewport` / `useCollapsible` | Responsive breakpoint + persisted accordion state |
| `useCountUp` / `useToast` / `usePrefetch` | Stat-tile tween (respects reduced-motion), toast queue, idle-time range prefetch |

## Map rendering (`ScannerMap.vue`)

A **Google Maps** base with a **deck.gl** overlay. Roadmap (desaturated custom style) vs Hybrid satellite is toggleable, as is a full-color roadmap. Layers:

- **Kit position** — Google Maps marker with a vehicle-type SVG from `lib/vehicleIcons.js` (`scanning_car`/`repo_truck`/`flatbed`, from `VehicleTypeEnum`).
- **Live trail / scan paths** — deck.gl `PathLayer` (segmented; optional direction arrows).
- **Hit markers** — Google `MarkerClusterer`, split Direct vs Staging clusterers, tappable.
- **Hit / order heatmaps** — deck.gl `GeoJsonLayer` from the geohash-density endpoints (see [real-time tracking](scout-real-time-tracking.md)).
- **Zones** — deck.gl `GeoJsonLayer` (no-scan zones render gray); admin nameplates via `TextLayer`.
- **Driver markers/trails** (admin) — one marker + trail per kit, highlighted on hover/follow.

## `lib/` — the shared client plumbing

| Module | Role |
|--------|------|
| `fetch.js` | Picks `BASE_URL` (`/api/scout` driver vs `/scout/org-map/api` admin), builds `authHeaders()` (Bearer vs session+CSRF), `handleUnauthorized()` (401 driver / 403 admin → clear + redirect + `clearApiCache`), and `buildUrl()` |
| `adminScope.js` | App-wide `selectedKitIds` / `followedKitId` refs; `buildUrl()` auto-appends `kit_ids[]` to every admin request **except** `/map/heatmap/*` (org-wide, address-based). Stats scope to the followed driver when one is highlighted |
| `echo.js` | Laravel Echo / Pusher client — `connectEcho(token)` / `disconnectEcho()` (driver only) |
| `track.js` / `analyticsEvents.js` | Fire-and-forget `POST /api/scout/track`; no-ops without a token / in admin mode — see [analytics taxonomy](scout-analytics-taxonomy.md) |
| `scoutNav.js` / `formatters.js` / `pooled.js` / `vehicleIcons.js` | Query-param navigation helpers, display formatters, a 2-at-a-time promise pool (range prefetch), vehicle marker SVGs |

## Client-side live-trail merge

The server sends pre-segmented path pieces; the client stitches incremental poll results in `map.js`:

- `SEGMENT_BREAK_METERS = 500 * 0.3048` (500 ft) — **matches** `HistoricPathBuilder`'s server-side break so client and server segment identically (see [real-time tracking](scout-real-time-tracking.md)).
- Tail↔head within ~10 m → the boundary point is de-duped (the server's `since` cursor is inclusive); within 500 ft → continue the segment; beyond → start a new one (a parked/power-off gap becomes a visible break, not a straight jump).

## Local persistence (browser)

| Key | Purpose |
|-----|---------|
| `scanner_token` / `scanner_user` / `scanner_kit` | Driver session (cleared on logout / 401) |
| `clarity-scout-kit-position:{userId}` | Last dot, **24h TTL** (`KIT_POSITION_MAX_AGE_MS`), per user — repaints the map instantly on reload; cleared on logout |
| `scanner_install_dismissed` | Suppresses the install prompt after dismissal |
| collapsible keys | Per-panel open/closed choice |

## Where it lives in code

- Entries / shells: `resources/js/clarity-scout/main.js`, `birdseye.js`, `App.vue`, `router/index.js`, `views/{Login,ScoutHome}.vue`, `resources/views/clarity-scout*.blade.php`
- Stores / composables / components / lib: `resources/js/clarity-scout/{stores,composables,components,lib}/`
- Map: `resources/js/clarity-scout/components/{MapShell,ScannerMap,LiveTracking}.vue`

## See also

- [Scout](scout.md) — feature hub, the three surfaces, gating
- [Scout real-time tracking](scout-real-time-tracking.md) — the data pipeline the stores/composables poll; offline detection; zone broadcasts; Bird's Eye fan-out
- [Scout Bird's Eye](scout-birds-eye.md) — the admin backend surface this `adminMode` client drives
- [Scout PWA & offline](scout-pwa-and-offline.md) — service worker, install/update, PII purge
- [Scout API](scout-api.md) — the endpoints `lib/fetch.js` calls
- [Scout analytics taxonomy](scout-analytics-taxonomy.md) — `lib/track.js` naming
