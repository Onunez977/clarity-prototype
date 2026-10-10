# DEV-2934 harness self-check (Plan)

**Verdict:** CLEAN → post checked Jira comment (`DEV-2934.jira-comment.checked.md`)
**Checked:** 2026-10-08 ~12:54pm CDT against live Jira DEV-2934, First Glance 43378, and the PM ruling (12:23pm CDT)

| Check | Result |
| --- | --- |
| Status READY (not PARKED) | PASS: L2 |
| Branch path = main→main (FG 43378) | PASS: L2, base `43255f51`, PR #512 |
| `ch_touch: no` present and unambiguous | PASS: §5 L51 "`ch_touch: no`". §4 "ClickHouse: none". The comment says "ClickHouse: none, ch_touch: no" |
| Scope = live AC | PASS: §2 copies AC1–AC4 verbatim. D1/D2 are FG defaults, labeled as such. The one extra test (13, `RDNTest`) only pins the new getter. Out of scope (§7) matches Jira and the FG hard stop (Orlando dup, no CH/UI/query, no un-seal tooling, no backfill run) |
| #24 over-engineering lock | PASS: an explicit "Simplest-first (#24)" section says the change is inline in the existing catch / `handle()` / `classify()` and reuses the upsert, outcome and `sealedPredicate()`. It also states "No inline or existing-path option was found insufficient". The NOT-added list covers queue, job, throttle/limiter, Horizon (`config/horizon.php` untouched), config (`config/*.php` untouched), class/trait/service/helper, metric, outcome, log line, migration and new test file |
| No queue/job/throttle/Horizon/config in the diff plan | PASS: §4 blast radius covers 3 product files + 3 existing test files. NOT-touched list names `config/horizon.php`, `config/services.php`, `config/queue.php`, `GetOrgHotlistCases`, `RdnJob`, `Org::getRdnClients()` and the limiters |
| Narrow match only | PASS: guard is `instanceof \SoapFault` AND case-insensitive `not authorized to access this case` (step 2 `Str::contains(..., ignoreCase: true)`). Negative pins: tests 4 (generic 500), 5 (key-wide "This API key is not authorized"), 6 (null, non-SoapFault with the literal, HTTP connect, "Invalid API key", the ticket paraphrase). Named risk section included |
| Key-never-printed tests present (AC4) | PASS: test 7 (job, `Log::listen` on every message + context, non-empty guard) and test 12 (command live + dry run, `Artisan::output()` + logs) with a fake key only. Honest-scope note: these are regression guards |
| Generic-500 regression present (AC2/AC3) | PASS: test 4 `SoapFault('SOAP-ENV:Server','Internal Server Error')` → 0 rows, `transport_failed`, re-dispatched. Existing L1046 / L1092 / L1459 / L135 must stay green |
| Command live + dry run + parity (AC1/AC3) | PASS: tests 9 (live seal, `failed 0`, re-run skips via `calledCases`), 10 (dry-run bucket regex, nothing written), 11 (`DENIED` in parity loop L302) |
| Seal on first refusal (D1) | PASS: step 4 `recordUnresolvedLookup(seal: true)` → `greatest(+1, maxLookupAttempts())` in the same upsert. `branch_id` guard inherited. Tests 1/3 pin `=== maxLookupAttempts()` |
| Citations spot-checked | PASS: RDN.php L31 property, L40–44 key in URL/header, catch L121–125. Job: `Str` import L19, `normalizeBranchName()` ends L288, `RateLimited('rdn-case-info')` L331–334. `GetOrgHotlistCases` sealed filter L232–235. Command outcome columns L235–240. `RDNTest` L132/L157. Fractional-counter test L945. `okf/concepts/rdn.md` L36 stale limiter name confirmed |
| #9 deviation recorded | PASS: "Accepted provision deviation (#9)" section |
| #30 trust record | PASS: `--trust-tools` = card `allowedTools` exactly (18 entries, derived from the worktree card at launch). `fs_write` is in neither. No `@atlassian` write tool trusted (`addCommentToJiraIssue` is in `tools` only, guard-blocked). No `--trust-all-tools`. MCP names accepted by `--trust-tools` (run exited 0). Spec + comment written through the untrusted, path-limited `fs_write` (auto-approved in-limit, no denials) |
| No bot/tool names (Jira comment) | PASS: grep clean after harness edits. Header "Planner:" → "Plan:"; signature → "-- Plan"; "kept locally" footer → control-plane spec path; added Branch path + PR line; `@reporter` tag dropped |
| No bot/tool names (committed spec mirror) | PASS: harness edits only: byline "Planner" → "Plan"; removed the memory/ledger tooling line in §3; `@reporter`/`@product` → `@PM`; appended "Plan check notes". No content change to the plan |
| Plan run posted nothing / wrote only `.ai/specs/` | PASS: `ALLOW_JIRA_POST` absent. Raw log shows only `getJiraIssue` (read) plus 2 `Creating` writes under `.ai/specs/`. Worktree `git status` clean apart from ignored `.ai/`, `<agent-config-dir>/`, `vendor/`, `.env.testing` |

**Plan check notes (non-blocking, carried in the spec):**
- The `greatest()` floor changes the shared upsert. The value is identical for counters ≥ -1. Implement may limit it to the seal path.
- Never log or serialize `lastCallException()`.
- Verify the dry-run column order before pinning the test 10 regex.
- `composer analyse`: report only new errors vs base.
- Wiki drift: `okf/concepts/rdn.md` L36 names limiter `rdn-api`, while the code uses `rdn-case-info`. Queued separately, not edited in this ticket.

**Open questions:** 1 (non-blocking): RDN fault code not captured, so the match is on the fault text only and a reword falls back to transport (the safe direction).
**Spec Status:** READY
**ch_touch:** no
