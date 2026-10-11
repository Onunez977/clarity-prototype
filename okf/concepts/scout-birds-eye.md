---
type: Concept
title: Scout Bird's Eye (admin org map)
description: The admin org-wide Scout map — the same driver Vue app booted in adminMode, session-authed under ClarityScoutAdminMiddleware, fanning every org driver's live dot/trail (with a scan-derived fallback, DEV-2253) and org-wide stats/heatmaps. Covers the endpoint mirror, kit_ids scope intersection, the cross-connection kit-settings lookup, and how it differs from the driver map.
resource: app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php
tags: [scout, admin, clickhouse, vue, geo]
timestamp: 2026-08-04T00:00:00Z
---

# Scout Bird's Eye (admin org map)

**Bird's Eye** is the org admin's view of every driver at once — live dots, trails, org-wide stats and heatmaps. It is the **same driver Vue app** (`resources/js/clarity-scout/App.vue`) booted in `adminMode`, not a clone (DEV-2116), served at `scout/org-map/{any?}` → `view('clarity-scout-birds-eye')`. See [Scout](scout.md) for the three surfaces and [real-time tracking](scout-real-time-tracking.md) for the polling it drives.

## Access & routing

- **Routes** (`routes/web.php`, inside `if (config('app.experimental.clarity_scout'))`): the `scout/org-map` group under `['auth', ClarityScoutAdminMiddleware]`, registered **above** the driver catch-all `scout/{any?}` so `scout/org-map/*` never falls through to the driver SPA. The API lives under `scout/org-map/api/*` and deliberately **mirrors the driver `/api/scout/*` paths and response shapes** so the same Pinia stores render org-wide unchanged.
- **Gate** (`ClarityScoutAdminMiddleware`): `setPermissionsTeamId($user->org_id)`, then `(Feature CLARITY_SCOUT active OR session admin_impersonator) AND hasRole(ADMIN)`, else `abort(403)`. Same gate as `ScoutUsersResource`; distinct from the driver `ClarityScoutMiddleware` (Sanctum + assigned kit). This is one of Scout's [five gate points](scout.md#gate-points-keep-in-sync).
- **Session auth, no bearer:** admin requests are Filament web-session authenticated. `enterAdminMode()` sets no Sanctum token — so there is **no Echo/WebSocket, no trips, no shifts, no notifications, no client-side `track` polling** on this surface (those endpoints aren't in the admin route group; `lib/track.js` still no-ops in admin mode). The one exception is the server-side `disposition-saved` event, fired directly by `ClarityScoutBirdsEyeDispositionController` — see [Scout Analytics Taxonomy](scout-analytics-taxonomy.md) (DEV-2235). `isAuthenticated` is satisfied by `adminMode` alone.

## Endpoints (`ClarityScoutBirdsEyeController`)

| Endpoint | Returns | Backed by |
|----------|---------|-----------|
| `GET …/api/drivers` | `{data:[{kit_id, name, driver_name, vehicle_type}]}` — all the org's active kits, `driver_name`/`vehicle_type` null when unenrolled | `orgScoutKits()` |
| `GET …/api/map/driver-positions` | `{data:[{kit_id, …, lat, lng, heading, minutes_ago, source, position_source}]}` — latest live dot per in-scope driver | `OrgLivePositionsQuery`, falling back to `OrgScanPathPositionsQuery` for kits it didn't resolve (DEV-2253) |
| `GET …/api/map/driver-paths` | `{drivers:[{kit_id, segments, cursor}]}` — per-driver live trails, each with its **own** cursor | `OrgLivePathsQuery` + `segmentByMapping()` |
| `GET …/api/map/scan-paths` | `[{kit_id, color, name, path, trip_date, is_today}]` | `KitScanPathsQuery` |
| `GET …/api/map/hits` | `{data:[markers], capped}` (cap 100) | `ClickhouseHit` + `ClickhouseHotlist` open semi-join |
| `GET …/api/map/heatmap/hits` | GeoJSON hit density | `HitDirectHotspotQuery` |
| `GET …/api/map/heatmap/orders` | unioned GeoJSON order density | `HotlistAddressGeohashQuery` (via `ScoutMapHelpers`) |
| `GET …/api/map/zones` | `{assigned:[Feature…]}` — **all** org zones | `Zone::forOrg()->withGeoJson()` |
| `GET …/api/dashboard/stats` | `{data:{scans_today, direct_hits, staging_hits, repos_affected, trip_count}}` | `ClickhouseScan` + `ClickhouseHit` |
| `GET …/api/dashboard/recent-hits` | `{data:[…]}` — Live+Staging, deduped by plate, 20 rows | `ClickhouseHit` + `ClickhouseHotlist` |
| `POST …/api/scan/disposition` | `200`; sets `Scan::disposition` | `ClarityScoutBirdsEyeDispositionController` (its own controller, not `ClarityScoutBirdsEyeController`), org-scoped via `Scan::forOrg()`, `throttle:30,1` (DEV-2235) |

Constants: `MAX_MAP_HITS = 100`, `LIVE_CUTOFF_HOURS = 24` (mirrors `kitPosition`). Live dot heading is emitted only when `speed > 1` (no spin at rest). See [ClickHouse queries](scout-clickhouse-queries.md) for the `OrgLive*` SQL.

## Org scoping — the client can't widen past its org

Every method reads `$request->user()->org_id`. An optional `kit_ids[]` selector is **always intersected against the org's own Scout kits** via `scopedKits()`:

```php
$orgKits = $this->orgScoutKits((int) $request->user()->org_id);
$requested = $request->input('kit_ids');
if (! is_array($requested) || $requested === []) { return $orgKits; }   // none = all org kits
return $orgKits->filter(fn (Kit $kit) => in_array((int) $kit->id, array_map('intval', $requested), true))->values();
```

So a requested id outside the org is silently dropped; no `kit_ids` means all the org's kits.

### Cross-connection kit-settings lookup (the gotcha)

`Kit` lives on the `zod` connection while `KitSettings` lives on `platform`, so a `whereHas()`/join across the two resolves to nothing (cross-connection correlated subquery). `orgScoutKits()` therefore attaches settings with a **separate keyed `KitSettings` lookup filtered in PHP**, pre-setting the `settings`/`user` relations to avoid N+1 — settings are **optional**, not a filter:

```php
$kits = Kit::query()->forOrg($orgId)->excludingDeleted()->orderBy('name')->get();
$settings = KitSettings::query()->whereIn('kit_id', $kits->pluck('id')->all())->with('user')->get()->keyBy('kit_id');
return $kits
    ->each(fn (Kit $kit) => $kit->setRelation('settings', $settings->get($kit->id)))
    ->values();
```

Every non-deleted org kit is returned — a kit with **no** `kit_settings` row still appears, with `settings` set to `null`. **This is not what the page previously said:** an earlier version of `orgScoutKits()` filtered to only kits with a `kit_settings` row (`$kits->filter(fn (Kit $kit) => $settings->has($kit->id))`), i.e. "Scout enrollment" gated map/driver-list visibility. DEV-2182 removed that filter — `Kit::forOrg()->excludingDeleted()` is now the entire scope, proven by the passing test `test_drivers_lists_all_active_org_kits_regardless_of_scout_enrollment`. Do not reintroduce an enrollment gate here without checking that ticket.

`kitsByMapping()` then lowercases `kit_mapping` into an `array<string,Kit>` (the only key `sc_positions` carries) to join query rows back to kits; kits with a null/empty `kit_mapping` are skipped by that map (but see the scan-derived fallback below — it doesn't need one). See [Kit vs KitSettings](scout-user-assignment.md).

### Per-driver trail cursors

`driver-paths` gives **each driver its own cursor** (a `sinces` map), not one shared cursor: fleet GPS ingests at wildly different latencies, so a single cursor pinned to the freshest kit would freeze every laggier driver's trail while its dot kept moving. First poll seeds from the local day start; drivers with no new segments are left untouched.

### The live dot has a scan-derived fallback

A kit that scans all day but never reports a GPS fix to `clarity.sc_positions` used to vanish entirely from `driver-positions` while still rendering fine on its own driver map (`kitPosition()` already degraded there) — the reported symptom of DEV-2253. `driverPositions()` now mirrors that degrade org-wide: `OrgLivePositionsQuery` first, then `OrgScanPathPositionsQuery` for whichever scoped kits it didn't resolve, both bounded by the **same** `LIVE_CUTOFF_HOURS` cutoff, built in **UTC** (`now()->utc()->subHours(...)` — both tiers' `defaultCutoff()` compare against a UTC column regardless of `APP_TIMEZONE`, DEV-2164), so the two tiers can't diverge. Tier 1 always wins when a kit has both; the "missing" set is computed from the **full** scoped `Kit` collection, not `kitsByMapping()`'s keys, so a kit with a null/empty `kit_mapping` — invisible to tier 1 by construction — is still rescuable via tier 2, which is keyed by `kit_id` (see [ClickHouse queries](scout-clickhouse-queries.md#orgscanpathpositionsquery--birds-eye-dot-fallback-dev-2253) for why the two tiers use different keys). Tier 2 also carries its own zero-geo guard on the aggregated point, matching tier 1's discard of a `(0,0)` fix — see [ClickHouse queries](scout-clickhouse-queries.md#orgscanpathpositionsquery--birds-eye-dot-fallback-dev-2253) for why `sc_positions`'s "0 rows" measurement doesn't cover `kit_scan_paths`.

**The controller's `try`/`catch` around each tier is *written* to short-circuit on a thrown tier-1 read (skip tier 2 entirely) and to degrade a thrown tier-2 read to tier-1-only — but in production this does NOT behave like `kitPosition()`'s equivalent short-circuit, and the two surfaces do not actually share a not-on-throw rule.** `kitPosition()`'s tier-1 read is an inline `DB::clickhouse()` call, so a real connection failure reaches its `catch`. `driverPositions()`'s tier-1 read goes through `OrgLivePositionsQuery::query()`, a `ClickhouseQuery` subclass whose own `catch` swallows any `\Throwable` to `[]` in `production`/`testing` (see [ClickHouse queries](scout-clickhouse-queries.md#base-class--appclickhousequeriesclickhousequeryphp)) *before* the controller ever sees it — so in production a cluster outage makes tier 1 return `[]` "successfully", every scoped kit becomes "missing", and tier 2 runs anyway (and, on the same outage, also fails — two connect-timeout waits and two `report()` calls per poll, not one, as `kitPosition()`'s design intends to avoid). The controller's tier-1 short-circuit is real code, but reachable only outside `production`/`testing` (e.g. `local`) or on an error raised outside `ClickhouseQuery::query()`. Tests `test_driver_positions_tier_one_throw_short_circuits_before_tier_two` and `test_driver_positions_tier_two_throw_keeps_tier_one_dots` (`ClarityScoutBirdsEyeControllerTest`) pin both branches under `config(['app.env' => 'local'])`.

Each entry now carries `position_source: 'gps' | 'scan_path'` alongside the pre-existing `source` field (`source` still means scanner-hardware vs. browser-GPS, unrelated and unchanged). The client (`App.vue`'s DEV-2197 no-GPS banner, `ScannerMap.vue`'s marker rendering) reads `position_source` to keep the two provenances visually and semantically distinct — a scan-derived dot is dimmed and tooltipped as "last scan position, no live GPS" and never carries a `heading`. **Not extended to trails**: `driverPaths()`/`OrgLivePathsQuery` and the single-driver `livePath()`/`KitHistoricPositionsQuery` are unchanged and still `sc_positions`-only — same idiom, deliberately deferred as DEV-2228, so a rescued dot can appear with no trail under it. No `kit_trips` tier-3 either (0 measured rescues in production; `kit_trips` derives from `sc_positions` so it's empty exactly when tier 1 is).

## How Bird's Eye differs from the driver map

| Aspect | Driver map | Bird's Eye |
|--------|-----------|------------|
| Live dot/trail | one kit (`kit-position` / `live-path`) | all drivers (`driver-positions` / `driver-paths`, `OrgLive*` queries) |
| `kit_ids[]` scope | n/a | auto-appended to all requests **except heatmaps**; stats scope to the followed driver, else the selection |
| Order heatmap clip | driver's **assigned zones** | org **run area** (`org_run_areas`); no run area → viewport bbox |
| Hit heatmap | zone-clipped | **org-wide, selector-independent** (`kit_ids` not honored — density is address-based, no kit dimension) |
| Zones | only the driver's assigned zones | **all** org zones |
| `trip_count` | real (if flag on) | **always 0** (trips are driver-only) |
| Auth / realtime | Sanctum bearer + Echo | web session, **no Echo** |
| 401/403 handling | clears token → `/scout/login` | 403 → `/app/login` |

`hits`/`recentHits` scope by **`kit_org_id`** (via `forKitOrg()`) — Staging Live's `hotlist_org_id` differs from the kit's org, so `forOrg()` would drop them (DEV-2053) — and resolve **current** hotlist status via a `clarity.hotlist FINAL` open semi-join (DEV-2113/2117), same as the driver map.

## Frontend adminMode

The admin shell (`birdseye.js`) mounts the driver `App.vue` after `enterAdminMode()` — `adminMode = true`, no token, no Echo, `tripsEnabled = false` — signaled by `window.__CLARITY_SCOUT_CONFIG__.birdsEye`. `lib/fetch.js` swaps the API base to `/scout/org-map/api` (session cookie + `X-CSRF-TOKEN`, no bearer) and auto-appends `kit_ids[]` to every request except heatmaps (stats scoping to `followedKitId`, else `selectedKitIds`); a 403 redirects to `/app/login`. The full client boot, Pinia stores, `adminScope` refs, and deck.gl layers are in [Scout frontend](scout-frontend.md); the admin polling loop (`useBirdsEyePolling`) in [real-time tracking](scout-real-time-tracking.md).

## Where it lives in code

- Controller: `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`, plus `ClarityScoutBirdsEyeDispositionController.php` for `scan/disposition`
- Middleware / routes: `app/Http/Middleware/ClarityScoutAdminMiddleware.php`, `routes/web.php` (`scout/org-map` group)
- Shared map helpers: `app/Http/Controllers/Api/Concerns/ScoutMapHelpers.php`
- Frontend: `resources/js/clarity-scout/birdseye.js`, `lib/fetch.js`, `lib/adminScope.js`, `composables/useBirdsEyePolling.js`
- Queries: `OrgLivePositionsQuery`, `OrgScanPathPositionsQuery`, `OrgLivePathsQuery`, `KitScanPathsQuery`, `HitDirectHotspotQuery`, `HotlistAddressGeohashQuery` — see [ClickHouse queries](scout-clickhouse-queries.md)

## See also

- [Scout](scout.md) — the three surfaces and gating
- [Scout real-time tracking](scout-real-time-tracking.md) — polling, offline, `HistoricPathBuilder`
- [Scout ClickHouse queries](scout-clickhouse-queries.md) — the `OrgLive*` and heatmap SQL
- [Scout dashboard stats & map counting](scout-dashboard-stats.md) — how the org-wide stats/heatmaps are counted
- [Scout user assignment](scout-user-assignment.md) — Kit (zod) vs KitSettings (platform) cross-connection
