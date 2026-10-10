# Soft coordinate — recovery_scans accuracy

- The Staging Live / RecoveryLeader recovery_scans accuracy work owns the `recovery_scans` accuracy audit (AC6).
- **DEV-2429** measure is view vs FINAL **divergence for LprAssist** (`clarity.lpr_assists` lacking FINAL).
- Soft coordinate only: this measure is **READ-ONLY Cloud aggregates**, **must not mutate**, and does not block that recovery_scans accuracy work.
- Share findings: today Cloud shows **delta=0**, **tombstones=0**, but ~4.8k unmerged duplicate version rows on `recovery_scans` (row-count FINAL gap). Useful context for that accuracy work; not a hard dependency either direction.
- Fix-path for this ticket (view DDL vs app FINAL vs accept) stays gated on the fix-path Question Gate after measure — no silent parallel mutate of junction DDL while that recovery_scans accuracy work is in flight.
