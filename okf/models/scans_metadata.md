---
type: DataModel
title: scans_metadata (ScanMetaData)
description: 1:1 sidecar of derived attributes for a scan (vehicle color/make/type, plate state, JSON meta), in PostgreSQL zod database, public schema.
resource: app/Models/ScanMetaData.php
tags: [model, postgres, public, lpr, scan]
timestamp: 2026-07-06T00:00:00Z
---

# scans_metadata

A **scans_metadata** record is the 1:1 sidecar for a [Scan](../concepts/scan.md), holding derived and AI-inferred attributes — a JSON `meta` blob plus flattened vehicle color / make / type, whether the image is a plate, and the plate's state. It is keyed by `scan_id` (the primary key, non-incrementing) and carries no timestamps. The `Scan` concept page notes that this metadata is managed by database triggers rather than application code.

The record lives in the **PostgreSQL** `zod` database, `public` schema, on the `zod` connection — alongside the core LPR `scans` table.

## Location

- **Database:** `zod` (PostgreSQL)
- **Schema:** `public`
- **Connection:** `zod`
- **Table:** `scans_metadata`
- **Model class:** `App\Models\ScanMetaData` — `app/Models/ScanMetaData.php`
- **Primary key:** `scan_id` (non-incrementing); `$timestamps = false`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `scan_id` | bigint | no | Primary key and FK → `public.scans.id`. The [Scan](../concepts/scan.md) this metadata describes. |
| `meta` | jsonb | no | JSON metadata blob (default `{}`); cast to `ScanMetaDataDto` via `ScanMetaDataCast`. |
| `vehicle_color` | character varying | yes | Inferred vehicle color. |
| `vehicle_make` | character varying | yes | Inferred vehicle make. |
| `vehicle_type` | character varying | yes | Inferred vehicle type. |
| `is_plate` | boolean | yes | Whether the detected object is a license plate. Cast to `boolean`. |
| `plate_state` | character varying | yes | Inferred plate US state. |

## Casts & enums

- `meta` → `App\Casts\ScanMetaDataCast` (hydrates a `App\Dto\ScanMetaDataDto`).
- `is_plate` → `boolean`

## Relationships

- **scan()** — belongsTo [Scan](../concepts/scan.md) via `scan_id` (1:1 sidecar).

## Data mapping (PostgreSQL ↔ ClickHouse)

`public.scans_metadata` replicates via PeerDB CDC to ClickHouse `zod.public_scans_metadata` (adding `_peerdb_synced_at`, `_peerdb_is_deleted`, `_peerdb_version`). Downstream, scan attributes are denormalized into the `clarity.scans_cold` / `scans_hot` analytical tables alongside the core scan fields. Confirm the exact join/denormalization against live ClickHouse before relying on it — see [ClickHouse integration](../concepts/clickhouse-integration.md).

## Notable scopes & behavior

- Non-incrementing primary key: `$primaryKey = 'scan_id'`; `$timestamps = false` (no `created_at`/`updated_at`).
- **Managed by database triggers** — per the [Scan](../concepts/scan.md) concept page, metadata is written by DB triggers, not by application code.
- No soft deletes, no global scopes.

## See also

- [Scan](../concepts/scan.md)
- [scan_validations](scan_validations.md)
- [Data Architecture](../concepts/data-architecture.md)
- [ClickHouse integration](../concepts/clickhouse-integration.md)
