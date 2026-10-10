# DEV-2429 fix options

**Locked path: accept + OKF** (2026-09-24). No view DDL. No `LprAssist` query rewrite. No ClickHouse mutate.

Measure headline (Cloud READ-ONLY, 2026-09-24 ~11:30 CDT): **view_uniq = final_uniq = 269,429; delta = 0; tombstones = 0.** The view still lacks `FINAL`. That structural gap is documented as latent risk, not patched in this ticket.

Options (1) and (2) stay recorded so a later ticket can pick them up. They are not the disposition.

## Option (1) — View + FINAL DDL (chlocal / Cloud)

Rewrite `clarity.lpr_assists` to:

```sql
SELECT DISTINCT recovery_id
FROM clarity.recovery_scans FINAL
WHERE _peerdb_is_deleted = 0
```

- Ownership: chlocal / Cloud DDL (not app-only).
- Pros: every consumer of the view inherits correct ReplacingMergeTree semantics.
- Cons: DDL deploy path; FINAL cost on every view read; must ship schema dump + Cloud apply; coordinate with the Staging Live / RecoveryLeader recovery_scans accuracy work if it touches the same table.

## Option (2) — LprAssist direct FINAL (app path)

Bypass or supplement the view: query `clarity.recovery_scans FINAL` (deleted=0) inside `LprAssist` builders — same pattern as RecoveryLeader / the card-cited repossession-count comparator class (not on main).

- Ownership: platform PHP.
- Pros: no Cloud DDL; scoped to Client Analytics metric path.
- Cons: other view consumers stay unprotected; duplicates source-of-truth; still soft-coordinate if the Staging Live / RecoveryLeader recovery_scans accuracy work changes junction semantics.

## Option (3) — Accept + document OKF — **selected**

Given **delta = 0** and **zero tombstones** today, document that current prod risk is latent (async merges / future deletes) and update OKF to state when `FINAL` is and is not required at the view grain.

- Ownership: OKF / docs.
- Pros: no mutate; matches measured magnitude.
- Cons: leaves the structural gap (view without `FINAL`). Future tombstones can diverge without a code or DDL change. That cons is accepted.

## Decision

**Accept + OKF.** Numbers live in `docs/investigations/DEV-2429/measure.json`. The concept page is `okf/concepts/lpr-assist.md` (View semantics). Distinct `recovery_id` counts agree with FINAL-on-junction admin reads today and differ by design after this disposition. `RecoveryLeaderQuery` is the in-tree analogue. The card-cited repossession-count comparator class is not on main.
