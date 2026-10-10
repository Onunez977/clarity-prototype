---
type: DataModel
title: aggregate_scan_count_unq_h (AggregateScanCountUniqueByHour)
description: Hourly rollup of unique scan counts per Kit, in the PostgreSQL zod database, public schema.
resource: app/Models/AggregateScanCountUniqueByHour.php
tags: [model, postgres, public, aggregate, lpr, scout]
timestamp: 2026-07-06T00:00:00Z
---

# aggregate_scan_count_unq_h

Each row is the **count of unique [scans](../concepts/scan.md) (distinct plates) captured by a [Kit](../concepts/kit.md) in a given hour**. It is the unique-count companion to [aggregate_scan_count_h](aggregate_scan_count_h.md): where that table counts all scans, this one counts distinct plates, so activity reports can distinguish raw throughput from unique coverage. The `_h`/`unq` suffixes denote the hourly grain and uniqueness.

The model lives in **PostgreSQL** — logical database `zod`, `public` schema, `zod` connection. Timestamps are disabled; treat it as a refreshed aggregate table.

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `aggregate_scan_count_unq_h`
- Model class: `App\Models\AggregateScanCountUniqueByHour` (`app/Models/AggregateScanCountUniqueByHour.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `timeblock` | `integer` | no | Integer-encoded hour bucket (from the `timeblock()` helper) the count applies to. |
| `kit_id` | `integer` | no | The [Kit](../concepts/kit.md) whose unique scans are counted. FK → `public.kits.id`. |
| `scan_count` | `integer` | no | Number of unique (distinct-plate) scans the kit captured in the hour. Defaults to `0`. |
| `id` | `bigint` | no | Synthetic surrogate key (sequence `aggregate_scan_count_unq_h_seq`). |
| `scanned_at` | `timestamp without time zone` | yes | Human-readable timestamp of the hour bucket. |

## Casts & enums

None declared.

## Relationships

- **kit()** — belongsTo [Kit](../concepts/kit.md) via the default `kit_id`

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (rollup aggregate, PostgreSQL-only). Not part of the PeerDB `public.*` → `clarity.*` path.

## Notable scopes & behavior

- `public $timestamps = false;` — no `created_at`/`updated_at`.
- Consumed by `AggregateScanCountByHour::affiliateStatsReportQuery()`, which sums `scan_count` from this table (filtered by `timeblock` range) to produce the per-kit "unique scans" metric alongside total scans.

## See also

- [aggregate_scan_count_h.md](aggregate_scan_count_h.md) — the all-scans hourly rollup this table complements
- [Kit](../concepts/kit.md) · [Scan](../concepts/scan.md) · [Multi-Tenancy](../concepts/multi-tenancy.md)
