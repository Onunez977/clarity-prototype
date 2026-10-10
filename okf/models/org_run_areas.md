---
type: DataModel
title: org_run_areas (OrgRunArea)
description: Per-organization geographic "run area" polygons materialized from input definitions — lives in the PostgreSQL zod database, platform schema.
resource: app/Models/OrgRunArea.php
tags: [model, postgres, platform, organization, geo, postgis]
timestamp: 2026-07-06T00:00:00Z
---

# org_run_areas

An `org_run_areas` record defines the geographic operating area ("run area") for an [organization](../concepts/organization.md). Each row pairs a set of input definitions (`inputs` JSON) with a materialized PostGIS `MultiPolygon` geometry that a Materializer process computes from those inputs. This is org-scoped configuration that describes *where* an org operates. See [multi-tenancy](../concepts/multi-tenancy.md).

This table lives in the **PostgreSQL `zod` database, `platform` schema, `platform` connection** (Clarity add-on tables). Ownership of columns is split: `org_id`/`inputs` are Backyard-owned and mass-assignable, while `geometry`/`source_vintage`/`materialized_at` are Clarity-owned and written by the Materializer via raw SQL. Because the `platform` connection's `search_path` is `platform`, PostGIS functions are called schema-qualified as `public.ST_*`.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform`
- Table: `org_run_areas`
- Model class: `App\Models\OrgRunArea` — `app/Models/OrgRunArea.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key |
| `org_id` | `integer` | no | FK → `public.orgs.id` — the [organization](../concepts/organization.md) this run area belongs to |
| `inputs` | `jsonb` | no | Input definitions the geometry is materialized from (default `[]`); cast to `array` |
| `source_vintage` | `character varying(255)` | yes | Version/vintage of the source data used to materialize the geometry (Clarity-owned) |
| `materialized_at` | `timestamp(0) with time zone` | yes | When the geometry was last materialized (Clarity-owned); cast to `datetime` |
| `created_at` | `timestamp(0) with time zone` | yes | Row creation time |
| `updated_at` | `timestamp(0) with time zone` | yes | Row update time |
| `geometry` | `public.geometry(MultiPolygon,4326)` | yes | Materialized PostGIS MultiPolygon (SRID 4326); binary EWKB, hidden from serialization (Clarity-owned) |

## Casts & enums

- `inputs` → `array`
- `materialized_at` → `datetime`

## Relationships

- **org()** — belongsTo [Org](../concepts/organization.md) via `org_id`.

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only). Run-area geometry is used for operational/geographic scoping in PostgreSQL and is not part of the analytical CDC surface.

## Notable scopes & behavior

- **`scopeWithGeoJson()`** — appends `public.ST_AsGeoJSON(geometry) AS geometry_geojson` so the polygon can be read as GeoJSON (exposed via the `geometry_geojson` read-only attribute). PostGIS calls are schema-qualified because the `platform` connection's `search_path` does not include `public`.
- `$hidden = ['geometry']` — the raw binary EWKB column is never serialized into API responses; consumers use `geometry_geojson` instead.
- Column ownership split: only `org_id`/`inputs` are in `$fillable`; `geometry`/`source_vintage`/`materialized_at` are written by the Materializer's raw SQL, not mass assignment.

## See also

- [Organization](../concepts/organization.md) — the org a run area belongs to
- [org_meta](org_meta.md) — another per-org sidecar table
- [Multi-Tenancy](../concepts/multi-tenancy.md) — org-scoped configuration
- [Zone](../concepts/zone.md) — related geographic scoping concept
