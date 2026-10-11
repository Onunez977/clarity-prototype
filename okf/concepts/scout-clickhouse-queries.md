---
type: Pattern
title: Scout ClickHouse Queries (full reference)
description: The actual SQL behind every Scout ClickHouse query class (plus the non-Scout readers Kit User Path uses — scan-path and geohash only as of DEV-2451; it no longer shares a GPS reader) — tables and database, FINAL usage, the exact SELECT/WHERE/GROUP BY shape, org scoping, dedup/geohash bucketing, CH-side route segmentation, and the load-bearing code-comment gotchas. Companion to scout-dashboard-stats (what the numbers mean) — this page is the query mechanics.
resource: app/Clickhouse/Queries/ClickhouseQuery.php
tags: [scout, clickhouse, queries, sql, geo]
timestamp: 2026-08-31T16:10:00Z
---

# Scout ClickHouse Queries (full reference)

The query-shape reference for Scout's read side. [Scout dashboard stats & map counting](scout-dashboard-stats.md) explains **what each number means and why** (dedup, precision, org scoping); this page lays out **the actual SQL** — tables, `FINAL`, filters, bucketing, and the gotchas baked into each class. Read [Data Architecture](data-architecture.md) and [ClickHouse query patterns](clickhouse-query-patterns.md) first.

All classes extend `ClickhouseQuery` and read the ClickHouse side. Nothing here writes. Org scoping columns differ by intent — see [dashboard stats](scout-dashboard-stats.md#org-scoping--two-different-org-columns).

## Base class — `app/Clickhouse/Queries/ClickhouseQuery.php`

The shared plumbing every class inherits (`::query($args, cache:)` wraps `handle()`):

- **Silent degrade to `[]`:** `query()` catches any `\Throwable`; in **`production` and `testing`** it `report($e)` and returns `[]` (an empty layer, never a 500); in `local`/other envs it **re-throws** so bugs surface in dev (`:29-37`). This is why a broken Scout query shows an empty map rather than an error.
- **Caching:** off by default (`$cache = false`). When on, default TTL is `now()->addMinutes(10)` (`:18`), overridable via a `cacheTTL` arg. Under staff impersonation (`admin_impersonator` session key) TTL collapses to **`now()->addSeconds(3)`** for near-live debugging (`:20-22`).
- **Cache key:** built from the args; keys named `cacheTTL` and any **underscore-prefixed key** are excluded (`:50-58`) — underscore keys carry payloads too big for a cache key (e.g. polygon rings); pair them with a sibling scalar (`run_area_version`) for invalidation.
- **`polygonLiteral(ring)`:** emits `[(lng,lat),…]` with each coord `(float)`-cast — an injection-safe literal for `whereRaw`, because the CH driver mishandles bound array literals (`:74-90`).
- **`clarityDb()`:** returns the clarity DB name for raw SQL — literal `clarity` under the pgsql bridge, else `config('database.connections.clarity_clickhouse.database')` (`clarity` in prod, `clarity_<wt>` under local-CH QA) (`:97-107`).

### The `DB::clickhouse()` macro — `app/Providers/AppServiceProvider.php:69-82`

`DB::clickhouse(string $table, string $connection = 'clarity', bool $final = false, ?string $as = null)`:

- `connection = 'zod'` maps to the `public_clickhouse` connection; else `{$connection}_clickhouse`.
- **`FINAL` is only appended when the driver is a real `clickhouse`** — under the pgsql test bridge the keyword is dropped, and clarity tables get a `clarity.` schema prefix.

## Position & path queries (raw GPS / polylines)

All read the **`clarity`** connection. `clarity.sc_positions` (raw scanner GPS, ReplacingMergeTree, keyed by `kit_mapping`) is read **without `FINAL`** — dedup is via `argMin`/`argMax`. `sc_positions` has **no `org_id`/`kit_id`** — scoping is purely `lower(kit_mapping)` (the caller resolves the org's mappings). ClickHouse `=`/`IN` are **case-sensitive** and platform lowercases `kit_mapping` while the scanner feed doesn't, so every read wraps both sides in `lower()` (DEV-2012).

> ⚠ **That `lower()` costs the primary key.** `sc_positions` is `ORDER BY (kit_mapping, timestamp_utc)` and the sort key is matched **literally**, so wrapping the prefix in a function means the index **cannot prune** — measured **3056 / 32732 granules vs 13 / 32732** for exact-case equality, i.e. ~600 ms per query instead of ~20 ms. The `lower()` is still *correct* (the casing genuinely differs); it is the **performance** that is the trap, and it is the dominant cost in the DEV-2342 check-in recorder. Nine call sites share this shape. Fix is a projection ordered by `(lower(kit_mapping), timestamp_utc)` — no query rewrite needed. See **PG-024** in [Platform Gotchas](platform-gotchas.md).

> Aliasing note baked into all three GPS readers: the min/max timestamp is aliased **`ts`, not `timestamp_utc`** — a `... AS timestamp_utc` alias shadows the real DateTime column, so `toStartOfInterval(timestamp_utc, …)` would resolve to a String and ClickHouse throws *"Illegal type String of 1st argument of function toStartOfInterval."* And `argMin`/`argMax` operate on the **extracted Float64 coords** (`tupleElement(geo,1|2)`), not the `geo` Point, to keep both coords on the same earliest/latest-in-bucket row.

### `KitScanPathsQuery` — scan-path polylines
`app/Clickhouse/Queries/KitScanPathsQuery.php`. Powers the driver "today" red track, the Bird's Eye historical overlay, and — **as of DEV-2451 — the client-portal Kit User Path map's *sole* path source** (`KitUserPathController::paths()`). Reads `clarity.kit_scan_paths` (a ≤15-min ReplacingMergeTree MV of scan polylines) **with `FINAL`**.

```sql
SELECT kit_id,
       arrayElement([<42 RGB tuples>], (kit_id % 42) + 1) AS color,
       if(kit_name = '', concat('Kit ', toString(kit_id)), kit_name) AS name,
       '{layer}' AS layerId,          -- layer is addslashes()'d
       path_points AS path,
       toString(toDate(timestamp)) AS trip_date
FROM clarity.kit_scan_paths FINAL
WHERE org_id = ?                        -- org scoping
  [AND kit_id IN (…)]                   -- optional, whereIntegerInRaw
  [AND timestamp BETWEEN ? AND ?]       -- tz→UTC
  AND length(path_points) >= 2          -- drop degenerate single-point rows
ORDER BY kit_id, timestamp
```

Deterministic per-kit color from a **42-entry palette** (`kit_id % 42`). `trip_date` lets the client fade older drives by age; the driver "today" overlay ignores it. Also carries a `STATE_BOUNDS` constant (51 US bounding boxes).

### `KitHistoricPositionsQuery` — one kit's trail
`app/Clickhouse/Queries/KitHistoricPositionsQuery.php`. Feeds the driver historic-path overlay, the live-path endpoint, and shift-summary miles (`BuildShiftSummary`).

```sql
SELECT argMin(tupleElement(geo, 1), timestamp_utc) AS lng,
       argMin(tupleElement(geo, 2), timestamp_utc) AS lat,
       toString(min(timestamp_utc)) AS ts
FROM clarity.sc_positions
WHERE lower(kit_mapping) = ?
  [AND timestamp_utc >= ?] [AND timestamp_utc <= ?]   -- tz→UTC
GROUP BY toStartOfInterval(timestamp_utc, INTERVAL 3 SECOND)   -- DECIMATION_SECONDS
ORDER BY min(timestamp_utc)
```

`DECIMATION_SECONDS = 3` is chosen so continuous driving (even highway speed) keeps consecutive kept points **under `HistoricPathBuilder`'s 500 ft break threshold**, so only a genuine power-off gap breaks a line. That constant is **pinned, not tunable** — see [`KitRangePathsQuery`](#kitrangepathsquery--kit-user-path-multi-kit-route-non-scout) for why widening it is unsound and what to widen instead.

### `KitRangePathsQuery` — Bird's Eye multi-kit continuous route
`app/Clickhouse/Queries/KitRangePathsQuery.php`. Assembles many kits' continuous-GPS routes over a date range and is the **only** GPS reader that segments **inside ClickHouse** rather than handing points to `HistoricPathBuilder`.

**Caller (changed by DEV-2451):** Scout's Bird's Eye Org Map historical overlay (`ClarityScoutBirdsEyeController::historicalContinuousPaths()`) is now the **only** caller. It was built for the client-portal Kit User Path map, and DEV-2374 aligned Bird's Eye to it for parity — but **DEV-2451 removed the tier-1 call path from `KitUserPathController`**, so Kit User Path renders from scan data only (see `KitScanPathsQuery` above). The two surfaces now deliberately diverge, pending DEV-2450. Note `cachedHandle()` (the 5-minute `Cache::remember` entry point, with the `_mappings`/`mappings_key` cache-key workaround for `kit_mapping` values containing `-`) has had **no caller** since DEV-2451 — Bird's Eye calls `handle()` directly. It is kept deliberately so DEV-2450 can restore a cached read without re-deriving it.

Pipeline order is load-bearing: `3s decimation → groupArray → arraySort → arrayFold (distance dedup) → arraySlice (per-(kit,hour) 1200 head-cap) → arraySplit (break) → arrayFilter (length >= 2) → arrayJoin`.

```sql
-- outermost: one row per RENDERED segment
SELECT mapping,
       toString(toDate(toDateTime(segment[1].1, 'UTC'), '{tz}')) AS trip_date,
       toJSONString(arrayMap(p -> [round(p.2, 5), round(p.3, 5)], segment)) AS path_json
FROM (
  SELECT mapping,
         arrayJoin(arrayFilter(s -> length(s) >= 2,
           arraySplit((point, index) -> index > 1
                        AND geoDistance(points[index-1].2, points[index-1].3, point.2, point.3) > 152.4,
                      points, arrayEnumerate(points)))) AS segment
  FROM (<per-(kit,hour) dedup+cap assembly>)   -- GROUP BY mapping, toStartOfHour(toDateTime(ts,'UTC'), '{tz}')
)
ORDER BY mapping, segment[1].1
```

Three things are unique to this class and easy to get wrong:

- **It returns segments, not points.** `handle()` yields `{mapping, trip_date, path_json}`. `arraySplit` starts a new chunk **at** the breaking element, and `arrayFilter length >= 2` drops the remainders — together an exact SQL port of `HistoricPathBuilder::build()`. A parity test pins the two implementations together; **if you change either, run it** (`tests/Unit/Clickhouse/Queries/KitRangePathsQueryTest.php`).
- **`path_json` is pre-encoded and spliced into the HTTP response verbatim.** Decoding it into PHP arrays to re-encode is precisely the per-point memory cost the class exists to avoid — see [PG-022](platform-gotchas.md#pg-022-bounding-a-continuous-gps-route-query).
- **Hour buckets and `trip_date` are cut in the viewer's timezone** (`toStartOfHour(…, tz)`), not UTC. The earlier UTC-grouping-plus-PHP-local-split arrangement only agreed for whole-hour offsets. The timezone is interpolated into raw SQL, so `safeTimezone()` validates it against `timezone_identifiers_list()` and falls back to `UTC`.

`DEDUP_METERS` is a **per-call parameter** here (default 5 m; the controller passes 50 m for the 15/30-day windows), clamped to `BREAK_METERS / 3` ≈ 50.8 m so it can never approach the break. `countPoints()` is the caller's cheap pre-flight — `sum(length(points))` over the same assembly, counted **pre-split**, so it is an upper bound by however many single-point segments get dropped.

### `OrgLivePositionsQuery` — Bird's Eye driver dots
`app/Clickhouse/Queries/OrgLivePositionsQuery.php`. Latest fix per mapping across the whole org in one round trip.

```sql
SELECT lower(kit_mapping) AS mapping,
       argMax(tupleElement(geo, 1), timestamp_utc) AS lng,
       argMax(tupleElement(geo, 2), timestamp_utc) AS lat,
       toString(max(timestamp_utc)) AS ts,
       argMax(speed, timestamp_utc) AS speed,
       argMax(heading, timestamp_utc) AS heading
FROM clarity.sc_positions
WHERE lower(kit_mapping) IN (?, ?, …)   -- org's mappings, lowercased + deduped app-side
  AND timestamp_utc >= ?                -- default now()->subHours(24)
GROUP BY lower(kit_mapping)
```

`argMax` keeps lng/lat/speed/heading on the same newest row. The "only when moving" heading gate (`speed > 1`) is applied by the caller, not the query.

### `OrgScanPathPositionsQuery` — Bird's Eye dot fallback (DEV-2253)
`app/Clickhouse/Queries/OrgScanPathPositionsQuery.php`. Tier-2 org-wide fallback for `driverPositions()` (see [Scout Bird's Eye](scout-birds-eye.md)) — the latest scan-derived point per kit, for kits `OrgLivePositionsQuery` didn't resolve. One batched round trip per call, never fanned out per kit.

```sql
SELECT kit_id,
       argMax(tupleElement(arrayElement(path_points, length(path_points)), 1), timestamp) AS lng,
       argMax(tupleElement(arrayElement(path_points, length(path_points)), 2), timestamp) AS lat,
       toString(max(timestamp)) AS ts
FROM clarity.kit_scan_paths FINAL
WHERE org_id = ?
  AND kit_id IN (?, ?, …)
  AND timestamp >= ?              -- default now()->utc()->subHours(24)
  AND length(path_points) >= 1    -- out-of-bounds guard, see below
  AND tupleElement(arrayElement(path_points, length(path_points)), 1) != 0
  AND tupleElement(arrayElement(path_points, length(path_points)), 2) != 0
                                   -- zero-geo guard on the aggregated point, see below
GROUP BY kit_id
```

**Keyed by `(org_id, kit_id)`, not `lower(kit_mapping)`.** `clarity.kit_scan_paths` (`ORDER BY (org_id, kit_id, timestamp)`, schema at `database/schema/clickhouse-schema.sql:143`) carries **no `kit_mapping` column at all** — unlike `sc_positions`. This is a genuine trap for the next reader: `driverPositions()` runs tier 1 (`OrgLivePositionsQuery`) keyed by lowercased mapping and tier 2 (this class) keyed by kit id, in the **same request**. Don't port a mapping-keyed lookup onto `kit_scan_paths`, and don't try to join the two tiers' rows by mapping — join tier 2 back to `Kit` by `kit_id`.

`FINAL` is **mandatory** (`ReplacingMergeTree(_version)`) — a no-op under the pgsql test bridge, but load-bearing under the real driver: a duplicate `(org_id, kit_id, timestamp)` row from a re-processed MV batch would otherwise return whichever copy the planner reads first, not the latest `_version`. The table has a **90-day insert-time DELETE TTL**, so fixtures must anchor `timestamp` to `now()`/`now()->subMinutes(N)` — a hardcoded past date silently reads back 0 rows with nothing to blame.

Two **scalar** `argMax` columns rather than `argMax` over the tuple — avoids the driver-dependent tuple-decoding `kitPosition()`'s single-kit tier-2 read has to hand-destructure. `length(path_points) >= 1` guards `arrayElement()` on an empty array — verified directly against the ClickHouse engine in use, this returns the tuple type's default `(0,0)` rather than throwing, so the guard's job is to keep an empty bucket from rendering as a phantom `(0,0)` dot, not to prevent a query error. **Zero-geo guard** (DEV-2253 review round 1): the last `path_points` element being `(0,0)` is filtered explicitly, mirroring every other scan-derived Scout query (`ScoutCheckInRollupQuery.php:92`, `HitDirectHotspotQuery.php:73`). This matters because `path_points` derives from the scan tables (`lat`/`lng` Float64 DEFAULT 0, `database/schema/clickhouse-schema.sql:163`) — a different producer than `sc_positions`, whose own "0 rows in 172.7M" measurement (tier 1's zero-geo discard, applied at the controller) does not cover this table. This predicate subsumes `length(path_points) >= 1` for the empty-array case (an empty array's default last-point is also `(0,0)`), but both are kept for clarity.

### `OrgLivePathsQuery` — Bird's Eye driver trails
`app/Clickhouse/Queries/OrgLivePathsQuery.php`. Org-wide analogue of `KitHistoricPositionsQuery`, decimated to 1 pt/3s.

```sql
SELECT lower(kit_mapping) AS mapping,
       argMin(tupleElement(geo, 1), timestamp_utc) AS lng,
       argMin(tupleElement(geo, 2), timestamp_utc) AS lat,
       toString(min(timestamp_utc)) AS ts
FROM clarity.sc_positions
WHERE ( (lower(kit_mapping) = ? AND timestamp_utc >= ?)   -- PER-MAPPING cursor, OR'd
     OR (lower(kit_mapping) = ? AND timestamp_utc >= ?) … )
  [AND timestamp_utc <= ?]
GROUP BY lower(kit_mapping), toStartOfInterval(timestamp_utc, INTERVAL 3 SECOND)
ORDER BY lower(kit_mapping), min(timestamp_utc)
```

**Each mapping carries its own lower bound** (a `sinces` map) — a single shared cursor pinned to the freshest kit would starve every laggier driver's trail (their delayed points forever below the shared `since`). `segmentByMapping()` then groups the flat points per mapping and runs each through `HistoricPathBuilder` so a power-off gap breaks the line.

Unlike the dot (`OrgLivePositionsQuery` → `OrgScanPathPositionsQuery`, DEV-2253), **the trail still has no fallback tier**: this query and the single-driver `KitHistoricPositionsQuery` are both still `sc_positions`-only, so a kit rescued onto the org map by tier 2 gets a dot but no trail. Same unfixed idiom, deliberately deferred — tracked as DEV-2228 (stitching 15-min `kit_scan_paths` buckets into a trail is materially different work than picking one dot, and `HistoricPathBuilder`'s 500 ft segmentation is tuned for 3s-decimated GPS, not 15-min buckets). Don't assume parity between the dot and the trail.

### Inline read — `ClarityScoutMapController::kitPosition()` (3-tier fallback)
Not a query class; the driver live dot. `app/Http/Controllers/Api/ClarityScoutMapController.php:366-499`. All tiers use a 24 h cutoff (a dormant kit's dot shouldn't teleport to a days-old fix).

1. **`clarity.sc_positions`** (no FINAL): latest row, `lower(kit_mapping) = ?`, `timestamp_utc >= now()-24h`, `ORDER BY timestamp_utc DESC LIMIT 1`. Heading only when `speed > 1` (jitter gate). `source: 'scan'`.
2. **`clarity.kit_scan_paths FINAL`**: last element of `path_points`, scoped `org_id`+`kit_id`, 24 h window.
3. **`clarity.kit_trips`** (FINAL via model scope): `end_point` of the most recent trip in 24 h.

A **thrown** primary read means the cluster is unreachable → it degrades straight to the empty payload *without* trying tiers 2/3 (they're on the same cluster and would re-pay the full timeout on every 10 s poll). Tiers 2/3 run only when tier 1 succeeds but returns no recent row.

Since DEV-2253, the **org-wide** dot (`ClarityScoutBirdsEyeController::driverPositions()`) has the analogous 2-tier degrade — `OrgLivePositionsQuery` → `OrgScanPathPositionsQuery` — but deliberately **no `kit_trips` tier**: production measurement showed 0 rescues, and `kit_trips` derives from `sc_positions`, so it's structurally empty exactly when `sc_positions` is.

**The two surfaces do NOT actually share a not-on-throw rule in production**, despite superficially identical `try`/`catch` shapes. `kitPosition()`'s tier-1 read is an **inline `DB::clickhouse()` call**, so a thrown connection error really does reach its own `catch` and short-circuit before tiers 2/3. `driverPositions()`'s tier-1 read instead goes through `OrgLivePositionsQuery::query()` — a `ClickhouseQuery` subclass — whose *own* `catch` (`ClickhouseQuery.php:29-37`) swallows the `\Throwable` to `[]` in `production`/`testing` **before** it ever reaches the controller's `catch`. So in production, tier 1 doesn't throw at all on a cluster outage — it returns an empty result set "successfully", every scoped kit is treated as "missing", and tier 2 runs anyway — where it is swallowed the same way, so it is **not** caught by the controller either. The outage therefore costs two connect-timeout waits and two `report()` calls per poll, not one, and neither `Log::warning` fires. The controller's tier-1 `catch`/short-circuit is real code, but it is only reachable outside `production`/`testing` (e.g. `local`, where `ClickhouseQuery::query()` re-throws instead of swallowing) or on an error raised outside `ClickhouseQuery::query()` altogether. The tier-2 `catch` (degrade to tier-1-only, never wipe `$data`) has the identical caveat and is exercised the same way. Tests `test_driver_positions_tier_one_throw_short_circuits_before_tier_two` and `test_driver_positions_tier_two_throw_keeps_tier_one_dots` (`ClarityScoutBirdsEyeControllerTest`) pin both branches under `config(['app.env' => 'local'])` — the only way to actually reach them.

Each `driverPositions()` entry now also carries `position_source: 'gps' | 'scan_path'` — the new tier discriminator, and distinct from the pre-existing `source` field, which still means scanner-hardware vs. browser-GPS and is consumed by `stores/map.js:474`; do not conflate the two. Tier 2 entries never carry a `heading` (`kit_scan_paths` has no bearing column). See [Scout Bird's Eye](scout-birds-eye.md#the-live-dot-has-a-scan-derived-fallback).

**Three known divergences from `kitPosition()` were left in place by DEV-2253** — recorded here so nobody assumes parity:

1. **Zero-geo guard, org side only.** `OrgScanPathPositionsQuery` filters rows whose last `path_points` element is `(0,0)`; `ClarityScoutMapController.php:546` does not, and `[0, 0]` is a truthy PHP array, so an all-zero last point there yields a `(0,0)` dot *and* returns early, suppressing the `kit_trips` tier-3 that would have supplied a real point. Measured prevalence at the time of writing: **0** zero-tailed and **0** empty `path_points` buckets in 23,690 rows over 24 h, so neither surface is currently affected — the org-side guard is insurance against a producer-side regression (`scans_hot.lat`/`lng` are `Float64 DEFAULT 0`), not a live filter.
2. **UTC cutoff, org side only.** `driverPositions()` builds its cutoff with `now()->utc()`; `ClarityScoutMapController.php:482` still uses app-local `now()`. Inert while `APP_TIMEZONE=UTC` (the committed default), but the two adjacent surfaces are constructed differently.
3. **`KitScanPathsQuery` emits raw `path_points`** with only a `length >= 2` guard, so a zero point *inside* a bucket would draw a polyline leg through `(0,0)`. Same measured prevalence of 0.

### Inline read — `ClarityScoutMapController::livePath()` (cursor)
`:125-173`. `sc_positions` has no surrogate id and is `ORDER BY (kit_mapping, timestamp_utc)`, so **`timestamp_utc` is the cursor**. The client passes `since`; first poll (no `since`) seeds from the start of the local day. Delegates the read to `KitHistoricPositionsQuery`, echoes the client's cursor when there are no new points, and on a thrown read returns empty segments + the same cursor (keep the current line, don't 500).

## Density & count queries (heatmaps + rollups)

### `HitDirectHotspotQuery` — hit-density heatmap
`app/Clickhouse/Queries/HitDirectHotspotQuery.php`. Driver + Bird's Eye "honey-hole" heatmap. Reads `clarity.hits_cold FINAL`, semi-joined to `clarity.hotlist FINAL` for **current** open status.

```sql
-- outer: re-aggregate at display precision
SELECT geohashEncode(lng, lat, {precision}) AS geohash, count() AS hit_count
FROM (
    -- inner: dedup repeat scans to one per (hour × ~38m cell)
    SELECT toStartOfHour(h.hit_created_at) AS hour_bucket,
           geohashEncode(h.lng, h.lat, 8) AS loc_bucket,   -- DEDUP_PRECISION = 8
           any(h.lng) AS lng, any(h.lat) AS lat
    FROM clarity.hits_cold h FINAL
    WHERE h.hotlist_org_id = {orgId}
      AND h.hotlist_id IN (
          SELECT id FROM clarity.hotlist FINAL
          WHERE org_id = {orgId} AND status = {Open} AND _peerdb_is_deleted = 0
      )
      AND h._peerdb_is_deleted = 0
      AND h.hit_type = 'L'
      AND h.hit_created_at >= '{9 months ago}'
      AND h.lat != 0 AND h.lng != 0
      AND (h.disposition IS NULL OR h.disposition = '{VALID}')
      [AND h.lat BETWEEN … AND h.lng BETWEEN …]     -- optional bbox
    GROUP BY hour_bucket, loc_bucket
) deduped
GROUP BY geohash
```

The `hotlist_id IN (… status = Open …)` semi-join is the crux: it uses **current** hotlist status, not the hit row's frozen `hotlist_status` (DEV-2117, mirrors the DEV-2113 marker fix), so an order closed after its hit stops shading the cell. `sanitizePrecision` clamps display precision 6–9 (default 6; but the controller forces **7** in prod — see [dashboard stats](scout-dashboard-stats.md#geohash-precision--forced-to-7-in-production)). Tiers 1 / 2–4 / 5+ (green→yellow→red).

### `HotlistAddressGeohashQuery` — order-address density (Direct + Staging)
`app/Clickhouse/Queries/HotlistAddressGeohashQuery.php`. **Cross-database:** `zod.public_address` + `zod.public_hotlist_address` joined to `clarity.hotlist FINAL`, all on the `clarity_clickhouse` connection (which carries `validate_polygons=0`).

```sql
SELECT geohashEncode(lng, lat, {precision}) AS geohash,
       count(DISTINCT lat, lng) AS address_count       -- distinct locations, computed live
FROM (
    SELECT a.id AS a_id,
           toFloat64OrNull(extract(a.geo, 'POINT ?\(([-0-9.]+) ')) AS lng,   -- EWKT via re2
           toFloat64OrNull(extract(a.geo, ' ([-0-9.]+)\)')) AS lat
    FROM zod.public_address a
    WHERE a._peerdb_is_deleted = 0
      AND a.geo != '' AND a.geo IS NOT NULL
      AND a.house_number IS NOT NULL AND a.house_number != ''
      AND a.road IS NOT NULL AND a.road != ''
      AND a.id IN (                                       -- id-membership, NOT a JOIN
          SELECT ha.address_id FROM zod.public_hotlist_address ha
          WHERE ha.hotlist_id IN (
              SELECT id FROM clarity.hotlist FINAL
              WHERE _peerdb_is_deleted = 0 AND org_id IN ({orgList})
                AND status = 1 AND length(plate) > 3 [AND is_staged = true]
          )
      )
)
WHERE lng IS NOT NULL AND lat IS NOT NULL [AND bbox] [AND pointInPolygon(...) OR …]
GROUP BY geohash
HAVING (<tier bands>)
```

Load-bearing choices, all from code comments:
- **`a.id IN (subquery)`, never a JOIN** — a JOIN hashes the 100M+ `public_address` table and times out; `IN` rides the id primary key.
- **EWKT parsed with re2 `extract()` + `toFloat64OrNull`**, not `readWKTPoint` — `readWKTPoint` throws on one malformed/non-POINT row and the base class swallows the whole layer to `[]`; `toFloat64OrNull` yields NULL for bad rows, which the outer `WHERE` drops.
- **`count(DISTINCT lat, lng)`** — distinct locations computed live per cell; the precision-6 materialized view was **retired** because it diverged on status/plate/(0,0) filtering.
- **Staging** adds `is_staged = true` and scopes to `stagingOrgIds()`; direct needs no `is_staged` (org_id already scopes) — without the staging filter, forwarder orgs over-count (DEV-2080).
- **Run-area clip** via `pointInPolygon((assumeNotNull(lng), assumeNotNull(lat)), [ring])` OR-ed across TIGER rings; needs `validate_polygons=0` (non-simple rings) and `assumeNotNull` (pointInPolygon rejects nullable tuples). Rings arrive under `_run_area_rings` (excluded from the cache key).

Tiers: direct = purple ramp, staging = green ramp, combined (unioned) = stepped scale capped at 10+ (DEV-2031).

### `ScoutCheckInRollupQuery` — dashboard check-in counts (Direct vs Staging)
`app/Clickhouse/Queries/ScoutCheckInRollupQuery.php`. Single-database: `clarity.scans_hot|scans_cold FINAL` × `clarity.scout_open_addresses`. A check-in = one `(address, hour)` where a scan landed within ~160 m of an open-order address already in the book.

```sql
WITH scan_window AS (                       -- clarity.scans_{hot|cold} FINAL, 90-day split
    SELECT lng, lat, toStartOfHour(scanned_at) AS hour_bucket
    FROM clarity.{scansTable} FINAL
    WHERE org_id = {orgId} AND kit_id = {kitId}
      AND scanned_at BETWEEN '{start}' AND '{end}'
      AND lng != 0 AND lat != 0 AND _peerdb_is_deleted = 0
),
scan_bbox AS ( SELECT min/max(lng|lat) ± 0.003 … FROM scan_window ),
addresses AS (
    SELECT address_id, is_staged,
           tupleElement(geo, 1) AS addr_lng, tupleElement(geo, 2) AS addr_lat
    FROM clarity.scout_open_addresses book
    CROSS JOIN scan_bbox b
    WHERE ( (org_id = {orgId} AND is_staged = false)
         OR (org_id IN ({stagingIds}) AND is_staged = true) )
      AND addr coords non-zero AND inside bbox
),
matches AS ( SELECT DISTINCT a.address_id, a.is_staged, s.hour_bucket
             FROM scan_window s
             INNER JOIN addresses a ON geoDistance(...) <= 160.934 )
SELECT toUInt64(countIf(is_staged = false)) AS direct_bucket,
       toUInt64(countIf(is_staged = true))  AS staging_bucket
FROM matches
```

`PROXIMITY_METERS = 160.934` (~0.1 mi). The bbox is a **CROSS JOIN** against the CTE, not four scalar subqueries (measured 1.55M vs 1.97M read_rows on the org-14 slice). `hotlistOrgFilter` stays duplicated per class. Address source is `clarity.scout_open_addresses` (Point), **not** `clarity.recovery_address`. Open-status and plate-length live in the MV.

A shared address on both a Direct and a Staging open order is counted in **both** buckets here. The recorder matcher below is Direct-wins for the persisted row. See [check-in](check-in.md) and [the recorder](scout-check-in-recorder.md).

### `ScoutCheckInMatchQuery` — recorder GPS match (per kit)
`app/Clickhouse/Queries/ScoutCheckInMatchQuery.php`. The **persisted** check-in matcher used by `scout:record-check-ins`. Same book + CROSS JOIN bbox + `geoDistance <= 160.934`, but the left side is **`clarity.sc_positions`** keyed by `lower(kit_mapping) = ?` and the output is one row per `(address_id, hour_bucket)` with Direct-wins `argMin` + a single-tuple `argMin` for coords.

The recorder calls `::handle()` directly — `ClickhouseQuery::query()` would swallow a failure to `[]` in prod. Rollup keeps `::query(cache: true)`.

`ScoutOpenOrderAddressesQuery` (the old PHP-side per-org enumeration + `values()` re-injection) was **deleted** in DEV-2342. Do not resurrect it.

## Which classes Scout actually uses

| Class | Used by Scout | Notes |
|-------|---------------|-------|
| `KitScanPathsQuery` | ✅ driver + Bird's Eye | scan-path polylines; **also Kit User Path's sole path source** (DEV-2451) |
| `KitHistoricPositionsQuery` | ✅ driver live/historic path, shift miles | `sc_positions`, 3s decimation |
| `KitRangePathsQuery` | ✅ Bird's Eye historical (only caller since DEV-2451) | multi-kit continuous-GPS route; the only reader that segments **in ClickHouse** instead of via `HistoricPathBuilder` |
| `OrgLivePositionsQuery` / `OrgLivePathsQuery` | ✅ Bird's Eye only | org-wide dots/trails |
| `OrgScanPathPositionsQuery` | ✅ Bird's Eye only | tier-2 dot fallback from `kit_scan_paths`, keyed by `(org_id, kit_id)` (DEV-2253) |
| `HitDirectHotspotQuery` | ✅ driver + Bird's Eye | hit-density heatmap |
| `HotlistAddressGeohashQuery` | ✅ driver + Bird's Eye (+ non-Scout `KitUserPathController`) | order density |
| `ScoutCheckInRollupQuery` | ✅ dashboard check-in tile | Direct/Staging rollup (on-read, scans-based); counts a shared address in both buckets |
| `ScoutCheckInMatchQuery` | ✅ check-in **recorder** (DEV-2313 / DEV-2342 / DEV-2386) | per-kit `sc_positions` match against `scout_open_addresses`. See [recorder](scout-check-in-recorder.md) |
| `HitDensityGeohashQuery` | ❌ **non-Scout** (`KitUserPathController`) | the repo heatmap; scopes by `hotlist_org_id`, trusts the frozen hit disposition. Don't confuse with `HitDirectHotspotQuery`. |
| `LprVisitCountQuery` | ❌ non-Scout | the mirror `ScoutCheckInRollupQuery` was modeled on |

## See also

- [Scout dashboard stats & map counting](scout-dashboard-stats.md) — what each number means (dedup, precision, current-vs-frozen status, org scoping)
- [Scout automated check-in recorder](scout-check-in-recorder.md) — the command that persists `ScoutCheckInMatchQuery` rows
- [Scout real-time tracking](scout-real-time-tracking.md) — the polling loops and `HistoricPathBuilder` that consume these
- [Scout Bird's Eye](scout-birds-eye.md) — the admin surface that fans `OrgLive*` queries org-wide
- [ClickHouse query patterns](clickhouse-query-patterns.md) — `FINAL`, `_peerdb_is_deleted`, `kit_mapping` casing, the `DB::clickhouse()` macro
- [Data Architecture](data-architecture.md) — `clarity` vs `zod` ClickHouse databases; PostgreSQL read side
