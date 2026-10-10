---
type: DataModel
title: scans_cold (App\Clickhouse\Models\Scan)
description: Denormalized ClickHouse analytical copy of every LPR scan (all-time), the read side for scan search, map, and kit/camera aggregation.
resource: app/Clickhouse/Models/Scan.php
tags: [model, clickhouse, clarity, lpr, scan, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# scans_cold

`scans_cold` is the denormalized, all-time analytical copy of every [scan](../../concepts/scan.md) — a single license-plate read captured by an LPR camera. Each row carries the OCR'd plate, geo coordinates, the capturing kit and its org, and reverse-geocoded address/disposition, so read-heavy surfaces (search, map, trips, analytics) serve wide rows without joining PostgreSQL. It lives in the **ClickHouse `clarity` database**, `clarity_clickhouse` connection, `SharedReplacingMergeTree` engine.

`scans_cold` holds the full history; the sibling `scans_hot` is the same shape restricted to roughly the last 90 days (TTL-trimmed) for fast recent-window queries, selected via `Scan::hot()` / `Scan::forWindow($start)`. This table is the **kit ↔ `camera_name` aggregate**: it denormalizes both the kit (logged-in user) and the physical `camera_name` per scan — see [Kit](../../concepts/kit.md). The transactional source of truth is the PostgreSQL `scans` table (`public` schema); see [ClickHouse Integration](../../concepts/clickhouse-integration.md) and [Data Architecture](../../concepts/data-architecture.md).

## Location

- Database: `clarity` (ClickHouse)
- Connection: `clarity_clickhouse`
- Table: `scans_cold` (sibling hot table: `scans_hot`)
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\Scan` — `app/Clickhouse/Models/Scan.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `Int64` | no | Scan id (mirrors PostgreSQL `scans.id`). |
| `kit_id` | `Int32` | no | FK → `public.kits.id` — the kit (logged-in user) that captured the scan. |
| `ocr` | `String` | no | OCR'd plate text. |
| `us_state` | `String` | no | US state read from the plate. |
| `camera_name` | `String` | no | Physical device that produced the read (per-scan, not per-kit). |
| `guid` | `UUID` | no | Globally unique scan identifier (resolves scan images via `scan_img()`). |
| `scanned_at` | `DateTime64(6)` | no | When the scan was captured (cast to `datetime`). |
| `created_at` | `DateTime64(6)` | no | When the scan record was created. |
| `lng` | `Float64` | no | Capture longitude (exposed via `longitude`). |
| `lat` | `Float64` | no | Capture latitude (exposed via `latitude`). |
| `geo` | `Point` | no | Geographic capture point (lat/lng). |
| `kit_name` | `String` | no | Denormalized kit name. |
| `org_id` | `Int32` | no | Owning org (resolved through the kit) — the tenant-scoping key (`forOrg()`). |
| `disposition` | `Nullable(String)` | yes | `DispositionEnum` value; `NULL` = untriaged. |
| `address` | `Nullable(String)` | yes | Reverse-geocoded capture address. |
| `_peerdb_synced_at` | `DateTime64(9)` | no | CDC: when the row was synced from PostgreSQL. |
| `_version` | `UInt64` | no | ReplacingMergeTree version key for dedup (higher wins). |
| `_peerdb_is_deleted` | `UInt8` | no | Soft-delete flag (0=active, 1=deleted). Always filter `= 0`. |

## Casts & enums

- `scanned_at` → `datetime`.
- `disposition` (column) → interpreted via `App\Enums\DispositionEnum`.

## Relationships

Read-only analytical model with no Eloquent relationship methods. `kit_id` and `org_id` reference their PostgreSQL counterparts by id and are queried by scope. Related concept/model pages: [scan](../../concepts/scan.md), [Kit](../../concepts/kit.md), [hits_cold](hits_cold.md).

## Data mapping (PostgreSQL ↔ ClickHouse)

- PostgreSQL source: `scans` (`public` schema, `zod` database). CDC-mirrored to `zod.public_scans`, then denormalized (joined with kit + org) into `clarity.scans_cold` / `clarity.scans_hot` via materialized views.
- The `_peerdb_*` columns are PeerDB CDC metadata: `_peerdb_synced_at` (sync time), `_peerdb_is_deleted` (soft delete), `_version` (dedup version).

## Notable scopes & behavior

- **Global scope `FilterDeletedRecords`** (via `FiltersCdcDeletes`) — filters `_peerdb_is_deleted = 0`; bypass with `withDeleted()`.
- **No automatic `FINAL`** — apply `final()` / `scopeFinal()` where exact dedup matters (async ReplacingMergeTree).
- Scopes: `forOrg($orgId)` (filters `org_id`), `forKit($kitId)`, `between($start,$end,$tz)` (timezone-aware, converts to UTC; defaults to the authed user's timezone), `forPlate()`, `forDisposition()` / `excludeDisposition()` (NULL-safe raw), `inBounds($south,$north,$west,$east)` for map viewport filtering.
- Accessors: `coordinates` (`{latitude, longitude}`), `latitude`/`longitude`, `plateImage`/`plateImageUrl`, `vehicleImage`/`carImageUrl`/`carImage`, `images`.
- Implements `ScanFilterable`; uses `ClustersByLocation` for map clustering; has a `ScanFactory`.
- `Scan::hot()` targets `scans_hot`; `Scan::forWindow($start)` picks hot vs cold by the 90-day threshold.

## See also

- [scan](../../concepts/scan.md) — the domain concept (notes the `scans_cold` mirror)
- [Kit](../../concepts/kit.md) — this table is the kit ↔ `camera_name` aggregate
- [hits_cold](hits_cold.md) — hits derived from scans
- [ClickHouse Integration](../../concepts/clickhouse-integration.md), [Data Architecture](../../concepts/data-architecture.md)
