# DEV-2654 — recovery_scans audit (AC6)

**Finding:** accurate-as-is for the Recovery Leader metric. Fractional kit credit is not the LPR Assists KPI. The view-without-FINAL gap is cited from that Cloud measure and is not reopened.

**Measured:** 2026-09-24 ~11:41 CDT, fleet Docker ClickHouse `clarity-qa-clickhouse-dev-2654` at `127.0.0.1:18124`, database `clarity_dev_2654`. Read-only. `cloud_touched` false. No mutations.

Cloud ClickHouse was not re-measured. The view-versus-FINAL numbers below are the view-without-FINAL Cloud measure from 2026-09-24 ~11:30 CDT (PR #456, the view-without-FINAL Cloud measure write-up).

## Schema

`clarity.recovery_scans` in `database/schema/clickhouse-schema.sql` (the `recovery_scans` table) and the fleet twin's `SHOW CREATE` agree:

- Engine: `ReplacingMergeTree(_peerdb_version)`
- **ORDER BY `(recovery_id, scan_id)`**
- CDC columns: `_peerdb_is_deleted`, `_peerdb_version`, `_peerdb_synced_at`

The view-without-FINAL ticket sentence that says `ORDER BY (recovery_id, hit_id)` is stale. `hit_id` is a column. It is not the sort key.

Fleet twin tables: `recoveries` present, `recovery_scans` present, `lpr_assists` **missing** (the view was not applied in the seed remap).

| Metric | Fleet twin |
| --- | ---: |
| recovery_scans rows | 0 |
| recovery_scans FINAL rows | 0 |
| recoveries rows | 0 |

The empty twin cannot compare `lpr_assists` to a FINAL read. That comparison is the Cloud cite below.

## Recovery Leader query

`RecoveryLeaderQuery` (code review, not a fleet aggregate — the twin has no junction rows):

- Reads `clarity.recovery_scans FINAL` and `clarity.recoveries FINAL`.
- Keeps `_peerdb_is_deleted = 0` on both.
- Weights each recovery by `count()` of junction rows: `kit_count / total_count` per `(kit_id, recovery_id)`, then `round(sum(...), 2)`. That is fractional kit credit, not `uniqExact(recovery_id)`.
- Filters `recoveries.repo_date` with viewer-local `Y-m-d` strings. It does not filter `scanned_at`.
- The 90-day assist window lives in the Cloud scoring job (`okf/concepts/lpr-assist.md`), not in this query.

`handle()` keys the result by kit **name**. Staging Live forbids that for its own card. On this empty twin there is no measured name collision, so kit-name keying stays an audit seed, not a defect.

## View without FINAL

Committed `clarity.lpr_assists` is a distinct `recovery_id` read of `clarity.recovery_scans` with `_peerdb_is_deleted = 0` and **no FINAL**.

View-without-FINAL Cloud measure (do not re-run, do not change the view):

- view_uniq = final_uniq = **269429**
- delta = **0**
- tombstones = **0**
- Disposition on that ticket: accept, and record it in the wiki there. This story does not implement the view-without-FINAL gap and does not open a second ticket for the same gap.

## What was not done

No schema change. No Cloud write. A pgsql stand-in was not used as the measurement. The RecoveryCounts batching neighbor (`RecoveryCounts`, `RecoveryTotalsBatchQuery`) was not touched.
