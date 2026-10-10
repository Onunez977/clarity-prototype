---
type: DataModel
title: aggregate_address_heatmap (AggregateAddressHeatMap)
description: PostGIS ZIP-code-level rollup of address counts vs. population used to render address-density heat maps, in the PostgreSQL zod database, public schema.
resource: app/Models/AggregateAddressHeatMap.php
tags: [model, postgres, public, aggregate, geo]
timestamp: 2026-07-06T00:00:00Z
---

# aggregate_address_heatmap

Each row is a **ZIP-code polygon** carrying its state, census population, land area, aggregated address count, and PostGIS geometry. It is a pre-aggregated rollup used to draw ZIP-level address-density heat maps, where the map weight is computed from the ratio of addresses to population. It supports mapping/analytics rather than the core [scan](../concepts/scan.md) → [hit](../concepts/hit.md) → [recovery](../concepts/recovery.md) flow.

The model lives in **PostgreSQL** — logical database `zod`, `public` schema, `zod` connection. Timestamps are disabled; it is effectively a refreshed aggregate table consumed by a GeoJSON builder method.

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `aggregate_address_heatmap`
- Model class: `App\Models\AggregateAddressHeatMap` (`app/Models/AggregateAddressHeatMap.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `zip_code` | `character varying` | yes | The ZIP (ZCTA5) code the polygon represents. |
| `state` | `character varying` | yes | US state for the ZIP. |
| `population` | `integer` | yes | Census population for the ZIP; denominator in the address-density weight calculation. |
| `area` | `double precision` | yes | Land area for the ZIP. |
| `addresses` | `bigint` | yes | Count of addresses aggregated into the ZIP; numerator in the density weight. |
| `geom` | `geometry` | yes | PostGIS polygon geometry of the ZIP (SRID 4326), declared as a Magellan PostGIS column. |

## Casts & enums

None declared. `geom` is registered as a PostGIS column (`type` `geometry`, SRID 4326) via the `HasPostgisColumns` trait.

## Relationships

- **address()** — belongsTo `Address` via the default `address_id` foreign key. (Note: no `address_id` column exists on this aggregate table, so this relationship is effectively vestigial; the model is keyed by ZIP, not address.)

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (analytics/mapping aggregate, PostgreSQL-only). Not part of the PeerDB `public.*` → `clarity.*` replication path.

## Notable scopes & behavior

- `public $timestamps = false;` — no `created_at`/`updated_at`; treat as a refreshed rollup.
- `getGeospatialJson()` is a static helper that builds heat-map GeoJSON per ZIP: it simplifies the polygon (`ST_Simplify`), dumps the contour coordinates, and computes a weight tier (0–4) from `(addresses / nullif(population,0)) * 100`, grouping by ZIP attributes.

## See also

- [aggregate_address_geohash6.md](aggregate_address_geohash6.md) — the geohash-cell counterpart rollup
- [zcta5_all.md](zcta5_all.md) — census ZIP geometry reference used to build ZIP heat-map data
- [Data Architecture](../concepts/data-architecture.md)
