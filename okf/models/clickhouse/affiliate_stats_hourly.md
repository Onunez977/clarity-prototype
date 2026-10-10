---
type: DataModel
title: affiliate_stats_hourly (AffiliateStatsHourly)
description: ClickHouse ReplacingMergeTree of per-kit hourly scan and unique-plate counts, replicated from PostgreSQL and used for affiliate/kit scan-activity analytics.
resource: app/Clickhouse/Models/AffiliateStatsHourly.php
tags: [model, clickhouse, clarity, scan, kit, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# affiliate_stats_hourly

`affiliate_stats_hourly` holds per-[kit](../../concepts/kit.md) hourly [scan](../../concepts/scan.md) counts and distinct-plate counts. It powers affiliate/kit scan-activity analytics — e.g. total scans, unique plates, active hours (hours with ≥ 50 scans), and active days for a kit over a window. The counts are stored as plain `UInt64` columns, deduped by the `ReplacingMergeTree` engine (queried with `FINAL`), so no merge-state finalization is needed. See [ClickHouse Integration](../../concepts/clickhouse-integration.md).

> **Caution — `unique_scans` here is NOT period-distinct plates.** `affiliateStatsQuery()`'s
> `unique_scans` rollup **sums** per-hour `unique_plates`, so a plate seen in two hours counts
> twice. That is a deliberate, long-shipped definition for this report, but it is **not** what
> "unique scans" means elsewhere in Clarity: the canonical reading is one plate counted once
> across the whole reporting window (see [Scan → Business rules](../../concepts/scan.md#business-rules),
> confirmed on DEV-2467). DEV-2466 §3 measured the gap at **1.556x** (org 1271, Jul 2026:
> 11,070,941 summed vs 7,115,302 period-distinct). Any new surface wanting period-distinct plates
> must compute `uniqExact(ocr)` over the window against `clarity.scans_cold` — this table cannot
> answer that question.

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `affiliate_stats_hourly`
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\AffiliateStatsHourly`
- Source: `app/Clickhouse/Models/AffiliateStatsHourly.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `org_id` | `Int32` | no | Owning organization. FK → `public.orgs.id`. Filtered by `forOrg()`. |
| `kit_id` | `Int32` | no | The kit (logged-in user/device) that produced the scans. FK → `public.kits.id`. Model `$primaryKey`. |
| `scan_hour` | `DateTime` | no | Hour bucket (UTC) for the counts. |
| `scan_count` | `UInt64` | no | Total scans by the kit in that hour. The analytics query takes `max(scan_count)` per `(kit_id, scan_hour)` before summing. |
| `unique_plates` | `UInt64` | no | Distinct plates scanned by the kit in that hour. **Hour-scoped — do not sum across hours to get period-distinct plates** (see caution below). |
| `_peerdb_synced_at` | `DateTime64(9)` | no | PeerDB CDC sync timestamp carried from the replicated source. |
| `_version` | `UInt64` | no | `ReplacingMergeTree` version column used to keep the latest row on merge. |

## Casts & enums

None declared.

## Relationships

No Eloquent relationships are defined. The `affiliateStatsQuery()` helper joins to `clarity.kits` (with `FINAL` and `_peerdb_is_deleted = 0`) by id to pull the kit `name`.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Derives from PostgreSQL `public.scans` aggregated per kit/hour: `public.scans` → `zod.public_scans` (CDC mirror) → the denormalized `clarity.affiliate_stats_hourly`. See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md). `_peerdb_synced_at` is inherited; `_version` drives `ReplacingMergeTree` dedup.

## Notable scopes & behavior

- **`forOrg($orgId)`** — filters `org_id` (primary tenant scope; see [Multi-Tenancy](../../concepts/multi-tenancy.md)).
- **`affiliateStatsQuery(int $orgId, array $kitIds, Carbon $start, Carbon $end)`** — static builder that runs a raw sub-select `FROM clarity.affiliate_stats_hourly FINAL`, taking `max(scan_count)`/`max(unique_plates)` per `(kit_id, scan_hour)`, then rolling up to `scan_total`, `unique_scans`, `total_hours` (`countIf(hourly_scans >= 50)`), and `total_days` (`uniqExact(scan_day)`), inner-joined to `clarity.kits FINAL … _peerdb_is_deleted = 0` for the kit name, filtered `HAVING scan_total > 0`. Optional `kitIds` narrows to specific kits.
- **`ReplacingMergeTree` reads** — always use `FINAL` to collapse duplicate versions; count columns read directly (no merge-state functions).

## See also

- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines and PeerDB CDC
- [Kit](../../concepts/kit.md) — the kit (logged-in user) that captures scans
- [Scan](../../concepts/scan.md) — the atomic read being counted
- [kits](kits.md) — the ClickHouse `clarity.kits` mirror joined for kit names
- [Multi-Tenancy](../../concepts/multi-tenancy.md) — `forOrg()` scoping
