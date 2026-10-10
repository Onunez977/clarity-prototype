---
type: DataModel
title: hotlist_plate_log (PlateLog)
description: Append-only audit log of plate/state changes for hotlist entries, in the PostgreSQL zod database, public schema.
resource: app/Models/PlateLog.php
tags: [model, postgres, public, zod, lpr, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# hotlist_plate_log

A `hotlist_plate_log` row records a change to the license plate or state on a [hotlist](../concepts/hotlist.md) entry, capturing the old and new plate/state pair plus when the change occurred. It is an append-only audit trail: when a hotlisted plate is corrected or updated (e.g. an OCR fix or a re-registration), the prior and new values are logged here so the history is preserved even as the live `hotlist` row changes.

It lives in **PostgreSQL**, the `zod` logical database, `public` schema, on the `zod` connection — one of the core LPR tables managed by the external `insight-lpr/laravel-zod-migrations` package. See [Data Architecture](../concepts/data-architecture.md).

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `hotlist_plate_log`
- Model class: `App\Models\PlateLog` (`app/Models/PlateLog.php`)
- Timestamps: disabled (`$timestamps = false`); `created_at` is set by the database default.

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key (sequence `hotlist_plate_log_seq`). |
| `hotlist_id` | bigint | no | FK → `public.hotlist.id`. The hotlist entry whose plate changed. |
| `old_plate` | character varying(12) | no | Plate value before the change. |
| `old_state` | character varying(2) | no | US state (2-letter) before the change. |
| `new_plate` | character varying(12) | no | Plate value after the change. |
| `new_state` | character varying(2) | no | US state (2-letter) after the change. |
| `created_at` | timestamp without time zone | no | When the change was logged. Defaults to `CURRENT_TIMESTAMP`. |

## Relationships

The model declares no relationship methods, but each row references a [hotlist](hotlist.md) entry via `hotlist_id` (FK → `public.hotlist.id`).

## Notable scopes & behavior

- Append-only audit log; the model is annotated `@codeCoverageIgnore` and has no application-side timestamp management.

## Data mapping (PostgreSQL ↔ ClickHouse)

Part of the broader hotlist family. Whether this audit table is replicated to ClickHouse is not established here; treat it as a transactional-only audit trail unless a `zod.public_hotlist_plate_log` replica is confirmed. See [Data Architecture](../concepts/data-architecture.md).

## See also

- [hotlist](hotlist.md) — the entry whose plate/state changes are logged
- [Hotlist concept](../concepts/hotlist.md)
- [Data Architecture](../concepts/data-architecture.md)
