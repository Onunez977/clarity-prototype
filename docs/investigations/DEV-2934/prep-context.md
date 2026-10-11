# DEV-2934 prep-context

**Gate:** CLEAN
**At:** 2026-10-08 12:30pm CDT
**Ticket:** [DEV-2934](https://insightlpr-team.atlassian.net/browse/DEV-2934) · Task · To Do · Sprint 42 (active) · Application Clarity · epic DEV-1580 · relates to DEV-2928 (QA Complete; PR #510 merged) · assignee Dalton McCleery
**Repo:** insight-lpr/platform · **Branch path:** main→main (First Glance 43378) · base `origin/main` @ `43255f51` (the #510 squash merge)
**Branch:** `cursor/dev-2934-rdn-unauthorized-case-miss` (fresh off `origin/main`; #510's branch is deleted) · size S · route bug

## Live AC (Jira wins)
1. A not-authorized 500 for a case is recorded once as a miss and is not retried, in both the job path and the backfill command.
2. Other 500s and connection errors are still retried as before.
3. Feature tests cover the not-authorized 500 for both the job and the command (dry run and live), plus a regression test for a generic 500.
4. The API key and any secrets never appear in logs, test output, or the PR.

Fix bullets (description): do not retry / do not rethrow as transport; leave `branch_id` untouched; in `app:rdn-backfill-branches` count it in the miss / no-match bucket instead of as an error; all other 500s and transport errors keep current retry. "Simplest thing first: classification change in the existing error handling. No new queue, job, throttle, or config."
Out of scope: duplicate RDN branch "Action Towing & Recovery Orlando " (RDN ids 20576 / 21849).

**PM ruling (Dalton 12:23pm CDT):** the RDN SOAP fault "This API key is not authorized to access this case" (HTTP 500) = MISS, not endless transport retry.
**Literal fault text** (DEV-2928 pre-prod dry run, hotlist 31963346, logged by `RDN.php` L122): `This API key is not authorized to access this case`. Ticket paraphrase is not the literal string — match the logged text.

## Surface scout (main `43255f51`, verified)
| File | Lines | Role |
| --- | --- | --- |
| `app/Services/RDN.php` | `lastCallCompleted()` L101–104 · `call()` L106–146 · catch L121–125 | fault text exists only here; catch logs message, returns `EMPTY_RESULT`, `lastCallCompleted` stays false |
| `app/Jobs/Rdn/GetOrgHotlistCaseInfo.php` | outcomes L44–57 · `sealedPredicate()` L98–102 · `maxLookupAttempts()` L120–126 · `findBranch()` L258 · `handle()` L366–429 (transport return L418) · `recordOutcome()` L450–459 · `isSealed()` L461–467 · `recordUnresolvedLookup()` L510–550 (+1) | refusal currently lands in transport branch |
| `app/Console/Commands/RdnBackfillBranches.php` | live loop L147–178 (calls `handle()` in-process) · summary L189–214 (walked/processed/failed) · `classify()` L285–324 (transport return L313–315) · `eligible()` L333–342 | dry run duplicates the rule → needs same check |
| `tests/Feature/Jobs/Rdn/GetOrgHotlistCaseInfoTest.php` | transport no-burn L1046–1088 · existing counter L1092–1139 · at-ceiling seal L1330–1357 · transport outcome L1459–1487 · miss counter L733 / L975 | extend |
| `tests/Feature/Console/Commands/RdnBackfillBranchesTest.php` | live L135–165 · dry-run buckets L239–289 · parity L293–325 (loop L302) · `stubSoapClient()` L427–447 | add `DENIED` arm |

## Rules carried to Plan
- Smallest change (#24 + over-engineering lock): one property + getter in `RDN.php`, one static check next to `findBranch()`, a branch in `handle()` and `classify()`; tests. No new queue/job/throttle/config/metric/outcome/class; no `config/horizon.php` diff.
- Narrow match only: `\SoapFault` whose message contains `not authorized to access this case` (case-insensitive). Generic 500 / "API key" / "not authorized" alone stay `transport_failed` (org-wide key revocation must not seal rows — no un-seal path, no TTL).
- Default: seal on first refusal (attempts = `maxLookupAttempts()`); `branch_id` untouched; outcome `miss_no_branch_name`. No new live-summary column.
- Never print an RDN key; test asserts the key is absent from output + logs.
- `ch_touch: no` (#31). #25 N/A. #27 N/A. #18 N/A.
- No bot / tool names on Jira or GitHub (#17).

## OKF to read
`okf/index.md` + RDN / integration / jobs / testing / logging pages found by grep (Plan cites what exists).

## Material open questions blocking planning
None. Defaults per First Glance (seal on first refusal; no new summary column) stand unless Dalton says otherwise.

## Provision note (PM-accepted #9 deviation)
No Path A site / ClickHouse twin for this key. Plan / Implement / Review on a keyed test PG DB (`zod_testing_dev_2934`) + `vendor/` + `.env.testing`. Agent QA suites-only (no UI/copy/query change).

## Preflight cites
#9 (accepted deviation) · #17 · #23 · #24 (incl. 2026-10-08 lock) · #26 · #30 (2026-10-08 trust fix) · #31
