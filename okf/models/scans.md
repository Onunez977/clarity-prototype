---
type: DataModel
title: scans (Scan)
description: License-plate reads captured by kits — the atomic LPR record, in PostgreSQL zod database, public schema.
resource: app/Models/Scan.php
tags: [model, postgres, public, lpr, scan]
timestamp: 2026-07-06T00:00:00Z
---

# scans

A `scans` row is a single license-plate read captured by a physical LPR camera — the atomic unit of the Clarity system, from which every [hit](../concepts/hit.md), [recovery](../concepts/recovery.md), and analytic derives. It stores the OCR'd plate, the plate's US state, a PostGIS point for the capture location, the capturing [kit](../concepts/kit.md), the physical `camera_name`, and the scan timestamp. This is a transactional record living in **PostgreSQL**, database `zod`, schema `public`, on the `zod` connection. It is owned by the external `insight-lpr/laravel-zod-migrations` package and mirrored to ClickHouse (`clarity.scans_cold`) for analytics. See the conceptual page [scan](../concepts/scan.md) for domain background.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `public`
- Connection: `zod`
- Table: `scans`
- Model class: `App\Models\Scan` (`app/Models/Scan.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key (sequence `new_scans_id_seq`) |
| `ocr` | `character varying(12)` | no | OCR'd license-plate text |
| `us_state` | `character varying(2)` | no | US state of the plate (2-letter) |
| `geo` | `geometry(Point)` | no | PostGIS capture location (SRID 4326); accessed via `lat`/`lng`/`coordinates` accessors |
| `kit_id` | `integer` | no | FK → `public.kits.id` — the [kit](kits.md) (logged-in user) that captured the read; org is resolved through the kit |
| `is_visible` | `boolean` | no | Visibility flag; hidden scans are excluded by the `ShowVisibleScan` global scope. Default `true` |
| `scanned_at` | `timestamp without time zone` | no | When the read was captured; surfaced timezone-aware via the `scanned_at` accessor + `ScanTimezoneScope` |
| `created_at` | `timestamp without time zone` | no | Row insert time; accessor normalizes to UTC |
| `camera_name` | `character varying(64)` | no | The **actual physical device** that produced the read (e.g. a `GL…` camera), recorded per-scan rather than on the kit |
| `guid` | `uuid` | no | Globally unique scan identifier; used to resolve vehicle/plate image URLs |
| `meta` | `jsonb` | yes | Inline metadata blob; richer sidecar data lives in `scans_metadata` (`ScanMetaData`) |

## Casts & enums

- `meta` → `array`
- `is_visible` → `boolean`
- `scanned_at` → `datetime`
- `created_at` → `datetime`
- PostGIS: `geo` registered via `$postgisColumns` as `geometry` SRID 4326.

## Relationships

- **kit()** — belongsTo [Kit](kits.md) via `kit_id`.
- **org()** — hasOneThrough [Org](orgs.md) through `Kit` (`kit_id` → `kits.org_id`); the org↔scan bridge runs through the kit.
- **hits()** — hasMany [Hit](hits.md) via `scan_id`.
- **liveHits() / historicalHits() / stagingLiveHits() / stagingHistoricalHits()** — hasMany [Hit](hits.md) filtered by `hit_type` (`HitTypeEnum` L/H/S/Z respectively).
- **hotlist()** — hasOneThrough [Hotlist](hotlist.md) through `Hit` (`scan_id` → `hits.hotlist_id`).
- **scanMetaData()** — hasOne `ScanMetaData` via `scan_id` (1:1 sidecar; managed by DB triggers).

## Data mapping (PostgreSQL ↔ ClickHouse)

- Replicates via PeerDB CDC to `zod.public_scans` (adds `_peerdb_synced_at`, `_peerdb_is_deleted`, `_peerdb_version`).
- Denormalizes into `clarity.scans_cold` (plus `clarity.scans_hot` for recent data) for analytics. See [clickhouse-integration](../concepts/clickhouse-integration.md) and the ClickHouse model page [clickhouse/scans_cold.md](clickhouse/scans_cold.md) if present.

## Notable scopes & behavior

- **Global scope `ShowVisibleScan`** — hides rows where `is_visible = false`.
- **Global scope `ScanTimezoneScope`** — left-joins the PostGIS `timezones` polygon table (`ST_Within(scans.geo, timezones.geom)`) to compute local scan time; exposes `timezone` and drives the `scanned_at` accessor.
- `scopeForOrg($org_id, $withSharedKits)` — org-scopes by expanding the org's kit IDs (`Kit::getKits`, optionally including shared kits) and filtering `kit_id`. See [multi-tenancy](../concepts/multi-tenancy.md).
- `scopeCoordinates` — PostGIS `ST_DWithin` proximity filter on `geo`.
- `scopeForPlate` — delegates to `PlateFilter` (LIKE + optional FlexSearch on `ocr`).
- `scopeForDisposition` / `scopeExcludeDisposition` — JSON filters against a joined `scans_metadata.meta->disposition`.
- `scopeBetween` — `scanned_at` range (UTC-normalized).
- `$timestamps = false` (Eloquent does not manage `created_at`/`updated_at` automatically).
- Accessors: `lat`/`lng`/`latitude`/`longitude`/`coordinates` (from `geo`); `address`/`simpleAddress`/`disposition`/`aiState`/`vehicle*`/`isNotPlate` (from the `scanMetaData` sidecar); `plateImage`/`vehicleImage`/`images` (resolve S3 URLs by `guid`).
- `updateDisposition()` / `updateAddress()` mutate the `scanMetaData` sidecar and log activity.

## See also

- [scan](../concepts/scan.md) — conceptual background
- [kits](kits.md) — the capturing kit (org owner)
- [hits](hits.md) — matches derived from scans
- [orgs](orgs.md) — ownership through the kit
- [hotlist](hotlist.md) — matched hotlist entries
- [data-architecture](../concepts/data-architecture.md), [multi-tenancy](../concepts/multi-tenancy.md)
