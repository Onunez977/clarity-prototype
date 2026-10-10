---
type: DataModel
title: kit_settings (KitSettings)
description: Per-kit Scout configuration (vehicle type, associated user), one row per kit, in PostgreSQL zod database, platform schema.
resource: app/Models/KitSettings.php
tags: [model, postgres, platform, scout, kit]
timestamp: 2026-07-06T00:00:00Z
---

# kit_settings

A **kit_settings** record holds per-[Kit](../concepts/kit.md) Scout configuration — chiefly the kind of vehicle the kit drives and the user currently associated with the kit. It is a 1:1 sidecar to a kit, keyed by `kit_id` (the primary key is `kit_id`, not an auto-increment `id`). This table is a Clarity add-on used by [Scout](../concepts/scout.md) real-time tracking and shift/trip features, not part of the core LPR ingest tables.

The record lives in the **PostgreSQL** `zod` database, `platform` schema, on the `platform` connection.

## Location

- **Database:** `zod` (PostgreSQL)
- **Schema:** `platform`
- **Connection:** `platform`
- **Table:** `kit_settings`
- **Model class:** `App\Models\KitSettings` — `app/Models/KitSettings.php`
- **Primary key:** `kit_id` (non-incrementing, int)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `kit_id` | bigint | no | Primary key and FK → `public.kits.id`. The [Kit](../concepts/kit.md) this configuration belongs to. |
| `user_id` | bigint | yes | FK → `public.users.id`. The user associated with the kit (see [Scout user assignment](../concepts/scout-user-assignment.md)). |
| `vehicle_type` | character varying(32) | yes | Cast to `VehicleTypeEnum` — `scanning_car`, `repo_truck`, or `flatbed`. |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp. |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp. |

## Casts & enums

- `vehicle_type` → `App\Enums\VehicleTypeEnum` (string-backed: `scanning_car` "Scanning Car", `repo_truck` "Repo Truck", `flatbed` "Flatbed").

## Relationships

- **kit()** — belongsTo [Kit](../concepts/kit.md) via `kit_id`.
- **user()** — belongsTo `User` via `user_id`.

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only). This is a Clarity add-on configuration table in the `platform` schema and is not part of the PeerDB `public.*` → `zod.public_*` CDC pipeline.

## Notable scopes & behavior

- Non-incrementing primary key: `$incrementing = false`, `$keyType = 'int'`, `$primaryKey = 'kit_id'`. Insert with an explicit `kit_id`.
- `$table` is set to `platform.kit_settings` on the model (schema-qualified) on the `platform` connection.
- No soft deletes, no global scopes.

## See also

- [Kit](../concepts/kit.md)
- [Scout](../concepts/scout.md)
- [Scout user assignment](../concepts/scout-user-assignment.md)
- [kit_shifts](kit_shifts.md)
- [Data Architecture](../concepts/data-architecture.md)
