---
type: DataModel
title: aggregate_address_geohash6 (AggregateAddressGeohash)
description: PostGIS geohash-level rollup of address density used to render address heat-map layers, in the PostgreSQL zod database, public schema.
resource: app/Models/AggregateAddressGeohash.php
tags: [model, postgres, public, aggregate, geo]
timestamp: 2026-07-06T00:00:00Z
---

# aggregate_address_geohash6

Each row is a **geohash-6 bucket** holding the count of addresses that fall within that geohash cell, plus the cell's PostGIS geometry. It is a pre-aggregated rollup used to draw address-density heat-map / contour layers on maps (weights bucketed by `count`, colored green-scale) without scanning the raw address table. It supports mapping/analytics rather than the core [scan](../concepts/scan.md) → [hit](../concepts/hit.md) → [recovery](../concepts/recovery.md) flow.

The model lives in **PostgreSQL** — logical database `zod`, `public` schema, `zod` connection. It carries no timestamps, no primary key, and no relationships; it is effectively a read-only aggregate table consumed by GeoJSON builder methods.

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `aggregate_address_geohash6`
- Model class: `App\Models\AggregateAddressGeohash` (`app/Models/AggregateAddressGeohash.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `geohash` | `text` | yes | The geohash-6 string identifying the map cell. Rows with null geohash are excluded from GeoJSON output. |
| `count` | `bigint` | yes | Number of addresses aggregated into this geohash cell. Drives the heat-map weight/color buckets (0=<10, 1=<50, 2=<100, 3=<400, else 4). |
| `geom` | `geometry` | yes | PostGIS geometry of the geohash cell (SRID 4326), declared as a Magellan PostGIS column. |

## Casts & enums

None declared. `geom` is registered as a PostGIS column (`type` `geometry`, SRID 4326) via the `HasPostgisColumns` trait rather than a standard cast.

## Relationships

None. This is a standalone aggregate table with no Eloquent relationships.

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (analytics/mapping aggregate, PostgreSQL-only). Derived from address data in the `zod` database; not part of the PeerDB `public.*` → `clarity.*` replication path.

## Notable scopes & behavior

- `$timestamps` inherit the framework default (not disabled here), but the table has no `created_at`/`updated_at` columns — treat it as a refreshed rollup, not application-written rows.
- `jsonObject()` and `geojsonQuery()` are static helpers that build heat-map GeoJSON: they bucket `count` into weight tiers and green color ramps and dump the cell polygon coordinates via `ST_Dump`/`ST_AsGeoJSON`.
- `toGeojson($bounds)` streams (via cursor) only rows with a non-null `geohash` and `count > 0`, optionally intersected against a bounds polygon (`ST_Intersects`), assigning a color per count band.

## See also

- [aggregate_address_heatmap.md](aggregate_address_heatmap.md) — the ZIP-level counterpart heat-map rollup
- [zcta5_all.md](zcta5_all.md) — census ZIP geometry reference used for ZIP-level heat maps
- [Data Architecture](../concepts/data-architecture.md)
