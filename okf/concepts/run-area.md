---
type: DomainEntity
title: Run Area
description: An organization's operating region — a PostGIS boundary authored in Backyard (state/MSA/polygon inputs) and compiled in Clarity, used to clip Scout heatmaps and density queries to where the org actually works.
resource: app/Models/OrgRunArea.php
tags: [run-area, postgis, scout, geo, multi-tenancy, backyard]
timestamp: 2026-07-06T00:00:00Z
---

# Run Area

A **run area** is the geographic region an [Organization](organization.md) actually operates in. It is a compiled PostGIS boundary used to **clip density/heatmap queries** so a repo org only sees orders/hits inside its territory (rather than the whole viewport). Authorship is split across two apps: **Backyard authors the inputs**, **Clarity compiles the geometry** (DEV-1760).

## Storage

One row per org in `platform.org_run_areas` — **PostgreSQL `zod` database, `platform` schema, `platform` connection**. `Org::runArea()` is a `HasOne`. Full column detail is on the model page [org_run_areas](../models/org_run_areas.md); the shape that matters here:

- `inputs` (jsonb) — **Backyard-owned**, the definition (below). Only `org_id` + `inputs` are mass-assignable.
- `geometry` (`public.geometry(MultiPolygon, 4326)`, GIST-indexed, hidden) + `source_vintage` + `materialized_at` — **Clarity-owned**, written by the Materializer via raw SQL, never mass-assigned.

Because the `platform` connection's `search_path` is `platform` (not `public`), PostGIS is called schema-qualified as `public.ST_*` — the same convention as [Zone](zone.md).

## The `inputs` definition

`inputs` is an array of entries, each a `kind` plus kind-specific fields:

| `kind` | Field | Meaning |
|--------|-------|---------|
| `state` | `reference` | US state abbreviation (e.g. `TX`) |
| `msa` | `reference` | CBSA (metro-area) FIPS code |
| `polygon` | `geometry` | An inline GeoJSON Polygon |

## Materialization (`app/Services/RunArea/Materializer.php`)

`Materializer::materialize(OrgRunArea)` turns `inputs` into the `geometry` MultiPolygon:

- `state` → join `tiger_data.state_all` on `stusps`, reproject NAD83 (4269) → WGS84 (4326).
- `msa` → join `tiger_data.tl_2019_us_county` on `cbsafp`, reproject 4269 → 4326.
- `polygon` → `public.ST_GeomFromGeoJSON()`, pinned to SRID 4326.

The pieces are `ST_Union`'d and cleaned to a valid MultiPolygon (`public.ST_Multi(public.ST_CollectionExtract(public.ST_MakeValid(…), 3))`), then written with `source_vintage = 'tiger:2019'` and `materialized_at = now()`. It runs off the queue via `app/Jobs/Org/MaterializeOrgRunArea.php` (`{low}`), which no-ops when `inputs` is empty.

### Materialization refuses rather than stamping over nothing (DEV-2252)

`materialize()` previously had a silent-failure hole: recognized inputs that resolved to nothing produced a NULL union, and the row was still **stamped as materialized over that NULL** — so a re-materialize could replace a previously-good boundary with nothing, permanently and invisibly. It now throws `RuntimeException` in three cases, all **inside** the `platform` transaction so the rollback preserves the prior geometry and leaves `materialized_at` untouched:

1. **A recognized `kind` yields no piece** — e.g. a `polygon` entry whose GeoJSON is malformed. Collected as `$malformed` and reported with the entry's index.
2. **The union is `NULL` or `ST_IsEmpty`** — nothing resolved at all.
3. **Any individual reference did not resolve**, even though the union is non-empty. This is the case an emptiness check cannot catch: `ST_Union` **drops** NULL members, so a valid polygon alongside an unresolvable `state:TX` still yields a perfectly good geometry that silently omits the state. `unresolvedReferences()` re-queries `tiger_data.state_all` / `tiger_data.tl_2019_us_county` per reference with an explicit `whereNotNull(<geometry column>)` — because a TIGER row **can exist with a NULL geometry** — and the throw names each missing reference as `state:TX` / `msa:19100`. See [Platform Gotchas](platform-gotchas.md) PG-031.

Two consequences worth knowing before touching this:

- **The `whereNotNull` on `simplified_geom` is a live behaviour change for mixed inputs.** Nothing in this repo populates `simplified_geom` on `tiger_data.state_all` (`StateSeeder` adds that column to `tiger.state`, a *different* table, with no backfill), and it was unverifiable from a development environment. If it is empty in production, a **mixed** polygon + `state` run area moves from silently under-clipping to a loud `failed_jobs` entry. A `state`-**only** run area already threw via the emptiness guard, so only the mixed case changes.
- **The happy path is not covered by PHPUnit and cannot be.** Every `state`/`msa` branch reprojects 4269 → 4326, and `ST_Transform` blocks on the test harness's lock on `public.spatial_ref_sys` ([Platform Gotchas](platform-gotchas.md) PG-032). The tests pin the *throw* paths; that the guard stays **quiet** on a valid polygon-only run area is verified in browser QA instead.

## Usage — heatmap clipping

The order-density heatmap clips to the run area. `ClarityScoutMapController` / `ScoutMapHelpers::loadRunAreaForClip($orgId)` reads the geometry via `OrgRunArea::scopeWithGeoJson()`, extracts the outer rings, and passes them as `_run_area_rings` to `HotlistAddressGeohashQuery`, which filters cells with `pointInPolygon(...)`. That runs on `clarity_clickhouse` (which carries `validate_polygons=0` so a non-simple ring can't throw the query empty). With no materialized run area, density falls back to the viewport bbox. See [Scout real-time tracking](scout-real-time-tracking.md).

## Ownership (cross-repo)

- **Backyard** owns the UI to pick states/MSAs/polygons and writes the `inputs` JSON (DEV-1760). There is **no Clarity Filament surface** and **no feature flag** — a run area is simply active once its row has non-empty `inputs`.
- **Clarity** ships the `org_run_areas` migration and compiles/serves the geometry. See [Repositories](repositories.md).

## Where it lives in code

- Model: `app/Models/OrgRunArea.php` (`platform.org_run_areas`); migration `database/migrations/2026_05_28_000000_create_org_run_areas_table.php`
- Materializer / job: `app/Services/RunArea/Materializer.php`, `app/Jobs/Org/MaterializeOrgRunArea.php`
- Heatmap clip: `app/Http/Controllers/Api/Concerns/ScoutMapHelpers.php`, `app/Clickhouse/Queries/HotlistAddressGeohashQuery.php`
- Org relation: `app/Models/Org.php` (`runArea()`)

## See also

- [org_run_areas](../models/org_run_areas.md) — the model/table reference (columns, casts, scopes)
- [Zone](zone.md) — the sibling Scout PostGIS pattern (geometry rebuilt from a `definition`)
- [Scout real-time tracking](scout-real-time-tracking.md) — the geohash heatmaps the run area clips
- [Organization](organization.md) — the org a run area scopes
- [Repositories](repositories.md) — Backyard (writes `inputs`) vs Clarity (compiles geometry)
