---
type: DataModel
title: hotlist_status_aggregate (HotlistStatusAggregate)
description: ClickHouse AggregatingMergeTree of hotlist status transitions bucketed by hour, powering the Client Analytics assignments/accepted/declined/repossession metrics.
resource: app/Clickhouse/Models/HotlistStatusAggregate.php
tags: [model, clickhouse, clarity, hotlist, aggregate, client-analytics]
timestamp: 2026-07-06T00:00:00Z
---

# hotlist_status_aggregate

`hotlist_status_aggregate` is a pre-aggregated analytics table that counts [hotlist](../../concepts/hotlist.md) status transitions per hour, per org, per client org, and per branch. Each row is keyed by an `(old_status, new_status, status_hour, …)` bucket and holds a merge-state count of distinct hotlists that made that transition. It is the read side behind the Client Analytics assignments / accepted / declined / repossession metrics — see [Client Analytics](../../concepts/client-analytics.md) and [ClickHouse Integration](../../concepts/clickhouse-integration.md). The source rows come from the PostgreSQL hotlist status log, replicated by PeerDB and folded into this aggregate by a materialized view.

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `hotlist_status_aggregate`
- Engine: `SharedAggregatingMergeTree`
- Model class: `App\Clickhouse\Models\HotlistStatusAggregate`
- Source: `app/Clickhouse/Models/HotlistStatusAggregate.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `org_id` | `Int32` | no | Owning organization (the LPR/lienholder org). FK → `public.orgs.id`. Filtered by `forOrg()`. |
| `client_org_id` | `Int32` | no | Client organization the hotlist belongs to (for client-scoped rollups). FK → `public.orgs.id`. |
| `lienholder_org_id` | `Int32` | no | Lienholder organization on the order. FK → `public.orgs.id`. |
| `branch_id` | `Int64` | no | Branch the order is assigned to; filtered by `forBranch()`. |
| `old_status` | `Int32` | no | Status the hotlist transitioned *from* — `HotlistStatusEnum` (0=Other, 1=Open, 2=Hold, 3=Repossessed, 4=ClosedPositive, 5=Closed, 90=NewFromClient, 99=Invalid). |
| `new_status` | `Int32` | no | Status the hotlist transitioned *to* — same `HotlistStatusEnum`. Scopes filter on this (e.g. `openAssignments`, `repossessions`). |
| `status_hour` | `DateTime` | no | Hour bucket (UTC) the transition occurred in; the `between()` scope filters this range. |
| `unique_hotlists` | `AggregateFunction(uniq, Int64)` | no | Merge-state count of distinct hotlist ids in the bucket. **Must be finalized with `uniqMerge(unique_hotlists)`** — never read raw. |
| `total_changes` | `SimpleAggregateFunction(sum, UInt64)` | no | Running count of transition events in the bucket; a `SimpleAggregateFunction` so it collapses on merge without an explicit finalize. |
| `_peerdb_synced_at` | `DateTime64(6)` | no | PeerDB CDC sync timestamp inherited from the upstream replicated rows. |

## Casts & enums

- No Eloquent `casts()` are declared, but `old_status` / `new_status` are `HotlistStatusEnum` values (see column notes). The status-filtering query scopes reference the enum directly.

## Relationships

No Eloquent relationships are defined on this model. Joins to orgs/branches/clients are done by id inside query classes rather than through relationship methods.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Derives from PostgreSQL `public.hits` / the hotlist status log: `public.hits` → `zod.public_hits`, and the hotlist status-log CDC mirror feeds a materialized view that produces `clarity.hotlist_status_aggregate`. See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md).
- This is a derived `clarity.*` aggregate, not a row-level CDC mirror; the single `_peerdb_synced_at` column is carried through from the upstream replicated source.

## Notable scopes & behavior

- **`forOrg($orgId)`** — filters `org_id`; the primary tenant scope (see [Multi-Tenancy](../../concepts/multi-tenancy.md)).
- **`forClient($clientOrgId)`** / **`forBranch($branchId)`** — optional filters applied only when the id is present (`when()`).
- **`between($start, $end, $timezone = 'UTC')`** — filters `status_hour` after converting the bounds to UTC.
- **Status-transition scopes** (all filter `new_status`, some also constrain `old_status`):
  - `openAssignments` / `acceptedOpen` — `new_status = Open (1)`.
  - `repossessions` / `acceptedRepos` — `new_status = Repossessed (3)`.
  - `accepted` — `new_status = Open (1)` from `old_status ∈ {Other(0), NewFromClient(90)}`.
  - `closedCohort` — `new_status = Closed (5)` from `old_status ∈ {Open(1), Hold(2)}`.
  - `declined` — `new_status = ClosedPositive (4)` from `old_status ∈ {Open(1), Hold(2)}`.
  - `acceptedClosed` — `new_status = Closed (5)`; `acceptedHold` — `new_status = Hold (2)`.
- **Aggregation scopes** — `selectTotal` emits `uniqMerge(unique_hotlists) as total`; `groupByClient` groups by `client_org_id` with the same merge; `byMonth` / `groupByClientAndMonth` use `HasMonthGrouping::getMonthSelectSql('status_hour')` (`toDate(toStartOfMonth(...))`) plus the `uniqMerge`.
- **Merge-state discipline** — because `unique_hotlists` is an `AggregateFunction(uniq, …)`, every read path must go through `uniqMerge`; selecting the column directly returns opaque intermediate state.

## See also

- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines, PeerDB CDC, aggregate vs mirror
- [Client Analytics](../../concepts/client-analytics.md) — how these transitions become dashboard metrics
- [Hotlist](../../concepts/hotlist.md) — the underlying order concept
- [client_historical_hits_hourly](client_historical_hits_hourly.md) — sibling `AggregateFunction` hourly aggregate
- [Multi-Tenancy](../../concepts/multi-tenancy.md) — `forOrg()` scoping
