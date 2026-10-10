# Insight AI — Build Record

> Mirror of Jira comment **42108** on DEV-2616 (posted 2026-09-28 09:45:15 CDT). Verify/Build Record after Agent QA **PASS** on verify-only / no product PHP.

**Sprint 42 · Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/471 (**DRAFT** — Verify does not undraft)

## TL;DR

Verify-only confirm: `ScanStatsBatchQuery` / `clarityDb()` already on `main` via the Company Metrics paired-count commit (`93f9a249`). Company Metrics `/app` loads under keyed fleet Docker ClickHouse **without** a `clarity` view shim. Pin suites PASS on real `CLICKHOUSE_DRIVER=clickhouse` @ `:18129`. Nine CM query classes clean of executable `FROM clarity.` / `FROM zod.`. **Zero `app/` ship** on this PR.

## WHAT changed

* **Nothing under `app/`.** Ship surface = docs-only investigation artifacts (NO_PRODUCT_PHP).
* AC-1 already true on `main`: `ScanStatsBatchQuery::handleSql` calls `static::clarityDb()` at `:47` and selects `FROM {$clarityDb}.scan_report_hourly` at `:53` (landed in the Company Metrics paired-count commit, `93f9a249`).
* Agent QA confirmed live CM `/app` on Path A fleet twin + pin suites on real Docker CH.
* Ticket-out residuals (LprVisit / Rdn* hour counts / cohort / Eloquent `$table = 'clarity.*'` incl. Kit) **not ported** under this key.

## WHY

Local ClickHouse QA provisions per-worktree databases (`clarity_<worktree>`), not a literal `clarity` database. Hardcoded `FROM clarity.…` caused Company Metrics 500s under local CH QA (surfaced during the prior QA view-shim work). Sibling queries already used `clarityDb()`; the Company Metrics paired-count commit (`93f9a249`) ported `ScanStatsBatchQuery`. This key verifies the close and proves CM loads without restoring that QA view shim.

## HOW numbers were derived

* **Agent QA PASS** (2026-09-28 **09:40:25 CDT**):
  * Pins: ScanStatsBatchQueryContractTest **5/14** · prefix guard **1/2** · UniqueScanCountsTest **27/109**
  * `CLICKHOUSE_DRIVER=clickhouse` · `PROCESS_CLICKHOUSE_DRIVER=clickhouse` · config `@ 172.19.0.1:18129` / `clarity_testing_dev_2616`
  * Live: site `:18086` · PG `:15438` · Docker CH `:18129` · ledger `docs/investigations/DEV-2616/qa-prep-ledger.json`
  * `cloud_touched=false` · `shim_clarity_db_present=false` · `mac_herd_refused=true`
  * Live AC-2: CM `/app` title **Company Metrics - Platform** · no 500 · header kit **Active Kit Users 0 / 0 kits** (expected silent-0 from `Kit::$table = 'clarity.kits'`) · OTP `BYPASSED_NON_PROD`
* **Review:** **skipped** (verify-only / NO_PRODUCT_PHP; AC-1 already on main via the Company Metrics paired-count commit (`93f9a249`); zero `app/` ship). No Review round. `rerun_required=false`. `act_on=NONE`. Mechanical placeholder score recorded only so the Build Record gate accepts a non-empty `scores` array — **not** fabricated Review lane scores (do not read as 94/92/95).
* Thin conventions **#16**: docs-only / NO_PRODUCT_PHP → **Pint N/A** (documented). Refuse redesign.
* Preflight **#18 PASS (cite Agent QA):** Plan-time `18: does_not_fire` meant “do not invent a DSN at plan.” Agent QA **did** stand real fleet Docker CH. Cite: driver=`clickhouse` · published DSN **`:18129`** · keyed `clarity_testing_dev_2616` / `clarity_dev_2616` · suites PASS · `cloud_touched=false`. Postgres/pgsql does **not** clear this key.
* Preflight **#25 N/A:** No query rewrite on this tip; AC already on main via the Company Metrics paired-count commit (`93f9a249`); no old-vs-new parity table.
* Cloud ClickHouse: READ-ONLY forever; `cloud_touched=false`. Fleet left **UP** (`:18086` / `:15438` / `:18129`). Teardown is next-lane / PM at QA Ready exit.

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 ScanStatsBatchQuery uses clarityDb() | SATISFIED | Already on main (Company Metrics paired-count commit, `93f9a249`). `:47` `$clarityDb = static::clarityDb()` · `:53` `FROM {$clarityDb}.scan_report_hourly` · ClickhouseQuery clarityDb helper |
| AC-2 Company Metrics `/app` without clarity shim | SATISFIED | Live `http://127.0.0.1:18086/app` · title Company Metrics - Platform · no 500 · CM grid cards visible · **Active Kit Users 0 / 0 kits** (expected silent-0) · `shim_clarity_db_present=false` · evidence `live-qa-evidence/06-cm-*` |
| AC-3 Scan Count monthly totals (pin suites) | SATISFIED | Contract 5/14 · prefix guard 1/2 · UniqueScanCountsTest 27/109 on real Docker CH `:18129` · `agent-qa-pins.log` |
| AC-4 Grep CM path — fix or ticket | SATISFIED | Nine Dashboard-imported query classes CLEAN of executable `FROM clarity.` / `FROM zod.` · ticket-out residuals not ported |
| Preflight #18 | PASS (Agent QA cite) | Real Docker CH `:18129` · driver clickhouse · cloud_touched=false · not pgsql/stub-only |
| Preflight #25 | N/A | No query rewrite this tip |
| Thin conventions #16 | N/A (docs-only) | Pint N/A · refuse redesign |

## Soft flags (non-blocking)

* Header kit counts silent-0 expected (`Kit::$table = 'clarity.kits'`) under keyed worktree DB without classic `clarity` — not a product regression under this key
* Soft vs the prior QA view-shim work: **no** clarity view shim restored
* Ticket-out residuals not ported (LprVisitCountQuery, RdnScanHourCountQuery, RdnHitHourCountQuery, cohort/controller `FROM zod.`/`FROM clarity.`, Eloquent `$table = 'clarity.*'`)
* Review skipped (verify-only) — mechanical score placeholder only
* Docs tips are `[skip ci]` — CI trigger is next lane
* Soft twin fleet ports untouched

## What this ticket did NOT do

* Edit product PHP / re-port `ScanStatsBatchQuery`
* Restore the prior QA clarity view shim
* Port ticket-out residuals
* Cloud ClickHouse writes
* Undraft / QA Ready / merge / Status Board / Fleet Board from Verify
* Tear down fleet (left UP for next lane)

## Review / conventions

Review **skipped** · verify-only / NO_PRODUCT_PHP · `rerun_required=false` · act_on **NONE**. Thin conventions #16: Pint **N/A** (docs-only).

## Tips

| Role | SHA |
| --- | --- |
| **Docs / PR head** | `eebdb2669f140f9e742fccdee7a7b1935b9eeefb` |
| **Verify-record tip** | `b90611248e30a7440dd5ef148506aeac843254ba` |
| **Product checkout (suites)** | `79b8db4a5ab57c31407a3e8e3d1cfa97177a55cf` |

Zero `app/` diff vs `main` on PR #471. Suites exercised product checkout tree (already has `clarityDb()` on main).

## PR

https://github.com/insight-lpr/platform/pull/471 — **DRAFT** · head `eebdb266…`

## Next

**Undraft / CI / QA Ready** (PM). Verify does **not** undraft, flip QA Ready, merge, or post Status/Fleet Board. Docs tips are `[skip ci]` — CI trigger is next lane. Fleet env left **UP** (`:18086` / `:15438` / `:18129`); teardown on closeout / QA Ready exit is next lane.

## Verify exit note (gates)

`phase=done`. `BUILD_RECORD_GATE` + `REWORK_GATE` expected PASS after comment id recorded.
