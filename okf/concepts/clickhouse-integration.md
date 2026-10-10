---
type: Concept
title: ClickHouse Integration
description: ClickHouse analytical side of Clarity — connections, PeerDB CDC replication, the clarity/zod databases, key tables + engines, and the dual-driver test bridge. Corrected from the legacy .claude/docs/clickhouse-integration.md.
resource: app/Clickhouse/Models/ClickhouseModel.php
tags: [domain, lpr, clickhouse, postgres, analytics, cdc]
timestamp: 2026-09-11T00:00:00Z
---

# ClickHouse Integration

ClickHouse is the **analytical** read side of Clarity. PostgreSQL is the transactional source of truth (see [Data Architecture](data-architecture.md)); ClickHouse holds replicated copies and pre-aggregated tables that power the Client Analytics dashboard and other heavy read paths. **Read [Data Architecture](data-architecture.md) first** — the PostgreSQL-vs-ClickHouse split is the most common source of confusion.

> This page supersedes the legacy `.claude/docs/clickhouse-integration.md`. Several claims there were stale: query/model paths (`app/Queries/`, `app/Models/Clickhouse/`), table names (`client_stats_aggregate`, `aggregate_recovery_kit_scans`, `client_days_to_repo_hourly`), and the assertion that `missed_opportunities`/`recoveries` are views. The real paths/names are below.

## Two ClickHouse databases, three connections

Defined in `config/database.php`:

| Connection | Driver | ClickHouse database | Purpose |
|------------|--------|---------------------|---------|
| `clarity_clickhouse` | `clickhouse` (default) or `pgsql` | `clarity` | Aggregate/analytical tables — the read side for client analytics, hit stats, scan paths, recoveries. |
| `clarity_clickhouse_write` | `clickhouse` or `pgsql` | `clarity` | Write-path connection (separate `CLICKHOUSE_WRITE_USERNAME`/`_PASSWORD`) for seeders/jobs that insert into `clarity.*`. |
| `public_clickhouse` | `clickhouse` or `pgsql` | `zod` | Replicated PostgreSQL tables (`zod.public_*`) — the CDC mirror of the transactional `zod.public` schema. |

- The `clarity_clickhouse`/`public_clickhouse` driver is `env('CLICKHOUSE_DRIVER', 'clickhouse')`. Set `CLICKHOUSE_DRIVER=pgsql` to point them at PostgreSQL instead (the local test bridge — see below).
- `config('database.use_clickhouse')` is `!empty(env('CLICKHOUSE_HOST'))` — a convenience flag that is true when a ClickHouse host is configured.
- Read-path timeouts are intentionally low (`CLICKHOUSE_TIMEOUT_CONNECT=2`, `CLICKHOUSE_TIMEOUT_QUERY=60`) for fast failure. The **write path** (`clarity_clickhouse_write`) instead uses `CLICKHOUSE_WRITE_TIMEOUT_CONNECT=5` and `CLICKHOUSE_WRITE_RETRIES=1` — a longer connect budget plus one in-client retry — to absorb ClickHouse Cloud TLS-handshake / idle-scaling connect latency that was timing out analytics inserts (DEV-2123). The write path is async (queued, dedup'd, exactly-once), so the extra latency is harmless there.
- The `clarity` connection sets `max_bytes_before_external_group_by = 10 GB` so large `GROUP BY` spills to disk instead of OOMing.

## The production server has no memory ceiling — sizing is the caller's job

Read directly from production `system.settings` (DEV-2464, 2026-09-09):

| setting | value | consequence |
| --- | --- | --- |
| `max_memory_usage` | **0** | no per-query memory cap |
| `max_memory_usage_for_user` | **0** | no per-user cap either |
| `max_bytes_in_join` | **0** | no join-spill threshold |
| `max_execution_time` | **0** | server-side; so `CLICKHOUSE_TIMEOUT_QUERY=60` is **client-side only** — an abandoned query keeps consuming server resources until it finishes |
| `join_use_nulls` | **0** | an unmatched `LEFT JOIN` yields **type defaults, not `NULL`** (see PG-030) |

Two things follow, and both are easy to get wrong:

- **Nothing bounds a single expensive query, and nothing bounds N of them.** The instance's total
  RAM is *not readable* from `readonly_user` (`system.asynchronous_metrics` is denied), so there is
  no denominator available in-app to compare a projection against. `max_bytes_before_external_group_by
  = 10 GB` on the `clarity` connection protects a large `GROUP BY` specifically; it does **not**
  protect joins or overall query memory.
- **A client timeout is not a cancellation.** Because `max_execution_time` is 0 server-side, a
  render that gives up at 60 s leaves the query running. Timed-out waiters therefore *add* to the
  concurrent memory footprint rather than relieving it.

### Sizing a per-org card: cost x concurrency, and what actually synchronises

Per-query cost is cheap to obtain read-only from the `X-ClickHouse-Summary` response header
(`read_rows`, `read_bytes`, `memory_usage`) — no `READ ON REMOTE` grant needed, unlike
`system.query_log`. DEV-2464's funnel measured **584M read_rows / 13.18 GB read / 2.79 GB
server-side peak / 6.0 s median** for the 13-month shape.

The number that matters is **cost x the number of copies running at once**, and the honest way to
reason about the second factor is to ask *what would make them coincide*:

- **Per-key cache mutexes do not help across orgs.** A cold-path lock is per cache key, so N orgs
  going cold together run N copies concurrently by design.
- **Production `CACHE_STORE` is `redis` and no deploy hook clears it** (`config/deploy.yml`; no
  `cache:clear` / `optimize:clear` in the Kamal hooks). So a deploy is **not** an all-cold event —
  which is the single biggest input into whether an N-way ceiling is a recurring event or a tail.
- **A Redis flush or eviction is the synchronising event**, and it does more than cause one spike:
  it writes all N keys within the same moment and **phase-locks** them, so they re-expire together
  on every subsequent TTL boundary.
- **`Cache::flexible([fresh, hard])` changes who waits, not what the server does.** At the `fresh`
  boundary the refresh is deferred and nobody blocks, but the server still executes N queries.
  Only the `hard` boundary (or a flush) produces N *blocking* cold renders.

**Design conclusion:** for an expensive per-org metric, the durable fix is a **batch shape** — one
grouped query covering every org, turning `N x cost` into `1 x cost` — not a larger cache TTL. If a
per-org shape must ship first, record the ceiling explicitly as an open risk rather than reporting a
single-query measurement as if it covered concurrency, and prefer **setting a per-query
`max_memory_usage`** (a bounded, clean per-query failure) over leaving it unbounded.

## Models and query classes (real paths)

> The legacy docs said `app/Models/Clickhouse/*` and `app/Queries/*`. **Real paths:** `app/Clickhouse/Models/*` (namespace `App\Clickhouse\Models`) and `app/Clickhouse/Queries/*` (namespace `App\Clickhouse\Queries`).

- Base model: `app/Clickhouse/Models/ClickhouseModel.php` — `protected $connection = 'clarity_clickhouse'`, `$timestamps = false`, `$incrementing = false`. It binds the `LaravelClickhouseEloquent` builder when the connection is a ClickHouse connection (from `insight-lpr/laravel-clickhouse-eloquent`), and provides `scopeFinal($query)` which calls `final()` on the underlying builder when it exists (no-op on the pgsql test driver).
- Models: `HotlistStatusAggregate`, `MissedOpportunity`, `LprAssist`, `ClientHistoricalHits`, `DaysToRepoAggregate`, `Hit`, `Hotlist`, `Kit`, `Scan`, `Recovery`, `RecoveryKitLeaderboard`, `RecoveryKitScanReport`, `HitStatsDaily`, `AffiliateStatsHourly`, `Analytics`, `ZodPublicHotlist`.
- Query base: `app/Clickhouse/Queries/ClickhouseQuery.php` (see [ClickHouse Query Patterns](clickhouse-query-patterns.md)).
- Client query classes: `app/Clickhouse/Queries/Client/ClientRankedMetricsBatch.php`, `BatchMonthlySparklineData.php`, `CohortClosedQuery.php`, `CohortDeclinedQuery.php`.

The old docs' `ClickhouseModel` listed `getMonthSelectSql()`/`getMonthGroupBySql()` and a `clarity.`-prefixing `getTable()`. The real base does **not** contain those — driver-specific SQL is handled per-query (see [ClickHouse Query Patterns](clickhouse-query-patterns.md)). Don't invent those methods.

## PeerDB CDC replication

PostgreSQL `zod.public` tables are replicated to ClickHouse's `zod` database by **PeerDB CDC** as `zod.public_<table>` (e.g. `zod.public_hotlist`, `zod.public_hits`, `zod.public_scans`, `zod.public_kits`, `zod.public_orgs`, `zod.public_hotlist_meta`, `zod.public_hotlist_status_log`, …). Every replicated row carries CDC columns:

| Column | Type | Purpose |
|--------|------|---------|
| `_peerdb_synced_at` | `DateTime64(9)` | When the row was synced from PostgreSQL. |
| `_peerdb_is_deleted` | `UInt8` | Soft-delete flag (`0` = active, `1` = deleted). **Always filter `= 0` in queries.** |
| `_peerdb_version` | `UInt64` | Version monotonically increasing for `ReplacingMergeTree` deduplication. |

Most replicated tables use `ReplacingMergeTree(_peerdb_version, _peerdb_is_deleted)` with `TTL _peerdb_synced_at + toIntervalDay(1) WHERE _peerdb_is_deleted = 1` so soft-deleted rows expire. Because dedup is asynchronous, queries must use `FINAL` (or the model's `scopeFinal()`) and filter `_peerdb_is_deleted = 0`.

## The `clarity` analytical tables

Aggregates and denormalized tables live in the `clarity` database. Key tables (engines verified from `database/schema/clickhouse-schema.sql`):

| Table | Engine | Purpose |
|-------|--------|---------|
| `clarity.hotlist_status_aggregate` | `AggregatingMergeTree` | Hotlist status changes by hour; `unique_hotlists AggregateFunction(uniq, Int64)`. Drives assignments/accepted/declined/repossessions metrics (see [Client Analytics](client-analytics.md)). |
| `clarity.client_historical_hits_hourly` | `AggregatingMergeTree` | Historical hit counts; `historical_hit_count AggregateFunction(uniqExact, Int64)` → `uniqExactMerge()`. |
| `clarity.client_days_to_repo` | `ReplacingMergeTree` | Days-to-repo by recovery type (`_voluntary`/`_involuntary`/`_investigate`/`_impound`/`_lpr_staging`/`_unknown`). (Not `_hourly`.) |
| `clarity.hotlist` | `ReplacingMergeTree` | Denormalized hotlist with `branch_id`, `order_date`, `repo_date`, `close_date`. |
| `clarity.hits_cold` / `clarity.hits_hot` | `ReplacingMergeTree` | Denormalized hit rows (hot = last 90d, TTL 90d; cold = all). Carry `camera_name`, `kit_id`, `kit_name`, `hotlist_*`, `disposition`. |
| `clarity.scans_cold` / `clarity.scans_hot` | `ReplacingMergeTree` | Denormalized scans with `kit_id` + `kit_name` + `camera_name` + `org_id` (hot TTL 90d). This is the **kit↔camera_name aggregate** — see [Kit](kit.md). |
| `clarity.missed_opportunities` | `ReplacingMergeTree` | Missed-opportunity rows (a **table**, not a view); filtered by `hotlist_org_id` (see [Missed Opportunity](missed-opportunity.md)). |
| `clarity.recoveries` / `clarity.recoveries_new` | `ReplacingMergeTree` | Recovery rows (tables, not views) with `repo_date`, `geo` Point, address fields. |
| `clarity.recovery_kit_scan_report` | `ReplacingMergeTree` | Recovery + kit scan-count join (the real name; not `aggregate_recovery_kit_scans`). |
| `clarity.recovery_address` / `clarity.recovery_scans` | `ReplacingMergeTree` | Recovery address + recovery↔scan junction. |
| `clarity.kit_scan_paths` / `clarity.kit_trips` | `ReplacingMergeTree` | Kit path/trip aggregations (TTL 90d). |
| `clarity.hotlist_address_geohash` | `ReplacingMergeTree` | Geohashed hotlist address counts. |
| `clarity.hotlist_order_type_by_created_at` / `clarity.hotlist_order_type_by_repo_date` | `AggregatingMergeTree` | Order-type counts by hour. |
| `clarity.hit_stats_daily` | `ReplacingMergeTree` | Daily live/historical/staging hit counts per org. |
| `clarity.analytics` | `MergeTree` | Product-analytics events. |

Materialized views populate the denormalized/aggregate tables from the `zod.public_*` CDC mirror (e.g. `zod.hits_optimized_mv` → `zod.hits_optimized`; `zod.hotlist_json_denormalized_mv` extracts JSON fields; `zod.kit_last_scan_mv`; `zod.scan_lag_5min_mv`). Replication is eventually consistent with short lag; aggregates refresh on schedule.

### `zod.kit_last_scan` — last scan per kit (read it, don't re-aggregate)

`zod.kit_last_scan_mv` maintains `zod.kit_last_scan` (`org_id`, `org_name`, `kit_id`, `kit_name`,
`last_scan`) incrementally as scans arrive over CDC. It is the **cheap** answer to "when did this
kit last scan": measured 2026-08-31, **0.23 s / 0.25 MB** for one org, versus **1.61 s / 2.16 B
rows / 9,586 MB** for the equivalent `max(scanned_at) GROUP BY kit_id` over `clarity.scans_cold`
— which does not prune, because that table's live sort key is `(org_id, scanned_at, kit_id, id)`
and `kit_id` is only its third column.

Accuracy was verified before adoption: ~5,042 rows / 548 orgs, backfilled to 2021-12, and exact
for active kits — 1,571 of 1,571 agreed with the CDC mirror within 1 s (p99 delta 0 s).

Three constraints on reading it:

1. It is a `ReplacingMergeTree` and is **not reliably merged** (observed at 7,405 rows for 4,755
   distinct kits, then 5,042/5,042 minutes later). Always `max(last_scan) GROUP BY kit_id`.
2. Its sort key is `(org_id, kit_id)`, so it keeps a kit's stale row under a **former** org —
   **275 of 4,755 kits** carry rows under more than one `org_id`, and the stale one may be newer.
   Resolve the kit-id set from PostgreSQL and pass it in; treat `org_id` as defence-in-depth only.
3. It is **chlocal-owned**, and platform's first consumer is
   `App\Clickhouse\Queries\OrgKitLastScanQuery` (DEV-2448). A chlocal reshape blanks the column
   silently, because `ClickhouseQuery` swallows `Throwable`.


## PostgreSQL → ClickHouse mapping

| PostgreSQL (`zod.public`) | CDC mirror (`zod.public_*`) | Analytical (`clarity.*`) |
|----------------------------|------------------------------|---------------------------|
| `hotlist` | `zod.public_hotlist` | `clarity.hotlist` (denormalized) |
| `hotlist_meta` / `hotlist_json` | `zod.public_hotlist_meta` / `zod.public_hotlist_json` | folded into `clarity.hotlist` (via MV) |
| `hits` | `zod.public_hits` | `clarity.hits_cold` / `clarity.hits_hot`; `clarity.hotlist_status_aggregate` (from status log) |
| `scans` | `zod.public_scans` | `clarity.scans_cold` / `clarity.scans_hot` |
| `kits` | `zod.public_kits` | `clarity.kits`; `clarity.kit_scan_paths` / `clarity.kit_trips` |
| `recoveries` (Clarity `platform`-schema table) | — (Clarity-owned) | `clarity.recoveries` / `clarity.recovery_*` |

## Dual-driver testing (the pgsql bridge)

Tests do **not** require a real ClickHouse. Set `CLICKHOUSE_DRIVER=pgsql` (and point `CLICKHOUSE_DATABASE_CLARITY` at a PostgreSQL `clarity_testing` DB) and the same `clarity_clickhouse`/`public_clickhouse` connections run against PostgreSQL. `scopeFinal()` is a no-op on pgsql, and query classes branch on `DB::connection('clarity_clickhouse')->getDriverName()` for dialect differences (`toStartOfMonth` vs `date_trunc`, `uniqMerge` vs `count(distinct)`, etc.). `tests/TestCase.php` includes `clarity_clickhouse` in `$connectionsToTransact` so the bridge participates in test transactions. See [Testing Conventions](testing-conventions.md) and [ClickHouse Query Patterns](clickhouse-query-patterns.md).

## Rules

- **ClickHouse is read-only for app code** (ad-hoc queries are read-only by default; only seeders/jobs write via `clarity_clickhouse_write`). Writes/transactional truth live in PostgreSQL.
- **Always `FINAL` + filter `_peerdb_is_deleted = 0`** on replicated `ReplacingMergeTree` tables (see [ClickHouse Query Patterns](clickhouse-query-patterns.md)).
- **Client Analytics is ClickHouse-only** — it does not fall back to PostgreSQL at runtime. It is gated by the `CLIENT_ANALYTICS` pro feature (`FeatureEnum::CLIENT_ANALYTICS`). Tests skip when ClickHouse isn't configured.
- **Don't confuse `clarity.*` aggregates with `zod.public_*` CDC mirrors** — aggregates are pre-computed; mirrors are row-level copies.

## Where it lives in code

- Base model: `app/Clickhouse/Models/ClickhouseModel.php`
- Models: `app/Clickhouse/Models/*.php`
- Query base + classes: `app/Clickhouse/Queries/ClickhouseQuery.php`, `app/Clickhouse/Queries/Client/*`
- Schema (production): `database/schema/clickhouse-schema.sql`; local test schema: `database/schema/clickhouse-testing-postgres.sql`
- Connection config: `config/database.php` (`clarity_clickhouse`, `clarity_clickhouse_write`, `public_clickhouse`, `use_clickhouse`)
- ORM package: `insight-lpr/laravel-clickhouse-eloquent` (see [Repositories](repositories.md))

## See also

- [Data Architecture](data-architecture.md) — PostgreSQL vs ClickHouse disambiguator (read first)
- [ClickHouse Query Patterns](clickhouse-query-patterns.md) — `ClickhouseQuery` base, FINAL, CDC filter, driver-aware SQL, hot/cold
- [Client Analytics](client-analytics.md) — metric definitions, formulas, status transitions
- [Kit](kit.md) — `clarity.scans_cold`/`scans_hot` is the kit↔`camera_name` aggregate
- [Missed Opportunity](missed-opportunity.md) — `clarity.missed_opportunities` and `hotlist_org_id`
