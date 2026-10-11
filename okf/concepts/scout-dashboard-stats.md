---
type: Pattern
title: Scout Dashboard Stats & Map Counting
description: How every Scout number is computed — dashboard stat tiles (scans / direct / staging / repos / trips), the hit and order-density heatmaps, and check-in counts. Covers dedup semantics, org scoping (kit_org_id vs hotlist_org_id), the forced production geohash precision, current-vs-frozen hotlist status, and the cache/TTL ladder.
resource: app/Http/Controllers/Api/ClarityScoutDashboardController.php
tags: [scout, clickhouse, stats, geo, counting]
timestamp: 2026-08-19T12:30:00Z
---

# Scout Dashboard Stats & Map Counting

This page is the source of truth for **how each Scout number is produced** — the questions that come up as "what are we counting, and is it right?" Every stat is computed in **ClickHouse** (read side); writes and truth live in PostgreSQL. Read [Data Architecture](data-architecture.md) before editing any query here. Companion pages: [real-time tracking](scout-real-time-tracking.md) (the live GPS/path side), [Scout API](scout-api.md) (the endpoints), [Hit](hit.md), [Hotlist](hotlist.md).

## Org scoping — two different "org" columns

The single most common source of a wrong count. Pick the column by the question being asked:

- **`kit_org_id`** — "what did *my drivers* do?" Used for the driver stat tiles (scans, direct/staging hits, repos), scan paths, and shift counts. This is the **scanning kit's** org.
- **`hotlist_org_id`** — "where are *my orders* being hit / are located?" Used for the hit-density and order-density heatmaps. This is the **order-owning** org. A hit on your order produced by a forwarding partner's kit still belongs on your heatmap.

Using `forOrg()` (which scopes by `hotlist_org_id`) on the driver stat tiles would silently drop Staging Live hits — the driver tiles scope by `kit_org_id` via `forKitOrg()`.

## Dashboard stat tiles

Endpoint `GET /api/scout/dashboard/stats?range=…` → `ClarityScoutDashboardController::stats()`. Ranges: `today` / `last_7` / `last_15` / `last_30` / `last_60`. All five tiles are computed in ClickHouse (`clarity.*`).

| Tile | Source table (ClickHouse `clarity`) | How it's counted |
|------|--------------------------------------|------------------|
| **Scans** | `scans_hot` / `scans_cold` | `Scan::forWindow($start)->final()->forOrg()->forKit()->between()->count()`. `forWindow()` reads `scans_hot` when the window starts within **90 days**, else `scans_cold`. `FINAL` collapses CDC duplicate row versions. |
| **Direct hits** | `hits_hot` / `hits_cold` | `uniqExactIf((ocr, toStartOfHour(hit_created_at)), hit_type = 'L')` — one per **(plate, hour)**. |
| **Staging hits** | same | `uniqExactIf((ocr, toStartOfHour(hit_created_at)), hit_type = 'S')` — one per **(plate, hour)**. |
| **Repos affected** | same | `uniqExactIf(hotlist_id, hotlist_status = Repossessed AND hit_type = 'L')` — distinct repossessed **orders**, Live only, **not** (plate,hour)-deduped. |
| **Trips** | `kit_trips` | `KitTrip::forOrg()->forKit()->betweenDates()->count()`, **or `0`** when `CLARITY_SCOUT_TRIPS` is off (the query is skipped entirely). |

All three hit aggregates run in one `selectRaw` on a query filtered `->final()->forKitOrg()->forHitTypes([Live, Staging_Live])->forCreatedBetween()->validDisposition()` (`validDisposition()` = disposition NULL or Valid). Source: `ClarityScoutDashboardController.php:59-90`.

### Why the counts are lower than raw rows (intentional)

- **(plate, hour) dedup on hits.** A car parked outside an office and re-scanned on every pass counts **once per hour**, not once per scan (DEV-2077/2053). This applies to Direct and Staging tiles.
- **`repos_affected` is deliberately un-deduped by (plate, hour).** It counts distinct repossessed **orders** (`hotlist_id`), Live hits only, staging excluded — the question is "how many different accounts you hit have since been marked repossessed," so it must not collapse by plate/hour. This is the one tile whose dedup rule differs, and the one most often mis-stated as "unique plates." It is **orders**, not plates. **UI note:** as of **DEV-2138** the Repos tile is currently **pulled from the UI** (`MapStatsBar.vue` / `ProfilePanel.vue`, all breakpoints) until the direct/staging repo split is resolved — the backend still computes and returns `repos_affected`, so the metric is intact, just not surfaced.
- **`FINAL` on scans.** Without it, CDC keeps every row version and the scan count over-counts (~5%). The dashboard scan count applies `final()`.

### Cache / TTL ladder

`Cache::remember` keyed by org + kit + range. TTL ladder: `today` = **10s**, `last_15` = 3m, `last_30` = 5m, `last_60` = **15m**, default = 2m. Under staff impersonation (`admin_impersonator` session key) every Scout cache collapses to **~3s** so debugging shows near-live data. Recent-hits list caches 90s normally / 3s impersonating.

## Current vs frozen hotlist status (critical)

A hit row carries a **denormalized `hotlist_status` column that is frozen at hit-write time and never back-updated**. If an order is repossessed or closed *after* its hit was written, that hit's `hotlist_status` still reads its old value. Surfaces therefore differ in whether they trust the frozen column or re-resolve current status via a semi-join to the live hotlist:

| Surface | Status source | Notes |
|---------|---------------|-------|
| Map hit markers (`/map/hits`) | **Current** — semi-join `clarity.hotlist FINAL` open lookup | Fixed in DEV-2113 |
| Hit density heatmap | **Current** — semi-join `clarity.hotlist FINAL WHERE status = Open` | `HitDirectHotspotQuery` |
| Order density heatmap | **Current** — semi-join `clarity.hotlist FINAL WHERE status = Open` | `HotlistAddressGeohashQuery` |
| Check-in counts | **Book snapshot** — `clarity.scout_open_addresses` (Open + plate-length already applied by the chlocal MV). Not a live `hotlist FINAL` join. | `ScoutCheckInRollupQuery` |
| **Recent-hits list** (`/dashboard/recent-hits`) | **Frozen** — reads the hit row's `hotlist_status` via `forStatus(Open)` | ⚠ Known gap tracked as **DEV-2117**; markers were the DEV-2113 fix, the list still uses the frozen column |
| Stat tiles (`repos_affected`) | **Frozen** — `hotlist_status = Repossessed` on the hit row | By design: counts hits whose order *was repossessed*, read from the frozen column |

## Heatmaps

Both heatmap endpoints throttle 120/min and return GeoJSON. Geohash cells are computed live — there is **no** precomputed density column (see below).

### Geohash precision — forced to 7 in production

`resolveDebugPrecision()` (in `ClarityScoutMapController` and the shared `ScoutMapHelpers` trait) returns a **hard-coded `7`** whenever `config('app.scanner_debug_map_enabled')` (env `SCANNER_DEBUG_MAP_ENABLED`, default `false`) is not exactly `true`. So in production the visible cells are **always precision 7**, regardless of any `precision` query param the client sends. Only with the debug flag on does a caller-supplied `precision` (validated 6–9) take effect.

- The `?? 6` fallback inside `HitDirectHotspotQuery` / `HotlistAddressGeohashQuery` (`sanitizePrecision`, clamps 6–9) is the query-class default and is **never reached** on the prod path — the controller always hands the query a `7`.
- Separately, `HitDirectHotspotQuery` uses an internal `DEDUP_PRECISION = 8` (~38m) to collapse repeat scans *before* the visible cells are aggregated at 7. That `8` is a dedup bucket, not the display precision.
- Higher precision = smaller cells. If we ever want denser/looser boxes, the lever is that one gate, not the counting.

### Hit density heatmap — `HitDirectHotspotQuery`

Reads `clarity.hits_cold FINAL`, semi-joined to `clarity.hotlist FINAL WHERE status = Open` (current status). Filters: `hit_type = 'L'`, `hit_created_at >=` **9 months** ago, `lat/lng != 0`, disposition NULL-or-Valid. Repeat scans dedup to one per (hour × ~38m geohash-8 cell) so a parked car can't inflate a hotspot; the survivors are re-aggregated at display precision 7. Tiers: 1 / 2–4 / 5+ (green→red). Scoped by `hotlist_org_id`. Source: `app/Clickhouse/Queries/HitDirectHotspotQuery.php`.

### Order (address) density heatmap — `HotlistAddressGeohashQuery`

The "how many open orders are near here" layer, Direct + Staging. Crosses ClickHouse databases: addresses in **`zod.public_address`** + **`zod.public_hotlist_address`**, joined to **`clarity.hotlist FINAL`**.

- **The cell number is a live `count(DISTINCT lat, lng)`** computed in the query, grouped by a geohash encoded at query time — **not** read from a precomputed "address_count" column. A precision-6 materialized view used to serve this but **diverged from the raw path on status/plate/(0,0) filtering, so it was retired**; every precision now computes from the raw CDC rows (one source of truth). "Is the 4 from your code or a table column?" → our code, live.
- **The only summing** is the Direct+Staging union: the two per-layer counts are added together in PHP for the blended tier. Each layer's number is itself a live distinct-count — no row-summing.
- **Bad-address dedup + completeness filters:** `geo` non-empty/non-null, `house_number` and `road` both present, hotlist `status = Open` and `length(plate) > 3`; rows where coordinate extraction failed are dropped. `count(DISTINCT lat, lng)` collapses co-located/duplicate addresses so the same address isn't counted twice.
- **Staging layer** adds `is_staged = true` and scopes to approved provider orgs (`Org::stagingOrgIds()`); the direct layer needs no `is_staged` filter because `org_id` already scopes it.
- **EWKT geo** is parsed with a re2 `extract()` + `toFloat64OrNull` (not `readWKTPoint`, which throws on one malformed row and blanks the whole layer). Addresses resolve via `a.id IN (subquery)` — **never a JOIN** (a JOIN hashes the 100M+ `public_address` table and times out; `IN` rides the id primary key). Optional run-area clip uses `pointInPolygon` (requires `validate_polygons=0` on the connection).

Source: `app/Clickhouse/Queries/HotlistAddressGeohashQuery.php`.

## Check-in counts — `ScoutCheckInRollupQuery`

Endpoint `/dashboard/check-in-counts` (present but not surfaced by the current PWA). Reads **`clarity.scout_open_addresses`** (the 15-minute full-refresh book from chlocal migration 111 / DEV-2383) ⋈ `clarity.scans_hot|scans_cold FINAL`. Coordinates are `tupleElement(geo, 1|2)` — there is no EWKT parse and no `zod.public_address` / `zod.public_hotlist_address` join on this path (those tables remain the heatmap's source). Scans within **`geoDistance <= 160.934` m** (0.1 mi) of a book address, bbox-prefiltered via a **CROSS JOIN** of the scan-window min/max, deduped to **one check-in per (address, hour)**, then split `countIf(is_staged = false)` (direct) vs `countIf(is_staged = true)` (staging). A shared address on both a Direct and a Staging open order is counted in **both** buckets. Same 90-day hot/cold scan split. Open-status and `length(plate) > 3` live in the MV, not in this class — a book row still counts even if the live hotlist has since closed. There is **no** freshness gate on the tile or the recorder; a stalled book here silently rolls up to `{0,0}` and caches. (**Not** `clarity.recovery_address`.) Source: `app/Clickhouse/Queries/ScoutCheckInRollupQuery.php`.

> This tile computes counts **on read from scans**. It is distinct from the backend **check-in recorder** (DEV-2313), which persists check-ins from **GPS `sc_positions`** into `platform.scout_check_ins`. Same address enumeration and 160.934 m radius, different source table and storage — see [Scout Automated Check-in Recorder](scout-check-in-recorder.md) (mechanism) and [Check-in](check-in.md) (the canonical definition of the event).

## Hotlist stats (open/staged order counts)

Endpoint `/dashboard/hotlist-stats` reads **PostgreSQL** (`App\Models\Hotlist`, `zod` connection, `public` schema): `Hotlist::forOrg()->open()->whereRaw('length(plate) > 3')->count()` plus a staged count joined to `approval_list`. This is the only dashboard surface that counts in Postgres rather than ClickHouse. The route exists but the current frontend does not call it.

## Time windows quick reference

| Window | Where | Why |
|--------|-------|-----|
| 90-day hot/cold scan split | scan count, check-ins, shift summary | `scans_hot` holds the recent partition for cheap reads |
| 9-month hit cutoff | hit-density heatmap | relevance window for repossession intel |
| 24h live-position cutoff | live dot / paths | a dormant kit's dot can't teleport to a days-old fix |
| 90-day trips retention | `kit_trips` TTL + browse cap | the browse span cap mirrors the ClickHouse TTL |

## See also

- [Scout](scout.md) — feature hub, the three surfaces, gating
- [Scout ClickHouse queries](scout-clickhouse-queries.md) — the full SQL behind these numbers
- [Scout real-time tracking](scout-real-time-tracking.md) — the live GPS / path / query-class side
- [Scout API](scout-api.md) — endpoint catalog and throttles
- [Hit](hit.md) — how hits are created (exact-plate `find_hits()` triggers), `hit_type` L/S/H/Z
- [Hotlist](hotlist.md) — order status, `is_staged`, `status = Open`
- [ClickHouse query patterns](clickhouse-query-patterns.md) — `FINAL`, `kit_mapping` casing, EWKT parsing, caching
- [Data Architecture](data-architecture.md) — ClickHouse read side vs PostgreSQL
