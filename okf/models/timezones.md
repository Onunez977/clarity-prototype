---
type: DataModel
title: timezones (Timezone)
description: PostGIS timezone polygon reference table used to compute local scan time, in the PostgreSQL zod database, public schema.
resource: app/Models/Timezone.php
tags: [model, postgres, public, geo, reference]
timestamp: 2026-07-06T00:00:00Z
---

# timezones

Each row is a **timezone polygon** — an IANA timezone id (`tzid`) paired with a PostGIS multipolygon covering the geographic area that observes it. It is a static geographic reference table used to resolve a scan's capture point to its local timezone. This directly underpins the [Scan](../concepts/scan.md) timezone global scope, which joins these polygons to compute local scan time (see [Data Architecture](../concepts/data-architecture.md)).

The model lives in **PostgreSQL** — logical database `zod`, `public` schema, `zod` connection. Keyed by `gid`, timestamps disabled; read-only reference geometry.

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `timezones`
- Model class: `App\Models\Timezone` (`app/Models/Timezone.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `gid` | `integer` | no | Primary key (sequence `timezones_gid_seq`). |
| `tzid` | `character varying(80)` | yes | IANA timezone identifier (e.g. `America/Chicago`). |
| `geom` | `geometry(MultiPolygon,4326)` | yes | PostGIS multipolygon of the timezone's area (SRID 4326); the working geometry joined against scan points. |
| `original_geom` | `geometry` | yes | The original (unmodified) source geometry retained alongside the working `geom`. |

## Casts & enums

None declared. `geom` is registered as a PostGIS column (`type` `geometry`, SRID 4326) via the `$postgisColumns` configuration.

## Relationships

None declared.

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (static geographic reference data, PostgreSQL/PostGIS-only). Not part of the PeerDB `public.*` → `clarity.*` replication path.

## Notable scopes & behavior

- `public $timestamps = false;` — no `created_at`/`updated_at`.
- Read-only reference table: the application queries it (spatially joining scan points into these polygons) rather than writing to it.
- The [Scan](../concepts/scan.md) model's `ScanTimezoneScope` global scope depends on this table to render scan timestamps in local time.

## See also

- [Scan](../concepts/scan.md) — consumer via the timezone global scope
- [Data Architecture](../concepts/data-architecture.md) — timezone-aware scan time
- [zcta5_all.md](zcta5_all.md) — the sibling PostGIS reference table (census ZIP polygons)
