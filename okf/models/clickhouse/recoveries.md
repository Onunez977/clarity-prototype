---
type: DataModel
title: recoveries (App\Clickhouse\Models\Recovery)
description: Denormalized ClickHouse analytical copy of recovery (repossession) records with geo/address facets, the read side for recovery analytics.
resource: app/Clickhouse/Models/Recovery.php
tags: [model, clickhouse, clarity, lpr, recovery, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# recoveries

`clarity.recoveries` is the denormalized ClickHouse **table** (not a view) holding [recovery](../../concepts/recovery.md) records — repossessed orders — with their org relationships, vehicle attributes, geo point, and reverse-geocoded address fields folded in. It is the read side for recovery analytics and reporting. It lives in the **ClickHouse `clarity` database**, `clarity_clickhouse` connection, `SharedReplacingMergeTree` engine.

The transactional source of truth is the Clarity-owned PostgreSQL `recoveries` table in the **`platform` schema** (the Clarity add-on schema, not `public`), on the `zod` database. Unlike the core LPR tables, recoveries are Clarity-owned; they are replicated/denormalized into `clarity.recoveries` and the related `clarity.recovery_kit_scan_report`. See [ClickHouse Integration](../../concepts/clickhouse-integration.md) and [Data Architecture](../../concepts/data-architecture.md).

## Location

- Database: `clarity` (ClickHouse)
- Connection: `clarity_clickhouse`
- Table: `clarity.recoveries` (the model sets `$table = 'clarity.recoveries'` explicitly)
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\Recovery` — `app/Clickhouse/Models/Recovery.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `Int64` | no | Recovery id (mirrors PostgreSQL `platform.recoveries.id`). |
| `org_id` | `Int32` | no | Owning org — the tenant-scoping key (`forOrg()`). |
| `integration_id` | `Nullable(Int32)` | yes | `IntegrationEnum` source integration. |
| `remote_order_id` | `Nullable(String)` | yes | External integration order id. |
| `client_org_id` | `Nullable(Int32)` | yes | Client org associated with the order. |
| `lienholder_org_id` | `Nullable(Int32)` | yes | Lienholder org associated with the order. |
| `status` | `Nullable(Int32)` | yes | `HotlistStatusEnum` value (0=Other,1=Open,2=Hold,3=Repossessed,4=ClosedPositive,5=Closed,90=NewFromClient,99=Invalid). |
| `order_type` | `Nullable(Int32)` | yes | Order type code. |
| `is_staged` | `Bool` | no | Staged (LPR-staging) vs direct order. |
| `plate` | `Nullable(String)` | yes | Target plate. |
| `us_state` | `Nullable(String)` | yes | Target plate US state. |
| `vin` | `Nullable(String)` | yes | Target VIN. |
| `vehicle_year` | `Nullable(String)` | yes | Vehicle year. |
| `vehicle_make` | `Nullable(String)` | yes | Vehicle make. |
| `vehicle_model` | `Nullable(String)` | yes | Vehicle model. |
| `lookback_days` | `Nullable(Int32)` | yes | Historical lookback window in days. |
| `created_at` | `DateTime` | no | Record creation timestamp. |
| `updated_at` | `DateTime` | no | Record last-updated timestamp. |
| `branch_id` | `Nullable(Int64)` | yes | Branch scope for the order. |
| `repo_date` | `Date` | no | Repossession date (drives `between()`). |
| `order_date` | `Nullable(Date)` | yes | Order placement date. |
| `address_id` | `Nullable(Int64)` | yes | FK → recovery address record. |
| `geo` | `Point` | no | Geographic point (primary geo). |
| `geo_point` | `Point` | no | Geographic point (secondary/derived). |
| `road` | `Nullable(String)` | yes | Address road/street. |
| `house_number` | `Nullable(String)` | yes | Address house number. |
| `address_state` | `Nullable(String)` | yes | Address US state. |
| `city` | `Nullable(String)` | yes | Address city. |
| `postcode` | `Nullable(String)` | yes | Address postal code. |
| `_peerdb_synced_at` | `DateTime64(9)` | no | CDC: when the row was synced from PostgreSQL. |
| `_version` | `UInt64` | no | ReplacingMergeTree version key for dedup (higher wins). |
| `_peerdb_is_deleted` | `UInt8` | no | Soft-delete flag (0=active, 1=deleted). Always filter `= 0`. |
| `has_case_claim` | `UInt8` | no | Whether the recovery has an associated case claim (0/1). |
| `is_known_address` | `UInt8` | no | Whether the recovery occurred at a known address (0/1). |
| `is_lpr_assist` | `UInt8` | no | Whether the recovery was LPR-assisted (0/1). |

## Casts & enums

No `casts()` declared. Relevant enums referenced by columns:

- `status` → `App\Enums\HotlistStatusEnum` (int).
- `integration_id` → `InsightLPR\BlackAdam\Enum\IntegrationEnum`.

## Relationships

Read-only analytical model with no Eloquent relationship methods. `org_id`, `client_org_id`, `lienholder_org_id`, `branch_id`, `address_id` reference their PostgreSQL counterparts by id and are queried by scope. Related concept/model pages: [recovery](../../concepts/recovery.md), [organization](../../concepts/organization.md), [hotlist](hotlist.md).

## Data mapping (PostgreSQL ↔ ClickHouse)

- PostgreSQL source: `recoveries` in the **`platform` schema** (Clarity add-on, `zod` database) — a Clarity-owned table, not a core `public` LPR table.
- ClickHouse counterparts: `clarity.recoveries` (this table) and `clarity.recovery_kit_scan_report` (recovery + kit scan-count join). Recovery-kit scoring lives in ClickHouse materialized views (`recovery_scans_mv` → `recovery_kit_scan_report_mv`), not in PostgreSQL.
- CDC columns: `_peerdb_synced_at` (sync time), `_peerdb_is_deleted` (soft delete), `_version` (dedup version).

## Notable scopes & behavior

- **Global scope `ApplyFinal`** — this model applies ClickHouse `FINAL` on every query (via `booted()`), resolving ReplacingMergeTree dedup automatically (no-op on the pgsql test driver).
- **Global scope `FilterDeletedRecords`** (via `FiltersCdcDeletes`) — filters `_peerdb_is_deleted = 0`; bypass with `withDeleted()`.
- Scopes: `forOrg($orgId)` (filters `org_id`), `between($start,$end)` (filters `repo_date` range as date strings).
- Has a `RecoveryFactory`.

## See also

- [recovery](../../concepts/recovery.md) — the domain concept
- [organization](../../concepts/organization.md) — org relationships
- [hotlist](hotlist.md) — recoveries derive from hotlist orders
- [ClickHouse Integration](../../concepts/clickhouse-integration.md), [Data Architecture](../../concepts/data-architecture.md)
