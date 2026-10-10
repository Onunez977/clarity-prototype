---
type: DomainEntity
title: Zone
description: A Scout patrol area — an org-scoped geographic region (polygons and/or ZIP codes) that drivers are assigned to, optionally flagged as a no-scan zone.
resource: app/Models/Zone.php
tags: [domain, scout, postgres, postgis, multi-tenancy]
timestamp: 2026-06-30T12:00:00Z
---

# Zone

A **Zone** is a named geographic area in [Scout](scout.md) that an org draws on a map and assigns to drivers. Zones are patrol areas: they give drivers context for where to scan, drive the zone-clipped heatmaps and per-zone shift roll-ups, and can be flagged as **no-scan zones** (areas drivers should avoid). A zone is always scoped to a single [Organization](organization.md).

## Definition

A zone carries display metadata (name, description, color), a no-scan flag, and a geometry **definition** — a structured payload of GeoJSON polygons and/or ZIP codes that is compiled into a PostGIS `MultiPolygon`. Drivers are linked to zones many-to-many through the `zone_user` pivot. Zones are soft-deleted, never hard-deleted.

## Key attributes / columns (`platform.zones`, PostgreSQL `platform` connection)

The `Zone` model uses `$connection = 'platform'` and `$table = 'zones'`, so the table is `platform.zones` (the `platform` schema of the `zod` database). Created by `database/migrations/2026_05_12_000000_create_zones_table.php`; `is_no_scan_zone` added by `2026_05_14_000000_add_is_no_scan_zone_to_zones_table.php`.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `org_id` | int | Owning [Organization](organization.md) — scoped at the app layer (no cross-schema FK) |
| `name` | string | Zone name |
| `description` | text, nullable | Optional description |
| `color` | string | Hex color from the constrained palette (default `#2563eb`) |
| `is_no_scan_zone` | bool | Marks an off-limits area; renderers read `color`, so no-scan zones persist gray (`#9ca3af`) |
| `definition` | jsonb (cast `array`) | `{ "polygons": [GeoJSON Polygon\|MultiPolygon, …], "zip_codes": ["75201", …] }` |
| `geom` | PostGIS MultiPolygon (SRID 4326) | Compiled geometry. **Hidden** from default selects/serialization (can be large) |
| `created_by_user_id` | int, nullable | FK to the creating [User](scout-user-assignment.md) |
| `created_at` / `updated_at` / `deleted_at` | timestamps | Soft-deletes (`SoftDeletes`) |

### Color palette

`Zone::PRESET_COLORS` is a fixed set so operators can't pick colors that fight with scan tracks: Blue `#2563eb` (also `DEFAULT_COLOR`), Teal `#0d9488`, Orange `#ea580c`, Purple `#9333ea`, Green `#16a34a`. No-scan zones use `Zone::NO_SCAN_COLOR` = `#9ca3af`. `Zone::coerceColor()` snaps legacy/free-form colors back to the palette.

## Relationships

- `Zone::org()` → `BelongsTo` [Organization](organization.md).
- `Zone::creator()` → `BelongsTo` [User](scout-user-assignment.md) via `created_by_user_id`.
- `Zone::users()` → `BelongsToMany` User via the **`platform.zone_user`** pivot (with timestamps). The inverse is `User::zones()`. Pivot created by `database/migrations/2026_05_12_000001_create_zone_user_table.php`.

## Business rules

- **Org-scoped.** Always filter with `scopeForOrg($orgId)`; tenancy is enforced in app code, not by a database FK (cross-schema convention — see [Multi-Tenancy](multi-tenancy.md)).
- **Geometry is derived, not authored directly.** Callers persist `definition`, then call `Zone::rebuildGeometryFromDefinition()` to (re)build `geom`. It runs `public.ST_GeomFromGeoJSON()` per polygon, reprojects ZIP (ZCTA5) geometries from NAD83 (SRID 4269) to WGS84 (SRID 4326), and unions them into a single `ST_Multi`. Degenerate rings (<4 positions) are skipped so a bad shape can't 500 the save.
- **A save is refused when any ZIP has no boundary (DEV-2252).** `rebuildGeometryFromDefinition()` returning a geometry is **not** sufficient evidence that the zone is correct: `ST_Union` drops NULL members, so one resolvable ZIP alongside one unresolvable ZIP still yields a valid, non-empty `geom` with the bad ZIP silently discarded (see [Platform Gotchas](platform-gotchas.md) PG-031). `PersistZone` (`app/Services/Zones/PersistZone.php`), the write path behind `ManageZones::save()` and Otto zone accept, therefore checks **two** things before committing: the rebuilt geometry is not `NULL`/`ST_IsEmpty`, **and** the ZIP lookup against `tiger_data.zcta5_all` — run **outside** the write transaction — returns empty. Either check failing aborts the save. On the map editor that raises a **persistent** danger notification naming the offending codes (*"No Census boundary found for ZIP code(s): …. Remove them, or draw the zone manually."*). Before this, the save reported "Zone saved" unconditionally and left a `geom = NULL` zone that was invisible on every map with nothing anywhere saying so.
- **The error needs somewhere to render.** `definition` is a `Hidden` component with no error slot and the page has no form-level error region, so a validation failure on it renders **nothing at all**. Surface zone-save rejections as a notification, not as a field error — a rejection the admin cannot see is worse on the edit path than the original bug.
- **ZIP input is exact-5-digit, client and server (DEV-2252).** `resources/js/components/zone-manager.js` validates tokens with `/^\d{5}$/` (previously `/^\d{3,5}$/`) and `addZip()` requires an exact match (it previously fell back to `rows[0]`). Both mattered because `Zone::searchZipCodes()` matches by **prefix** while `rebuildGeometryFromDefinition()` matches **exactly** — so a 4-digit fragment, the shape Excel produces when it strips a leading zero from an 0xxxx ZIP, used to resolve to a real but unrelated ZIP and attach the wrong geography. The prefix behaviour of `searchZipCodes()` is intentional and test-pinned; the reconciliation belongs client-side.
- **PostGIS calls are schema-qualified.** The `platform` connection's `search_path` is `platform`, not `public`, so geometry helpers call `public.ST_*` explicitly (`scopeWithGeoJson`, `scopeWithBbox`, `intersectingGeohashes`).
- **No-scan zones persist gray.** Renderers (Filament swatch, scanner map) read `color` directly, so the gray color is stored rather than computed at render time.
- **Soft-delete only.** `ZonePolicy::forceDelete()` always denies; deletes are reversible and notify previously-assigned drivers on delete and restore.
- **ADMIN-only.** `ZonePolicy` requires the ADMIN role for `viewAny`/`create`, and ADMIN + same-org for `view`/`update`/`delete`/`restore`. The whole resource is also Scout-feature-gated (see [Scout](scout.md)).

## Spatial query helpers

- `Zone::scopeWithGeoJson()` — adds `geom_geojson` / `centroid_geojson` for map rendering.
- `Zone::scopeWithBbox()` — exposes the geometry bounding box for geohash tessellation.
- `Zone::intersectingGeohashes($geohashes, $zoneIds, $orgId)` — one PostGIS round-trip returning the geohashes whose cell intersects the union of the given zones; cached 5 min per org + zone set + geohash set. Used by zone-clipped heatmaps and coverage tessellation. See [Scout real-time tracking](scout-real-time-tracking.md).
- `Zone::searchZipCodes($query, $limit)` — ZIP autocomplete for the map editor, querying `tiger_data.zcta5_all` on the `zod` connection (projected to WGS84).

## Where it lives in code

- Model: `app/Models/Zone.php` (PostgreSQL `platform` connection, `platform.zones`)
- Migrations: `database/migrations/2026_05_12_000000_create_zones_table.php`, `..._000001_create_zone_user_table.php`, `2026_05_14_000000_add_is_no_scan_zone_to_zones_table.php`
- Factory: `database/factories/ZoneFactory.php`
- Policy: `app/Policies/ZonePolicy.php`
- Filament list: `app/Filament/Resources/ZoneResource.php` (+ `Pages/ListZones.php`) — under the "Scout" nav group
- Filament map editor: `app/Filament/Pages/ManageZones.php` (deep-link only; `shouldRegisterNavigation()` is false)
- Zone write path: `app/Services/Zones/PersistZone.php` (map editor and Otto accept; refuses empty geometry and unresolved ZIPs)
- Filament actions: `app/Filament/Actions/ZoneReassignAction.php`, `app/Filament/Actions/ZoneQuickEditAction.php`
- Assignment / broadcast services: `app/Services/Zones/ZoneAssignmentSyncer.php`, `app/Services/Zones/ZoneBroadcaster.php`
- Geohash helper: `app/Support/Scout/Geohash.php`

## See also

- [Scout](scout.md) — the feature hub and gating
- [Scout user assignment](scout-user-assignment.md) — who can be assigned to a zone (kit-assigned users only) and the sync/broadcast flow
- [Scout real-time tracking](scout-real-time-tracking.md) — `ZoneUpdated` / `ZoneAssignmentsChanged` broadcasts and zone-clipped heatmaps
- [Multi-Tenancy](multi-tenancy.md) — org scoping
- [Data Architecture](data-architecture.md) — `platform` schema vs `public` / ClickHouse
