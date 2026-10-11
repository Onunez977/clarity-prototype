2026-08-31 07:47 CDT · intake · intake complete: route=bug app=clarity base=main

## Phase 1 — Ultra-Prep (escalated, best-of-N)

Escalation triggers: 3 unanswered needs-human questions in the fast pass; confidence 40 < 80;
escalate.recommend=true; behavior-reversal risk against DEV-2441 (merged 3 days before this branch).

- Fast pass (sonnet): confidence 40, escalate=true.
- 3 blind candidates (sonnet, parallel): confidence 55 / 42 / 38, all escalate=true.
- Grep backstop (haiku, parallel with candidates): 10 candidates, 1 fabricated
  (tests/Roles/UserRoleTest.py does not exist). Two material finds: ClarityScoutBirdsEyeController
  is a second caller of BOTH tier query classes; KitRangePathsQueryTest is a large tier-1 suite the
  fast pass never listed.
- Reconcile: performed by the driver at opus (= TICKET_PLAN_MODEL default) rather than a dispatched
  sub-agent, because the driver held verification no candidate had. Recorded as a deviation in
  prep-context.reconcile.

Unanimous 4/4 on the code reality: tier 1 (KitRangePathsQuery -> clarity.sc_positions) is PRIMARY
and unconditional for mapped kits; tier 2 (KitScanPathsQuery -> clarity.kit_scan_paths) is a
fallback only for kits with zero tier-1 coverage. No flag governs the choice.

Driver verifications beyond the candidates:
- DEV-2441 merged 2026-08-28 (PR #373); DEV-2451 created the same day, comment id 38642 vs
  DEV-2441's Build Record 38636.
- Tier 2 generalizes to all kits when tier 1 is skipped ($coveredKitIds empty) — zero change
  needed to scanPathFallbackRows().
- geojson()/hitDensity() CLEARED: they read HotlistAddressGeohashQuery / HitDensityGeohashQuery,
  not path sources.
- config('app.experimental.*') and Feature::define() are LAYERED, not competing designs.

Live read-only ClickHouse measurement (aggregates only, see derivation_log):
- 617 kits render continuous routes over 30d (495 over 7d) — the regression-scope number.
  Cross-validates DEV-2441's own "610 GPS kits / 494 newly render".
- kit_scan_paths: 1.66M rows fleet-wide, 1309 kits / 281 orgs (90d).
- sc_positions has a fully-populated `gl` String column, 469 distinct values — so the ticket's
  "merged GL" names something real.
- GL<->kit_mapping is many-to-many, BUT the alarming 467-GL fan-out is entirely on the blank
  kit_mapping sentinel, which tier 1 already excludes. Among real mappings worst case is 6 GLs
  over 30 days, consistent with hardware swaps. NOT evidence of a live tier-1 misattribution bug.

Envelope direction CORRECTED against the fast pass: gating tier 1 off REMOVES the expensive
sc_positions assembly, so net CH load should fall. The material risk is UX regression scope
(617 kits), not resource exhaustion.

GATE: BLOCKED — 4 open needs-human questions. Reconciled confidence 78.
2026-08-31 08:07 CDT · prepped · prep complete (escalated best-of-N): gate=BLOCKED, 4 open questions, 617-kit regression scope measured

## Phase 2 — Question Gate (BLOCKED -> interviewed -> CLEAN)

Interviewed Dalton on all 4 open questions. Answers:
1. Reading A-STRICT — revert the tier-1 call path OUTRIGHT, not a flag gate. (Driver had
   recommended the reversible flag; concern stated, Dalton decided, proceeding as specified.)
2. DEV-2448 is a typo — cite DEV-2450 only.
3. Global — all orgs, all kits.
4. Kit User Path only — accept the divergence from Bird's Eye, record it explicitly.

Gate recomputed: CLEAN (0 open questions).

## Phase 3 — Plan

Single subtask t1 (complex, no fan-out). Authored by the driver at opus.
Scope precision forced by Q4: because Bird's Eye stays on continuous GPS and calls
KitRangePathsQuery directly, the revert removes the tier-1 CALL PATH from the controller only —
the query class and its unit suite are PRESERVED.

Verified dead-code inventory (read the full controller, not inferred): the KitRangePathsQuery
import, DEDUP_METERS, BROAD_DEDUP_METERS, the `if ($mappings !== [])` block, mappingIndex(),
segmentRows(), three scanPathFallbackRows() params, and the $coveredKitIds loop all become dead.
over_budget collapses to 422, unavailable to 503, response to $fallback['rows'].

Two traps recorded for the worker: (a) MAX_RENDER_POINTS survives but its "summed across BOTH
tiers" docblock is now wrong; (b) removing tier 1 removes the only ->utc() mutation of
$start/$end, so the $localStart/$localEnd capture must be RE-COMMENTED, not tidied away.

Gates: sizing PASS (2 files, one concern); perf gate NOT REQUIRED (removes the sc_positions read;
kit_scan_paths measured at 1.66M rows, far below the >1B threshold); no sensitive-glob match.
2026-08-31 08:14 CDT · planned · gate CLEAN after interview; plan: single complex subtask, outright tier-1 call-path removal

## Phase 3.5 — Provision

Ran qa-prep provision-only with QA_PROVISION_CH=yes (CH is definitely needed: the target suite
uses RequiresClickhouse and prep-context.data_impact.ch_tables is non-empty, so deferring CH only
to re-request it at Phase 4 would buy nothing). provision-only has no Track R, so Steps 2-5 ran
inline in the foreground rather than forking a background runner.

Warm path: PG dev + testing DBs already existed and were migrated; Herd probe 200. No bootstrap.

Three real problems found and fixed:
1. `.env` had CLICKHOUSE_DRIVER=clickhouse pointed at ClickHouse CLOUD, with
   CLICKHOUSE_DATABASE_CLARITY=zod_claude_dev_2451_50da1a — a Cloud database that DOES NOT EXIST
   (leftover from the prior DEV-2409 session). Every CH read from the QA site would have failed
   with UNKNOWN_DATABASE, and the file also carried a populated CLICKHOUSE_WRITE_PASSWORD.
2. up.sh aborted on its own prefix-verification check ("80 objects landed in plain clarity, zod
   databases") and therefore NEVER rewrote .env/.env.testing — no backups, env left on Cloud.
   Wrote the CH env manually. The "plain database" landing it treats as an error is exactly what
   the test trait requires, so this is an up.sh limitation, not a provisioning failure.
3. `.env.testing` had CLICKHOUSE_DRIVER=pgsql, which makes RequiresClickhouse markTestSkipped
   ALL 29 KitUserPathControllerTest tests. Set to clickhouse.

CH database naming: verified from source that the LITERAL clarity/zod names are REQUIRED, not a
preference. The KUP query classes are config-resolved (DB::clickhouse() / DB::connection
('clarity_clickhouse')), so they would tolerate per-worktree names — but
tests/Concerns/RequiresClickhouse.php TRUNCATEs a HARDCODED list of 'clarity.*'/'zod.*' table
names, so per-worktree names make the suite truncate a different database than the app writes to.

Verified both access patterns against the local container: the app's bare-table reads
(kit_scan_paths, sc_positions) and the trait's qualified TRUNCATEs (clarity.kit_scan_paths,
zod.public_address) all succeed.

Ledger safety: the literal clarity/zod databases and the clarity-qa-clickhouse container are
SHARED (the container also holds clarity_claude_dev_2409/2426). Recorded in
shared_databases_do_not_drop so teardown cannot drop them; only the two unused per-worktree DBs
up.sh created this run (clarity_claude_dev_2451, zod_claude_dev_2451) are marked safe to drop.
2026-08-31 08:18 CDT · provisioned · isolated PG (warm) + local docker CH on literal clarity/zod; fixed 3 env faults incl. .env pointed at a nonexistent Cloud DB

## Phase 4 — Implement

Perf gate: pass (not a deferred-perf CH-at-scale design). DIFF_BASE=e247bbb5.
Jira In Progress transition SKIPPED — already In Progress; this workflow marks every transition
isGlobal:true so "In Progress" is offered even from In Progress, breaking the flow's idempotency
assumption. Posting it would have added a no-op changelog entry.

Single-node fast path, worker on sonnet (complexity=complex). TDD: test file rewritten first,
RED confirmed (2 new tests failed against the still-tier-1 controller), then green.

Diff: 2 files, 163 insertions / 905 deletions.
Removed: the KitRangePathsQuery import (+ a now-unused ClarityScoutBirdsEyeController import),
DEDUP_METERS, BROAD_DEDUP_METERS, the whole `if ($mappings !== [])` tier-1 block, segmentRows(),
mappingIndex(), three now-constant scanPathFallbackRows() params, the $coveredKitIds loop, and the
array_merge of tier-1 + tier-2 rows. over_budget -> unconditional 422; unavailable -> unconditional
503; response is just the scan rows.

Both planned traps handled correctly:
- $localStart/$localEnd kept, comment rewritten to "kit_scan_paths wants LOCAL wall-clock strings"
  (no stale Carbon::utc() mutation explanation; verified no ->utc() remains).
- kitsById()'s dangling {@see mappingIndex()} fixed; scanPathFallbackRows()/scanPathRow() docblocks
  rewritten out of tier-1-relative language.
Class docblock records: scan-only, DEV-2450 (NOT the description's DEV-2448 typo), global, and the
deliberate Bird's Eye divergence.

Worker deviation ACCEPTED: renamed scanPathFallbackRows()->scanPathRows() and $fallback->$result.
Beyond the literal brief but correct — "fallback" was stale once scan data became the only source.

HARD CONSTRAINTS verified by diff (not by the worker's report): KitRangePathsQuery.php,
KitRangePathsQueryTest.php and ClarityScoutBirdsEyeController.php are all byte-identical.

Tests: filtered 17/17 green. Blast radius 175/175 green across 7 suites, INCLUDING the untouched
KitRangePathsQueryTest and ClarityScoutBirdsEyeControllerTest — the real proof Bird's Eye is intact
rather than incidentally broken. Pint clean. No sensitive-glob match.

FULL SUITE — corrected report. `php artisan test` does NOT run locally: it OOMs at the default 128M
inside symfony/finder during test DISCOVERY, and the shell wrapper still reports exit 0, so it
LOOKS like a pass. I initially mis-reported it as passing and corrected it.
`php -d memory_limit=2G vendor/bin/phpunit` runs it properly: 1668 tests, 2 errors + 6 failures.
All 8 verified PRE-EXISTING by reverting the two files to DIFF_BASE and re-running the same classes
(1 error + 4 failures at base), plus isolation runs (RdnHitHourCountQueryTest passes 5/5 alone;
HasStatDrillDownTest fails at a different line alone than in batch). Mechanism is the known
RequiresClickhouse shared-truncation cross-pollution, not this change.
2026-08-31 09:03 CDT · implemented · tier-1 sc_positions call path removed from KUP; 17/17 filtered, 175/175 blast radius, full-suite failures verified pre-existing

## Phase 5 — Review (2 rounds, both passed)

RN1: 3 lanes, all harness-claude-p provenance (no in-session fallback). Scores 94/94/94.
REVIEW_GATE: pass min=94 threshold=90.

Driver verified each finding rather than accepting the passing score. Outcomes:
- CONFIRMED: the endpoint lost its cached read path (KitRangePathsQuery::cachedHandle wraps
  Cache::remember 5min; KitScanPathsQuery::countPoints/encodedPaths have no cache) AND the
  uncached path widened from GPS-less kits to all kits.
- CORRECTED a lane error: two lanes framed this as "15/30-day windows lost decimation".
  kit_scan_paths NEVER accepted dedup_meters — tier 2 was always undecimated. What changed is
  that it now serves ALL kits. Volume consequence real; mechanism as described wrong.
- CONFIRMED BY MEASUREMENT (the PG-024 requirement claude1 correctly said I had skipped):
  prod clarity.kit_scan_paths FINAL, 30d per-org rendered points max=3,523,861 (2.35x the
  1,500,000 ceiling), p95=522,201, mean=121,478 over 263 orgs. Orgs over ceiling: 30d=2/263
  (4 more within 80%), 15d=1/253, 7d=0/248.
  DECISION (Dalton): ship it, NO follow-up ticket, document the behavior.
- CONFIRMED: the AC was not pinned — both RN1 tests seeded a kit_scan_paths bucket, so the real
  consequence (a GPS-only kit renders NOTHING) was untested.
- CONFIRMED: 5 factually false docblock sites in ClarityScoutBirdsEyeController, one naming
  DEDUP_METERS/BROAD_DEDUP_METERS which this diff deleted.
  DECISION (Dalton): fix the comments only.

RN1 fix pass (6 fixes) -> RN2: 3 lanes, 94/94/94, REVIEW_GATE: pass min=94 rn=2.

RN2 surfaced one genuinely NEW and correct finding (claude1 M1), verified:
  cachedHandle() now has ZERO callers in app/ — Bird's Eye calls handle() at
  ClarityScoutBirdsEyeController:605 and nothing passes _mappings/mappings_key. So the caching
  entry point and its cache-key collision workaround are orphaned dead code, and
  KitRangePathsQuery's own class docblock was false in 4 places.
  Applied the SAME comment-only correction Dalton had approved for Bird's Eye — fixing this drift
  class in two files while leaving it in the most-affected file was indefensible on consistency
  grounds. This means KitRangePathsQuery is NO LONGER byte-identical (comments only; verified zero
  executable lines changed). cachedHandle() was NOT deleted — behavior-adjacent, and DEV-2450 will
  want it back.

All 3 RN2 lanes independently flagged that okf/ still teaches the deleted design (4 sites). It IS
queued in plan.json/prep-context, but CLAUDE.md's WRITE rule forbids landing wiki edits in flight,
so it is carried into the Build Record for the post-merge pass.

MID-REVIEW ENV INCIDENT (not code): a blast-radius run showed 4 failures — ClickHouse error 241
"total memory limit exceeded ... current RSS: 5.21 GiB, maximum: 5.21 GiB" raised inside
RequiresClickhouse.php:45's TRUNCATE setup, before any test logic. Local Docker CH had accumulated
pressure over ~40min across 8 databases. Dropped this run's two unused per-worktree DBs, restarted
the container (3.65 -> 1.74 GiB); back to 177/177.

Final: 6 files, 275 insertions / 922 deletions. FOUR of the six are comment-only
(KitRangePathsQuery, KitScanPathsQuery, ClarityScoutBirdsEyeController, KitScanPathsQueryTest).
Tests: 19/19 filtered, 177/177 blast radius, pint clean.
2026-08-31 09:47 CDT · reviewed · review RN1+RN2 both pass min=94; 8 findings fixed, cachedHandle dead-code + okf drift recorded as residual

## Phase 6 — Verify (qa-prep full + agent-qa)

qa-prep full mode took the PROVISION_REUSE path (Phase-3.5 env reused, no re-provision).
scope=both, SCOUT=1, scale_relevant=true. Seeded via tinker + a persisted CH SQL file.

The fixture design is what makes this verifiable: kit 16 was seeded with BOTH sources at
DELIBERATELY DIFFERENT coordinates — kit_scan_paths around Indianapolis (-86.15/39.76),
sc_positions around Austin (-97.74/30.27) — same kit, same window. So the coordinates in the
response ARE the proof of which source was read, rather than an inference from data presence.
Kit 17 = GPS only, zero scan buckets (must render nothing). Kit 18 = scan only.

Seed agent fixed two things worth recording: platform.users_id_seq was out of sync on the branch
DB (is_called=false while id=1 was occupied, so any new-user insert collided) — repaired with a
data-only setval(); and its first sc_positions draft keyed off bare now(), breaking idempotency on
re-apply (45->60 rows) — re-anchored to toStartOfHour(now()).

agent-qa: 2 browser lanes + backend lane + resource item. Result 14 ✅ / 0 ❌ / 0 ⚠️ / 1 ⏭️.
No console errors, no 4xx/5xx, Laravel log CLEAN (0 new bytes). qa.verdict=PASS.

The decisive check passed from THREE independent directions:
  - A1 browser response: Indianapolis, zero Austin coords anywhere in the whole pass
  - B2 query class executed directly: path_json [[-86.129,39.7806],...]
  - B3 static assertion: no executable sc_positions / KitRangePathsQuery reference
And the deliberate divergence was demonstrated on ONE shared fixture: Bird's Eye (A6) returned
AUSTIN coords for the same kit 16 that Kit User Path (A1) rendered at INDIANAPOLIS.

B4 confirmed KitRangePathsQuery still executes (60 points) after the comment-only docblock edit,
so Bird's Eye's dependency is live rather than incidentally broken.
B6 confirmed cachedHandle() has ZERO callers — the dead code this revert introduced, kept
deliberately for DEV-2450. Reported, not fixed.

ALL THREE ACs SATISFIED.

Resource envelope recorded as verdict=not-measured, deliberately NOT PASS: this change touches no
command and no job (the scale surface is a request-path endpoint), so there is nothing for
Step 5b to execute, and the seeded env holds 60 rendered points against a 1,500,000 ceiling —
0.004%, an extrapolation factor of ~58,700x. Calling that a PASS would be exactly the false green
the gate exists to prevent. The authority stays the read-only production measurement.

Two items left for human judgment (patched into the human plan, mechanics already verified):
hp-3 does kit 17's empty state READ as "no scan data" or as "broken"? (the experience ~617
production kits now give); hp-4 how fragmented does the scan-only route actually LOOK, and is that
tolerable for the interim? adj-2 (the 422 ceiling) is unreachable locally.

Env left UP for human QA. PR stays in draft.
2026-08-31 10:12 CDT · verified · agent QA PASS: 14 ✅/0 ❌, 3/3 ACs SATISFIED, log clean; envelope honestly not-measured

## Phase 6b — Production-cardinality QA (answering the human-judgment items with measurement)

Dalton asked whether the two items left to human judgment could be answered with production
cardinality instead of opinion. They could, and doing so CORRECTED AN ERROR I had propagated.

Method: read-only prod ClickHouse, aggregates only. zod.public_kits FINAL (_peerdb_is_deleted=0)
bridges kit_mapping <-> kit_id, which is what allows sc_positions (keyed by kit_mapping, scanner
casing -> lower()) to be cross-tabbed against kit_scan_paths (keyed by kit_id). Both filtered to
length(path_points) >= 2, the same predicate KitScanPathsQuery::filteredQuery() applies.

hp-3 — "how many kits actually render nothing?"
  7d: 0 | 15d: 0 | 30d: 1
  30d population of 5,570 kits: 611 hold BOTH sources and keep rendering; 1 goes dark;
  487 scan-only unaffected; 4,471 were already dark before this change. Orgs with a dark kit: 1.
  The one dark kit is kit 13075 / org 445986 — 13 GPS rows across 1 active day in 30 days.

*** CORRECTION *** I had repeatedly written "~617 production kits will render nothing" — in the
PR body, the human plan hp-3, the agent report, and the journal. That was WRONG. 617 was the count
of kits losing the CONTINUOUS GPS route; 611 of them still render, from scan data. Only ONE kit
loses its map, and it is barely active. Corrected in: human plan (rev 3), agent plan A2, agent
report (addendum + inline), ledger qa_log, qa-ac-results.json, state.json, and below.

hp-4 — "how much fidelity is actually lost?"
  median 1,183.5 points per kit-day across a median of 27 segments (avg 62.2 pts/segment;
  p90 42 segments, worst 93). Segment quality over 124,494 buckets: 7.1% are two-point stubs,
  52.6% carry >20 points. On the 2,345 kit-days holding both sources, scan retains ~1/3 of GPS
  rendered detail (11,339 median raw GPS rows vs 1,336 median scan points, using the recorded ~3x
  decimation factor — order-of-magnitude, not precise).
  So "fragmented" = ~27 disconnected polylines per day instead of one continuous track, each
  substantive. The loss is CONTINUITY BETWEEN segments, not the segments themselves.

REVISED RISK ORDERING: the render ceiling is the bigger real-world consequence of this revert
(2 of 263 orgs now get HTTP 422 on a 30-day All-Kits selection) than kits going dark (1 kit).
I had these the wrong way round.

Artifact: docs/investigations/DEV-2451/production-cardinality-qa.md
2026-08-31 10:28 CDT · verified · production-cardinality QA: hp-3 = 1 dark kit not ~617 (error corrected); hp-4 = ~27 segments/day at ~1/3 GPS detail

## Phase 7 — Handoff

human-qa guide rendered; operator signalled "I've completed QA" with no findings reported, so the
hp-4 fragmentation tradeoff stands accepted.

PR #375 undrafted and retitled (WIP prefix stripped). Undrafting did NOT auto-transition Jira
(status still read In Progress afterwards), so the pipeline transitioned via the Atlassian MCP to
In Code Review -> jira_transitioned_by = "pipeline-mcp" (the designed fallback, NOT a human
intervention).

Build Record posted as comment 38740. Drafted by a sonnet sub-agent from the artifacts ONLY (it has
no session memory, which is the point), then driver-reviewed: spot-checked the quoted seed SQL
against .context/qa-ch-seed.sql (verbatim match), all four okf line numbers, the controller
diffstat, and the final test count (19) before posting.

okf WIKI UPDATED PRE-MERGE at Dalton's explicit direction, overriding CLAUDE.md's WRITE rule. The
driver flagged the rule and the DEV-2342 precedent behind it; Dalton chose to update now.
Found FIVE stale sites, not the four the review lanes flagged — the KitScanPathsQuery section
(:42) and its table row (:309) also needed updating, since that class is now Kit User Path's SOLE
path source rather than just a driver/Bird's Eye reader. Six edits to
okf/concepts/scout-clickhouse-queries.md (incl. the frontmatter description + timestamp) and one
index.md row. `php scripts/okf-lint.php` PASSED (106 files, 0 warnings).

Knowledge card drafted at Dalton's request: knowledge/clarity/testing.md, covering both durable
lessons in one card (the artisan-test false-green OOM and the RequiresClickhouse literal-database
requirement, plus the pgsql-driver silent-skip and the CH container error-241 degradation).
Registered in knowledge/index.json. NOTE this is the claude-plugins repo, not platform — left
uncommitted; it needs its own branch + PR.
2026-08-31 11:02 CDT · verified · handoff: PR undrafted, Jira In Code Review, Build Record 38740, okf wiki updated pre-merge (lint passed)

## Phase 7 step 6 — Done gates + teardown

REWORK_GATE: pass (2 files changed since reviewed_sha, <= materiality 3, no sensitive globs, no
pivot — the okf edit correctly classified as light-path drift).
BUILD_RECORD_GATE: pass id=38740.

Teardown via qa-complete in QA_TEARDOWN_ONLY=1 mode (handoff had already posted the Build Record,
transitioned Jira, and finalized the PR — no duplicates created).

DELIBERATE DEVIATION: did NOT run clickhouse-qa down.sh. The ledger's clickhouse.databases were the
LITERAL clarity/zod — which are also in shared_databases_do_not_drop and currently serve
clarity_claude_dev_2409 and clarity_claude_dev_2426 for other in-flight tickets. down.sh reads .env
to choose its targets, so running it would have dropped databases three other worktrees depend on.
Tore CH down manually instead: deleted only this run's seeded rows, restored .env/.env.testing from
the backups, left the shared container and databases standing (verified intact).

Destroyed: Postgres zod_claude_dev_2451_50da1a + zod_testing_claude_dev_2451_50da1a (both behind
the zod_/zod_testing_ guards; shared zod/zod_testing verified INTACT), Herd site unlinked (probe
now 000), browser sessions swept, ledger deleted.

FINDING worth carrying forward: the seeded CH rows were ALREADY 0 on arrival. query_log shows the
seed applied at 15:01:04Z and a TRUNCATE burst on clarity.kit_scan_paths / clarity.sc_positions at
16:03:24Z — from a test run in ANOTHER worktree, since RequiresClickhouse truncates the shared
literal tables by hardcoded name. The operator's QA window (guide written 15:49Z, "I've completed
QA" ~15:56Z, Build Record 15:58Z) PRECEDED the wipe, so that QA ran against intact data. But the
margin was ~7 minutes; slightly different timing and he would have QA'd an empty map with no
indication. This is the concrete instance of a hazard the knowledge card now documents.

phase=done.
2026-08-31 11:05 CDT · done · DEV-2451 complete: Build Record 38740, PR #375 ready, Jira In Code Review, okf updated, QA env torn down (shared CH preserved)
