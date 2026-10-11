---
type: Registry
title: Scout API
description: The HTTP surface for Scout — the driver PWA's /api/scout/* endpoints, the admin Bird's Eye /scout/org-map/api/*, auth/SPA web routes, broadcast auth, and the non-prod QA workflow endpoints.
resource: routes/api.php
tags: [scout, api, routes, sanctum, registry]
timestamp: 2026-06-30T12:00:00Z
---

# Scout API

The catalog of HTTP endpoints behind [Scout](scout.md). The driver PWA talks to JSON `/api/scout/*` endpoints (Sanctum bearer token); the admin Bird's Eye view talks to `/scout/org-map/api/*` (web session); SPA shells and auth flows are plain web routes. **Every Scout route is wrapped in `if (config('app.experimental.clarity_scout', false))`** — when the experimental flag is off, none of them register.

> Which endpoints emit analytics, the canonical `clarity-scout-*` event/page names, and the PWA's `/api/scout/track` endpoint are documented in [scout-analytics-taxonomy.md](scout-analytics-taxonomy.md).

## Driver API — `/api/scout/*`

Defined in `routes/api.php`. `login` is public (throttled `5,1`); everything else is `auth:sanctum` + `ClarityScoutMiddleware` (kit + `clarity-scout` permission + feature). Controllers are under `app/Http/Controllers/Api/`.

| Method | Path | Controller@method | Returns |
|--------|------|-------------------|---------|
| POST | `/api/scout/login` | `ClarityScoutAuthController@login` | token, user, kit, `is_impersonating`, `trips_enabled` |
| POST | `/api/scout/logout` | `ClarityScoutAuthController@logout` | — |
| GET | `/api/scout/me` | `ClarityScoutAuthController@me` | current user/kit context |
| GET | `/api/scout/dashboard/stats` | `ClarityScoutDashboardController@stats` | scans / direct hits / staging hits / repos / trips tiles |
| GET | `/api/scout/dashboard/recent-hits` | `ClarityScoutDashboardController@recentHits` | recent hit list |
| GET | `/api/scout/dashboard/hotlist-stats` | `ClarityScoutDashboardController@hotlistStats` | hotlist counts |
| GET | `/api/scout/dashboard/check-in-counts` | `ClarityScoutDashboardController@checkInCounts` | per-zone check-in roll-up |
| GET | `/api/scout/map/scan-paths` | `ClarityScoutMapController@scanPaths` | scan tracks for a range (`today`/`last_7`/`last_15`/`last_30`/`last_60`) |
| GET | `/api/scout/map/hits` | `ClarityScoutMapController@hits` | up to ~100 Live+Staging hit markers (+ capped flag) |
| GET | `/api/scout/map/heatmap/hits` | `ClarityScoutMapController@heatmapHits` | GeoJSON FeatureCollection (geohash density; zone-clip via `zone_ids`) |
| GET | `/api/scout/map/heatmap/orders` | `ClarityScoutMapController@heatmapOrders` | Direct+Staging order density (`layers` param) |
| GET | `/api/scout/map/kit-position` | `ClarityScoutMapController@kitPosition` | latest dot: lat/lng, `scanned_at`, `minutes_ago`, vehicle type, heading |
| GET | `/api/scout/map/live-path` | `ClarityScoutMapController@livePath` | incremental trail segments + cursor (`since` param) |
| GET | `/api/scout/map/zones` | `ClarityScoutZonesController@index` | assigned zones as GeoJSON features |
| GET | `/api/scout/trips/current` | `ClarityScoutTripsController@current` | today's active trip |
| GET | `/api/scout/trips/browse` | `ClarityScoutTripsController@browse` | paginated trip list |
| GET | `/api/scout/trips/day/{date}` | `ClarityScoutTripsController@day` | trips for a date (`YYYY-MM-DD`) |
| GET | `/api/scout/trips/{date}/{tripIndex}` | `ClarityScoutTripsController@show` | a single trip |
| GET | `/api/scout/shift/status` | `ClarityScoutShiftController@status` | current shift status |
| POST | `/api/scout/shift/end` | `ClarityScoutShiftController@end` | ends shift, returns summary (throttle `10,1`) |
| GET | `/api/scout/notifications` | `ClarityScoutNotificationsController@index` | bell notifications |
| POST | `/api/scout/notifications/read-all` | `ClarityScoutNotificationsController@markAllRead` | — |
| POST | `/api/scout/notifications/{notification}/read` | `ClarityScoutNotificationsController@markRead` | — |

**Throttling:** the ClickHouse-backed map endpoints (`map/scan-paths`, `map/hits`, `map/heatmap/*`, `map/kit-position`, `map/live-path`) sit inside a `throttle:120,1` group — `kit-position`/`live-path` are uncached and polled, and 120/min clears the ~6/min poll.

**Trips** endpoints are additionally gated by `CLARITY_SCOUT_TRIPS` (see [Scout shifts & trips](scout-shifts-and-trips.md)).

## Broadcast auth — `/api/broadcasting/auth`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| POST | `/api/broadcasting/auth` | `Illuminate\Broadcasting\BroadcastController@authenticate` | `auth:sanctum`. Registered under `/api` (not `withBroadcasting()`) so the web catch-all can't swallow it; authorizes the `clarity.scout.user.{userId}` private channel. See [Scout real-time tracking](scout-real-time-tracking.md). |

## Admin Bird's Eye — `/scout/org-map/api/*`

Defined in `routes/web.php`, behind `auth` (web session) + `ClarityScoutAdminMiddleware`. These mirror the driver `/api/scout/*` paths and response shapes so the real Pinia stores consume them unchanged (DEV-2116). All map to `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`, except `scan/disposition`, which has its own `ClarityScoutBirdsEyeDispositionController` (DEV-2235).

| Method | Path | Method | Returns |
|--------|------|--------|---------|
| GET | `/scout/org-map/api/drivers` | `drivers` | org drivers list |
| GET | `/scout/org-map/api/map/scan-paths` | `scanPaths` | org-wide scan paths |
| GET | `/scout/org-map/api/map/hits` | `hits` | org-wide hits |
| GET | `/scout/org-map/api/map/heatmap/hits` | `heatmapHits` | org-wide hit heatmap |
| GET | `/scout/org-map/api/map/heatmap/orders` | `heatmapOrders` | org-wide order heatmap |
| GET | `/scout/org-map/api/map/zones` | `zones` | org zones |
| GET | `/scout/org-map/api/map/driver-positions` | `driverPositions` | every driver's latest dot |
| GET | `/scout/org-map/api/map/driver-paths` | `driverPaths` | every driver's live trail |
| GET | `/scout/org-map/api/dashboard/stats` | `stats` | org dashboard stats |
| GET | `/scout/org-map/api/dashboard/recent-hits` | `recentHits` | org recent hits |
| POST | `/scout/org-map/api/scan/disposition` | `ClarityScoutBirdsEyeDispositionController` | org-scoped disposition write (`throttle:30,1`) |
| GET | `/scout/org-map/{any?}` | `view('clarity-scout-birds-eye')` | Bird's Eye SPA shell (Vue Router owns the rest) |

## Auth & SPA shells (web)

Defined in `routes/web.php`.

| Method | Path | Handler | Middleware |
|--------|------|---------|------------|
| GET | `/scout/force-login/{kit}` | `ClarityScoutForceLoginController` | `auth` — admin impersonation; mints a signed token (`clarity-scout-force`) and opens the driver PWA as that driver |
| GET | `/scout/set-password/{user}` | `ScoutSetPasswordController@show` | `ValidateScoutSignedUrl` — absolute or relative signature from the onboarding email |
| POST | `/scout/set-password/{user}` | `ScoutSetPasswordController@update` | `ValidateScoutSignedUrl` |
| GET | `/scout/{any?}` | `view('clarity-scout')` | driver PWA SPA catch-all |

## QA workflow (non-production only) — `/api/v1/qa/workflow/*`

Defined in `routes/api.php`, registered only when `config('app.env') !== 'production'`, behind `TestingWorkflowMiddleware` (Black Adam signed URL). Used to inject Scout test data on local/staging — see the QA pipeline skills.

| Method | Path | Controller@method |
|--------|------|-------------------|
| POST | `/api/v1/qa/workflow/scans/create` | `Api\Qa\ScanController@create` |
| POST | `/api/v1/qa/workflow/hotlists/create` | `Api\Qa\HotlistController@create` |
| POST | `/api/v1/qa/workflow/hits/create` | `Api\Qa\HitController@create` (fires `HitEvent`) |

## Where it lives in code

- Route definitions: `routes/api.php`, `routes/web.php`, `routes/channels.php`
- Driver controllers: `app/Http/Controllers/Api/ClarityScout*Controller.php`
- Admin controller: `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`
- QA controllers: `app/Http/Controllers/Api/Qa/`
- Middleware: `app/Http/Middleware/ClarityScoutMiddleware.php`, `ClarityScoutAdminMiddleware.php`, `TestingWorkflowMiddleware.php`

## See also

- [Scout](scout.md) — gating and the three surfaces
- [Scout real-time tracking](scout-real-time-tracking.md) — what the `map/*` and broadcast endpoints feed
- [Scout shifts & trips](scout-shifts-and-trips.md) — `shift/*` and `trips/*`
- [Events & Observers](events-and-observers.md) — `HitEvent` fired by the QA hit endpoint
