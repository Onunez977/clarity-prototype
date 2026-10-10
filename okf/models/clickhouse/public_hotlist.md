---
type: DataModel
title: public_hotlist (App\Clickhouse\Models\ZodPublicHotlist)
description: PeerDB CDC mirror of the PostgreSQL public.hotlist table in the ClickHouse zod database — row-level replica, not an aggregate.
resource: app/Clickhouse/Models/ZodPublicHotlist.php
tags: [model, clickhouse, zod, lpr, hotlist, cdc]
timestamp: 2026-07-06T00:00:00Z
---

# public_hotlist

`zod.public_hotlist` is the **PeerDB CDC mirror** of the PostgreSQL `public.hotlist` table — a row-level replica of every [hotlist](../../concepts/hotlist.md) order, not a pre-aggregated analytical table. It lives in the **ClickHouse `zod` database** (the CDC-mirror side, one `zod.public_*` table per replicated PostgreSQL `public.*` table), `public_clickhouse` connection, `SharedReplacingMergeTree` engine.

This differs from [clarity.hotlist](hotlist.md): `clarity.hotlist` is the *denormalized/enriched* analytical copy (folding in `hotlist_json` / `hotlist_meta`), while `zod.public_hotlist` is the *raw replicated* copy of just the base `hotlist` row. See [ClickHouse Integration](../../concepts/clickhouse-integration.md) and [Data Architecture](../../concepts/data-architecture.md).

## Location

- Database: `zod` (ClickHouse — the CDC-mirror database)
- Connection: `public_clickhouse`
- Table: `public_hotlist`
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\ZodPublicHotlist` — `app/Clickhouse/Models/ZodPublicHotlist.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `Int64` | no | Hotlist order id (mirrors PostgreSQL `public.hotlist.id`). |
| `integration_id` | `Int32` | no | Source integration id (`IntegrationEnum`). |
| `remote_order_id` | `Nullable(String)` | yes | External integration order id. |
| `status` | `Int32` | no | `HotlistStatusEnum` value (0=Other,1=Open,2=Hold,3=Repossessed,4=ClosedPositive,5=Closed,90=NewFromClient,99=Invalid). |
| `org_id` | `Int32` | no | Owning org — the tenant-scoping key (`forOrg()`). |
| `client_org_id` | `Int32` | no | Client org associated with the order (`forClient()`). |
| `lienholder_org_id` | `Nullable(Int32)` | yes | Lienholder org associated with the order. |
| `order_type` | `Int32` | no | Order type code. |
| `is_staged` | `Bool` | no | Staged (LPR-staging) vs direct order. |
| `plate` | `String` | no | Target plate. |
| `us_state` | `String` | no | Target plate US state. |
| `vin` | `String` | no | Target VIN. |
| `vehicle_year` | `String` | no | Vehicle year. |
| `vehicle_make` | `String` | no | Vehicle make. |
| `vehicle_model` | `String` | no | Vehicle model. |
| `lookback_days` | `Int32` | no | Historical lookback window in days. |
| `created_at` | `DateTime64(6)` | no | Order creation timestamp. |
| `updated_at` | `DateTime64(6)` | no | Order last-updated timestamp. |
| `_peerdb_synced_at` | `DateTime64(9)` | no | CDC: when the row was synced from PostgreSQL. |
| `_peerdb_is_deleted` | `UInt8` | no | Soft-delete flag (0=active, 1=deleted). Always filter `= 0`. |
| `_peerdb_version` | `UInt64` | no | CDC version key for ReplacingMergeTree dedup (monotonically increasing; higher wins). |

## Casts & enums

No `casts()` declared. Relevant enums referenced by scopes/columns:

- `status` → `App\Enums\HotlistStatusEnum` (int).
- `integration_id` → `InsightLPR\BlackAdam\Enum\IntegrationEnum`.

## Relationships

Read-only CDC-mirror model with no Eloquent relationship methods. `org_id`, `client_org_id`, `lienholder_org_id` reference their PostgreSQL counterparts by id and are queried by scope. Related concept/model pages: [hotlist](../../concepts/hotlist.md), [PostgreSQL hotlist](../hotlist.md), [clarity.hotlist](hotlist.md).

## Data mapping (PostgreSQL ↔ ClickHouse)

- PostgreSQL source: `hotlist` (`public` schema, `zod` database). PeerDB CDC replicates it row-for-row to `zod.public_hotlist` (this table), adding the standard CDC columns.
- This CDC mirror is in turn one of the inputs denormalized into the enriched `clarity.hotlist` analytical table (this page's [clarity.hotlist](hotlist.md) sibling).
- CDC columns (canonical PeerDB set): `_peerdb_synced_at` `DateTime64(9)` (sync time), `_peerdb_is_deleted` `UInt8` (soft delete — always filter `= 0`), `_peerdb_version` `UInt64` (dedup version). Note this table uses the fully-named `_peerdb_version`, whereas the enriched `clarity.*` tables shorten it to `_version`.

## Notable scopes & behavior

- **Global scope `FilterDeletedRecords`** (via `FiltersCdcDeletes`) — filters `_peerdb_is_deleted = 0`; bypass with `withDeleted()`.
- **No automatic `FINAL`** — apply `final()` / `scopeFinal()` where exact dedup matters (async ReplacingMergeTree).
- Scopes: `forOrg()` (filters `org_id`), `forClient()` (filters `client_org_id`), `open()` (status=Open), `hold()` (status=Hold), `active()` (Open or Hold), `createdAfter()`, `between($start,$end,$tz)` (timezone-aware, converts to UTC).

## See also

- [hotlist](../../concepts/hotlist.md) — the domain concept
- [PostgreSQL hotlist](../hotlist.md) — the transactional source table
- [clarity.hotlist](hotlist.md) — the denormalized/enriched analytical copy
- [ClickHouse Integration](../../concepts/clickhouse-integration.md), [Data Architecture](../../concepts/data-architecture.md)
