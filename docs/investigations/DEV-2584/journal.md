2026-09-17 11:03 CDT · intake · intake complete: route=bug app=clarity base=main
2026-09-17 11:14 CDT · prepped · prep complete: gate=BLOCKED. Metric redefinition, not a display bug — the earlier Unique Scans to Case Claims percentage card built the percentage deliberately on Oscar's wording and rejected the ratio family. 99.7% regression reading ruled out (LEFT-JOIN guard present at UniqueLprCaseClaimsQuery.php:371). Deep path skipped: the open question is a product decision no candidate can settle; driver ran the exhaustiveness sweep + grep backstop instead.

## Phase 2 — Question Gate interview (2026-09-17, normal mode)

Q1. Should this card's metric change from a rate (pre_claim/deduped*100) to a ratio
    (unique scans / case claims)? The earlier Unique Scans to Case Claims percentage card
    built the rate deliberately on Oscar's explicit
    ask and recorded "Do not build the 9.97x reading" — the same not-a-rate family this
    ticket requests. Against that: the card's own name, both sibling cards, and
    UniqueScansToRepo's docblock naming this card the family's odd one out.
A1. YES — change it to the ratio. (Dalton McCleery, 2026-09-17)
    Noted: worth confirming with Oscar, since the rate was his ask.

Q2. 1 or 2 decimal places?
A2. 1 decimal — number_format($ratio, 1), matching both siblings. The ticket's 3,295.57
    was illustrative arithmetic, not a formatting spec. (Dalton McCleery, 2026-09-17)

Q3. Does the existing "seen by our cameras first" rate survive anywhere?
A3. Drop it, no follow-up ticket. The *WithPreClaimScans() query methods stay in the
    codebase and stay tested, so it can be revived cheaply. (Dalton McCleery, 2026-09-17)

Gate recomputed: BLOCKED -> CLEAN. 0 open questions.
2026-09-17 11:18 CDT · gated · gate CLEAN: metric changes to unique_scans/case_claims, 1dp, no '%'; old rate dropped with no follow-up
2026-09-17 11:21 CDT · planned · plan: single node t1 (9 files, one concern). Sizing gate considered — split not recommended, 6 of 9 files are the changed class's own tests. No blast radius. CH deferred for Phase 4 (tests stub the fetch seams).
2026-09-17 11:23 CDT · provisioned · Postgres isolated: zod_claude_dev_2584_4ba5e6 + zod_testing_claude_dev_2584_4ba5e6 created and migrated; PostGIS tiger patch applied + baseline reseed; CH deferred and host neutralized to 127.0.0.1 in both env files (it pointed at Cloud); dev-login probe 200
2026-09-17 11:39 CDT · implementing · t1: card metric changed to unique_scans/deduped ratio, 1dp, no '%'; 9 percentage sites fixed; two sibling docblocks corrected; pre-claim query methods left intact. Targeted tests green (28 passed).
2026-09-17 11:39 CDT · implemented · blast-radius re-check clear; expansion-test failures proven pre-existing by reverting to DIFF_BASE and reproducing 7/9 identically
2026-09-17 12:19 CDT · implemented · review RN1 fix cycle: H1 shared cache key on UniqueLprCaseClaimsQuery (both cards), M1 e2e thousands separator, M2 numerator test coverage, M3 UNAVAILABLE_VALUE, M4 two false docblock claims, M5 tooltip. 35+28+23 targeted tests green.

## Review round 2 — REGRESSION round (2026-09-17)

Scores 72/84/72 (MIN 72, down from 87). The blocking findings were created by round 1's OWN
fix, not argued afresh from pre-existing code, so this is a `regression` round and does not
consume the single gate-fix budget.

What round 1's H1 fix did wrong: it built the new shared cache keys with
`sharedMonthlySeriesCacheKey($orgId ?? 0, ...)`. `UniqueLprCaseClaimsQuery` treats org 0 as
PLATFORM-WIDE, and its class docblock states outright that the only thing making that
fail-open branch unreachable is `buildWidgetCacheKey(string, int $orgId)`'s type hint, which
TypeErrors on a null org and yields a 500 rather than another tenant's data. The `?? 0`
removed exactly that guard. Two lanes rated it High independently.

Operator decision (2026-09-17): **revert the shared-key change entirely.**
- The duplicate query it was fixing is NOT a regression from main. Today two cards run two
  DIFFERENT heavy queries; after the revert they run two identical ones — and this ticket has
  already removed the heavier `clarity.hits_cold` join, so the card still ships cheaper than
  what is live.
- Key-sharing also conflicts irreducibly with `UniqueLprCaseClaims`'s TTL policy: the shared-key
  contract requires one fixed `[3600, 86400]` pair, which killed that card's 10-second
  impersonation floor. Fixing the leak would not have fixed that.
- Reverting removes the leak, the freshness regression and the sibling-card scope creep in one
  move, and returns the diff to this ticket's actual scope.

Also approved: fix the half-finished UNAVAILABLE_VALUE work on our own card — the compact card
renders '--' but the expanded 13-bar chart still drew a confident flat-zero history.

Kept from round 1: M1 (e2e thousands separator), M2 (numerator test coverage), M3
(UNAVAILABLE_VALUE), M4 (two false docblock claims), M5 (tooltip).
2026-09-17 12:42 CDT · implemented · RN3: reverted the shared-cache-key change (tenant leak + freshness regression); sibling card/query/test byte-identical to base again; null-org guards on both denominator legs; sparkline suppressed on numerator failure
2026-09-17 13:10 CDT · implemented · RN4 prep: memoised windowRatio() + reset numeratorUnavailable in refreshData() (mirrors UniqueScansToRepo); numerator seams now counted in the round-trip test. Scope verified: sibling card/query/test still byte-identical to base.
2026-09-17 13:29 CDT · reviewed · review gate PASS rn=4 score=90 src=lane-min lanes=3 sources=harness-claude-p:3 (rounds: 87 -> 72 regression -> 94 -> 90)

## PARKED at operator request — mid Phase 6 (verify), 2026-09-17

Stopped before the agent-QA browser pass. Everything up to and including the QA environment,
the seed and both QA plans is done and committed; nothing is half-applied.

Done: intake, prep, gate, plan, provision, implement, review (gate PASS rn=4 score=90),
and the Phase 6 setup — ClickHouse stood up per-worktree, world seeded and verified through the
real query classes, both split QA plans written.

Not done: the agent-QA browser pass, human QA, the Build Record, the Jira transition to
In Code Review, and the QA teardown.

THE QA ENVIRONMENT IS LEFT UP, deliberately (Phase 6 hands it to Phase 7):
  - Postgres: zod_claude_dev_2584_4ba5e6, zod_testing_claude_dev_2584_4ba5e6
  - ClickHouse: container clarity-qa-clickhouse, databases clarity_/zod_claude_dev_2584_4ba5e6
    (+ _test)
  - Herd site: clarity-claude_dev_2584_4ba5e6.test
  - .env / .env.testing rewritten to local; originals in .env.ch-qa-backup /
    .env.testing.ch-qa-backup, and MAIL_MAILER/CLICKHOUSE_HOST originals in the ledger's
    env_restore.

Resume with `/ticket DEV-2584 --resume` (picks up at the browser pass), or tear the
environment down on its own with `I've completed QA DEV-2584`.
2026-09-17 13:40 CDT · verified · PARKED at operator request before the agent-QA browser pass. QA env up + seeded + both plans written; review gate already PASSED (rn=4, 90).

CORRECTION (same session): the park checkpoint was taken with phase `verified`, which was wrong —
the agent-QA browser pass never ran. state.phase reset to `reviewed` and record.ac left empty, so
phase-plan.sh re-runs verify on resume and build-record-gate.sh stays fail-closed until it does.
2026-09-17 13:40 CDT · reviewed · correct the park phase: verify did not complete, so phase=reviewed and record.ac stays empty

2026-09-17 13:48 CDT · reviewed · resumed by daltonmccleery@gmail.com (takeover, same person different email), prior phase reviewed — continuing at verify. Note: an accidental checkout onto cursor/2fa6e6f5 was reverted; that branch received a stray journal-only commit f59fd5c2b which is NOT part of this ticket.
2026-09-17 13:48 CDT · reviewed · resumed by daltonmccleery@gmail.com (email takeover), prior phase reviewed — continuing at verify

2026-09-17 13:58 CDT · reviewed · agent-qa blocker: ScanStatsBatchQuery hardcodes clarity. (sibling Scan Count). QA shim: CREATE VIEW clarity.*/zod.* → per-worktree DBs. /app now 200. Re-running browser lanes. Code fix deferred (same footgun HitStatsBatchQuery already fixed for itself).

2026-09-17 14:07 CDT · verified · agent-qa PASS: F1 5/5, F2 3/3, B 6/6; all AC SATISFIED. Caveat: ScanStatsBatchQuery clarity. hardcoded — QA view shim.
2026-09-17 14:07 CDT · verified · agent-qa PASS — all AC SATISFIED (F1 5/5, F2 3/3, B 6/6); CH view shim caveat for ScanStatsBatchQuery

2026-09-17 14:11 CDT · verified · human-qa guide rendered + opened. PARKED awaiting human QA. Guide: .context/qa-human-guide.html. Resume: /ticket DEV-2584 --resume or say I've completed QA DEV-2584.
2026-09-17 14:11 CDT · verified · handoff park — awaiting human QA; guide open; Build Record drafted (not posted)

2026-09-17 14:45 CDT · handoff · human QA completed by operator; Build Record posted as Jira comment 40117

2026-09-17 14:45 CDT · handoff · Jira → In Code Review (pipeline-mcp); PR #419 undrafted

2026-09-17 14:46 CDT · done · QA env torn down (CH DBs + Postgres branch DBs + Herd site + clarity/zod view shim). Build Record 40117 · PR #419 ready · Jira In Code Review. Gates: rework PASS, build-record PASS.
2026-09-17 14:46 CDT · done · DEV-2584 complete — Build Record posted, PR ready, QA env torn down, Jira In Code Review
