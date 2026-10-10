---
type: DataModel
title: missed_opportunities (App\Clickhouse\Models\MissedOpportunity)
description: ClickHouse analytical table of missed-opportunity hits (scans that matched a hotlist but were not acted on), scoped by hotlist_org_id.
resource: app/Clickhouse/Models/MissedOpportunity.php
tags: [model, clickhouse, clarity, lpr, hit, missed-opportunity, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# missed_opportunities

`missed_opportunities` is a denormalized ClickHouse **table** (not a view) holding missed-opportunity hits — scans that matched a [hotlist](../../concepts/hotlist.md) order but were not surfaced/acted on in time. It shares the wide hit-and-hotlist row shape of [hits_cold](hits_cold.md) and powers missed-opportunity analytics. It lives in the **ClickHouse `clarity` database**, `clarity_clickhouse` connection, `SharedReplacingMergeTree` engine. See [Missed Opportunity](../../concepts/missed-opportunity.md).

Rows are tenant-scoped by `hotlist_org_id` (the org that owns the hotlist order), and metrics count distinct `hotlist_id`. The transactional lineage is the same as hits — PostgreSQL `hits` / `scans` / `hotlist` (`public` schema) via PeerDB CDC and materialized views. See [ClickHouse Integration](../../concepts/clickhouse-integration.md) and [Data Architecture](../../concepts/data-architecture.md).

## Location

- Database: `clarity` (ClickHouse)
- Connection: `clarity_clickhouse`
- Table: `missed_opportunities`
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\MissedOpportunity` — `app/Clickhouse/Models/MissedOpportunity.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `Int64` | no | Row id (mirrors the source hit id). |
| `hotlist_id` | `Int64` | no | FK → `public.hotlist.id` — the missed order (metrics count distinct `hotlist_id`). |
| `scan_id` | `Int64` | no | FK → `public.scans.id` — the scan that matched. |
| `hit_type` | `LowCardinality(String)` | no | `HitTypeEnum` value (typically `M` Missed Opportunity, `F` Missed Opportunity Spotted & Closed). |
| `hit_created_at` | `DateTime` | no | When the hit record was created. |
| `ocr` | `String` | no | OCR'd plate text from the scan. |
| `scanned_at` | `DateTime` | no | When the scan was captured (drives `between()` / `byMonth()`). |
| `camera_name` | `String` | no | Physical device that captured the scan. |
| `scan_us_state` | `LowCardinality(String)` | no | US state read from the plate. |
| `scan_guid` | `UUID` | no | Globally unique scan identifier. |
| `kit_id` | `Int32` | no | FK → `public.kits.id` — capturing kit. |
| `kit_name` | `String` | no | Denormalized kit name. |
| `kit_org_id` | `Int32` | no | Org that owns the capturing kit. |
| `hotlist_us_state` | `Nullable(String)` | yes | US state on the matched hotlist entry. |
| `hotlist_plate` | `Nullable(String)` | yes | Plate on the matched hotlist entry. |
| `hotlist_status` | `Nullable(Int32)` | yes | `HotlistStatusEnum` value (0=Other,1=Open,2=Hold,3=Repossessed,4=ClosedPositive,5=Closed,90=NewFromClient,99=Invalid). |
| `hotlist_vin` | `Nullable(String)` | yes | VIN on the matched hotlist entry (`forVin()`). |
| `hotlist_org_id` | `Int32` | no | Org that owns the hotlist order — the tenant-scoping key (`forOrg()`). |
| `remote_order_id` | `Nullable(String)` | yes | External integration order id. |
| `integration_id` | `Nullable(Int32)` | yes | `IntegrationEnum` source (NICB filtered out by `notNicb()`). |
| `client_org_id` | `Nullable(Int32)` | yes | Client org associated with the order. |
| `branch_id` | `UInt32` | no | Branch scope (`forBranch()`). |
| `lienholder_org_id` | `Nullable(Int32)` | yes | Lienholder org associated with the order. |
| `order_date` | `Nullable(Date)` | yes | Order placement date. |
| `repo_date` | `Nullable(Date)` | yes | Repossession date, if repossessed. |
| `close_date` | `Nullable(Date)` | yes | Order close date, if closed. |
| `client_org_name` | `Nullable(String)` | yes | Denormalized client org name. |
| `lienholder_org_name` | `Nullable(String)` | yes | Denormalized lienholder org name. |
| `_peerdb_synced_at` | `DateTime` | no | CDC: when the row was synced from PostgreSQL. |
| `_version` | `UInt64` | no | ReplacingMergeTree version key for dedup (higher wins). |
| `_peerdb_is_deleted` | `UInt8` | no | Soft-delete flag (0=active, 1=deleted). Always filter `= 0`. |
| `vehicle_year` | `Nullable(String)` | yes | Vehicle year from the hotlist order. |
| `vehicle_make` | `Nullable(String)` | yes | Vehicle make. |
| `vehicle_model` | `Nullable(String)` | yes | Vehicle model. |
| `lat` | `Float64` | no | Scan latitude. |
| `lng` | `Float64` | no | Scan longitude. |
| `address` | `Nullable(String)` | yes | Reverse-geocoded scan address. |
| `disposition` | `Nullable(String)` | yes | `DispositionEnum` value; `NULL` = untriaged. |
| `_peerdb_hotlist_synced_at` | `DateTime` | no | CDC: when the joined hotlist data was last synced. |
| `_peerdb_scan_synced_at` | `DateTime` | no | CDC: when the joined scan data was last synced. |

## Casts & enums

No `casts()` declared. Relevant enums referenced by scopes/columns:

- `hit_type` → `App\Enums\HitTypeEnum` (string).
- `hotlist_status` → `App\Enums\HotlistStatusEnum` (int).
- `disposition` → `App\Enums\DispositionEnum` (string).
- `integration_id` → `InsightLPR\BlackAdam\Enum\IntegrationEnum`.

## Relationships

Read-only analytical model with no Eloquent relationship methods. FKs (`hotlist_id`, `scan_id`, `kit_id`, `*_org_id`) reference their PostgreSQL counterparts by id and are queried by scope. Related concept/model pages: [Missed Opportunity](../../concepts/missed-opportunity.md), [hits_cold](hits_cold.md), [hotlist](hotlist.md).

## Data mapping (PostgreSQL ↔ ClickHouse)

- PostgreSQL lineage: same source tables as hits (`hits`, `scans`, `hotlist` in the `public` schema, `zod` database), CDC-mirrored to `zod.public_*` and denormalized into `clarity.missed_opportunities` via materialized views. This is a materialized table, not a view.
- CDC columns: `_peerdb_synced_at`, `_peerdb_hotlist_synced_at`, `_peerdb_scan_synced_at` (facet freshness), `_peerdb_is_deleted` (soft delete), `_version` (dedup version).

## Notable scopes & behavior

- **Global scope `FilterDeletedRecords`** (via `FiltersCdcDeletes`) — filters `_peerdb_is_deleted = 0`; bypass with `withDeleted()`.
- **No automatic `FINAL`** — apply `final()` / `scopeFinal()` where exact dedup matters.
- Tenant scoping: `forOrg($orgId)` filters `hotlist_org_id`.
- Metric scopes: `selectTotal()` (`countDistinct(hotlist_id)`), `groupByClient()`, `byMonth()` / `groupByClientAndMonth()` (uses `HasMonthGrouping::getMonthSelectSql('scanned_at')` for driver-aware month grouping).
- Filter scopes: `notNicb()`, `between($start,$end,$tz)`, `forCreatedBetween()`, `forBranch()`, `forClient()` / `forClients()`, `forLienholders()`, `forKits()`, `forVin()`, `forHitType()`, `forDispositions()` / `excludeDisposition()` (NULL-safe raw).
- Implements `MissedOpportunityFilterable`; has a `MissedOpportunityFactory`.

## See also

- [Missed Opportunity](../../concepts/missed-opportunity.md) — the domain concept and `hotlist_org_id` scoping
- [hits_cold](hits_cold.md) — the sibling hit table (same wide shape)
- [hotlist](hotlist.md) — the denormalized hotlist analytical table
- [ClickHouse Integration](../../concepts/clickhouse-integration.md), [Data Architecture](../../concepts/data-architecture.md)
