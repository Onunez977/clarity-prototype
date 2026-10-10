---
type: DataModel
title: hit_stats_daily (HitStatsDaily)
description: ClickHouse ReplacingMergeTree of per-org daily live/historical/staging hit counts, replicated from PostgreSQL and consumed by hit-stat analytics.
resource: app/Clickhouse/Models/HitStatsDaily.php
tags: [model, clickhouse, clarity, hit, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# hit_stats_daily

`hit_stats_daily` holds one row per org per day with pre-counted live, historical, and staging [hit](../../concepts/hit.md) totals. Unlike the hourly `AggregateFunction`-backed aggregates, its counts are plain `UInt64` columns (the daily rollup is precomputed), so no merge-state finalization is needed at read time. The engine is `ReplacingMergeTree`, so queries still collapse duplicate `(date, org_id)` rows with `FINAL` and keep the latest by version. See [ClickHouse Integration](../../concepts/clickhouse-integration.md). Note the model sets `$table = 'clarity.hit_stats_daily'` with the `clarity.` database prefix baked in.

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `clarity.hit_stats_daily` (set explicitly on the model, prefix included)
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\HitStatsDaily`
- Source: `app/Clickhouse/Models/HitStatsDaily.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `date` | `DateTime` | no | Day bucket for the counts; the `between()` scope filters this range. |
| `org_id` | `UInt32` | no | Owning organization. FK → `public.orgs.id`. Filtered by `forOrg()`. |
| `live_hit_count` | `UInt64` | no | Count of live (real-time) hits for the org on that day. |
| `historical_hit_count` | `UInt64` | no | Count of historical hits for the org on that day. |
| `staging_live_hit_count` | `UInt64` | no | Count of live hits against staged/staging hotlist orders. |
| `staging_historical_hit_count` | `UInt64` | no | Count of historical hits against staged/staging hotlist orders. |
| `_peerdb_synced_at` | `DateTime64(9)` | no | PeerDB CDC sync timestamp carried from the replicated source. |

## Casts & enums

None. The model declares `$guarded = []` (fully mass-assignable, which supports the test factory below).

## Relationships

No Eloquent relationships are defined; `org_id` is a plain id column.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Derives from PostgreSQL `public.hits`: `public.hits` → `zod.public_hits` (CDC mirror) → the denormalized `clarity.hit_stats_daily`. The single `_peerdb_synced_at` column is inherited from the upstream replicated rows. See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md).

## Notable scopes & behavior

- **`forOrg($orgId)`** — filters `org_id` (primary tenant scope; see [Multi-Tenancy](../../concepts/multi-tenancy.md)).
- **`between($start, $end)`** — filters `date` between the bounds (no timezone conversion; already day-bucketed).
- **Factory** — has a `HasFactory` trait wired to `Database\Factories\Clickhouse\HitStatsDailyFactory` for seeding under the pgsql test bridge.
- **`ReplacingMergeTree` reads** — use `FINAL` (via the base model's `scopeFinal()`) to dedupe repeated `(date, org_id)` versions; unlike the hourly aggregates, the count columns are read directly (no `uniqMerge`/`uniqExactMerge`).

## See also

- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines and PeerDB CDC
- [Hit](../../concepts/hit.md) — the underlying hit concept
- [client_historical_hits_hourly](client_historical_hits_hourly.md) — the hourly, merge-state historical-hit aggregate
- [Client Analytics](../../concepts/client-analytics.md) — hit metric definitions
- [Multi-Tenancy](../../concepts/multi-tenancy.md) — `forOrg()` scoping
