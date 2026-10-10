# OKF cites — DEV-2429 (lpr_assists / FINAL)

Source checkout: `/workspace/dev-2653-rn2-tip/`

## Concept: LPR Assist

File: `okf/concepts/lpr-assist.md`

- L12: Engineering-facing definition — recovery id in `clarity.recovery_scans`, exposed as view `clarity.lpr_assists`.
- L41: Junction rows live in ClickHouse `clarity.recovery_scans` (`ReplacingMergeTree`).
- L83–L88 (view SQL as documented):
  ```sql
  -- clarity.lpr_assists (View)
  SELECT DISTINCT recovery_id
  FROM clarity.recovery_scans
  WHERE _peerdb_is_deleted = 0
  ```
  (Matches live Cloud `SHOW CREATE` — **no FINAL**.)
- L96–L99: `LprAssist` joins `clarity.lpr_assists la` → `clarity.recoveries r FINAL` with `_peerdb_is_deleted = 0`, aggregates `count(DISTINCT la.recovery_id)`.
- L143–L144: "Count distinct recoveries… Join recoveries with FINAL… soft-deletes live on `clarity.recoveries`." (View itself has no CDC columns.)

## Model: lpr_assists

File: `okf/models/clickhouse/lpr_assists.md`

- L12 / L19: Engine is a **`View`**; only column `recovery_id`.
- L35 / L50: Joined `clarity.recoveries` always read `FINAL` with `_peerdb_is_deleted = 0`.
- L40: As a View it holds no rows and carries no `_peerdb_*`; CDC columns belong to joined recoveries / underlying junction.

## Data architecture

File: `okf/concepts/data-architecture.md`

- L48: `ReplacingMergeTree` + `FINAL` forces dedup (use sparingly).
- L61: LPR assists map — `clarity.recovery_scans` → view `clarity.lpr_assists` (model `LprAssist`).
- L90: Dedup "not guaranteed without `FINAL`".

## App peek: `LprAssist`

File: `app/Clickhouse/Models/LprAssist.php`

- L10: `protected $table = 'lpr_assists';`
- L64–L66: `FROM clarity.lpr_assists la` + `JOIN clarity.recoveries r FINAL` — **does not** apply FINAL to `recovery_scans` / the view source.
- Contrast: `app/Clickhouse/Queries/RecoveryLeaderQuery.php` L26 reads `recovery_scans FINAL` with `_peerdb_is_deleted = 0` (direct junction FINAL pattern).
- Note: sprint card cites `LprRepossessionCountQuery` (the card-cited repossession-count comparator class, not on main) as a FINAL-on-junction comparator; that class is **not** on this tip. Do not port it. Closest in-tree analogue for "direct FINAL on recovery_scans" is `RecoveryLeaderQuery`.

## After accept+OKF (2026-09-24)

Line cites above are the pre-disposition snapshot. `okf/concepts/lpr-assist.md` now has **View semantics (no FINAL on the junction)**: the view has no `FINAL`; the metric is best-effort with latent tombstone and merge-lag risk; prod measure delta 0 (`view_uniq` = `final_uniq` = 269429); intentional difference vs `RecoveryLeaderQuery` and the card-cited repossession-count comparator class (not on main). `okf/models/clickhouse/lpr_assists.md` cross-links that section.
