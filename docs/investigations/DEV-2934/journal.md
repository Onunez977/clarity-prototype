# DEV-2934 journal

## 2026-10-08 ~12:27–12:31pm CDT — Prep intake (#26)

- Live re-GET DEV-2934 (Atlassian connector): To Do · Task · Medium · Sprint 42 (active) · epic DEV-1580 · assignee Dalton McCleery · relates to DEV-2928 (QA Complete) · labels none · created 12:23pm CDT.
- First Glance comment **43378** live (12:26pm CDT), identical to `/workspace/first-glance/DEV-2934-fg-comment.md` (copy in `cites/`). Verdict Ready · size S · **Branch path main→main** · hard stop / forecast gates / Next present. Only comment on the ticket.
- Live description + AC match the PM card; no scope drift. Note: AC bullet "count it in the miss / no-match bucket of the summary instead of as an error" — live summary has no miss bucket and the refusal already counts as *processed*; dry-run table has `miss_no_branch_name`. FG default (no new live column) stands.
- PM ruling (Dalton 12:23pm CDT): "This API key is not authorized to access this case" SOAP fault = MISS.
- `origin/main` = `43255f51` (#510 squash merge, 12:19pm CDT) — same SHA First Glance read.
- Box checkout `/workspace/dev-2934-wt` (`worktree add --no-track -b cursor/dev-2934-rdn-unauthorized-case-miss origin/main`); no upstream.
- Scout confirmed FG line refs in `RDN.php`, `GetOrgHotlistCaseInfo.php`, `RdnBackfillBranches.php` and both test files.
- Gate: **CLEAN**. Mac mini connected. Next: draft PR, then Plan.

## 2026-10-08 12:29pm CDT — Draft PR

- Prep tip `03a45aef` pushed with explicit refspec `HEAD:refs/heads/cursor/dev-2934-rdn-unauthorized-case-miss` (no upstream).
- Draft PR **#512** opened, base `main`, assignee DaltonMcCleery set, living ticket-status (Prep 1/7, Next Plan).

## 2026-10-08 12:29–12:54pm CDT — Plan (first launch under the 2026-10-08 trust fix)

- Mac worktree `<mac-run-dir>/platform-wt`: `worktree add --no-track -b … origin/main` → ff to `03a45aef`. No upstream.
- Worktree-local planner card: `fs_write` kept OUT of `allowedTools` (still in `tools`, `allowedPaths` = `<wt>/.ai/specs/**`). Guard repointed. Guard smoke 2/0/2/2. `ALLOW_JIRA_POST` absent.
- `launch.py` derived `--trust-tools` from the card: 18 entries = `allowedTools` exactly (5 built-ins + 13 read-only MCP). **<plan-cli> 2.27.1 accepted MCP names in `--trust-tools`**, so no fallback was needed.
- Planner (high effort) · exit 0 · wall **1450 s (~24m 10s)**. Model `StreamTimeout` (idle watchdog, 300 s) at 12:51pm, recovered on its own.
- **Spec + comment written through the untrusted, path-limited `fs_write`** (auto-approved in-limit; no denials in the raw log).

## 2026-10-08 ~12:54–12:56pm CDT — Plan CLEAN

- Spec READY · `ch_touch: no` · Simplest-first (#24) section present · narrow match (`SoapFault` + `not authorized to access this case`) · 13 named tests incl. generic-500 regression (4), key-wide regression (5/6), key-never-printed (7/12).
- Self-check **CLEAN** (`harness/self-check.md`). Harness edits for #17 only, plus appended "Plan check notes" (greatest() floor nuance; never log `lastCallException()`; verify dry-run column order; analyse vs base).
- Plan comment posted to Jira **43379** at 12:55pm CDT. Checked spec mirrored to Mac wt + box checkout `.ai/specs/`.

## 2026-10-08 ~12:30–12:57pm CDT — Provision Done (keyed test PG only, accepted #9 deviation)

- Mac local PG: `createdb -T zod_testing -O root zod_testing_dev_2934` (new keyed DB). platform 41 / public 147, PostGIS 3.4.2.
- `vendor/` APFS clone from Herd (`composer.lock` identical), `dump-autoload` OK. `.env.testing` from Herd with DB + `CLICKHOUSE_DATABASE_*` → `zod_testing_dev_2934` (pgsql mirror only to boot; `ch_touch: no`).
- Baseline: `GetOrgHotlistCaseInfoTest` + `RdnBackfillBranchesTest` → **58 passed / 213 assertions / 0 failed** (job 47, command 11), 10.7 s, pre-change.
- Ledger `.context/qa-prep-ledger.json` (Mac wt + box checkout, disk only): `provision_mode: keyed_test_pg_only`, `ch_touch: no`, #9 deviation (no Path A; Agent QA suites-only). Mirrored to the box control plane.
- state → `phase=provisioned`, gate CLEAN, Next Implement. PR #512 ticket-status → Provisioned (2/7). No Implement handoff from this step.

## 2026-10-08 ~12:59pm–1:10pm CDT — Implement

- Implement agent on the Mac worktree with the trust-scope fix (worktree card from the proposed template: writes untrusted and limited to the worktree, harness dirs denied; write guard limited to `RDN.php`, `GetOrgHotlistCaseInfo.php`, `RdnBackfillBranches.php` and their three test files; trusted read/search tools only; pre-launch check passed). Exit 0, 12:59–1:03pm CDT. Harness reviewed the diff against the spec and fences, then ran tests, Pint and static analysis on the Mac.
- Change (`45f27f26`): `RDN::call()` keeps the exception it already catches (`lastCallException()`, never logged). `GetOrgHotlistCaseInfo::isCaseRefusal()` = `SoapFault` + case-insensitive "not authorized to access this case". `handle()` checks it before the transport check and records a `miss_no_branch_name` miss with `recordUnresolvedLookup(seal: true)`: one upsert, counter = `greatest(+1, floor)` with floor = `maxLookupAttempts()` when sealing and 0 otherwise. `classify()` mirrors it for the dry run. Live backfill path unchanged.
- Tests: job + command 68 / 269 (baseline 58 / 213); `RDNTest` 15 / 61 (14 / 55); `GetOrgHotlistCasesTest` 54 / 140 (unchanged); new-test filter 11 / 61; all four files on PHP 8.3.33 137 / 470. Pint clean. Static analysis vs `43255f51`: 0 new errors (1222 = 1222). `config/` diff empty, no new `env(`, no queue/limiter/log additions.
- Spec deviations (tests only): test 12 puts one row in each dry-run age bucket so `--dry-run 3` samples all three; test 13 uses a call counter instead of chained mock returns.
- Jira To Do → In Progress; Implement comment 43380. state.json `pr.head_sha` corrected (was `03a45aef`). Next: Review.

## 2026-10-08 ~2:10pm CDT — Verify/Build Record done, PR undrafted

- Verify/Build Record PASS (6/7), Jira comment 43383; reviewed and verified SHA `0487f53e`.
- Docs-only commit on top: neutral phase names in the committed records (no code change) and ticket-status refreshed. No `[skip ci]`, so CI runs on the new tip.
- PR #512 marked ready for review. Next: CI → QA Ready.
