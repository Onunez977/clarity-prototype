---
type: DataModel
title: recovery_kit_scan_report (RecoveryKitScanReport & RecoveryKitLeaderboard)
description: ClickHouse ReplacingMergeTree joining recoveries to per-kit scan counts; read by two models — RecoveryKitScanReport (per-recovery) and RecoveryKitLeaderboard (per-kit ranking).
resource: app/Clickhouse/Models/RecoveryKitScanReport.php
tags: [model, clickhouse, clarity, recovery, kit, aggregate, client-analytics]
timestamp: 2026-07-06T00:00:00Z
---

# recovery_kit_scan_report

`recovery_kit_scan_report` joins each [recovery](../../concepts/recovery.md) to the [kit](../../concepts/kit.md) whose [scans](../../concepts/scan.md) contributed to it, with per-kit and total scan counts plus a hotlist-scan percentage. It is the read side behind recovery-by-kit reporting and kit "leaderboard" rankings in Client Analytics — see [Client Analytics](../../concepts/client-analytics.md) and [ClickHouse Integration](../../concepts/clickhouse-integration.md). It replaces the older `aggregate_recovery_kit_scans` naming. The model sets `$table = 'clarity.recovery_kit_scan_report'` with the database prefix baked in.

**Two models read this one table** for different purposes:

- **`RecoveryKitScanReport`** (`$primaryKey = 'recovery_id'`) — the per-recovery view: one logical entry per recovery, listing which kit is credited and its scan counts.
- **`RecoveryKitLeaderboard`** (`extends RecoveryKitScanReport`, overriding `$primaryKey = 'kit_id'`) — the per-kit ranking view: the same rows keyed by kit for leaderboard/ranking rollups. It inherits every scope, cast, and global scope from its parent; only the primary key differs.

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `clarity.recovery_kit_scan_report` (set explicitly on the model, prefix included)
- Engine: `SharedReplacingMergeTree`
- Model classes:
  - `App\Clickhouse\Models\RecoveryKitScanReport` — source `app/Clickhouse/Models/RecoveryKitScanReport.php` (`primaryKey = recovery_id`)
  - `App\Clickhouse\Models\RecoveryKitLeaderboard` — source `app/Clickhouse/Models/RecoveryKitLeaderboard.php` (subclass; `primaryKey = kit_id`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `recovery_id` | `Int64` | no | The recovery. FK → `platform.recoveries.id`. `RecoveryKitScanReport` primary key. |
| `kit_id` | `Int32` | no | The kit credited for the recovery's scans. FK → `public.kits.id`. `RecoveryKitLeaderboard` primary key; filtered by `forKits()`. |
| `org_id` | `Int32` | no | Owning organization. FK → `public.orgs.id`. Filtered by `forOrg()`. |
| `kit_name` | `String` | no | Denormalized kit name for display. |
| `vin` | `Nullable(String)` | yes | Recovered vehicle VIN, if known. |
| `plate` | `Nullable(String)` | yes | Recovered vehicle plate, if known. |
| `status` | `Nullable(Int32)` | yes | Recovery/hotlist status code (integer; interpret against the recovery/hotlist status enums). |
| `repo_date` | `Date` | no | Repossession date; filtered by `between()`. |
| `kit_scan_count` | `UInt64` | no | Scans by this kit that matched the recovery's hotlist. |
| `total_scan_count` | `UInt64` | no | Total scans associated with the recovery across all kits. |
| `hotlist_scan_percentage` | `Float64` | no | Share of scans that were hotlist matches (`kit_scan_count` relative to the total). |
| `_peerdb_synced_at` | `DateTime64(3)` | no | PeerDB CDC sync timestamp. |
| `_version` | `UInt64` | no | `ReplacingMergeTree` version column (keep latest on merge). |
| `_peerdb_is_deleted` | `UInt8` | no | CDC soft-delete flag (0 = active, 1 = deleted). **Always filter `= 0`** (handled by `FiltersCdcDeletes`). |

## Casts & enums

None declared. `status` is a raw `Nullable(Int32)` status code (no Eloquent cast on the ClickHouse model).

## Relationships

No Eloquent relationships are defined; `recovery_id` / `kit_id` / `org_id` are plain id columns joined in query classes.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Derives from the Clarity-owned `platform.recoveries` table joined to scan data: `platform.recoveries` → `clarity.recoveries` and scan aggregates → `clarity.recovery_kit_scan_report`. See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md). The PostgreSQL transactional recovery model is at [../recoveries.md](../recoveries.md).
- Carries the standard PeerDB triple (`_peerdb_synced_at`, `_peerdb_is_deleted`) plus a `_version` column for `ReplacingMergeTree` dedup.

## Notable scopes & behavior

- **`ApplyFinal` global scope** — added in `booted()`, so queries run with `FINAL` to collapse `ReplacingMergeTree` versions. Inherited by `RecoveryKitLeaderboard`.
- **`FiltersCdcDeletes` trait** — adds a global `FilterDeletedRecords` scope filtering `_peerdb_is_deleted = 0`; use `withDeleted()` to bypass.
- **`forOrg($orgId)`** — filters `org_id` (primary tenant scope; see [Multi-Tenancy](../../concepts/multi-tenancy.md)).
- **`between($start, $end)`** — filters `repo_date` between the (date-cast) bounds.
- **`forKits(array $kitIds)`** — filters `kit_id IN (…)` when the list is non-empty; central to leaderboard rollups.
- **Model choice** — query per-recovery detail through `RecoveryKitScanReport`; query per-kit rankings/leaderboards through `RecoveryKitLeaderboard` (same table, `kit_id`-keyed).

## See also

- [../recoveries.md](../recoveries.md) — the transactional PostgreSQL recovery model (source of truth)
- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines and PeerDB CDC
- [Recovery](../../concepts/recovery.md) — the recovery concept
- [Client Analytics](../../concepts/client-analytics.md) — recovery-by-kit and leaderboard metrics
- [lpr_assists](lpr_assists.md) — recoveries credited to an LPR scan ([concept](../../concepts/lpr-assist.md))
- [client_days_to_repo_all](client_days_to_repo_all.md) — days-to-repo analytics
