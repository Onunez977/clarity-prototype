# Insight AI — Build Record

> Mirror of Jira comment **41856** on DEV-2738 (posted 2026-09-25 11:25:37 CDT). Verify/Build Record after Agent QA PASS on product tip `d67034b…`.

**Sprint 41 · Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/466 (**DRAFT** — Verify does not undraft)

## TL;DR

Copy-only drop of residual "Unique" from two Company Metrics cards: **Unique Scan Counts → Scan Counts** and **Unique LPR Case Claims → LPR Case Claims** (widget titles + chart dataset labels). Comment + e2e heading-constant sync. Card keys / class names / cache namespaces stay. No `app/Clickhouse/` query SQL change. Do **not** reopen [DEV-2651](https://insightlpr-team.atlassian.net/browse/DEV-2651). Affiliate / leaderboard Unique left out of ship.

## WHAT changed

* `UniqueScanCounts.php`: `$heading` and `getDatasetLabel()` → `Scan Counts`
* `UniqueLprCaseClaims.php`: `$heading` and `getDatasetLabel()` → `LPR Case Claims`
* Row-1 comment sync in `CompanyMetricsOverview.php` + `DashboardCardExpansionTest.php`
* Test pins in `UniqueScanCountsTest` / `UniqueLprCaseClaimsTest` (incl. `getCardLabel` / `getDatasetLabel` asserts; `getCardKey` stays `unique-lpr-case-claims`)
* `tests/e2e/helpers/company-metrics.ts`: `UNIQUE_SCAN_COUNTS_HEADING` / `UNIQUE_LPR_CASE_CLAIMS_HEADING` string values updated (const names may still say `UNIQUE_*` — plan left alone)

Product tip `d67034b93b26c8503817d311d3486b0a8ee2c9d3` — **7 files**, string literals + tests/comments/e2e only. **No** `app/Clickhouse/` path in the tip.

## WHY

[DEV-2651](https://insightlpr-team.atlassian.net/browse/DEV-2651) already QA Complete (combine paired count cards + drop "unique" labels). Jeff/Oscar demo notes (2026-09-25) still showed residual "Unique" on these two cards' titles and chart labels. Thin polish leftover — label is not a query binding.

## HOW numbers were derived

* Agent QA (2026-09-25 **11:22:48 CDT**): Pint **PASS 6 files**; PHPUnit **64 passed / 0 fail / 0 skip / 320 asserts** (UniqueScanCounts 27/109 + UniqueLprCaseClaims 21/91 + DashboardCardExpansion 16/120); `CLICKHOUSE_DRIVER=clickhouse` @ `127.0.0.1:18128`; keyed `clarity_testing_dev_2738` / `clarity_dev_2738` (table_count **31**); PG `:15437` `zod_testing_dev_2738`; `cloud_touched=false`; refused ports `:18124`/`:18125`/`:18126`/`:18127` untouched; Live login **200** only
* Review rn=1: scores **[96, 94, 97]** · min 94 · `reviewed_sha=af18fcf827adcda2039e8943d2fdb5f78cb2b38f` · product tip `d67034b…` · `rerun_required=false` · act_on **NONE**
* Thin conventions #16: conventions lane **94**; Pint PASS **6** — refuse-only (not a second conventions pass)
* Ancestry: product tip `d67034b` ⊂ reviewed content `af18fcf` ⊂ PR head / Review stamp `f1e4c6c` (docs-only). Suites exercise the product tip tree.
* Preflight **#18 N/A** (plan + Question Gate: copy-only labels, no query edit). Soft/extra: Agent QA still ran real Docker CH `:18128` and PASS — cite only; no Cloud CH writes invented.
* Preflight **#25 N/A** (plan + Question Gate: label is not a query binding; no query rewrite; no parity table).
* Cloud ClickHouse: READ-ONLY forever; `cloud_touched=false`. Fleet left **UP** (`:18085` / `:15437` / `:18128`).

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 Widget titles | SATISFIED | `$heading` Scan Counts / LPR Case Claims; UniqueScanCountsTest assertSeeText; UniqueLprCaseClaimsTest getCardLabel |
| AC-2 Chart labels | SATISFIED | getDatasetLabel → Scan Counts / LPR Case Claims; UniqueScanCountsTest dataset assertSame; UniqueLprCaseClaimsTest getDatasetLabel |
| AC-3 No query/behavior change | SATISFIED | tip `--stat` 7 files copy/comments/e2e only; zero `app/Clickhouse/` paths; UniqueLprCaseClaimsQuery untouched (docblock-only hit stays) |
| Card keys unchanged | SATISFIED | `unique-scan-counts` / `unique-lpr-case-claims`; no class/cache rename; DEV-2651 not reopened |
| Preflight #18 | N/A (plan) | Soft cite: real CH `:18128` Agent QA 64/320 PASS · cloud_touched=false |
| Preflight #25 | N/A (plan) | Label not a query binding; no old-vs-new parity table |
| Live / OTP / Playwright | NOT_COVERED | curl login 200 only — soft OK |

## Soft flags (non-blocking)

* Live OTP / Pro browser path **NOT_COVERED**
* Playwright e2e company-metrics against fleet **NOT_COVERED** (plan: do not run)
* E2e const **names** may still say `UNIQUE_*` (values updated; plan left names alone)
* Affiliate Stats / Unique Scans Leaderboard Unique left out of ship
* Historical DEV-2651 absorption prose in `CompanyMetricsOverview` docblock keeps old titles (plan)
* Product tip commit may be `[skip ci]` — CI trigger is next lane

## What this ticket did NOT do

* Edit `app/Clickhouse/` query SQL
* Rename card keys / classes / cache namespaces
* Reopen [DEV-2651](https://insightlpr-team.atlassian.net/browse/DEV-2651)
* Ship Affiliate / leaderboard Unique copy
* Cloud ClickHouse writes
* Undraft / QA Ready / merge / Status Board from Verify
* Authenticated live dashboard / OTP / Playwright

## Review / conventions

Review rn=1 · 96 / 94 / 97 · min 94 ≥ 85 · `reviewed_sha` `af18fcf827adcda2039e8943d2fdb5f78cb2b38f` · `rerun_required=false`. Thin conventions #16 PASS (conventions 94; Pint 6).

## PR

https://github.com/insight-lpr/platform/pull/466 — **DRAFT** · product tip `d67034b93b26c8503817d311d3486b0a8ee2c9d3` · PR head `f1e4c6c969bc3b7593988754235f18848ac3c2ec`

## Next

**Undraft / CI / QA Ready** (PM). Verify does **not** undraft, flip QA Ready, merge, or post Status/Fleet Board. Product tip may be `[skip ci]` — CI trigger is next lane. Fleet env left **UP**; teardown on closeout is PM.

## Verify exit note (gates)

`phase=done`. `BUILD_RECORD_GATE` pass id=41856. `REWORK_GATE` pass (only investigation artifacts since reviewed_sha `af18fcf…`).
