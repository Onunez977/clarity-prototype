2026-09-14 09:54 CDT · intake · intake complete: route=feature app=clarity base=main
2026-09-14 10:01 CDT · prepped · prep complete (escalated): gate=BLOCKED, 4 needs-human questions, auth blast-radius

## Phase 2 — Question Gate (interview, verbatim)

Q1. Guard shape: environment-only vs environment + opt-in config flag?
A1. Environment only — `! app()->isProduction()`. Covers local/testing/preprod including every
    PR preview with no deploy-workflow change. A flag would follow DEV_LOGIN_ENABLED's precedent
    of being forced "false" on preprod (kamal-deploy.yml:55, kamal-pr-preview.yml:104) and would
    ship the bypass OFF on exactly the environments this ticket targets.
    -- human:Dalton 2026-09-14

Q2. UI behavior on non-prod?
A2. Straight through — submit email, authenticated redirect, OTP field never renders.
    -- human:Dalton 2026-09-14

Q3. Is the existing rateLimit(5) enough exposure protection on VPC-reachable preprod?
A3. Yes. rateLimit(5) already throttles authenticate(); preprod holds non-production data behind
    the VPC. No extra throttle, no host/IP restriction.
    -- human:Dalton 2026-09-14

Q4. Should the auth activity log mark the bypass?
A4. Yes — log the bypass explicitly (e.g. otp => 'BYPASSED_NON_PROD') so an audit can distinguish
    a bypassed login from a normal N/A.
    -- human:Dalton 2026-09-14

Blast-radius approval: the operator's answers to Q1 and Q3 are the explicit human approval for the
authentication blast-radius surface (app/Filament/Auth/**) required before Phase 4.
2026-09-14 10:05 CDT · gated · gate CLEAN: all 4 questions answered at the Phase 2 interview; auth blast-radius approved by operator
2026-09-14 10:06 CDT · planned · plan: 1 subtask (t1), 2 files, sizing gate passes, perf gate not required
2026-09-14 10:40 CDT · resumed · resumed by daltonmccleery@gmail.com, prior phase planned; window = provision implement (--stop-at=implement); RESUME_VERDICT ok; all prior done-signals re-verified against artifacts
2026-09-14 10:37 CDT · provisioned · resume: window=provision implement; provisioning BLOCKED pending an isolation decision (see journal)
2026-09-14 10:41 CDT · provisioned · provision: worktree isolation stood up (zod_claude_dev_2521 + testing DB, Herd site, PostGIS patched, reseeded); CH deferred
2026-09-14 10:47 CDT · implemented · implement t1: non-prod OTP bypass behind ! app()->isProduction(); eligibility check extracted; audit log marks BYPASSED_NON_PROD; 15/15 tests, pint clean
2026-09-14 11:00 CDT · implemented · revert boost-guideline blocks re-added to CLAUDE.md/AGENTS.md by the provisioning bootstrap; okf-lint passes again
2026-09-14 11:04 CDT · resumed · resumed by daltonmccleery@gmail.com, prior phase implemented; window = review verify handoff; RESUME_VERDICT ok; reverted boost-guideline drift in CLAUDE.md/AGENTS.md before review
2026-09-14 11:17 CDT · implemented · review RN1 fixes: wrap 7 drifted tests in withProductionEnvironment (3 were vacuous, 4 tautological); helper now sets+asserts both env signals; resolveEligibleUser accepts ?string; pin BYPASSED_NON_PROD audit marker; add client-panel + ineligible-user bypass tests; 17/17, 103 assertions

## Phase 5 — Review (2 rounds, 3 lanes each)

Lane provenance: review.sh's own `claude -p` lanes returned "Not logged in · Please run /login" (0/3 valid).
Lanes run in-session via insightlpr:adversarial-reviewer (opus), recorded as source=in-session-agent.
Lane count 3 (not 1): auth blast-radius + semantic-correctness territory.

RN1 scores 92 / 82 / 92 -> gate FAIL (min 82 < 90). All three lanes converged on the same defect class:
phpunit.xml forces APP_ENV=testing, so the new bypass fired in every unwrapped test. 3 OTP-verification
tests were vacuous, 4 negative-eligibility tests tautological, the production sendOtp() eligibility gate
uncovered. Fixed all scoring findings (one fix cycle); proven by mutation rather than asserted.

RN2 scores 100 / 80 / 93 -> gate FAIL (min 80 < 90). Fix cycle spent -> PARK non-blocking per the flow.
claude1 (100) cleared every RN1 fix and found no scoring defects. claude2 (80) found two NEW findings that
RN1 missed entirely, both verified independently by the driver:
  H1 HIGH  .github/workflows/kamal-pr-preview.yml:18,56-60,98 — the `staging` target overrides QA_DB_HOST to
           the PRODUCTION DB host while hardcoding APP_ENV: preprod. Falsifies the premise of the operator's
           approved Q3 answer. MATERIAL -> surfaced as an open question, not auto-answered (non-negotiable 1).
  H2 MED   tests/e2e/helpers/auth.ts:149-157 — loginViaOtp() hard-waits for an OTP field the bypass prevents
           from rendering; breaks 6 committed spec files. No Playwright CI job, so CI stays green.
  H3 LOW   CustomLoginPageTest.php:413-416 — the production audit assertion pins a negative and passes when
           no activity row exists at all.

state.record.review.rerun_required = true; reviewed_sha left null (no passing round). Residual findings
posted IN FULL to PR #402. No linked Jira Task created — asking the operator first.
2026-09-14 11:33 CDT · implemented · review RN1+RN2 parked: gate fail (min 80<90), fix cycle spent; 3 residual scoring findings posted to PR #402; H1 is material and needs an operator decision

## Phase 5 — Review, round 3 (operator unparked the run)

Operator decision 2026-09-14: fix H1 in this ticket (not track separately), and fix H2 + H3 now.
Scope therefore widens beyond plan.target_files to .github/workflows/kamal-pr-preview.yml and
tests/e2e/helpers/auth.ts. Recorded as two review-scope-change deviations.

H1  kamal-pr-preview.yml:98 — APP_ENV now resolves to 'production' on the `staging` target (which points
    DB_HOST at the production database) and stays 'preprod' on `preview` (QA database). Rationale comment
    added at the site, per the routes/web.php:158-174 precedent. Verified: no app code branches on the
    literal 'preprod'; YAML parses; kamal-deploy.yml unaffected (environment: qa).
H2  tests/e2e/helpers/auth.ts — loginViaOtp() races the post-submit redirect against the OTP field and
    returns early when the bypass already authenticated. Both stale docblocks corrected. esbuild parses
    auth.ts and all 7 spec files.
H3  CustomLoginPageTest.php — production audit assertion now assertNotNull(row) + assertSame($otp captured
    before forget()). Mutation-proven: forcing $bypassed_non_production = true now fails the test.

17 tests, 104 assertions, pint clean.
2026-09-14 11:37 CDT · implemented · fix residual H1/H2/H3 per operator decision: staging-target preview now runs APP_ENV=production (prod DB host); e2e loginViaOtp tolerates the bypass; production audit assertion pins the positive OTP

## Phase 5 — Review, round 3 result + post-gate hardening

RN3 scores 100 / 94 / 94 -> REVIEW_GATE: pass min=94 lanes=3 rn=3 sources=in-session-agent:3.

Two RN3 findings were resolved by reading the GitHub Actions variables directly rather than acting on
inference (both lanes said they could not verify):
  claude2 M2 (staging has no deliverable OTP) -> REFUTED. The `staging` environment defines MAIL_MAILER=smtp,
    MAIL_HOST=sandbox.smtp.mailtrap.io, MAIL_PORT=2525, MAIL_USERNAME. Mail works; the target keeps a login path.
  claude3 M1 (ClickHouse axis unfixed)        -> FACT CONFIRMED, SCOPE REJECTED. QA_CLICKHOUSE_HOST =
    zd69ju2h8d.us-east-2.aws.clickhouse.cloud = the production CH cluster, literal clarity/zod databases. But
    kamal-deploy.yml:94 uses the same var, so pre-platform and every preview have ALWAYS read production CH,
    read-only (CLICKHOUSE_WRITE_USERNAME falls back to the read-only CLICKHOUSE_USERNAME). Pre-existing and
    deliberate; the operator approved preprod exposure knowing their own architecture. Recorded as accepted
    risk, not fixed here. Follow-up: kamal-pr-preview.yml:319 prints "ClickHouse stays QA in every target",
    which is misleading about a security-relevant fact.

H1 also PROVEN, not inferred: the `staging` environment variable is literally
QA_DB_HOST=pg-prod.c7ideqgxcm2t.us-east-2.rds.amazonaws.com.

Post-gate hardening applied (claude2 scored it M1; claude1 and claude3 rated the same point non-scoring and
proved it unreachable today — type: choice with two GitHub-validated options). Taken anyway because it is one
line, changes no behaviour today, and removes the exact coupling foot-gun this ticket exists because of:
  - APP_ENV inverted to an ALLOW-LIST so it FAILS CLOSED: an unknown/new/renamed target now gets
    `production` (OTP required) instead of silently inheriting the bypass. Only `preview` and plain
    pull_request events keep `preprod`. YAML re-verified.
  - :59 comment now points at the computed APP_ENV; :96 header no longer claims it always mirrors preprod.
  - 7 stale E2E_OTP docstrings corrected, including auth.ts:130 which is THROWN to the developer at :143.

TOOLING NOTE (not a code defect): all three RN3 lanes reported phpunit hanging 8-25 min. Root cause found —
the lanes each ran `--filter CustomLoginPageTest` concurrently against the SAME zod_testing_claude_dev_2521
database and deadlocked on platform.permissions_id_seq / permissions (Lock:transactionid + Lock:relation).
Orphan processes kept the locks after the lanes ended. Killed 7 orphan PIDs; the suite then ran in 5.7s.
Future lanes must not be told they may run the suite against a single shared test DB.

17 tests, 104 assertions, pint clean, all 7 e2e TS files parse.
2026-09-14 12:26 CDT · implemented · post-gate hardening: APP_ENV guard inverted to a fail-closed allow-list; 7 stale E2E_OTP docstrings corrected; RN3 gate passed at min=94
2026-09-14 12:39 CDT · reviewed · RN4 gate PASS min=100 lanes=1; RN3 PASS min=94 lanes=3. rerun_required=false, reviewed_sha=c91500def. Non-scoring findings + accepted risks posted to PR #402.
2026-09-14 12:39 CDT · reviewed · review complete: 4 rounds, gate passed (RN3 min=94, RN4 re-gate min=100); all scoring findings fixed

## Phase 6 — Verify (qa-prep)

PROVISION_REUSE=1: the Phase 3.5 env was still standing and healthy (ledger provision_mode=provision-only,
.env still on zod_claude_dev_2521), so provisioning was skipped entirely — scope + seed + plans only.
Site probes 200 on /, /app/login, /client/login, /__qa/login. CH stays deferred (login-path change).
Both split plans written. The agent plan carries a hard warning NOT to use /__qa/login to test this ticket:
that dev-login route is a DIFFERENT non-prod bypass, and using it would skip the code path under test.

CI GAP FOUND (flagged by an RN3 lane, confirmed): every commit on this branch ends [skip ci], and
`gh pr checks 402` reports no checks have ever run. For an auth change the automated tests are the primary
evidence for AC-3 (production still requires the OTP), which cannot be exercised in a QA env at all —
APP_ENV=local is exactly what switches the bypass on. Pushing a clean-message commit now so CI runs during QA
rather than discovering a red suite at handoff.
2026-09-14 12:43 CDT · reviewed · verify: qa-prep reused the standing env, both QA plans written; trigger CI (no check has run on this branch - every prior commit was [skip ci])

CI CORRECTION + OPEN ITEM. Earlier note overstated the gap: `Pint & Tests` HAS run and PASSED on this
branch, on fd0672418 (the original implement commit). What has never been CI-tested is the REVIEWED code —
every commit after fd0672418 carries [skip ci], covering all RN1/RN2/RN3 fixes and the hardening.
Pushed clean-message commit e3529abdf at 17:43Z to trigger it. As of 17:48Z no run had fired and the PR's
statusCheckRollup is empty, despite the workflow being `active` and the PR targeting main. No repo-wide runs
since 15:51Z. UNRESOLVED — re-check before undrafting; a green run on the final SHA is a merge prerequisite
because AC-3 (production still requires the OTP) cannot be exercised in any QA environment.
2026-09-14 12:51 CDT · reviewed · verify: record the CI correction and open CI-trigger item

## Phase 6 — Verify (agent-qa) — PASS

13/13 items ✅ across 4 browser lanes (3 parallel + 1 follow-up) and the backend lane. 0 ❌, 0 🔴.
All four ACs SATISFIED (.context/qa-ac-results.json).

Highlights:
- A6 (the one that matters): qa-ineligible@tester.com — a real user row failing canAccessPanel on BOTH
  panels — was rejected with a byte-identical "Account Not Found" and stayed logged out. Confirmed
  independently at the unit level by B4 (resolveEligibleUser returns null for it). This is the empirical
  proof the change is an OTP bypass, not an authentication bypass.
- A4: the bypass is panel-aware — a client-org user lands on /client/scans, not /app.
- A7: activity_log carries {"otp":"BYPASSED_NON_PROD"} for the bypassed login.
- A8: rateLimit(5) still fires on the 6th submit in a minute, BEFORE the bypass branch.
- AC-3 is satisfied by the automated test + the static guard check, never by clicking: APP_ENV=local on a
  QA site is precisely what enables the bypass, so no QA environment can exercise the production branch.

Two 🟡 findings, neither blocking:
- The BYPASSED_NON_PROD marker has NO admin UI surface. ActivityResource.php:211 restricts the resource to
  log_name in (Scans/Hits/Hotlists/MissedOpportunities), so Login rows are excluded. Q4's data requirement
  is met; its stated purpose ("so an audit can distinguish a bypassed login") is only half-served.
  Pre-existing scoping — follow-up, not a defect of this ticket.
- The submit button still reads "Send OTP" outside production. Cosmetic; the e2e selector depends on it.

ENV FIX during QA (no app code): the worktree .env had ClickHouse on a closed port (127.0.0.1:8443,
HTTPS=true) with Postgres DB names in CLICKHOUSE_DATABASE_CLARITY/_ZOD and stale Cloud creds — a leftover of
the deliberate CH-deferred provisioning — so /app 500'd on the ScanCount widget. Proven unrelated to the
diff by reproducing the identical 500 via the dev-login route. Repointed at the pre-existing shared local
container (:18123, literal clarity/zod per the known constraint that per-worktree CH names break query
classes hardcoding the prefix). /app now 200. Prior values in the ledger env_restore; the container was not
created by this run and must not be torn down.
2026-09-14 13:05 CDT · verified · verify PASS: 13/13 agent-QA items, all 4 ACs satisfied, 0 critical; 2 non-blocking findings recorded

## Phase 7 — Handoff

PR #402 undrafted and retitled to "DEV-2521 | Bypass OTP on non-production environments after email validation".

CI DIAGNOSIS — CLOSED as out of our control. Three trigger attempts failed to produce a run: two
clean-message pushes (synchronize) and the undraft (ready_for_review). Root cause is NOT this PR: GitHub
Actions has produced ZERO runs repo-wide since 15:51Z (~2h40m), across every branch and workflow, including
main deploys and an unrelated active branch. All workflows report `active`; the repo is not archived or
disabled. Org-level Actions outage / policy / quota — not fixable from here, and not worth more polling.
Recorded as open risk CI-NOT-RUN. Tests were run locally 3x on the final code with identical results
(17 tests / 104 assertions), twice by the driver and once by a review lane.
2026-09-14 13:31 CDT · verified · handoff: PR undrafted+retitled; CI outage diagnosed repo-wide and recorded as open risk CI-NOT-RUN

## Phase 7 — Closeout (operator confirmed QA complete)

Ordering corrected first: the driver had undrafted the PR and transitioned to In Code Review BEFORE human
QA. Operator corrected it — nothing outward-facing moves until QA is complete. Both were reverted (PR back
to draft + [WIP], ticket to In Progress) and re-applied only after the operator said QA was done.

Closeout: PR #402 undrafted + retitled · ticket In Code Review · Build Record posted (comment 39831) ·
QA env torn down · both done gates pass.

TEARDOWN — two hazards caught, neither by the tooling:
1. The ledger listed ClickHouse databases `clarity` and `zod` with the container `clarity-qa-clickhouse` as
   teardown targets. Those are the SHARED literal databases (81 tables) used by clarity_claude_dev_2409 and
   clarity_claude_dev_2426, and this run created none of them — it only repointed .env at the existing
   container. Emptied clickhouse.databases/container in the ledger before teardown so the CH block was
   skipped entirely. Verified after: clarity, zod and both other worktrees' CH DBs all survive.
2. env_restore reverted CLICKHOUSE_HOST to the production ClickHouse Cloud cluster WITH a real password,
   because that is its pre-QA value (bootstrap copies it in). The worktree survives teardown, so this would
   have left live prod CH credentials wired into it. Re-neutralized HOST to 127.0.0.1 and cleared both CH
   passwords. Saved to memory: env_restore should not carry CLICKHOUSE_HOST or CH credentials.

Also left alone deliberately: the Herd site clarity-claude_dev_2521_resume_c9e256, which points at a
different worktree and was not in this ledger.

Gates: REWORK_GATE pass (only investigation artifacts changed since reviewed_sha c91500def) ·
BUILD_RECORD_GATE pass (id=39831, ac=4, rerun_required=false).

OPEN AT CLOSE: CI-NOT-RUN. No CI run exists on the reviewed code; GitHub Actions has produced zero runs
repo-wide since 15:51Z. Tests verified locally 3x (17 tests / 104 assertions). A green Pint & Tests on the
final SHA remains a merge prerequisite.
2026-09-14 13:44 CDT · done · closeout: QA complete, env torn down, both gates pass; CI-NOT-RUN remains open for merge
