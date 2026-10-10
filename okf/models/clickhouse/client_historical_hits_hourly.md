---
type: DataModel
title: client_historical_hits_hourly (ClientHistoricalHits)
description: ClickHouse AggregatingMergeTree of distinct historical hit counts bucketed by hour and client, powering the Client Analytics historical-hits metric.
resource: app/Clickhouse/Models/ClientHistoricalHits.php
tags: [model, clickhouse, clarity, hit, aggregate, client-analytics]
timestamp: 2026-07-06T00:00:00Z
---

# client_historical_hits_hourly

`client_historical_hits_hourly` is a pre-aggregated analytics table that counts distinct historical [hits](../../concepts/hit.md) per hour, per org, per client org, and per branch. Each row holds a merge-state distinct-count of hit ids for its `hour_timestamp` bucket. It is the read side behind the Client Analytics "historical hits" metric — see [Client Analytics](../../concepts/client-analytics.md) and [ClickHouse Integration](../../concepts/clickhouse-integration.md). Source rows come from PostgreSQL `public.hits`, replicated by PeerDB and folded into this aggregate by a materialized view.

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `client_historical_hits_hourly`
- Engine: `SharedAggregatingMergeTree`
- Model class: `App\Clickhouse\Models\ClientHistoricalHits`
- Source: `app/Clickhouse/Models/ClientHistoricalHits.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `org_id` | `UInt32` | no | Owning organization (LPR/lienholder org). FK → `public.orgs.id`. Filtered by `forOrg()`. |
| `client_org_id` | `UInt32` | no | Client organization the hits belong to. FK → `public.orgs.id`. Filtered by `forClient()`. |
| `branch_id` | `UInt32` | no | Branch the order is assigned to. Filtered by `forBranch()`. |
| `hour_timestamp` | `DateTime` | no | Hour bucket (UTC) for the count; the `between()` scope filters this range. |
| `historical_hit_count` | `AggregateFunction(uniqExact, Int64)` | no | Merge-state exact distinct count of historical hit ids in the bucket. **Must be finalized with `uniqExactMerge(historical_hit_count)`** — never read raw. |
| `updated_at` | `SimpleAggregateFunction(max, DateTime)` | no | Most recent update time in the bucket; a `SimpleAggregateFunction(max)` that collapses to the greatest value on merge. |

## Casts & enums

None declared.

## Relationships

No Eloquent relationships are defined. Org/client/branch are plain id columns joined in query classes.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Derives from PostgreSQL `public.hits`: `public.hits` → `zod.public_hits` (CDC mirror) → materialized view → `clarity.client_historical_hits_hourly`. See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md).
- A derived `clarity.*` aggregate, not a row-level CDC mirror; it carries no per-row `_peerdb_*` columns.

## Notable scopes & behavior

- **`forOrg($orgId)`** — filters `org_id` (primary tenant scope; see [Multi-Tenancy](../../concepts/multi-tenancy.md)).
- **`forClient($clientOrgId)`** / **`forBranch($branchId)`** — optional `when()` filters applied only when the id is present.
- **`between($start, $end, $timezone = 'UTC')`** — filters `hour_timestamp` after converting bounds to UTC.
- **`selectTotal`** — emits `uniqExactMerge(historical_hit_count) as total`.
- **`groupByClient`** — groups by `client_org_id` with the same `uniqExactMerge`.
- **`byMonth`** — uses `HasMonthGrouping::getMonthSelectSql('hour_timestamp')` (`toDate(toStartOfMonth(...))`) plus `uniqExactMerge`, grouped/ordered by month.
- **Merge-state discipline** — `historical_hit_count` is an `AggregateFunction(uniqExact, …)`; every read must finalize via `uniqExactMerge`. Selecting it directly returns opaque intermediate state.

## See also

- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines, PeerDB CDC, aggregate vs mirror
- [Client Analytics](../../concepts/client-analytics.md) — the historical-hits metric definition
- [Hit](../../concepts/hit.md) — the underlying hit concept
- [hotlist_status_aggregate](hotlist_status_aggregate.md) — sibling `AggregateFunction` hourly aggregate
- [hit_stats_daily](hit_stats_daily.md) — daily (non-merge-state) hit counts per org
