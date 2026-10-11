---
type: DataModel
title: aggregate_kit_scan_path_h (AggregateKitScanPathH)
description: Hourly rollup of a Kit's scan path (position over time) for map/trail rendering, in the PostgreSQL zod database, public schema.
resource: app/Models/AggregateKitScanPathH.php
tags: [model, postgres, public, aggregate, geo, lpr]
timestamp: 2026-07-06T00:00:00Z
---

# aggregate_kit_scan_path_h

Each row records a [Kit](../concepts/kit.md)'s aggregated geographic position at an hourly timestamp — a pre-computed "scan path" point used to draw where a kit traveled while capturing [scans](../concepts/scan.md). It is a rollup that avoids scanning raw scan rows to plot a kit's route/trail on a map. The `_h` suffix denotes the hourly grain.

The model lives in **PostgreSQL** — logical database `zod`, `public` schema, `zod` connection. It uses a composite key (`kit_id` + `timestamp`), is non-incrementing, and has timestamps disabled; treat it as a refreshed aggregate table.

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `aggregate_kit_scan_path_h`
- Model class: `App\Models\AggregateKitScanPathH` (`app/Models/AggregateKitScanPathH.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `kit_id` | `integer` | yes | The [Kit](../concepts/kit.md) whose path point this is. FK → `public.kits.id`. Part of the composite primary key. |
| `geo` | `geometry` | yes | PostGIS point/geometry (SRID 4326) of the kit's aggregated position for the hour, declared as a Magellan PostGIS column. |
| `timestamp` | `timestamp with time zone` | yes | The hour the position applies to. Part of the composite primary key. |

## Casts & enums

None declared. `geo` is registered as a PostGIS column (`type` `geometry`, SRID 4326) via the `HasPostgisColumns` trait.

## Relationships

None declared. `kit_id` conceptually references [Kit](../concepts/kit.md) but no Eloquent relationship method is defined.

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (mapping aggregate, PostgreSQL-only). Not part of the PeerDB `public.*` → `clarity.*` replication path.

## Notable scopes & behavior

- Composite primary key: `protected $primaryKey = ['kit_id', 'timestamp']`.
- `public $incrementing = false;` and `getIncrementing(): false` — no auto-increment id.
- `public $timestamps = false;` — no `created_at`/`updated_at`; the `timestamp` column is the aggregation grain, not a row-write time.

## See also

- [Kit](../concepts/kit.md) — the entity whose path is rolled up
- [Scan](../concepts/scan.md) — the underlying reads that produce the path
- [aggregate_scan_count_h.md](aggregate_scan_count_h.md) — hourly scan-count rollup per kit
- [Data Architecture](../concepts/data-architecture.md)
