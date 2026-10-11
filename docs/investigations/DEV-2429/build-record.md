# Insight AI — Build Record

> Mirror of Jira comment **41647** on DEV-2429 (posted 2026-09-24 ~12:03 CDT).

**Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/456 (draft)

## TL;DR

Disposition **accept + OKF** (QG). Docs/OKF only — no view DDL, no `LprAssist` rewrite, no ClickHouse mutate. Prod measure 2026-09-24: **delta 0** (`view_uniq` = `final_uniq` = **269429**). Review rn=1 PASS min=90 on tip `e01cfa9`. Verify/Build Record scored; Next = Undraft / CI / QA Ready (PM).

## WHAT

* Updated `okf/concepts/lpr-assist.md` — view has **no FINAL**; Client Analytics metric is **best-effort**; latent tombstone / merge-lag risk accepted; cites 2026-09-24 measure
* Intentional-semantics table: `LprAssist` (view, no FINAL) vs `RecoveryLeaderQuery` (FINAL-on-junction, in-tree) vs the card-cited repossession-count comparator class (not on main; do not port)
* Cross-link `okf/models/clickhouse/lpr_assists.md`; catalog/log hygiene
* Investigation pack under `docs/investigations/DEV-2429/` (measure, options locked to accept+OKF, soft-coord with the Staging Live / RecoveryLeader recovery_scans accuracy work unchanged)

**Content tip (reviewed):** `e01cfa9cc42a74711b0e33598fb54b91bd5161b1`
**Docs stamp HEAD (pre-BR):** `a78122d5ac0993a0b22754dcd97d18e280c560fa` `[skip ci]`
**Product code:** none · **Cloud writes:** false (`cloud_touched=true` for READ-ONLY measure only)

## WHY

* Live AC required measure-first, reconcile or document intentional difference vs the card-cited repossession-count comparator's FINAL-on-junction path, and keep OKF aligned with code
* QG chose **accept + OKF** given delta 0 / zero tombstones today — structural gap documented, not patched
* Options (1) view+FINAL DDL and (2) `LprAssist` direct FINAL remain recorded for a later ticket

## HOW numbers were derived

Cloud ClickHouse **READ-ONLY** (2026-09-24 ~11:30 CDT):

| Metric | Value |
| --- | ---: |
| `view_uniq` (`clarity.lpr_assists`) | **269429** |
| `final_uniq` (`recovery_scans FINAL` + deleted=0) | **269429** |
| **delta** | **0** |
| tombstones | 0 |
| unmerged duplicate-version rows (estimate) | ~4864 (do not move distinct `recovery_id`) |
| prev calendar month delta | 0 (7573) |

Artifacts: `measure.md` · `measure.json`. Aggregates only — no raw row dumps.

**#18:** N/A — docs/OKF-only tip; no CH-mutating product code. Cloud contact was measure READ-ONLY (`cloud_writes=false`).

## AC verdict (live Jira)

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 Production divergence view vs FINAL measured (aggregates only) | **SATISFIED** | delta 0; view_uniq = final_uniq = 269429; measure.md/json |
| AC-2 `LprAssist` and the card-cited repossession-count comparator class agree OR intentional difference documented | **SATISFIED** | Intentional semantics table in `okf/concepts/lpr-assist.md`; agree today (delta 0); differ by design after accept; in-tree analogue `RecoveryLeaderQuery`; comparator class not on main |
| AC-3 `okf/concepts/lpr-assist.md` matches what code does | **SATISFIED** | View SQL (no FINAL) matches tip + Cloud SHOW CREATE; `LprAssist` reads view + `recoveries FINAL`; no product PHP change |
| #18 / product tip | **N/A** | Docs/OKF only; no CH-mutating PHP; Cloud READ-ONLY measure |

## Thin conventions (#16)

PASS. Conventions review score **90** (≥85). Docs/OKF only; OKF cites are the deliverable and are present on tip. No redesign.

## Review

rn=1 PASS · min=90 · scores correctness **94** / conventions **90** / blast-radius **95** · `reviewed_sha=e01cfa9c…` · `rerun_required=false` · act_on **NONE**

## What this ticket did NOT do

* View DDL / Cloud mutate / chlocal apply
* `LprAssist` query rewrite / port of the card-cited repossession-count comparator class
* Cloud ClickHouse writes
* Undraft / CI green push / QA Ready / merge / Status Board
* Soft-coord with the Staging Live / RecoveryLeader recovery_scans accuracy work unchanged (junction mutate ownership stays there)

## Soft flags (accepted, not blockers)

* Latent tombstone / merge-lag risk remains (view still lacks FINAL; ReplacingMergeTree merges are async)
* Soft-coordinate the Staging Live / RecoveryLeader recovery_scans accuracy work unchanged

## Next

Undraft → CI (no `[skip ci]` on the undraft push) → QA Ready (PM) → merge. Jira status left **In Progress**. PR stays draft until Undraft.
