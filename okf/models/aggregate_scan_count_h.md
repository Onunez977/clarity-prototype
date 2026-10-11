---
type: DataModel
title: aggregate_scan_count_h (AggregateScanCountByHour)
description: Hourly rollup of scan counts per Kit, in the PostgreSQL zod database, public schema.
resource: app/Models/AggregateScanCountByHour.php
tags: [model, postgres, public, aggregate, lpr, scout]
timestamp: 2026-07-06T00:00:00Z
---

# aggregate_scan_count_h

Each row is the **count of [scans](../concepts/scan.md) captured by a [Kit](../concepts/kit.md) in a given hour**. It is a pre-aggregated rollup that powers activity/affiliate statistics (scans per hour, days active, hours with 50+ scans) without scanning the raw scans table. The `_h` suffix denotes the hourly grain; a `timeblock` integer encodes the hour bucket.

The model lives in **PostgreSQL** — logical database `zod`, `public` schema, `zod` connection. Timestamps are disabled; treat it as a refreshed aggregate table (it does carry a synthetic `id` sequence).

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `aggregate_scan_count_h`
- Model class: `App\Models\AggregateScanCountByHour` (`app/Models/AggregateScanCountByHour.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `timeblock` | `integer` | no | Integer-encoded hour bucket (computed by the `timeblock()` helper) the count applies to. |
| `kit_id` | `integer` | no | The [Kit](../concepts/kit.md) whose scans are counted. FK → `public.kits.id`. |
| `scan_count` | `integer` | no | Number of scans the kit captured in the hour. Defaults to `0`. |
| `id` | `bigint` | no | Synthetic surrogate key (sequence `aggregate_scan_count_h_seq`). |
| `scanned_at` | `timestamp without time zone` | yes | Human-readable timestamp of the hour bucket (used for day/hour grouping in reports). |

## Casts & enums

None declared.

## Relationships

- **kit()** — belongsTo [Kit](../concepts/kit.md) via the default `kit_id`
- **orgs()** — hasManyThrough [Org](../concepts/organization.md) through [Kit](../concepts/kit.md) (`Kit.id` → `kit_id`, `Org.id` → `Kit.org_id`); resolves the owning org(s) of the kit that produced the counts

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (rollup aggregate, PostgreSQL-only). Note that ClickHouse maintains its own scan aggregates (e.g. `clarity` scan rollups derived from `scans_cold`); this PostgreSQL table is a separate, independently maintained rollup and is not part of the PeerDB `public.*` → `clarity.*` path.

## Notable scopes & behavior

- `public $timestamps = false;` — no `created_at`/`updated_at`.
- `affiliateStatsReportQuery(Org $org, array $kit_ids, Carbon $start, Carbon $end)` builds the affiliate/kit-activity report: it joins `kits`, this table, and `aggregate_scan_count_unq_h` to compute per-kit total scans, unique scans, hours with ≥50 scans, and distinct active days between `timeblock($start)` and `timeblock($end)`.
- Org scoping in the report is done by filtering `kits.org_id`, reflecting that scans resolve their org through the kit (see [Multi-Tenancy](../concepts/multi-tenancy.md)).

## See also

- [aggregate_scan_count_unq_h.md](aggregate_scan_count_unq_h.md) — the unique-scan-count sibling used alongside this table
- [aggregate_kit_scan_path_h.md](aggregate_kit_scan_path_h.md) — hourly kit position rollup
- [Kit](../concepts/kit.md) · [Scan](../concepts/scan.md) · [Multi-Tenancy](../concepts/multi-tenancy.md)
