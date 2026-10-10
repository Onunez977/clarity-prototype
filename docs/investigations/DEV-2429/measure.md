# DEV-2429 measure — lpr_assists vs recovery_scans FINAL

**Measured at:** 2026-09-24 11:30:11 CDT (America/Chicago)  
**Cloud:** reads only (`cloud_touched=true`, `cloud_writes=false`)  
**Phase:** measured — no product code, no DDL mutate

## A. View definition (`clarity.lpr_assists`)

```sql
CREATE VIEW clarity.lpr_assists
(
    `recovery_id` Int64
)
AS SELECT DISTINCT recovery_id
FROM clarity.recovery_scans
WHERE _peerdb_is_deleted = 0
```

**No `FINAL`** on the underlying `recovery_scans` read.

## B. Engine (`clarity.recovery_scans`)

- Engine: `SharedReplacingMergeTree(..., _peerdb_version)` (Cloud shared form of ReplacingMergeTree)
- `ORDER BY (recovery_id, scan_id)`
- CDC: `_peerdb_is_deleted`, `_peerdb_version`, `_peerdb_synced_at`

## C. Global divergence (uniqExact recovery_id)

| Metric | Value |
|--------|------:|
| view_uniq (`lpr_assists`) | **269,429** |
| final_uniq (`recovery_scans FINAL` + deleted=0) | **269,429** |
| **delta (view − final)** | **0** |

**Divergence is zero** at global distinct-recovery_id grain.

## D. View vs non-FINAL filtered vs FINAL

| Metric | Value |
|--------|------:|
| view_uniq | 269,429 |
| nofinal_filtered_uniq | 269,429 |
| final_uniq | 269,429 |

All three agree → current prod gap is **not** merge-lag inflating distinct assists, and **not** filter-only vs FINAL at this grain.

## E. Tombstone pressure (row counts)

Without FINAL:

| Metric | Value |
|--------|------:|
| rows_total | 928,797 |
| rows_alive | 928,797 |
| rows_tombstoned | 0 |

With FINAL:

| Metric | Value |
|--------|------:|
| rows_total_final | 923,933 |
| rows_alive_final | 923,933 |
| rows_tombstoned_final | 0 |

- **No tombstoned rows** (`_peerdb_is_deleted != 0` count = 0) in either read.
- Row-count gap without→with FINAL ≈ **4,864** (unmerged duplicate versions; `duplicate_version_rows_estimate` = 4,864).
- That lag does **not** change `uniqExact(recovery_id)` under the deleted=0 filter today.

## F. Previous calendar month (repo_date window)

Join path used `clarity.recoveries.repo_date` (aggregates only; no recovery_id dumps).

| Metric | Value |
|--------|------:|
| view_uniq_prev_month | 7,573 |
| final_uniq_prev_month | 7,573 |
| delta | 0 |

Month window also **delta 0**.

## Magnitude takeaway

Hypothetical bug (view without FINAL over-counting tombstones / unmerged deletes) is **not currently materializing** on Cloud for distinct `recovery_id`: **delta = 0**, **tombstones = 0**. Structural risk remains (view still lacks FINAL; ReplacingMergeTree merge is async). Disposition after this measure: **accept + OKF** (`options-note.md`). No view DDL and no app query change in this ticket.
