<!-- ticket-status -->
**Ticket:** [DEV-2738](https://insightlpr-team.atlassian.net/browse/DEV-2738)
**Phase:** implemented
**Done:** intake · Ultra-Prep · Question Gate (CLEAN) · Ultra-Plan · Provision · Implement
**Next:** Review
**Open questions:** none
**Sprint:** Sprint 41
**Ship:** Widget titles and chart dataset labels are Scan Counts and LPR Case Claims. Four copy literals. Card keys `unique-scan-counts` and `unique-lpr-case-claims` unchanged.
**Fleet:** :18085 / :15437 / :18128
**Assignee:** DaltonMcCleery
**PR body:** not agent-managed — update refused. Living status is this block.
<!-- /ticket-status -->

# DEV-2738 journal

## Intake (2026-09-25)

Story, To Do, Sprint 41, Clarity, assignee Dalton McCleery (unchanged).

First Glance comment 41854: branch path main→main, Verdict Ready, Hardness Low, hard stop none.

Relates DEV-2651 (QA Complete). Residual Unique copy only. Do not reopen 2651.

Soft parallel DEV-2736 (different cards).

Base `main` at `b999329615174f5e3c6bfe7f77e5c592f01c021a` (matches `origin/main`).

## Ultra-Prep

Rendered Unique needles on the two cards:

- `app/Filament/Widgets/Dashboard/UniqueScanCounts.php:53` heading `Unique Scan Counts`
- `app/Filament/Widgets/Dashboard/UniqueScanCounts.php:114` dataset `Unique Scans` (consumed at `:534-539`)
- `app/Filament/Widgets/Dashboard/UniqueLprCaseClaims.php:96` heading `Unique LPR Case Claims`
- `app/Filament/Widgets/Dashboard/UniqueLprCaseClaims.php:108` dataset `Unique LPR Case Claims` (consumed at `:170` and `:176`)

Stat titles follow `$heading` via `getCardLabel()` (`UniqueScanCounts.php:519`, `UniqueLprCaseClaims.php:144`).

Already clean on these cards: `Total Scans` (`UniqueScanCounts.php:546`), `Scans per Case Claim` (`UniqueLprCaseClaims.php:177`, `:234`), axis `Case Claims` (`:219`), both tooltips (`UniqueScanCounts.php:57`, `UniqueLprCaseClaims.php:103`).

Test pins: `UniqueScanCountsTest.php:73`, `:86`, `:568`. E2e locators: `tests/e2e/helpers/company-metrics.ts:23`, `:29`.

`app/Clickhouse/` hit is the docblock quote at `UniqueLprCaseClaimsQuery.php:12` only. Not SQL. Preflight #18 N/A. Preflight #25 N/A.

Out of scope: Affiliate Stats `Unique Scans` (`AffiliateStatsResource.php:48`, `AffiliateStatsExporter.php:30`). Leaderboard heading already `Scans Leaderboard` (`UniqueScansLeaderboard.php:56`).

Preflight #24: four literals. No new helper.

## Question Gate

CLEAN. Wording is Scan Counts / LPR Case Claims. Open questions: none.

## Ultra-Plan

`plan.md` and `plan.json`. One subtask. phase=plan. Next: Provision.

No product PHP. No provision. No ClickHouse writes.

Draft PR https://github.com/insight-lpr/platform/pull/466 at `529146e7111738bdee3cbe637934274da94c8064`. GitHub assignee `DaltonMcCleery` was not set: the available token returned 403. Jira assignee was not changed.

## 2026-09-25 ~10:45 CT — dual-plan #23

Claude draft converges with plan.json. Compare on disk; no plan rewrite. Next Provision (ledger).

## Provision (2026-09-25)

Fleet ledger copied to `docs/investigations/DEV-2738/qa-prep-ledger.json` (password_ref only). Same JSON on disk at `.context/qa-prep-ledger.json` (not committed). This run did not stand an environment and did not write Cloud ClickHouse.

Cite, status `live`, prove already recorded on the fleet ledger at `2026-09-25T10:43:19-05:00`:

- Site `http://127.0.0.1:18085` container `clarity-qa-app-dev-2738`. `GET /app/login` → 200.
- Postgres `127.0.0.1:15437` container `clarity-qa-postgres-dev-2738`. Databases `zod_dev_2738` and `zod_testing_dev_2738`. User `clarity`.
- Docker ClickHouse `127.0.0.1:18128` container `clarity-qa-clickhouse-dev-2738`. Database `clarity_dev_2738`. `prove_select` PASS. `table_count` 31.
- `cloud_touched=false`. `mac_herd_refused=true`.
- Refused cross-key DSN: DEV-2736 `:18084` / `:15436` / `:18127`, torn DEV-2659, SCHEMA-DRIFT.

phase=provisioned. Next: Implement. Four-literal copy plan unchanged. No product PHP.

## Implement (2026-09-25)

Product commit `d67034b93b26c8503817d311d3486b0a8ee2c9d3` (`tip_sha`). Four literals:

- `UniqueScanCounts.php` heading and `getDatasetLabel()` → Scan Counts
- `UniqueLprCaseClaims.php` heading and `getDatasetLabel()` → LPR Case Claims

`getCardLabel()` still reads the heading. `getData()` still reads `getDatasetLabel()`. No helper, trait, constant, or class rename. Card keys unchanged. `UniqueLprCaseClaimsQuery.php` untouched, including the docblock. Historical DEV-2651 absorption sentences in `CompanyMetricsOverview` unchanged. `company-metrics.ts` leaderboard constant left as Unique Scans Leaderboard.

Row-1 comments in `CompanyMetricsOverview.php` and `DashboardCardExpansionTest.php` use the new titles.

Pint on dirty PHP: passed.

Tests on local `zod_testing` (`127.0.0.1:5432`), not the fleet DSN. `cloud_touched=false`. No fleet stand or teardown.

- `UniqueScanCountsTest`: 27 passed, 109 assertions, 0 failed
- `UniqueLprCaseClaimsTest`: 21 passed, 91 assertions, 0 failed

Log: `/opt/cursor/artifacts/dev-2738-widget-tests.log`

GitHub assignee `DaltonMcCleery` was already set on PR 466. Jira assignee was not changed. PR stays draft.

PR body update refused: current description is not agent-managed. Ticket-status lives on this journal.

phase=implemented. Next: Review. Fleet cite remains :18085 / :15437 / :18128.

## Phase — Review rn=1 (2026-09-25 CDT)

Adversarial Review on product tip `d67034b93b26c8503817d311d3486b0a8ee2c9d3`. Branch tip `af18fcf827adcda2039e8943d2fdb5f78cb2b38f` (docs after). Draft PR #466.

Ship: four copy literals — UniqueScanCounts / UniqueLprCaseClaims headings + dataset labels → Scan Counts / LPR Case Claims; comment sync; e2e constants. Card keys unchanged. No query/key/class/cache change. DEV-2651 not reopened.

Scores: correctness **96** / conventions **94** / blast-radius **97**. min=94 threshold=85. Gate PASS. Act-on Implement: **NONE**. `reviewed_sha` = branch tip. Next: **Pippin** Agent QA (fleet cite qa-prep-ledger.json; ports :18085 / :15437 / :18128).

Soft: historical DEV-2651 absorption prose keeps old titles (plan); Affiliate/leaderboard Unique out of ship; Pint claimed by Implement, not re-run here.

Blocking FAIL: none. Artifacts under `.ai/` (not committed).

## Agent QA (2026-09-25 11:22:48 CDT)

PASS on real Docker ClickHouse. Checkout Review stamp `f1e4c6c…` (docs-only); product tip `d67034b…` verified ancestor. Pint PASS 6 files. PHPUnit tip filter **64 passed / 320 asserts / 0 fail / 0 skip** (UniqueScanCounts 27/109, UniqueLprCaseClaims 21/91, DashboardCardExpansion 16/120). `CLICKHOUSE_DRIVER=clickhouse` @ `127.0.0.1:18128` keyed `clarity_testing_dev_2738`. `cloud_touched=false`. Live login 200 only; OTP/Pro/Playwright NOT_COVERED soft. act_on Implement NONE. Fleet left UP (:18085/:15437/:18128). Artifacts under `.context/` (disk-only) and `/workspace/docs/investigations/DEV-2738/agent-qa-real-ch.md`.

## Phase — Verify / Build Record (2026-09-25 CDT)

Copy-only confirm: product tip `d67034b…` is 7 files (string literals + tests/comments/e2e). Zero `app/Clickhouse/` paths. Preflight #18 N/A and #25 N/A per plan + Question Gate; soft-cite Agent QA real-CH PASS. Thin conventions #16: rn=1 conventions 94; Pint 6.

Posted Build Record Jira comment **41856** (`Insight AI — Build Record`). PR #466 stays **DRAFT**. Next: Undraft / CI / QA Ready (PM). Product tip may be `[skip ci]` — CI trigger is next lane. No undraft / QA Ready / merge / Status Board from Verify.

Gates: `BUILD_RECORD_GATE` + `REWORK_GATE` against worktree + KEY DEV-2738 → PASS → `phase=done`.
