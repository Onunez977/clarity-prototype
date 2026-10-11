---
type: DataModel
title: kits (Kit)
description: A logged-in LPR user/device account that captures scans and inherits org ownership, in PostgreSQL zod database, public schema.
resource: app/Models/Kit.php
tags: [model, postgres, public, lpr, kit]
timestamp: 2026-07-06T00:00:00Z
---

# kits

A `kits` row is the account/session that was logged in when scans were captured — the ownership bridge between a [scan](../concepts/scan.md) and its [organization](../concepts/organization.md). Every scan carries a `kit_id`, and the scan's org is resolved through the kit's `org_id`. Note the important distinction: the kit is the **logged-in user**, whereas the physical device that produced a read is recorded per-scan in `scans.camera_name`. This is a transactional record living in **PostgreSQL**, database `zod`, schema `public`, on the `zod` connection, owned by the external `insight-lpr/laravel-zod-migrations` package. See the conceptual page [kit](../concepts/kit.md).

## Location

- Database: `zod` (PostgreSQL)
- Schema: `public`
- Connection: `zod`
- Table: `kits`
- Model class: `App\Models\Kit` (`app/Models/Kit.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `integer` | no | Primary key (sequence `kits_id_seq`) |
| `org_id` | `integer` | no | FK → `public.orgs.id` — owning [org](orgs.md); scans inherit this |
| `kit_mapping` | `citext` | no | Case-insensitive external mapping/identifier for the kit |
| `kit_type` | `integer` | no | Kit type discriminator |
| `name` | `character varying(64)` | no | Kit display name (masked to "Commercial" for cross-org viewers via `getCameraName()`) |
| `text_phone` | `character varying(18)` | yes | Phone number for text alerts |
| `fixed_latitude` | `double precision` | yes | Fixed-position latitude (for stationary kits) |
| `fixed_longitude` | `double precision` | yes | Fixed-position longitude (for stationary kits) |

## Casts & enums

The model defines no `$casts`. The `vehicle_type` accessor resolves through the related `KitSettings` and returns a `VehicleTypeEnum` (ScanningCar/RepoTruck/Flatbed).

## Relationships

- **org()** — belongsTo [Org](orgs.md) via `org_id`.
- **settings()** — hasOne `KitSettings` via `kit_id` (holds `vehicle_type`, assigned user).
- **scans()** — hasMany [Scan](scans.md) via `kit_id`.
- **scan()** — hasMany [Scan](scans.md) (deprecated alias of `scans()`).

## Data mapping (PostgreSQL ↔ ClickHouse)

- Replicates via PeerDB CDC to `zod.public_kits` (adds `_peerdb_synced_at`, `_peerdb_is_deleted`, `_peerdb_version`) and is used to denormalize kit name/org onto scan and hit analytics tables in `clarity.*`. No dedicated `clarity` fact table of its own; mapping otherwise not established. See [clickhouse-integration](../concepts/clickhouse-integration.md).

## Notable scopes & behavior

- `scopeForOrg($org_id)` — filters `org_id`. See [multi-tenancy](../concepts/multi-tenancy.md).
- `getKits($org_id, $withDeleted, $withShared)` — static, cached (hourly) list of a org's kit `id`/`name`; optionally excludes soft-deleted kits (via `kit_metadata.deleted_at`) and merges shared kits. This is the source `Scan::scopeForOrg` uses to expand an org into its kit IDs.
- `getSharedKits($org_id)` — kits shared to the org via the `Shared` pivot (`shared_type = Kit`, `shared_with_type = Org`).
- `getCameraName($org_id_viewing_kit)` — returns `"Commercial"` when the viewer's org differs from the kit's `org_id`, else the kit name (cross-org privacy masking).
- `geospatial()` / `geospatialGeoJsonObject()` — static builders producing per-kit GeoJSON scan paths (color-cycled by `kit_id % 42`).
- `$timestamps = false`.

## See also

- [kit](../concepts/kit.md) — conceptual background (kit vs. camera distinction)
- [scans](scans.md) — captured by kits (device is `camera_name`)
- [orgs](orgs.md) — owning organization
- [hits](hits.md)
- [data-architecture](../concepts/data-architecture.md), [multi-tenancy](../concepts/multi-tenancy.md)
