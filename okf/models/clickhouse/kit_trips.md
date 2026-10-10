---
type: DataModel
title: kit_trips (KitTrip)
description: ClickHouse ReplacingMergeTree of per-kit driving trips with geometry (LineString paths, start/end points, speed), refresh-built (not CDC) and consumed by Scout trip surfaces.
resource: app/Clickhouse/Models/KitTrip.php
tags: [model, clickhouse, clarity, kit, scout, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# kit_trips

`kit_trips` holds one row per driving trip taken by a [kit](../../concepts/kit.md), reconstructed from that kit's [scans](../../concepts/scan.md): trip window, duration, point count, geometry (full and simplified `LineString` paths, start/end `Point`), and average/max speed. It is the read side behind the Scout trip surfaces — see [Scout: Shifts and Trips](../../concepts/scout-shifts-and-trips.md). Per the model's own docs, this table is **refresh-built rather than CDC-replicated**, so it has **no `_peerdb_is_deleted` column** and does not use `FiltersCdcDeletes`; instead it applies a global `FINAL` scope to collapse `ReplacingMergeTree` versions. See [ClickHouse Integration](../../concepts/clickhouse-integration.md).

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `kit_trips`
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\KitTrip`
- Source: `app/Clickhouse/Models/KitTrip.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `kit_id` | `Int32` | no | The kit that drove the trip. FK → `public.kits.id`. Filtered by `forKit()`. |
| `org_id` | `Int32` | no | Owning organization. FK → `public.orgs.id`. Filtered by `forOrg()`. |
| `kit_mapping` | `LowCardinality(String)` | no | External kit mapping identifier. |
| `gl` | `LowCardinality(String)` | no | Camera / device identifier (the `GL…` camera) associated with the trip. |
| `trip_date` | `Date` | no | Trip date; filtered by `onDate()`, `betweenDates()`, `before()`. |
| `trip_start` | `DateTime` | no | Trip start timestamp (UTC). |
| `trip_end` | `DateTime` | no | Trip end timestamp (UTC). |
| `trip_start_local` | `DateTime` | no | Trip start in the kit's local timezone. |
| `trip_end_local` | `DateTime` | no | Trip end in the kit's local timezone. |
| `duration_minutes` | `UInt32` | no | Trip length in minutes. |
| `point_count` | `UInt32` | no | Number of GPS/scan points that composed the trip. |
| `path` | `LineString` | no | Full trip path geometry. |
| `path_simplified` | `LineString` | no | Simplified path geometry; decoded to `[lng, lat]` pairs by `withCoordinates()`. |
| `avg_speed` | `Float32` | no | Average speed over the trip. |
| `max_speed` | `Float32` | no | Maximum speed over the trip. |
| `start_point` | `Point` | no | Trip start location. |
| `end_point` | `Point` | no | Trip end location. |
| `_version` | `UInt64` | no | `ReplacingMergeTree` version column (keep latest on merge). |
| `_refreshed_at` | `DateTime64(3)` | no | When the row was last rebuilt by the refresh job (this table is refresh-built, not CDC). |

## Casts & enums

None declared. Geometry columns are ClickHouse geo types (`LineString`, `Point`), not PostGIS.

## Relationships

No Eloquent relationships are defined; `kit_id` / `org_id` are plain id columns.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Derived (refresh-built) from PostgreSQL scan data: `public.scans` → `zod.public_scans` (CDC mirror) → the `clarity.kit_trips` / `clarity.kit_scan_paths` trip aggregations. See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md).
- Not a CDC mirror — there is no `_peerdb_*` triple; `_version` + `_refreshed_at` track rebuild versions instead.

## Notable scopes & behavior

- **`ApplyFinal` global scope** — added in `booted()`, so every query is executed with `FINAL` to collapse `ReplacingMergeTree` versions. Callers do not hand-roll `final: true`.
- **`forOrg($orgId)`** — filters `org_id` (primary tenant scope; see [Multi-Tenancy](../../concepts/multi-tenancy.md)).
- **`forKit($kitId)`** — optional `when()` filter on `kit_id`.
- **`onDate($date)`** / **`betweenDates($start, $end)`** / **`before($date)`** — filter `trip_date`.
- **`withCoordinates()`** — selects `arrayMap(p -> [tupleElement(p,1), tupleElement(p,2)], path_simplified) AS coordinates`, the `[lng, lat]` shape consumed by `ClarityScoutTripsController::parseCoordinates` / `calculateMiles`.

## See also

- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines; note refresh-built vs CDC
- [Scout: Shifts and Trips](../../concepts/scout-shifts-and-trips.md) — the trip surfaces this feeds
- [Kit](../../concepts/kit.md) — the kit that drives the trip
- [kits](kits.md) — the ClickHouse kit mirror
- [Multi-Tenancy](../../concepts/multi-tenancy.md) — `forOrg()` scoping
