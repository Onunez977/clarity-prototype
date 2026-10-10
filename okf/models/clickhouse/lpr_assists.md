---
type: DataModel
title: lpr_assists (LprAssist)
description: ClickHouse view of recoveries that an LPR scan assisted, joined to clarity.recoveries for the Client Analytics "LPR assists" metric.
resource: app/Clickhouse/Models/LprAssist.php
tags: [model, clickhouse, clarity, recovery, scout, client-analytics]
timestamp: 2026-09-24T16:50:00Z
---

# lpr_assists

`lpr_assists` identifies [recoveries](../../concepts/recovery.md) that an LPR [scan](../../concepts/scan.md) helped locate — the "LPR assist" credit. Domain meaning, attribution rules (0.1 mi / plate / lookback), and dashboard counting are documented on **[LPR Assist](../../concepts/lpr-assist.md)**. The view does **not** apply `FINAL` to `clarity.recovery_scans`. DEV-2429 accepts that: the Client Analytics metric is best-effort, with latent tombstone and merge-lag risk, and that differs on purpose from admin paths that read `recovery_scans FINAL` (`RecoveryLeaderQuery`). See [View semantics](../../concepts/lpr-assist.md#view-semantics-no-final-on-the-junction). The table itself is minimal (just `recovery_id`); all meaningful analytics come from joining it to `clarity.recoveries` (with `FINAL`) to pull the recovery's org, client, branch, and `repo_date`. It powers the Client Analytics "LPR assists" metric — see [Client Analytics](../../concepts/client-analytics.md) and [ClickHouse Integration](../../concepts/clickhouse-integration.md). The ClickHouse engine is a **`View`**. Because the queries require a join, the model deliberately uses static SQL-builder methods rather than Eloquent scopes.

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `lpr_assists`
- Engine: `View`
- Model class: `App\Clickhouse\Models\LprAssist`
- Source: `app/Clickhouse/Models/LprAssist.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `recovery_id` | `Int64` | no | The recovery that an LPR scan assisted. FK → `platform.recoveries.id` (joined to `clarity.recoveries.id` in queries). This is the only column the view exposes; recovery attributes (org, client, branch, repo date) are read from the joined `clarity.recoveries` row. |

## Casts & enums

None declared.

## Relationships

No Eloquent relationships are defined. The static query builders join `clarity.lpr_assists la` to `clarity.recoveries r FINAL ON r.id = la.recovery_id`, filtering `r._peerdb_is_deleted = 0` and `r.repo_date` within the window.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Backed by recovery/scan data: the Clarity-owned `platform.recoveries` table (`platform` schema, Clarity add-on) → `clarity.recoveries` / `clarity.recovery_scans`, over which this `lpr_assists` view selects the recoveries with LPR-scan credit. See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md).
- As a `View`, it holds no rows and carries no `_peerdb_*` columns; the CDC columns referenced in queries (`_peerdb_is_deleted`) belong to the joined `clarity.recoveries`.

## Notable scopes & behavior

- **Static SQL builders (no Eloquent scopes)** — all filtering is assembled in `buildWhereClause()` / `buildQuery()`:
  - `getTotal(...)` — `count(DISTINCT la.recovery_id)` over the window (org + optional branch/client filters).
  - `groupedByClient(...)` / `groupedByClientIds(...)` — same count grouped by `r.client_org_id`.
  - `byMonth(...)` — count grouped by `toDate(toStartOfMonth(r.repo_date)) as month`.
  - `byClientAndMonth(...)` — count grouped by `r.client_org_id` and month.
- **Filtering** is by org (`r.org_id`), `r.repo_date >= start AND < end`, and optional `r.repo_branch_id` / `r.client_org_id` / a `client_org_id IN (…)` list. Start/end are converted to UTC dates.
- **Join hygiene** — the joined `clarity.recoveries` is always read `FINAL` with `_peerdb_is_deleted = 0` to dedupe and drop soft-deleted recoveries.

## See also

- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines, views, CDC filtering
- [LPR Assist](../../concepts/lpr-assist.md) — product + engineering definition of the metric
- [Client Analytics](../../concepts/client-analytics.md) — the LPR-assists metric wiring
- [Recovery](../../concepts/recovery.md) — the recovery being credited
- [Scan](../../concepts/scan.md) — the LPR read that provides the assist
- [recovery_kit_scan_report](recovery_kit_scan_report.md) — recovery + kit scan-count analytics
