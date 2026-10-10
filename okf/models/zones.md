---
type: DataModel
title: zones (Zone)
description: Operator-defined geographic zones (polygons/zip codes) for Scout, in PostgreSQL zod database, platform schema.
resource: app/Models/Zone.php
tags: [model, postgres, platform, scout, zone]
timestamp: 2026-07-06T00:00:00Z
---

# zones

A **zones** record is an operator-defined geographic area — built from GeoJSON polygons and/or zip-code (ZCTA) unions — used across [Scout](../concepts/scout.md) mapping and analytics. Zones drive coverage tessellation, zone-clipped heatmaps, no-scan enforcement, and per-user zone assignment. See the [Zone](../concepts/zone.md) concept page for how zones are used operationally. The polygon geometry is stored in a PostGIS `MultiPolygon` column (`geom`, SRID 4326) that is derived from the structured `definition` payload.

The record lives in the **PostgreSQL** `zod` database, `platform` schema, on the `platform` connection.

## Location

- **Database:** `zod` (PostgreSQL)
- **Schema:** `platform`
- **Connection:** `platform`
- **Table:** `zones`
- **Model class:** `App\Models\Zone` — `app/Models/Zone.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key (`zones_id_seq`). |
| `org_id` | integer | no | FK → `public.orgs.id`. Owning [Organization](../concepts/organization.md). |
| `name` | character varying(255) | no | Zone display name. |
| `description` | text | yes | Optional free-text description. |
| `color` | character varying(16) | no | Hex swatch color (default `#2563eb`); constrained to `PRESET_COLORS`, coerced via `coerceColor()`. No-scan zones render as `#9ca3af` (`NO_SCAN_COLOR`). |
| `definition` | jsonb | no | Structured source payload `{ polygons: [GeoJSON...], zip_codes: ["75201", ...] }`; `geom` is rebuilt from this. Cast to `array`. |
| `created_by_user_id` | bigint | yes | FK → `public.users.id`. User who created the zone. |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp. |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp. |
| `deleted_at` | timestamp(0) without time zone | yes | Soft-delete timestamp (`SoftDeletes`). |
| `geom` | public.geometry(MultiPolygon,4326) | yes | PostGIS MultiPolygon geometry (WGS84), rebuilt from `definition`. Hidden from default selects. |
| `is_no_scan_zone` | boolean | no | When true, the zone marks a no-scan area (default `false`). Cast to `boolean`. |

## Casts & enums

- `definition` → `array`
- `is_no_scan_zone` → `boolean`

## Relationships

- **org()** — belongsTo [Organization](../concepts/organization.md) (`Org`) via `org_id`.
- **creator()** — belongsTo `User` via `created_by_user_id`.
- **users()** — belongsToMany `User` through the `zone_user` pivot (with timestamps) — see [Scout user assignment](../concepts/scout-user-assignment.md).

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only). Zones are a Clarity Scout add-on table in the `platform` schema and are not part of the PeerDB `public.*` → `zod.public_*` CDC pipeline. Zone-scoped analytics are computed by clipping scan/heatmap data against zone geometry at query time.

## Notable scopes & behavior

- **`forOrg($orgId)`** query scope — filters to a single org (multi-tenancy).
- **`withGeoJson()`** scope — adds `geom_geojson` and `centroid_geojson` via schema-qualified `public.ST_AsGeoJSON(...)` (the `platform` connection's `search_path` is `platform`, not `public`, so PostGIS calls are `public.`-prefixed).
- **`withBbox()`** scope — exposes the geometry bounding box as `bbox_min_lng`/`bbox_min_lat`/`bbox_max_lng`/`bbox_max_lat` for geohash tessellation.
- **`$hidden = ['geom']`** — the raw binary geometry is excluded from default selects/serialization; opt in with `withGeoJson()`.
- **`rebuildGeometryFromDefinition()`** — regenerates `geom` from `definition.polygons` (GeoJSON) and `definition.zip_codes` (Tiger `tiger_data.zcta5_all`, reprojected NAD83→WGS84). Degenerate rings (<4 positions) are skipped.
- **`intersectingGeohashes()` / `searchZipCodes()`** — PostGIS helpers for coverage tessellation and zip autocomplete; the `ST_Intersects` clip is cached per org + zone set + geohash set for 5 minutes.
- **SoftDeletes** — deletes set `deleted_at` rather than removing the row.
- **Activity logging** (`LogsActivity`, log name `zone`) — records dirty changes to `name`, `description`, `color`, `is_no_scan_zone`, `definition`.

## See also

- [Zone](../concepts/zone.md)
- [Scout](../concepts/scout.md)
- [Scout user assignment](../concepts/scout-user-assignment.md)
- [Organization](../concepts/organization.md)
- [Multi-Tenancy](../concepts/multi-tenancy.md)
- [Data Architecture](../concepts/data-architecture.md)
