---
type: DataModel
title: zcta5_all (ZipCode5All)
description: US Census TIGER ZCTA5 (5-digit ZIP) geometry reference table, in the PostgreSQL zod database, tiger_data schema.
resource: app/Models/Tiger/ZipCode5All.php
tags: [model, postgres, tiger_data, geo, reference]
timestamp: 2026-07-06T00:00:00Z
---

# zcta5_all

Each row is a **US Census TIGER ZCTA5 area** — a 5-digit ZIP Code Tabulation Area with its state FIPS code, ZIP code, land/water area, interior point, and PostGIS multipolygon geometry. It is a static geographic reference table (loaded from Census TIGER data) used to resolve addresses to ZIP polygons and to build ZIP-level heat maps. It supports geospatial analytics rather than the core LPR flow directly.

The model lives in **PostgreSQL** — logical database `zod`, `tiger_data` schema (the PostGIS TIGER geocoder data schema), `zod` connection. Keyed by `gid`, timestamps disabled, fully guarded (read-only reference data).

## Location

- Database: `zod`
- Schema: `tiger_data`
- Connection: `zod`
- Table: `tiger_data.zcta5_all`
- Model class: `App\Models\Tiger\ZipCode5All` (`app/Models/Tiger/ZipCode5All.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `gid` | `integer` | no | Primary key (sequence `tiger.zcta5_gid_seq`). |
| `statefp` | `character varying(2)` | no | State FIPS code. |
| `zcta5ce` | `character varying(5)` | no | The 5-digit ZCTA / ZIP code (joins addresses on `postcode`). |
| `classfp` | `character varying(2)` | yes | Census classification FIPS code. |
| `mtfcc` | `character varying(5)` | yes | MAF/TIGER Feature Class Code. |
| `funcstat` | `character varying(1)` | yes | Functional status code. |
| `aland` | `double precision` | yes | Land area (square meters); converted to square miles in heat-map queries. |
| `awater` | `double precision` | yes | Water area (square meters). |
| `intptlat` | `character varying(11)` | yes | Interior point latitude (as string). |
| `intptlon` | `character varying(12)` | yes | Interior point longitude (as string). |
| `partflg` | `character varying(1)` | yes | Part flag. |
| `the_geom` | `geometry` | yes | PostGIS multipolygon geometry of the ZIP area. |

## Casts & enums

None declared.

## Relationships

- **addresses()** — hasMany [Address] via `postcode` (local key `zcta5ce`); links a ZIP polygon to the addresses whose postcode falls in it

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (static geographic reference data, PostgreSQL/PostGIS-only). Not part of the PeerDB `public.*` → `clarity.*` replication path.

## Notable scopes & behavior

- `protected $guarded = ['*'];` — fully guarded; treat as read-only reference data.
- `public $timestamps = false;`.
- `withShape(string $shape)` scope — selects each sub-geometry of the multipolygon as GeoJSON via `ST_GeometryN`/`generate_series`.
- `getHeatMapData()` static helper — builds ZIP-level heat-map GeoJSON: counts related addresses per ZIP, converts `aland` to square miles, and collects contour coordinates (`ST_Collect`/`ST_CollectionExtract`); it feeds the same ZIP heat-map layer served by [aggregate_address_heatmap](aggregate_address_heatmap.md).

## See also

- [aggregate_address_heatmap.md](aggregate_address_heatmap.md) — ZIP-level address-density rollup that consumes this reference
- [aggregate_address_geohash6.md](aggregate_address_geohash6.md) — geohash-cell density rollup
- [timezones.md](timezones.md) — the sibling PostGIS reference table (timezone polygons)
- [Data Architecture](../concepts/data-architecture.md)
