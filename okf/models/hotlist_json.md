---
type: DataModel
title: hotlist_json (HotlistJson)
description: Per-hotlist JSON sidecar holding structured extension data (e.g. branch assignment) for a hotlist entry, in the PostgreSQL zod database, public schema.
resource: app/Models/HotlistJson.php
tags: [model, postgres, public, zod, lpr, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# hotlist_json

A `hotlist_json` row is a 1:1 JSON sidecar for a [hotlist](../concepts/hotlist.md) entry. It stores structured extension data that lives outside the main `hotlist` table — currently a `branch_id` (the [zone](../concepts/zone.md)/branch a plate is assigned to), captured in the `json` column and typed by the `HotlistJsonData` DTO. The record is keyed by `hotlist_id` (there is no separate surrogate key), so each hotlist entry has at most one `hotlist_json` row.

It lives in **PostgreSQL**, the `zod` logical database, `public` schema, on the `zod` connection — one of the core LPR tables managed by the external `insight-lpr/laravel-zod-migrations` package. See [Data Architecture](../concepts/data-architecture.md).

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `hotlist_json`
- Model class: `App\Models\HotlistJson` (`app/Models/HotlistJson.php`)
- Primary key: `hotlist_id` (non-incrementing)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `hotlist_id` | bigint | no | Primary key and FK → `public.hotlist.id`. Ties this sidecar to exactly one hotlist entry. |
| `json` | jsonb | no | Structured extension payload, cast to the `HotlistJsonData` DTO. Currently holds `branch_id` (nullable) — the branch/zone the plate is assigned to. Defaults to `'{}'::jsonb`. |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp. |
| `updated_at` | timestamp(0) without time zone | yes | Last-update timestamp. |

## Casts & enums

- `json` → `App\Dto\HotlistJsonData` (Spatie LaravelData DTO with a single `?int $branch_id` field).

## Relationships

- **hotlist()** — belongsTo [hotlist](hotlist.md) via `hotlist_id`.

## Data mapping (PostgreSQL ↔ ClickHouse)

Part of the hotlist family that replicates via PeerDB CDC into ClickHouse. `public.hotlist` (plus `hotlist_json`, `hotlist_meta`, `hotlist_address`) → `zod.public_hotlist*` and denormalizes into `clarity.hotlist`. The `branch_id` carried in this sidecar's `json` feeds branch/zone attribution on the analytical side. See [ClickHouse Integration](../concepts/clickhouse-integration.md).

## See also

- [hotlist](hotlist.md) — the parent hotlist entry
- [hotlist_meta](hotlist_meta.md) — the other per-hotlist sidecar (financial/lifecycle metadata)
- [Hotlist concept](../concepts/hotlist.md)
- [Zone](../concepts/zone.md) — branches referenced by `branch_id`
- [Data Architecture](../concepts/data-architecture.md)
