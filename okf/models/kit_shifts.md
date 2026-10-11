---
type: DataModel
title: kit_shifts (KitShift)
description: A single continuous activity window for a kit/driver in Scout, in PostgreSQL zod database, platform schema.
resource: app/Models/KitShift.php
tags: [model, postgres, platform, scout, kit]
timestamp: 2026-07-06T00:00:00Z
---

# kit_shifts

A **kit_shifts** record represents a single continuous activity window for a [Kit](../concepts/kit.md) / driver in [Scout](../concepts/scout.md). A shift opens when a driver starts working and closes either when the driver taps "End Shift" in the PWA (`end_type = confirmed`) or after at least three hours of GPS + scan inactivity detected by the scheduled close-inactive command (`end_type = assumed`). A partial unique index (`kit_shifts_one_open_per_kit`) guarantees each kit has at most one open (`ended_at IS NULL`) shift at a time. Shifts underpin [Scout shifts and trips](../concepts/scout-shifts-and-trips.md) reporting.

The record lives in the **PostgreSQL** `zod` database, `platform` schema, on the `platform` connection.

## Location

- **Database:** `zod` (PostgreSQL)
- **Schema:** `platform`
- **Connection:** `platform`
- **Table:** `kit_shifts`
- **Model class:** `App\Models\KitShift` — `app/Models/KitShift.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key (`kit_shifts_id_seq`). |
| `kit_id` | bigint | no | FK → `public.kits.id`. The [Kit](../concepts/kit.md) / driver this shift belongs to. |
| `org_id` | integer | no | FK → `public.orgs.id`. Owning [Organization](../concepts/organization.md); denormalized for org scoping. |
| `started_at` | timestamp(0) with time zone | no | When the shift opened. |
| `ended_at` | timestamp(0) with time zone | yes | When the shift closed; `NULL` while the shift is open. |
| `end_type` | character varying(16) | yes | Cast to `ShiftEndTypeEnum` — `confirmed` (driver tapped "End Shift") or `assumed` (auto-closed after inactivity). |
| `summary_sent_at` | timestamp(0) with time zone | yes | When the end-of-shift summary was sent. |
| `summary_skipped_reason` | character varying(64) | yes | Reason the shift summary was skipped, if any. |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp. |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp. |

## Casts & enums

- `started_at` → `datetime`
- `ended_at` → `datetime`
- `summary_sent_at` → `datetime`
- `end_type` → `App\Enums\ShiftEndTypeEnum` (string-backed: `confirmed` "Confirmed", `assumed` "Assumed").

## Relationships

- **kit()** — belongsTo [Kit](../concepts/kit.md) via `kit_id`.
- **org()** — belongsTo [Organization](../concepts/organization.md) (`Org`) via `org_id`.

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only). This is a Clarity Scout add-on table in the `platform` schema and is not part of the PeerDB `public.*` → `zod.public_*` CDC pipeline.

## Notable scopes & behavior

- **`isOpen()` / `isClosed()`** helpers — a shift is open when `ended_at === null`.
- Partial unique index `kit_shifts_one_open_per_kit` enforces at most one open shift per kit.
- Auto-close: a scheduled close-inactive command ends shifts after ~3 hours of GPS + scan inactivity with `end_type = assumed`.
- No soft deletes.

## See also

- [Kit](../concepts/kit.md)
- [Scout](../concepts/scout.md)
- [Scout shifts and trips](../concepts/scout-shifts-and-trips.md)
- [Scout real-time tracking](../concepts/scout-real-time-tracking.md)
- [kit_settings](kit_settings.md)
- [Organization](../concepts/organization.md)
