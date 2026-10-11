---
type: DataModel
title: hits_cold (App\Clickhouse\Models\Hit)
description: Denormalized ClickHouse analytical table of every LPR hit (all-time), the read side for hit stats, Scout surfaces, and client analytics.
resource: app/Clickhouse/Models/Hit.php
tags: [model, clickhouse, clarity, lpr, hit, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# hits_cold

`hits_cold` is the denormalized, all-time analytical copy of every [hit](../../concepts/hit.md) — a match between a [scan](../../concepts/scan.md) and a [hotlist](../../concepts/hotlist.md) entry. Each row folds together the hit, the scan that produced it, the hotlist order it matched, and the owning organizations, so heavy read paths (Scout, the map, recent-hits, client analytics) can serve a single wide row without joining across PostgreSQL. It lives in the **ClickHouse `clarity` database**, `clarity_clickhouse` connection, `SharedReplacingMergeTree` engine.

`hits_cold` holds the full history. Its sibling `hits_hot` is the same shape restricted to roughly the last 90 days (TTL-trimmed) for faster recent-window queries; the model exposes both via `Hit::hot()` and the base model's `Hit::forWindow($start)` selector. The transactional source of truth is the PostgreSQL `hits` table (`public` schema); see [ClickHouse Integration](../../concepts/clickhouse-integration.md) and [Data Architecture](../../concepts/data-architecture.md).

## Location

- Database: `clarity` (ClickHouse)
- Connection: `clarity_clickhouse`
- Table: `hits_cold` (sibling hot table: `hits_hot`)
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\Hit` — `app/Clickhouse/Models/Hit.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `Int64` | no | Hit id (mirrors PostgreSQL `hits.id`). |
| `hotlist_id` | `Int64` | no | FK → `public.hotlist.id` — the hotlist order this hit matched. |
| `scan_id` | `Int64` | no | FK → `public.scans.id` — the scan that produced the hit. |
| `hit_type` | `LowCardinality(String)` | no | `HitTypeEnum` value: `L`=Live, `H`=Historical, `S`=Staging Live, `Z`=Staging Historical, `M`=Missed Opportunity, `F`=Missed Opportunity Spotted & Closed, `P`=Probable Match. |
| `hit_created_at` | `DateTime` | no | When the hit record was created. |
| `ocr` | `String` | no | OCR'd plate text from the scan. |
| `scanned_at` | `DateTime` | no | When the underlying scan was captured. |
| `camera_name` | `String` | no | Physical device that captured the scan (per-scan, not per-kit — see [Kit](../../concepts/kit.md)). |
| `scan_us_state` | `LowCardinality(String)` | no | US state read from the plate. |
| `scan_guid` | `UUID` | no | Globally unique scan identifier (used to resolve scan images). |
| `kit_id` | `Int32` | no | FK → `public.kits.id` — the kit (logged-in user) that captured the scan. |
| `kit_name` | `String` | no | Denormalized kit name. |
| `kit_org_id` | `Int32` | no | Org that owns the capturing kit. |
| `hotlist_us_state` | `Nullable(String)` | yes | US state on the matched hotlist entry. |
| `hotlist_plate` | `Nullable(String)` | yes | Plate on the matched hotlist entry. |
| `hotlist_status` | `Nullable(Int32)` | yes | `HotlistStatusEnum` value (0=Other,1=Open,2=Hold,3=Repossessed,4=ClosedPositive,5=Closed,90=NewFromClient,99=Invalid). |
| `hotlist_vin` | `Nullable(String)` | yes | VIN on the matched hotlist entry. |
| `hotlist_org_id` | `Int32` | no | Org that owns the hotlist order — the tenant-scoping key (`forOrg()` filters on this). |
| `remote_order_id` | `Nullable(String)` | yes | External integration order id. |
| `integration_id` | `Nullable(Int32)` | yes | `IntegrationEnum` source integration (e.g. NICB, filtered out by `notNicb()`). |
| `client_org_id` | `Nullable(Int32)` | yes | Client org associated with the order. |
| `branch_id` | `UInt32` | no | Branch scope for the hotlist order. |
| `lienholder_org_id` | `Nullable(Int32)` | yes | Lienholder org associated with the order. |
| `order_date` | `Nullable(Date)` | yes | Order placement date. |
| `repo_date` | `Nullable(Date)` | yes | Repossession date, if repossessed. |
| `close_date` | `Nullable(Date)` | yes | Order close date, if closed. |
| `client_org_name` | `Nullable(String)` | yes | Denormalized client org name. |
| `lienholder_org_name` | `Nullable(String)` | yes | Denormalized lienholder org name. |
| `_peerdb_synced_at` | `DateTime` | no | CDC: when the hit row was synced from PostgreSQL. |
| `_peerdb_hotlist_synced_at` | `DateTime` | no | CDC: when the joined hotlist data was last synced. |
| `_peerdb_scan_synced_at` | `DateTime` | no | CDC: when the joined scan data was last synced. |
| `_version` | `UInt64` | no | ReplacingMergeTree version key for dedup (higher wins). |
| `_peerdb_is_deleted` | `UInt8` | no | Soft-delete flag (0=active, 1=deleted). Always filter `= 0`. |
| `vehicle_year` | `Nullable(String)` | yes | Vehicle year from the hotlist order. |
| `vehicle_make` | `Nullable(String)` | yes | Vehicle make from the hotlist order. |
| `vehicle_model` | `Nullable(String)` | yes | Vehicle model from the hotlist order. |
| `lat` | `Float64` | no | Scan latitude (exposed via `latitude` accessor). |
| `lng` | `Float64` | no | Scan longitude (exposed via `longitude` accessor). |
| `address` | `Nullable(String)` | yes | Reverse-geocoded scan address. |
| `disposition` | `Nullable(String)` | yes | `DispositionEnum` value; `NULL` means the untriaged "New Hit" state. Drives the canonical valid-hit rule (`validDisposition()`). |

## Casts & enums

This model declares no `casts()`; enum values are stored as their raw scalar types and interpreted at query time. Relevant enums referenced by scopes/columns:

- `hit_type` → `App\Enums\HitTypeEnum` (string).
- `hotlist_status` → `App\Enums\HotlistStatusEnum` (int).
- `disposition` → `App\Enums\DispositionEnum` (string; `NULL` = New Hit).
- `integration_id` → `InsightLPR\BlackAdam\Enum\IntegrationEnum`.

## Relationships

This is a read-only analytical model with no Eloquent relationship methods. Foreign keys (`hotlist_id`, `scan_id`, `kit_id`, `*_org_id`) reference their PostgreSQL counterparts by id but are queried by scope, not eager-loaded. Related concept/model pages: [hit](../../concepts/hit.md), [scan](../../concepts/scan.md) / [scans_cold](scans_cold.md), [hotlist](../../concepts/hotlist.md) / [hotlist](hotlist.md).

## Data mapping (PostgreSQL ↔ ClickHouse)

- PostgreSQL source: `hits` (`public` schema, `zod` database). CDC-mirrored to `zod.public_hits`, then denormalized (joined with scan, hotlist, kit, and org data) into `clarity.hits_cold` / `clarity.hits_hot` via materialized views.
- Related aggregates derived from the same source: `clarity.hotlist_status_aggregate` and `clarity.client_historical_hits_hourly`.
- The `_peerdb_*` columns are PeerDB CDC metadata: `_peerdb_synced_at` (sync time), `_peerdb_is_deleted` (soft delete), `_version` (dedup version). `_peerdb_hotlist_synced_at` / `_peerdb_scan_synced_at` track the freshness of the denormalized hotlist/scan facets.

## Notable scopes & behavior

- **Global scope `FilterDeletedRecords`** (via `FiltersCdcDeletes`) — filters `_peerdb_is_deleted = 0` automatically; bypass with `withDeleted()`.
- **No automatic `FINAL`** on this model — because the ReplacingMergeTree dedup is asynchronous, callers should apply `final()` / `scopeFinal()` where exact dedup matters.
- Tenant scoping: `forOrg($orgId)` filters `hotlist_org_id`; `forKitOrg($orgId)` filters `kit_org_id`.
- Disposition rules: `validDisposition()` = `NEW_HIT` (NULL) or `VALID` (the canonical DEV-2080 rule); `liveValid()` = Live hits passing that rule; `forDispositions()` / `excludeDisposition()` handle NULL-safe raw filtering.
- Other scopes: `notNicb()`, `forKits()`, `forHitType()`/`forHitTypes()`, `forStatus()` (folds `Other` into not-in Open/Repossessed), `forPlate()`, `forVin()`, `forOrderId()`, `forClients()`, `forLienholders()`, `forCreatedBetween()`, `forScannedBetween()`.
- Accessors: `latitude`/`longitude` alias `lat`/`lng`. Uses `ClustersByLocation` for map clustering.
- `Hit::hot()` targets `hits_hot`; `Hit::forWindow($start)` picks hot vs cold by the 90-day threshold.

## See also

- [hit](../../concepts/hit.md) — the domain concept
- [scans_cold](scans_cold.md) — the scan analytical table
- [hotlist](hotlist.md) — the denormalized hotlist analytical table
- [missed_opportunities](missed_opportunities.md) — sibling shape scoped by `hotlist_org_id`
- [ClickHouse Integration](../../concepts/clickhouse-integration.md), [Data Architecture](../../concepts/data-architecture.md)
