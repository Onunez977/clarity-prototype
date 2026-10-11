---
type: DataModel
title: hotlist_meta (HotlistMeta)
description: Per-hotlist financial and lifecycle metadata sidecar (order/repo/close dates, balances, reason) for a hotlist entry, in the PostgreSQL zod database, public schema.
resource: app/Models/HotlistMeta.php
tags: [model, postgres, public, zod, lpr, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# hotlist_meta

A `hotlist_meta` row carries the financial and lifecycle metadata for a [hotlist](../concepts/hotlist.md) entry: order/repossession/close dates, monthly payments, account balance, amount past due, and a free-text reason/note. It is a sidecar to the main `hotlist` table, keyed by `hotlist_id`, and gives recovery agents the account context behind why a plate is on the hotlist.

It lives in **PostgreSQL**, the `zod` logical database, `public` schema, on the `zod` connection — one of the core LPR tables managed by the external `insight-lpr/laravel-zod-migrations` package. See [Data Architecture](../concepts/data-architecture.md).

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `hotlist_meta`
- Model class: `App\Models\HotlistMeta` (`app/Models/HotlistMeta.php`)
- Timestamps: disabled (`$timestamps = false`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key (sequence `hotlist_meta_id_seq`). |
| `hotlist_id` | bigint | yes | FK → `public.hotlist.id`. The hotlist entry this metadata describes. |
| `order_date` | date | no | Date the account/order was placed. Defaults to current timestamp. Cast to `date`. |
| `repo_date` | date | yes | Repossession date, when applicable. Cast to `date`. |
| `close_date` | date | yes | Date the account was closed/resolved. Cast to `date`. |
| `monthly_payments` | numeric(10,2) | yes | Contracted monthly payment amount. |
| `balance_on_account` | numeric(10,2) | yes | Outstanding balance on the account. |
| `amount_past_due` | numeric(10,2) | yes | Amount currently past due. |
| `additional_info` | text | yes | Free-text note/reason; surfaced through the `reason` accessor. |

## Casts & enums

- `order_date` → `date`
- `repo_date` → `date`
- `close_date` → `date`

## Relationships

- **hotlist()** — belongsTo [hotlist](hotlist.md) via `hotlist_id` (references `hotlist.id`).

## Notable scopes & behavior

- **`reason` accessor/mutator** — a virtual `reason` attribute that reads from and writes to `additional_info`, so callers can treat the note as the "reason" a plate is hotlisted.
- **Fillable:** `additional_info`, `close_date`, `order_date`.

## Data mapping (PostgreSQL ↔ ClickHouse)

Part of the hotlist family that replicates via PeerDB CDC into ClickHouse. `public.hotlist` (plus `hotlist_json`, `hotlist_meta`, `hotlist_address`) → `zod.public_hotlist*` and denormalizes into `clarity.hotlist`. See [ClickHouse Integration](../concepts/clickhouse-integration.md).

## See also

- [hotlist](hotlist.md) — the parent hotlist entry
- [hotlist_json](hotlist_json.md) — the other per-hotlist sidecar (JSON extension data)
- [Hotlist concept](../concepts/hotlist.md)
- [Recovery](../concepts/recovery.md) — account resolution the lifecycle dates track
- [Data Architecture](../concepts/data-architecture.md)
