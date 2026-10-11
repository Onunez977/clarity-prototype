---
type: DataModel
title: hotlist (App\Clickhouse\Models\Hotlist)
description: Denormalized ClickHouse analytical copy of hotlist orders, folding hotlist + hotlist_json + hotlist_meta into one wide row for analytics.
resource: app/Clickhouse/Models/Hotlist.php
tags: [model, clickhouse, clarity, lpr, hotlist, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# hotlist

`clarity.hotlist` is the denormalized, analytical copy of a [hotlist](../../concepts/hotlist.md) order — the target vehicle/plate that scans are matched against. It folds the base hotlist row together with data extracted from `hotlist_json` and `hotlist_meta` (plate, VIN, vehicle attributes, order/repo/close dates, org relationships) into a single wide row for client analytics and Scout read paths. It lives in the **ClickHouse `clarity` database**, `clarity_clickhouse` connection, `SharedReplacingMergeTree` engine.

The transactional source of truth is the PostgreSQL `hotlist` table plus its `hotlist_json`, `hotlist_meta`, and `hotlist_address` sidecars (`public` schema); those replicate via PeerDB CDC to `zod.public_hotlist*` (see [public_hotlist](public_hotlist.md)) and are then denormalized here. See [ClickHouse Integration](../../concepts/clickhouse-integration.md) and [Data Architecture](../../concepts/data-architecture.md).

## Location

- Database: `clarity` (ClickHouse)
- Connection: `clarity_clickhouse`
- Table: `hotlist`
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\Hotlist` — `app/Clickhouse/Models/Hotlist.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `Int64` | no | Hotlist order id (mirrors PostgreSQL `hotlist.id`). |
| `org_id` | `Int32` | no | Owning org — the tenant-scoping key (`forOrg()`). |
| `integration_id` | `Nullable(Int32)` | yes | `IntegrationEnum` source integration; also drives `remoteName`/`remoteLink` accessors. |
| `remote_order_id` | `Nullable(String)` | yes | External integration order id. |
| `client_org_id` | `Nullable(Int32)` | yes | Client org associated with the order. |
| `client_org_name` | `Nullable(String)` | yes | Denormalized client org name. |
| `lienholder_org_id` | `Nullable(Int32)` | yes | Lienholder org associated with the order. |
| `lienholder_org_name` | `Nullable(String)` | yes | Denormalized lienholder org name. |
| `status` | `Nullable(Int32)` | yes | `HotlistStatusEnum` value (0=Other,1=Open,2=Hold,3=Repossessed,4=ClosedPositive,5=Closed,90=NewFromClient,99=Invalid). |
| `order_type` | `Nullable(Int32)` | yes | Order type code. |
| `is_staged` | `Bool` | no | Staged (LPR-staging) vs direct order (`staged()` / `direct()`). |
| `plate` | `Nullable(String)` | yes | Target plate. |
| `us_state` | `Nullable(String)` | yes | Target plate US state. |
| `vin` | `Nullable(String)` | yes | Target VIN. |
| `vehicle_year` | `Nullable(String)` | yes | Vehicle year (part of `vehicle` accessor). |
| `vehicle_make` | `Nullable(String)` | yes | Vehicle make. |
| `vehicle_model` | `Nullable(String)` | yes | Vehicle model. |
| `lookback_days` | `Nullable(Int32)` | yes | Historical lookback window in days for matching. |
| `created_at` | `DateTime` | no | Order creation timestamp. |
| `updated_at` | `DateTime` | no | Order last-updated timestamp. |
| `branch_id` | `Nullable(Int64)` | yes | Branch scope for the order (`forBranch()`). |
| `_peerdb_synced_at` | `DateTime` | no | CDC: when the base hotlist row was synced. |
| `_peerdb_hj_synced_at` | `DateTime64(9)` | no | CDC: when the joined `hotlist_json` data was last synced. |
| `_peerdb_hm_synced_at` | `DateTime64(9)` | no | CDC: when the joined `hotlist_meta` data was last synced. |
| `repo_date` | `Nullable(Date)` | yes | Repossession date, if repossessed. |
| `order_date` | `Nullable(Date)` | yes | Order placement date. |
| `_version` | `UInt64` | no | ReplacingMergeTree version key for dedup (higher wins). |
| `_peerdb_is_deleted` | `UInt8` | no | Soft-delete flag (0=active, 1=deleted). Always filter `= 0`. |
| `close_date` | `Nullable(Date)` | yes | Order close date, if closed. |

## Casts & enums

No `casts()` declared. Relevant enums referenced by scopes/columns:

- `status` → `App\Enums\HotlistStatusEnum` (int).
- `integration_id` → `InsightLPR\BlackAdam\Enum\IntegrationEnum`.

## Relationships

Read-only analytical model with no Eloquent relationship methods. `org_id`, `client_org_id`, `lienholder_org_id`, `branch_id` reference PostgreSQL counterparts by id and are queried by scope. Related concept/model pages: [hotlist](../../concepts/hotlist.md), [public_hotlist](public_hotlist.md), [hits_cold](hits_cold.md).

## Data mapping (PostgreSQL ↔ ClickHouse)

- PostgreSQL source: `hotlist` (+ `hotlist_json`, `hotlist_meta`, `hotlist_address`) in the `public` schema, `zod` database. CDC-mirrored to `zod.public_hotlist` / `zod.public_hotlist_meta` / `zod.public_hotlist_json`, then denormalized into `clarity.hotlist` via materialized views (`zod.hotlist_json_denormalized_mv` extracts the JSON fields).
- CDC columns: `_peerdb_synced_at` (base row sync), `_peerdb_hj_synced_at` (hotlist_json facet freshness), `_peerdb_hm_synced_at` (hotlist_meta facet freshness), `_peerdb_is_deleted` (soft delete), `_version` (dedup version).

## Notable scopes & behavior

- **Global scope `ApplyFinal`** — this model applies ClickHouse `FINAL` on every query (via `booted()`), so ReplacingMergeTree dedup is resolved automatically (no-op on the pgsql test driver).
- **Global scope `FilterDeletedRecords`** (via `FiltersCdcDeletes`) — filters `_peerdb_is_deleted = 0`; bypass with `withDeleted()`.
- Scopes: `forOrg()`, `forBranch()`, `forClient()`, `forClients()`, `open()` (status=Open), `active()` (Open or Hold), `forStatus()` (folds `Other` into not-in Open/Repossessed), `createdAfter()`, `between($start,$end,$tz)` (timezone-aware), `forVin()`, `forPlate()`, `forOrderId()`, `direct()` (`is_staged=0`), `staged()` (`is_staged=1`), `hasPlate()` (length>3), `shortPlate()` (length<3), `missingPlate()`.
- Accessors: `vehicle` (year make model), `remoteName` / `remoteLink` (from `IntegrationEnum`).
- Implements `HotlistFilterable`; has a `HotlistFactory`.

## See also

- [hotlist](../../concepts/hotlist.md) — the domain concept
- [public_hotlist](public_hotlist.md) — the CDC mirror this denormalizes from
- [hits_cold](hits_cold.md) — hits reference hotlist orders
- [ClickHouse Integration](../../concepts/clickhouse-integration.md), [Data Architecture](../../concepts/data-architecture.md)
