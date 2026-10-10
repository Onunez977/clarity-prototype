---
type: Pattern
title: ClickHouse Query Patterns
description: How to write ClickHouse query classes and models in Clarity — the ClickhouseQuery base (app/Clickhouse/Queries), FINAL, CDC soft-delete filtering, driver-aware SQL, hot/cold tables, caching. Corrected from the legacy .claude/docs/clickhouse-query-patterns.md.
resource: app/Clickhouse/Queries/ClickhouseQuery.php
tags: [domain, lpr, clickhouse, patterns, analytics, cdc]
timestamp: 2026-09-11T00:00:00Z
---

# ClickHouse Query Patterns

How Clarity reads from ClickHouse: the `ClickhouseQuery` base class, the `ClickhouseModel` scopes, and the rules every analytical query follows. Read [ClickHouse Integration](clickhouse-integration.md) and [Data Architecture](data-architecture.md) first.

> This page supersedes the legacy `.claude/docs/clickhouse-query-patterns.md`. The base class path was wrong there (`app/Queries/` → real `app/Clickhouse/Queries/`), and several helpers it described (`getMonthSelectSql` on the base model, a `selectTable()` hot/cold helper) don't exist in that form. Real mechanics below.

## The `ClickhouseQuery` base

File: `app/Clickhouse/Queries/ClickhouseQuery.php` (namespace `App\Clickhouse\Queries`).

```php
abstract class ClickhouseQuery
{
    abstract public static function handle(array $arguments = []): mixed;

    public static function query(array $arguments = [], bool $cache = false): mixed
    {
        // cache: remember() with TTL = $arguments['cacheTTL'] ?? now()->addMinutes(10)
        // admin impersonation: TTL shrinks to 3 seconds so stale caches don't mask real data
        // on Throwable: in production/testing -> report() and return []; otherwise rethrow
    }

    private static function buildCacheKey(array $arguments = []): string
    {
        // '{snake-kebab class name}:k1=v1:k2=v2' — arrays are sorted and joined with '-'
    }
}
```

Rules when extending:

- Implement `handle(array $arguments): mixed`. Call via `MyQuery::query([...], cache: true)` — never call `handle()` directly when you want caching/error-handling.
- **Cache TTL** defaults to 10 minutes; override with `$arguments['cacheTTL']`. While impersonating an admin (`session('admin_impersonator')`) the TTL collapses to **3 seconds** so cache doesn't hide freshly-changed data.
- **Fail gracefully in production/testing**: a `Throwable` is reported and `query()` returns `[]` (not `0`) — so callers must treat empty as "no data / errored", and chart/widget code should default to `0`/empty. In local/non-test non-production it **rethrows** so you see the error.
- **Cache key** is `{class}:{arg}={value}:…` (arrays sorted, joined with `-`); `cacheTTL` is excluded. Don't pass closures or resources as arguments.

## Model scopes (the preferred way)

ClickHouse models (`app/Clickhouse/Models/*`, namespace `App\Clickhouse\Models`) extend `ClickhouseModel` (`$connection = 'clarity_clickhouse'`) and expose reusable scopes. Prefer scopes + `ClickhouseQuery` classes over raw `DB::connection('clarity_clickhouse')` SQL.

`HotlistStatusAggregate` (table `clarity.hotlist_status_aggregate`) scopes — the backbone of [Client Analytics](client-analytics.md):

| Scope | Behavior |
|-------|----------|
| `forOrg($orgId)` | `WHERE org_id = ?` |
| `between($start, $end, $tz='UTC')` | `WHERE status_hour BETWEEN ? AND ?` (Carbon UTC-normalized) |
| `forBranch($branchId)` / `forClient($clientOrgId)` | optional `WHERE branch_id` / `client_org_id` |
| `openAssignments()` | `new_status = Open (1)` |
| `accepted()` | `new_status = Open (1)` AND `old_status IN (Other (0), NewFromClient (90))` |
| `repossessions()` | `new_status = Repossessed (3)` |
| `closedCohort()` | `new_status = Closed (5)` AND `old_status IN (Open (1), Hold (2))` |
| `declined()` | `new_status = ClosedPositive (4)` AND `old_status IN (Open (1), Hold (2))` |
| `selectTotal()` | `select uniqMerge(unique_hotlists) as total` |
| `groupByClient()` | `GROUP BY client_org_id` + `uniqMerge(unique_hotlists) as total` |
| `byMonth()` / `groupByClientAndMonth()` | month grouping via `HasMonthGrouping` |

> **Drift correction:** the legacy docs said "Total Declined = `new_status = 5` (Closed)". Real `declined()` uses `new_status = ClosedPositive (4)`. `Closed (5)` is the `closedCohort()` scope. Use the real scopes.

`Scan` (table `clarity.scans_cold`) scopes: `forOrg`, `forKit`, `between`, `forPlate` (delegates to `PlateFilter::modifyQuery`), `forDisposition`, `excludeDisposition`, `inBounds`. Use `Scan::hot()` to switch to `clarity.scans_hot` (see hot/cold below).

## Rule: filter CDC soft deletes

Replicated `ReplacingMergeTree` tables carry `_peerdb_is_deleted`. Two ways to filter:

- **Models using the `FiltersCdcDeletes` trait** (e.g. `Scan`) get a global scope `FilterDeletedRecords` that appends `WHERE _peerdb_is_deleted = 0` automatically. Use `->withDeleted()` to bypass it.
- **Raw `DB::connection(...)` queries and models without the trait**: add `->where('_peerdb_is_deleted', 0)` (or `->whereRaw('_peerdb_is_deleted = 0')`) yourself. Forgetting this includes tombstoned rows.

## Rule: `FINAL` for `ReplacingMergeTree`

Dedup is asynchronous; queries must force it. Use the model's `scopeFinal()` (`->final()`) — it calls `final()` on the ClickHouse builder when present and is a **no-op on the pgsql test driver**. For raw queries on ClickHouse, reference the table as `<table> FINAL` (e.g. `DB::connection('clarity_clickhouse')->table('hotlist_status_aggregate FINAL')`). Use `FINAL` only where needed — it has a performance cost.

### "Use `FINAL` only where needed" — the measured per-table numbers

The two house rules (*always* `FINAL` on CDC mirrors vs *use it sparingly*) are both true, and the
tie-breaker is **per table and per column**, not per query. Measured, read-only against production:

| table / shape | does `FINAL` change the answer? | cost of `FINAL` | verdict |
| --- | --- | --- | --- |
| `zod.public_hotlist` (DEV-2464) | **yes** — raw over-counts by **13.3%** | worth paying | **required** |
| `zod.public_hotlist_status_log` (DEV-2464) | **no** — identical result | **~10x** | **drop it** |
| `clarity.scans_cold`, `uniqExact(ocr)` (DEV-2466) | **no** — `ocr` is immutable across CDC | **+97%** server time, **+149%** bytes | **drop it** |
| `zod.public_billing_events` | n/a — 1-arg engine, see PG-028 | — | `FINAL` is **not** a tombstone filter here |

The pattern behind the table: `FINAL` earns its cost only when the column you are reading is
**mutable across CDC versions**. A status that transitions, or a row that gets superseded, needs it;
an immutable identifier or a column that only ever appears once does not. So the decision procedure
is: *name the column, ask whether CDC ever rewrites it, and measure both ways before committing.*
Asymmetric discipline within one query is normal and correct — DEV-2464's funnel applies `FINAL +
_peerdb_is_deleted = 0` on `public_orgs` / `public_api_keys` / `public_hotlist` and deliberately
omits it on `public_hotlist_status_log`.

Read the server-side envelope from the `X-ClickHouse-Summary` response header (`read_rows`,
`read_bytes`, `memory_usage`) rather than wall clock — it needs no `READ ON REMOTE` grant, unlike
`system.query_log`.

`AggregatingMergeTree` tables (`hotlist_status_aggregate`, `client_historical_hits_hourly`, `scan_report_hourly`) use `*Merge` aggregate functions (`uniqMerge`, `uniqExactMerge`) against `AggregateFunction(...)` columns instead of `FINAL` dedup — call `selectTotal()` / `uniqExactMerge(...)` as the scope provides.

## Hot/cold table selection

`Scan` defaults to `clarity.scans_cold` (all history). For the last-90-days hot table (TTL 90d, daily partitions), use the `hot()` static:

```php
Scan::hot()->forOrg($orgId)->between($start, $end)->get();   // scans_hot
Scan::query()->forOrg($orgId)->between($start, $end)->get(); // scans_cold
```

`hits_hot` / `hits_cold` follow the same 90-day TTL split. Choose by date range: recent ranges → hot (faster, smaller); historical ranges → cold.

## Driver-aware SQL (the pgsql test bridge)

Tests run ClickHouse queries against PostgreSQL via `CLICKHOUSE_DRIVER=pgsql`. Branch on the connection driver when SQL differs:

```php
$driver = DB::connection('clarity_clickhouse')->getDriverName();
$monthSql = $driver === 'clickhouse'
    ? "toDate(toStartOfMonth(status_hour)) as month"
    : "DATE_TRUNC('month', status_hour)::date as month";
```

The `HasMonthGrouping` trait (`app/Clickhouse/Concerns/HasMonthGrouping.php`) provides `getMonthSelectSql()` / `getMonthGroupBySql()` — these return the **ClickHouse** form (`toDate(toStartOfMonth(...))`); they are not driver-aware, so for dual-driver queries branch manually as above. Common dialect differences:

| ClickHouse | PostgreSQL (test bridge) |
|------------|---------------------------|
| `toStartOfMonth(col)` / `toDate(toStartOfMonth(col))` | `DATE_TRUNC('month', col)::date` |
| `uniqMerge(state)` | `count(distinct ...)` |
| `uniqExactMerge(state)` | `count(distinct ...)` |
| `geoDistance(lng, lat, lng, lat)` | `ST_DWithin(...::geography, ...::geography, m)` |
| `FINAL` | (omit) |

## Where it lives in code

- Query base: `app/Clickhouse/Queries/ClickhouseQuery.php`
- Client queries: `app/Clickhouse/Queries/Client/*` (`ClientRankedMetricsBatch`, `BatchMonthlySparklineData`, `CohortClosedQuery`, `CohortDeclinedQuery`)
- Other queries: `app/Clickhouse/Queries/*` — `HitStatsBatchQuery`, `ScanStatsBatchQuery`, `HitDensityGeohashQuery`, `HotlistAddressGeohashQuery`, `RecoveryLeaderQuery`, `KitUserRecoveryLeaderboardQuery`, `KitScanPathsQuery`, `LprVisitCountQuery`, `RdnHitHourCountQuery`, `RdnScanHourCountQuery`
- Base model + scopes: `app/Clickhouse/Models/ClickhouseModel.php`, `app/Clickhouse/Models/*`
- CDC filter trait/scope: `app/Clickhouse/Concerns/FiltersCdcDeletes.php`, `app/Clickhouse/Scopes/FilterDeletedRecords.php`
- Month grouping: `app/Clickhouse/Concerns/HasMonthGrouping.php`

## See also

- [ClickHouse Integration](clickhouse-integration.md) — connections, CDC, tables
- [Client Analytics](client-analytics.md) — metric definitions built on these scopes
- [Data Architecture](data-architecture.md) — PostgreSQL vs ClickHouse
- [Testing Conventions](testing-conventions.md) — the pgsql test bridge
