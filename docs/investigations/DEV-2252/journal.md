2026-09-16 12:11 CDT · intake · intake complete: route=bug app=clarity base=main

## prep escalation (2026-09-16)
- Fast pass (sonnet) returned confidence=64, escalate.recommend=true.
- Triggers fired: confidence<80; escalate.recommend; unmerged-branch dependency (DEV-2320 on `otto`, In Code Review, touches the same zip->zone domain); open questions with answered:false.
- Deep path dispatched: 3 blind prep candidates (sonnet) + grep backstop (haiku), in parallel.
- Base branch decision: `main`. The base-resolve sub-agent proposed `otto` at conf 95; the driver OVERRODE it. Grounds: main already ships the admin zip->zone surface (ZoneZipSearchController, ManageZones, Tiger\ZipCode5All, resources/js/components/zone-manager.js); `otto` only adds the DEV-2320 chat path on top and LACKS main's OrgRunArea/RunArea Materializer.
- base branch question raised by operator (deploy vs main). Evidence: origin/deploy is 0 ahead / 64 behind origin/main; last 15 merged PRs all targeted main. Deploy is the release-promotion branch. Operator confirmed: KEEP main. No re-target.
- 4 prep passes reconciled (fast pass + 3 blind candidates). Reconcile ran INLINE on the driver (opus = TICKET_PLAN_MODEL) because all candidate outputs were already in driver context; a dispatch would have re-sent them with no fresher judgment. Recorded for provenance.
- Candidate grounding check: C1 and C3 cited line numbers matching the real Zone.php (rebuild@251, searchZipCodes@329). The fast pass and C2 cited offsets that do not exist in the 354-line file; down-weighted on grounding, but their findings were independently re-verified by the driver against source.
- Driver independently verified every recorded defect against: app/Models/Zone.php:251-354, app/Filament/Pages/ManageZones.php:276-330, app/Services/Zones/ZoneKmlImporter.php:55-75, resources/js/components/zone-manager.js:592-650 and :418-432, tests/Filament/Pages/ManageZonesTest.php:188-220.
- Outcome: NOT one bug. Five distinct findings (D1-D5). D1/D2/D3/D4 confirmed locally with no production data needed; D5 (Census ZCTA coverage gap) unsizable locally (tiger_data.zcta5_all has 0 rows, no CDC mirror).
- KEY: D2 - tests/Filament/Pages/ManageZonesTest.php:191 test_saves_a_zip_only_zone passes WITHOUT seeding tiger data and WITHOUT asserting geom. The suite pins the silent-save bug as correct behavior. That is a local, reproducible proof of D1 that no individual candidate identified as such.
- gate = BLOCKED: 5 material open questions (Q1 failing zips, Q2 ZCTA-gap product response, Q3 DEV-2320 sequencing, Q4 DEV-2557 merge collision, Q5 Materializer sibling defect).
2026-09-16 12:34 CDT · prepped · prep reconciled: 5 findings, gate=BLOCKED on 5 material questions

## Phase 2 question gate (2026-09-16) — interview answers
- Q1 (which zips failed?) — NOT ANSWERED. Driver downgraded from blocking to an unresolved premise and stated the reasoning to the operator in chat. All four code defects are fixed regardless, so the answer does not change what is built — only what the Build Record may claim. Build Record MUST record that we could not confirm which defect produced the reported symptom.
- Q2 (no-boundary zips) → clear error + draw manually. Geocoding fallback explicitly DECLINED. · human:Dalton
- Q3 (DEV-2320 sequencing) → land on main now; DEV-2320 rebases and picks up the guard via PersistZone. Note on the PR. · human:Dalton
- Q4 (DEV-2557 collision) → proceed, accept the mechanical conflict. · human:Dalton
- Q5 (Materializer sibling defect) → FIX IN THIS TICKET. Operator OVERRODE the driver's follow-up-ticket recommendation. Materializer.php moved out_of_scope -> in scope. · human:Dalton
- gate recomputed: CLEAN. target_files = 5.
2026-09-16 12:38 CDT · gated · gate CLEAN: 4 answered, Q1 downgraded to unresolved premise; Materializer in scope per operator

## Phase 2.5 handoff (2026-09-16)
- Session A renamed "DEV-2252: Phase A - Gate" (desktop, set_session_title self).
- Session B spawned: 87619a25 / 87619a25-f589-4510-91d8-7ade945389f8, name "DEV-2252: Phase B - Implement",
  model opus, effort high, mode inplace, permission auto, cwd = run root. Verified running via `claude agents --json`.
  Window: /ticket DEV-2252 --resume --stop-at=review.
- Session A's own id is NOT exposed to itself (list_sessions excludes the current session); the recorded id was
  derived from the scratchpad path and is flagged as unverified in state.spawned_sessions.
2026-09-16 12:39 CDT · gated · handed build segment to session 87619a25

## Phase 3 plan (2026-09-16) — session B
- Resumed as session B (build segment). resume-validate: ok. Resolved window recorded from phase-plan.sh output: start_at=plan, stop_at=review (session A had left stop_at=handoff, which would have hidden the step-7 QA handoff condition).
- Jira MCP IS available in this spawned session (inherited connectors). Boundary check ran: ticket comments re-fetched, anchor = prep-context.generated_at 2026-09-16T17:30:00Z. Only comment is our own status mirror (40032). No scope change landed during the build.
- Ticket already sits In Progress, so the Phase 4 entry transition is a no-op; recorded rather than re-posted.
- Plan authored INLINE on the driver (opus = TICKET_PLAN_ARBITER_MODEL tier) rather than dispatched: prep-context plus the driver's own source verification were already in context, so a dispatch would have re-sent them with no fresher judgment. Same provenance reasoning session A recorded for the Phase 1 reconcile.
- 3 subtasks. Sizing gate EVALUATED at 6 target files = threshold, not over: no split recommended, and the one separable concern (Materializer) is in scope by explicit operator override of the driver's own recommendation (Q5). Re-recommending a split would re-litigate a settled decision.
- Blast radius: none. No target file matches the sensitive globs; no pre-approval line required.
- perf gate: not required (resource_envelope.scale_relevant=false; no CH surface at all, ch_tables empty).
- Execution shape: t3 serialized behind t1 (both exercise the shared branch test DB; CLARITY_BOOTSTRAP_CMD is unset so per-node worktrees would NOT get re-keyed DBs, and concurrent migrate:fresh on one DB is the contention the flow warns about). t2 is DB-free and parallel-safe. All nodes run in REPO_PATH against the standing env.
- NEW finding not in prep, recorded in the plan: the ManageZonesTest tiger seed must go through the PLATFORM connection, not zod. config/database.php gives zod and platform the same database but SEPARATE PDO connections, and TestCase::connectionsToTransact() wraps both, so a row seeded on zod is invisible to the platform connection that rebuildGeometryFromDefinition() actually reads. ZoneZipSearchControllerTest never hit this because it reads back on zod too.
- Test policy reconciled: CLAUDE.md forbids automatic FULL-suite runs; the repo's own boost guidelines require the affected tests to be run with a filter. Nodes run filtered tests only.
2026-09-16 12:47 CDT · planned · plan: 3 subtasks (D1/D2 guard+tests, D3/D4 client, Materializer sibling); sizing gate not tripped; no blast radius

## Phase 3.5 provision (2026-09-16)
- Isolation guard PASSED: run root is a worktree, not the main checkout. qa-prep mode detection keys on the PATH pattern */.claude/worktrees/*, not the directory name, so the worktree being named metric-cards-org-help-27a357 rather than DEV-2252 does NOT push it into checkout mode.
- provision-only, QA_PROVISION_CH=no. Warm path: both zod_claude_dev_2252_f748a2 and zod_testing_claude_dev_2252_f748a2 already existed AND were migrated, so bootstrap was skipped.
- SAFETY FIX: .env CLICKHOUSE_HOST was pointing at ClickHouse CLOUD (zd69ju2h8d.us-east-2.aws.clickhouse.cloud). Neutralized to 127.0.0.1 in BOTH .env and .env.testing, so a stray CH hit during Phase 4/5 fails loud-and-local instead of reaching production. Prior value recorded in the ledger's env_restore for teardown.
- MAIL_MAILER -> log in both files; .env.testing gained DEV_LOGIN_ENABLED/APP_ENV.
- tiger.state PostGIS patch applied to BOTH dev and testing DBs. The flow's Step 4 patches only $DEV_DB, but Phase 4/5 tests run against the TESTING db, so it was patched there too. simplified_geom was absent in both beforehand.
- Verified the tables this ticket needs exist in the TESTING db: tiger_data.zcta5_all, platform.zones, platform.org_run_areas.
- Baseline db:seed re-run on the dev DB (TIGER_CHANGED=yes trigger), exit clean. One SKIPPED line: ClarityScoutClickhouseSeeder skipped because CLICKHOUSE_DRIVER is not pgsql - expected, no CH surface in this change.
- Ledger written to .context/qa-prep-ledger.json with provision_mode=provision-only, plans_written=false, clickhouse.strategy=deferred.
2026-09-16 12:49 CDT · provisioned · provision: isolated PG env standing; CH deferred + host neutralized off Cloud; tiger patch on both DBs

## Phase 4 implement (2026-09-16)
- perf-gate: pass (not required; scale_relevant=false). Jira already In Progress, so the entry transition was a no-op. CH ensure SKIPPED by the eager-skip gate (ch_tables empty, no app/Clickhouse touched, no CH-referencing test) - CH stayed down all through implement.
- t2 (client) landed clean: /^\d{3,5}$/ -> /^\d{5}$/ at both sites, `|| rows[0]` replaced by an explicit `if (!exact) return 'not_found'` placed BEFORE the exact.zip dereference. Bundle rebuilt; manifest verified to have no dangling references; clarity-scout-sw.js is pure APP_VERSION noise.
- t1 (server guard) landed but its worker could NOT confirm red->green and reported status=blocked. Driver verified everything by hand.

### The big one: seeding tiger on the `platform` connection HANGS the suite
The plan told t1 that ManageZonesTest's tiger seed must go through `platform`, because `zod` and `platform` are separate PDO connections and rebuildGeometryFromDefinition() reads on `platform`. The visibility half of that is TRUE - but the conclusion was wrong, and acting on it deadlocks:
- With the row seeded on `platform`, `php artisan test tests/Models/ZoneTest.php` never finishes.
- pg_locks, captured mid-hang: the platform backend waits for AccessShareLock on `public.spatial_ref_sys`; the zod backend holds AccessExclusiveLock on it, from HasZodDatabase's `DROP EXTENSION IF EXISTS address_standardizer_data_us CASCADE`, for the entire life of the test transaction.
- ST_Transform(...,4326) MUST read spatial_ref_sys to project, so the zip path can never be resolved from the platform connection inside this harness. When the zip resolves to NOTHING, ST_Union returns NULL and ST_Transform(NULL) short-circuits without reading spatial_ref_sys - which is why the unresolvable case works fine and the resolvable case hangs.
CONCLUSION: the resolvable-zip happy path is NOT pinnable by the PHP suite. Reverted ZoneTest to exactly HEAD + one new test. Replaced ManageZonesTest::test_saves_a_zip_only_zone (which asserted the BUG - success over a NULL geom) with test_zip_only_zone_with_no_census_boundary_is_rejected, which proves the fix. Happy path documented in the docblock as browser-QA coverage, same bucket as D3/D4.

### Correctness fix to the worker's code
t1's `catch (RuntimeException)` would have turned ANY RuntimeException raised inside the save transaction into a "No Census boundary found" form error. test_save_persists_the_zone_and_assignment_when_broadcasting_throws deliberately throws RuntimeException in that transaction (currently rescued, so latent - but the catch was still wrong). Gated it on a sentinel set only by the empty-geometry guard; anything else rethrows.

### Test-environment damage and recovery
The t1 and t2 workers plus my own run left FOUR concurrent phpunit processes against the one branch test DB, and killing them left orphaned `idle in transaction` Postgres backends holding locks on platform.permissions/migrations - every later run blocked behind them and a "baseline" run reported 9 failures including pure-constant tests. Cleared the backends, migrate:fresh'd the testing DB, re-applied the tiger patch, and only then trusted any result. t3 was therefore implemented inline by the driver instead of dispatched.

### Results (each run alone, clean DB)
- ManageZonesTest 26 passed / 0 failed  (new rejection test verified RED on unfixed source, GREEN with the guard)
- MaterializerTest 7 passed / 0 failed  (2 new tests verified RED then GREEN; the 5 pre-existing contract tests still pass - empty inputs and unknown-kind-only still return early, leave geometry NULL, and do NOT throw)
- ZoneTest 12 passed / 3 failed - all 3 PRE-EXISTING: pristine HEAD on the same clean DB gives the identical 3 failures (11 passed there vs 12 here, the +1 being this ticket's new never-throw test). Two are the spatial_ref_sys limitation above; is_no_scan_zone_defaults_to_false is a separate Zone::create default/cast issue, outside this ticket.
- Regression set 15 passed (MaterializeOrgRunArea, OrgRunArea, ZoneZipSearchController) + KmlZoneParser 11 passed.
- composer format (pint): passed. Blast-radius re-check: no sensitive glob matches.
2026-09-16 14:05 CDT · implemented · implement: D1 guard + D3/D4 client + Materializer sibling guard; 3 nodes, red->green proven on both new guards

## Phase 5 review round 1 (2026-09-16) — Score 82/100, gate FAILED
- 1 lane. Lane count per the rule: none of the three escalation triggers fired (no counting/aggregation/dedup, auth, money, or date-boundary logic; no blast radius; scale_relevant=false).
- PROVENANCE: lane ran IN-SESSION via insightlpr:adversarial-reviewer, not review.sh. Reason is recorded, not silent: the full diff review.sh builds is 1.36 MB because public/build is tracked and the rebuilt minified bundle dominates it; the source-only patch is 15 KB. review.sh hardcodes its exclude list (EX=":(exclude).ai") with no flag to add public/build, so the harness path would have buried 15 KB of real logic in 1.3 MB of minified JS. Lane was handed .ai/_source-patch-1.diff plus the taxonomy, prep-context, and the driver's captured test results. lane_sources records in-session-agent.
- Lanes were explicitly forbidden from running the suite (one branch DB; concurrent runs deadlocked twice in Phase 4) and handed .ai/_test-result-1.txt instead.

### H1 was a real miss, and the driver had not caught it
A rejected save rendered NOTHING to the admin. ValidationException was attached to `data.definition`, whose only form component is Filament\Forms\Components\Hidden — verified against vendor: hidden.blade.php is a bare <input> with no field wrapper and no @error block, and Hidden.php sets columnSpan(['default' => 'hidden']). Separately, throwing ValidationException by hand from save() bypasses Filament's own onValidationError() toast, which only fires from InteractsWithSchemas::validate()/validateOnly(). manage-zones.blade.php contains zero error surfaces. Net effect: the guard rolled back correctly and the admin saw silence — on the EDIT path, strictly worse than the lying "Zone saved" it replaced. Driver independently confirmed all of this against vendor source before acting. Fixed by also sending a persistent danger Notification.

### M2 was the same class of bug one level down
ST_Union over ARRAY[...] is ST_UnaryUnion(ST_Collect(...)), and ST_Collect DROPS NULL members. So polygons + an unresolvable zip yields a NON-empty geometry, passes the emptiness guard, saves "successfully", and silently discards the zip — exactly the silent-failure class this ticket exists to close, surviving inside the fix for it. Now checks unresolved zips independently of emptiness. New test proven red on the round-1 code, green on round-2.

### M3 — both new Materializer tests could pass for the wrong reason
(a) QueryException extends RuntimeException, so expectException(RuntimeException) would also pass if the UPDATE failed before the guard ran; now asserts the message. Premise resolved rather than assumed: tiger_data.state_all IS readable from the platform connection in the branch test DB (exists, 0 rows), so the guard genuinely was exercised — it was a latent weakness, not a live false pass. (b) PHPUnit's AssertionFailedError also extends RuntimeException, so $this->fail() inside the try was being swallowed by the adjacent catch — the guard was dead code. Restructured.

### Also fixed: L1 (message helper could throw a QueryException while the sentinel was null, masking the real error with a 500 — now degrades), L2 (restored the definition-normalization coverage lost with the replaced test).
### Residual, not fixed: L3 (sync-queue 500 on the Backyard webhook; production runs Horizon tries=1 so it is one failed_jobs row, the intended signal — the fix would touch BackyardController, outside target_files) and a PRE-EXISTING Materializer::polygonPiece() missing ring-arity check the sweep surfaced.
- Tests after the fixes: ManageZonesTest 28 passed, MaterializerTest 7 passed, regression set 26 passed, ZoneTest unchanged at 12 passed / 3 pre-existing failures. pint passed.
2026-09-16 14:24 CDT · reviewed · review RN1 82/100 gate FAIL; fixed H1 (rejection rendered nothing) + M2 (partial zip still silent) + M3 test integrity + L1/L2

## Phase 5 review round 2 (2026-09-16) — Score 91/100, gate PASSES
Round 2 cleared the threshold, but surfaced two things worth a third round. Recorded as reason=regression, NOT a gate-fix cycle: the 1-fix-cycle bound applies to rounds where the gate FAILED, and one of the two findings is a defect this pipeline's own round-2 fix introduced. Parking on that would have left the tree worse than it started.

### M1 — I applied my own insight to one file and not its sibling
Round 1 taught us that ST_Union over ARRAY[...] DROPS NULL members, so emptiness is not a sufficient post-condition. I applied that to ManageZones (unresolvedZipCodes) and did NOT apply it to Materializer, which uses the identical construct. Net effect: inputs [valid polygon, state 'ZZ'] produced a non-empty geometry, passed the emptiness guard, and got stamped materialized while silently excluding an entire state - which then under-clips every Scout heatmap with no signal at all. That is the exact defect class this ticket exists to close, on the file the operator explicitly widened scope to include (Q5). Added unresolvedReferences(); proven red->green (against round-2 code the new test does not throw AT ALL).

### L1 — a regression my own round-2 fix introduced
The round-2 hardening wrapped the zip lookup in catch(Throwable) INSIDE the save transaction. Postgres aborts the whole transaction on any statement error, so a swallowed error there makes the subsequent COMMIT return as a silent ROLLBACK - and save() then falls through to Analytics::track and the "Zone saved" success toast for a zone that does not exist. That is AC-2 exactly inverted, reintroduced by the hardening meant to protect it. Fixed by hoisting the lookup above the transaction (it reads nothing the transaction writes); as a bonus that also takes the reference-table scan out from under the zones row lock.

### L2 — zcta5_all.the_geom is nullable
Row existence was the wrong test: a row whose the_geom is NULL contributes nothing to the union, so such a zip was reported "resolved" and silently dropped. Now whereNotNull('the_geom'), applied to the new Materializer reference check too.

### Deliberately NOT fixed (residual, with reasons)
- RN2-M2: the pre-existing `definition` ->rule() path (no polygon AND no zips) also renders nothing, same root cause as H1. Different trigger from this ticket's zip path, and fixing it properly means a blade/view change outside target_files. This ticket already widened scope once by explicit operator decision; widening again unasked is not the driver's call. Recommending a follow-up - it is cheap and it is the same "doesn't register" symptom users report.
- L3 (sync-queue 500) and the pre-existing polygonPiece() ring-arity gap: both triaged the same way by both rounds.

### Note on a test file that could not run locally
tests/Feature/Http/Controllers/Api/ClarityScoutMapControllerTest.php calls Materializer::materialize() at two sites, and ALL 65 of its tests SKIPPED locally - the class is RequiresClickhouse and CH is deliberately down for this CH-free change. Both call sites pass polygon-only inputs, so unresolvedReferences() returns [] and they are structurally unaffected. CI runs this file with ClickHouse available (the PR targets main), so it is covered there. Recorded as an unresolved premise rather than claimed as verified.

- Tests after round 3: ManageZonesTest 28 passed, MaterializerTest 8 passed, regression set 26 passed, ZoneTest unchanged at 12 passed / 3 pre-existing failures. pint passed.
2026-09-16 14:40 CDT · reviewed · review RN2 91/100 PASS; round 3 fixes M1 (Materializer mixed-input silent drop) + L1 (silent-rollback regression from RN2) + L2
2026-09-16 14:53 CDT · reviewed · review RN3 93/100 PASS; round 4 closes the null-piece silent drop + per-kind degrade + asserts the notification

## Phase 5 review rounds 3-5 (2026-09-16) — 93, then 94, then final
Scores: RN1 82 (FAIL) -> RN2 91 -> RN3 93 -> RN4 94. Every round found a real, distinct defect, so each was worth running; RN5 is declared the final round to stop the regress - anything it surfaces below HIGH is recorded as residual and handed to QA/human review.

### RN3 -> round 4 fixed
- M1: unresolvedReferences() only inspected state/msa entries with a non-empty STRING reference, so a recognized kind yielding no SQL piece slipped through - a `polygon` whose geometry is a JSON string, an int state FIPS, an empty msa reference. inputs=[polygon-as-JSON-string, state TX] stamped materialized covering Texas only, silently dropping the operator's polygon. Plausible precisely because the SIBLING builder (Zone::rebuildGeometryFromDefinition:259) explicitly tolerates a JSON-string polygon while polygonPiece() rejects it, and inputs is Backyard-owned and unvalidated in Clarity.
- L1: the try wrapped the whole two-kind loop, so an msa-pass failure discarded an already-collected state finding. Now per-kind.
- L2: the danger Notification - the ONLY user-visible half of the H1 fix - was unpinned; deleting those 5 lines left the suite green while the reported symptom returned. Now ->assertNotified('Zone not saved'), an API already used in ZoneResourceTest.

### RN4 -> round 5 fixed
- M1: $malformed was collected but `if ($pieces === []) return;` fired BEFORE it was read, so an ALL-malformed set still no-oped silently. Sharper than the mixed case it followed: one bad serialization drops EVERY entry, so the silent branch is the LIKELIER production shape. On re-materialize it left the row holding its old geometry and old materialized_at while the operator's change was ignored. Rejection moved ahead of the no-pieces return; the two documented-contract early returns (empty inputs, unrecognized-kind-only) are untouched and still silent by design.

### Correction to accept from RN4 (I had mis-cited coverage)
The sentinel's rethrow branch is NOT covered by test_save_persists_the_zone_and_assignment_when_broadcasting_throws - that RuntimeException is swallowed by ShouldRescue and never reaches the catch. It IS covered by test_save_rolls_back_and_does_not_broadcast_when_geometry_rebuild_fails, whose malformed ring raises a QueryException (a RuntimeException subclass) with the sentinel null. Coverage exists; my citation was wrong.

### Open premise that matters at deploy time
tiger_data.state_all is EMPTY locally and NOTHING in this repo populates simplified_geom on that table (StateSeeder.php:36-38 adds the column to `tiger.state`, a DIFFERENT table, no backfill). If prod simplified_geom is NULL, the new whereNotNull turns mixed polygon+state run areas from silently under-clipping into loud failures. That IS the intended direction, but it is a live behaviour change on unverified data. Blast radius is narrow - a state-ONLY area already threw via the emptiness guard. Verify read-only before merge:
  SELECT count(*) AS rows, count(simplified_geom) AS with_geom FROM tiger_data.state_all;
2026-09-16 15:05 CDT · reviewed · review RN4 94/100 PASS; round 5 closes the all-malformed silent no-op (last known instance of the ticket's own defect class)

## Phase 5 FINAL — RN5 94/100, REVIEW_GATE: pass score=94 src=arbitration lane_min=94 sources=in-session-agent:1
Scores across the sequence: 82 (FAIL) -> 91 -> 93 -> 94 -> 94. RN5 explicitly found nothing at HIGH, which is where the declared stopping rule bites: fix only a HIGH, record the rest, hand off. Stopping here is a decision, not an oversight - rounds 2-5 each cost ~10-12 min and the remaining items are smaller than the process cost of another cycle.
- reviewed_sha = d2217b6e4 = HEAD. rerun_required=false. rework-gate will therefore pass in Phase 7 as long as nothing else commits code.
- Deliberately NOT fixed, with reasons, all carried in state.record.review.findings_residual:
  * RN5-M1 hydrateZips() client-side silent truncation - PRE-EXISTING, same class as D1/D4, but its sibling half (geometryToRings interior rings) is owned by the unmerged DEV-2557 per Q4, so fixing it here would collide with that branch. Strongly recommend a follow-up.
  * RN5-M2 no happy-path coverage for a REAL state/msa reference - not closable in this harness (same spatial_ref_sys wall), routed to QA as a verify step.
  * The `...$malformed` spread at Materializer.php:78 is now dead code I introduced in round 5. Harmless; fixing it would invalidate reviewed_sha and force a sixth round for a no-op.
- Provenance is recorded, not silent: all five lanes ran in-session via insightlpr:adversarial-reviewer rather than review.sh, because review.sh hardcodes EX=":(exclude).ai" and public/build is tracked here, so the harness path would have handed each lane a 1.36 MB prompt that is ~98% minified bundle. manifest lane_sources says in-session-agent; the gate printed sources=in-session-agent:1.
2026-09-16 15:19 CDT · reviewed · review COMPLETE: RN5 94/100, gate pass (src=arbitration); 2 residual mediums recorded + follow-up recommended

## Phase 5 step 7 — QA handoff (2026-09-16)
- Gates BEFORE handing off: REVIEW_GATE pass (score=94, src=arbitration) and REWORK_GATE pass (only investigation artifacts moved since reviewed_sha d2217b6e4).
- PR #414 Status block and the Jira status-mirror comment (40032, edited in place - no duplicate) both regenerated to phase=reviewed, carrying the residuals, the follow-up recommendations, and the unresolved premises.
- Session C spawned: 248c8181, name "DEV-2252: Phase C - QA", opus/high, mode inplace, permission auto, cwd = run root. VERIFIED running via `claude agents --json` rather than assumed from the spawn output.
- Its brief (.spawn-prompt-qa.md) leads with `claude stop 87619a25-...` so this build session does not linger, and it spells out the three things only browser QA can settle (resolvable-zip happy path blocked by the spatial_ref_sys lock; D3/D4 with no JS runner; and whether the new rejection toast is actually VISIBLE - the review's highest-value catch), plus the read-only production queries for the simplified_geom premise.
- PARKING here: the invocation window was --stop-at=review, and step 7 is the last step of Phase 5, not something that follows it.
2026-09-16 15:21 CDT · reviewed · Phase 5 complete: gate pass 94, QA session 248c8181 spawned and verified; parking at --stop-at=review

## Phase R — resumed by session C (QA segment)
- `claude stop 87619a25-...` returned "No job matching" — the build session had already exited on its own, so nothing was left running. Recorded as fact, not assumed.
- `phase-plan.sh --resume` → `PHASE_PLAN: verify handoff` (PHASE_START=verify, PHASE_STOP=handoff). `resume-validate.sh` → `RESUME_VERDICT: ok`.
- Done-signals re-verified from artifacts rather than inherited from the prior session: `review-gate.sh ... --rn=5` prints `REVIEW_GATE: pass score=94 src=arbitration lane_min=94 lanes=1 sources=in-session-agent:1`; `rework-gate.sh` prints `REWORK_GATE: pass (only investigation artifacts changed since review)`. HEAD c5a0c31c9 differs from reviewed_sha d2217b6e4 only by journal.md + state.json.
- `.context/` holds ONLY qa-prep-ledger.json + .gitkeep — no stale qa-ac-results.json from a prior ticket in this reused worktree, so the Phase 6 AC fold-in cannot false-pass on someone else's artifacts.
- Claim marker on PR #414 re-stamped to this session.
2026-09-16 15:22 CDT · reviewed · Phase R: QA session resumed; window=verify handoff; review+rework gates re-verified from artifacts

## Phase 6 — provisioning + a worktree incident
- qa-prep ran in FULL mode and detected the Phase-3.5 env (`provision_mode: provision-only` + a live env + `.env` still on the branch DB), so it took the `PROVISION_REUSE` path: no re-bootstrap, no re-migrate. ClickHouse stayed deferred (`QA_PROVISION_CH=no`) — this change touches no CH table.
- Scale gate: qa-prep Step 6b-2's mechanical trigger DID fire, on two new `->pluck()` calls. Examined rather than waved off: both are `whereIn(<caller-supplied list>)->distinct()`, so the result set is bounded by the zone's zip list / the run area's reference list, not by table cardinality. Recorded `scale_relevant: false` WITH that reason, not by copying prep-context's verdict.
- Seeded (tinker, idempotent, persisted at `.context/qa-seed.tinker.php`): `tiger_data.zcta5_all` 75201/75202 with real MULTIPOLYGON boundaries, 75203 with `the_geom` NULL, and 99999 deliberately absent — the three distinct states the guard has to tell apart. Plus 2 ADMIN personas in a CLARITY_SCOUT-enabled org and 1 pre-existing polygon zone. Persona capabilities were asserted THROUGH the application (`$user->can(...)`, `Feature::for($user)->active(...)`), not by reading a column.
- Schema finding worth recording: `tiger_data.zcta5_all` carries `CHECK ((geometrytype(the_geom) = 'MULTIPOLYGON') OR (the_geom IS NULL))`. A NULL boundary is a SCHEMA-PERMITTED state, so review's RN2-L2 finding defends a state the schema explicitly allows — it is not hypothetical, independently of whether a production row currently has one.

### INCIDENT — the worktree was taken over mid-prep
Phases 0-5 ran in `.claude/worktrees/metric-cards-org-help-27a357`, a REUSED, generically-named worktree (Phase 0 step 6 adopts the session's current worktree, and this one was not named `<KEY>`). Partway through Phase 6 a concurrent interactive session started DEV-2591, adopted the same tree, checked out `claude/dev-2591-651eed`, and re-keyed `.env` to `zod_claude_dev_2591_651eed`. Nothing flagged the collision because the directory name carried no ticket identity.

Nothing was lost. The branch was already pushed at `eb2c1d7dc` and the seeded DB `zod_claude_dev_2252_f748a2` was never touched by the other run (it wrote only to its own DB).

Recovery, chosen so the DEV-2591 tree was never modified:
- `git worktree add .claude/worktrees/DEV-2252 claude/dev-2252-f748a2` (the branch was checked out nowhere, so this was clean).
- `vendor/` + `node_modules/` copied from the main checkout (copied, never symlinked — the classmap breaks). `insight-lpr/laravel-zod-migrations` confirmed present.
- `.env`/`.env.testing` written to the EXISTING seeded DBs, with `CLICKHOUSE_HOST` re-neutralized to `127.0.0.1`, `MAIL_MAILER=log`, `DEV_LOGIN_ENABLED=true`. `APP_KEY` lineage confirmed identical to the main checkout.
- `bootstrap.sh` was deliberately NOT run: it guards migrate behind `migrate:status`, so it would have been safe, but writing `.env` by hand removed any chance of `dev:migrate` (= `migrate:fresh`) wiping the seed.
- Herd site `clarity-claude_dev_2252_f748a2` moved to the new path (unlink → link → secure). Unrelated `.test` sites were checked before and after (302 both times) because one bad Herd nginx config takes down every site on the machine.
- `herd link`'s Laravel Boost step rewrote `CLAUDE.md`, `AGENTS.md` and `.junie/guidelines.md`; reverted immediately, tree clean — those would have counted as material post-review rework and broken `rework-gate.sh`.
- This ticket's 4 stale `.context` artifacts were removed from the DEV-2591 tree so ITS qa-prep cannot false-pass on a foreign ledger.

Also corrected here: the handoff's first instruction, `claude stop 87619a25-f589-4510-91d8-7ade945389f8`, returned "No job matching" and was initially read as "already exited". It was not — `claude stop` wants the SHORT id. `claude agents --json` still listed `87619a25` as running, and `claude stop 87619a25` returned `stopped 87619a25`. The build session ran unattended for the whole first part of this segment.
2026-09-16 15:46 CDT · reviewed · Phase 6: env recovered into a dedicated worktree after a concurrent session claimed the shared one; QA world seeded and verified

## Phase 6 — agent QA: 17/17 pass
- ONE browser lane, not two, deliberately: the plan's F1 and F2 both drive `/app/manage-zones` against the same database, and A7 asserts a zone COUNT. Parallel lanes would have made that assertion meaningless. Folding was a correctness decision, not a capacity limit.
- **The three things only a browser could settle are settled**, with the exact on-screen strings captured, not paraphrased:
  * A2 — `7520` → `Not a valid ZIP: 7520`, nothing attached. (Before the fix a 4-digit token was accepted and prefix-matched.)
  * A3 — `75209` → `No boundary found for: 75209`, and NO unrelated polygon substituted. (Before the fix `addZip()` fell back to `rows[0]`.)
  * A6 — a red, PERSISTENT `Zone not saved` toast naming `75203`. This is the review's highest-value catch: the guard was already rejecting correctly but rendered NOTHING, because `definition` is a `Hidden` component with no error slot. Screenshot `A6-zone-not-saved-toast.png` is the proof.
- A5 re-opened the saved zone from the list rather than trusting the success toast — the original bug was precisely a truthful-looking toast over a zone with no geometry, so a toast alone could not pass that item.
- A8 confirmed the `ST_Union`-drops-NULL trap is closed end to end: a valid zip alongside an unresolvable one still rejects, naming only the bad one.
- A7 re-verified INDEPENDENTLY by the driver, not taken from the lane: null/empty-geom zone count is still 0 after both rejections. Ids 3/4 are a rolled-back sequence gap, not leaked rows.
- Backend lane 6/6. **B3 closes the gap review recorded as RN5-M2**: every existing Materializer test proves the new guard FIRES; none proved it stays QUIET on good input. Polygon-only input → `ST_MultiPolygon`, non-empty, stamped `tiger:2019`, no throw. B3/B4 ran inside always-rolled-back transactions; `org_run_areas` count afterwards was 0.
- B5: `ManageZonesTest` + `MaterializerTest` 38 passed / 0 failed. `ZoneTest` 12 passed / 3 failed.
- The 3 `ZoneTest` failures were VERIFIED pre-existing three independent ways rather than repeated from the build session's claim: (1) `app/Models/Zone.php` is untouched by this diff, so the code under test is byte-identical to main; (2) the `ZoneTest.php` diff only ADDS one test, which passes — the three failing tests are unchanged; (3) the review's own `.ai/_test-result-1.txt` recorded the same three failing on pristine main. Claim (1) is the decisive one: unchanged tests over unchanged code cannot have a different outcome.
- Laravel log: 5 ERROR lines, ALL one cause and none from the zone pages — a cURL connect failure to `127.0.0.1:8443`, the ClickHouse host this run deliberately neutralized, raised by the Filament stats-overview widget on `App\Filament\Pages\Dashboard` (the post-login landing). That is the neutralization working as designed, not a regression.
- `state.qa.verdict = PASS`; `record.ac` folded in 5 SATISFIED + 1 N/A. AC-4 is N/A on purpose: it is the ticket's own QUESTION, not a behaviour, and Q1 was never answered — so this ticket cannot claim which defect produced the reported symptom.
- Resource envelope: `not-measured`, recorded as an explicit N/A with the reason (not scale-relevant; the mechanical trigger fired and was examined, not waved off).

### Still open, and only settleable off this machine
`tiger_data.state_all` and `tiger_data.tl_2019_us_county` are BOTH EMPTY in this QA database (0 rows each), so every state/msa reference is unresolvable here by construction. B4 therefore proves the new guard FIRES but says nothing about the case where the reference is valid. Combined with the production `simplified_geom` question, this is the one genuinely open risk at merge time. An attempt to settle it read-only against the production CDC mirror was DENIED by the permission classifier ("Production Reads"); that was respected rather than worked around, and the exact queries are carried to the operator instead.
2026-09-16 16:01 CDT · verified · Phase 6: agent QA 17/17 pass; 5 AC SATISFIED + 1 N/A; qa.verdict=PASS

## Phase 7 — parked at step 1, awaiting human QA
- Human QA guide rendered and OPENED: `.context/qa-human-guide.html` — 2 sections / 11 steps (ids `hp-1..8`, `ec-1..3` reused verbatim so checkbox state survives), 2 dev-login personas, env 5 ok / 1 warn (ClickHouse deferred, informational) / 0 bad, agent-qa's 3 findings folded in, and 10 of 11 steps carry a curated screenshot. Framed as a CONFIRMATION pass, because agent QA passed 17/17 — the guide says so rather than implying everything is still unverified.
- PR #414 Status block regenerated to `handoff — awaiting human QA`, plus a new "Agent QA — 17/17 pass" section. **Still a draft.**
- Jira status mirror comment 40032 EDITED IN PLACE (no duplicate), carrying the QA result, the exact on-screen strings, and the before-merge queries.
- Phase 7 step 2.4 backstop ran: the ticket has exactly ONE comment (the mirror itself) and nothing landed during the build, so no scope change to reconcile. Ticket status already `In Progress`, so no transition was owed.
- Build Record **DRAFTED AND HELD**, not posted: `docs/investigations/DEV-2252/build-record-draft.md`.

### Why this parks here rather than running to `done`
Phase 7 step 1 parks in `normal` mode until human QA is actually done, and the flow is explicit that nothing past it may run first. Concretely, all four of these are deliberately NOT done: the PR is not undrafted, the ticket is not transitioned to In Code Review, the Build Record is not posted, and the QA environment is NOT torn down — the human needs it standing to do the QA. This also matches the standing operator preference recorded from DEV-2521 ("nothing outward-facing moves until QA is complete"). Resume with `/ticket DEV-2252 --resume`.

### Hygiene check before parking
Per the Boost-regeneration gotcha, `git diff --name-only origin/main...HEAD` (excluding `docs/investigations`) was re-checked at the end and returns EXACTLY the 10 intended files — no `CLAUDE.md`/`AGENTS.md`/`.junie` drift survived from the recovery's `herd link`, and no stray `nginx.pid`. `rework-gate.sh` and `review-gate.sh` both still pass.
2026-09-16 16:07 CDT · verified · Phase 7: parked awaiting human QA; guide rendered, PR+Jira updated, Build Record drafted and held

## Phase R — resumed 2026-09-17 (session D)
- Resumed by daltonmccleery@gmail.com via `/ticket DEV-2252 --resume`. `resume-validate.sh` → `RESUME_VERDICT: ok`; `phase-plan.sh --resume` → `PHASE_PLAN: handoff` (start=stop=handoff).
- Fresh-eyes re-read of `state.json` + `journal.md` + the PR Status block: phase `verified`, PR #414 still DRAFT, no PR comments, no PR reviews, no new commits, working tree clean. Nothing landed since the park.
- QA env confirmed STILL UP: herd site `clarity-claude_dev_2252_f748a2.test`, Postgres `zod_claude_dev_2252_f748a2` / `zod_testing_claude_dev_2252_f748a2`, container `clarity-qa-clickhouse` up 18h (CH strategy is `deferred` — the container belongs to another run's ledger, not this one's; this run's ledger lists no CH container).
- PR claim marker re-stamped to this session. Run is at Phase 7 step 1 (parked awaiting human QA); nothing outward-facing moved on resume.
2026-09-17 08:20 CDT · verified · Phase R: run resumed and re-claimed; still parked at Phase 7 awaiting human QA

## Phase 7 — completed 2026-09-17
- Operator confirmed human QA complete, with no findings and no failing acceptance criterion. That is the signal Phase 7 step 1 parks for, so the closeout ran.
- **Step 2.4 backstop re-run before posting anything:** DEV-2252 still has exactly ONE pre-existing comment (the status mirror, 40032). Nothing landed during or after the build, so there was no scope change to reconcile and nothing to stop for.
- **Step 2 — PR #414 finalized:** undrafted via `pr-draft.sh` and retitled `DEV-2252 | SCOUT: Fix zone creation failure when entering zip codes` (the `[WIP] ` prefix stripped). Status block regenerated to `Phase: done` with the claim marker cleared.
- **Step 3 — Build Record POSTED**, Jira comment **40077**, recorded in `state.comment_ids.build_record`. The held draft was posted with one addition: a `Human QA` paragraph in the QA-performed section, because the draft predated the human pass and the flow requires the record to state that the guide ran.
- **Step 4 — transition:** `getTransitionsForJiraIssue` still offered `In Code Review` and the ticket read `In Progress` after the undraft, which proves no GitHub Action transitioned it. The pipeline transitioned it itself (id 4) → `state.jira_transitioned_by = "pipeline-mcp"`, the designed fallback. Not `human`: no person moved this ticket.
- Status mirror 40032 EDITED IN PLACE to `Phase: done` (no second comment), pointing at the Build Record for detail and keeping the before-merge queries visible.
- **Step 6 — both done gates pass:** `REWORK_GATE: pass (only investigation artifacts changed since review — no code rework)` and `BUILD_RECORD_GATE: pass id=40077 (route=bug, ac=6, review_rounds=5, rounds=gate:2 regression:1 scope:2, unresolved_premises=9, rerun_required=false)`.
- **QA env torn down** via `insightlpr:qa-complete` in `QA_TEARDOWN_ONLY=1` mode, ledger-driven and shape-guarded: dropped `zod_claude_dev_2252_f748a2` + `zod_testing_claude_dev_2252_f748a2`, unlinked `clarity-claude_dev_2252_f748a2.test`, restored the `.env`/`.env.testing` keys qa-prep set, swept this worktree's agent-browser sessions. No ClickHouse teardown ran because none was ever provisioned (`strategy: deferred`) — `down.sh` was correctly skipped rather than run against another run's container.
- **Verified by absence:** the ledger file is gone, which is qa-complete's final act, so `state.qa.torn_down = true` is recorded on evidence rather than on the skill's say-so.
- **Deliberately NOT torn down:** `zod_claude_dev_2252_resume_ad3a03`, `zod_testing_claude_dev_2252_resume_ad3a03`, and `clarity-claude_dev_2252_resume_ad3a03.test`. They match this ticket's key by name but belong to the `dev-2586-fd4bdd` worktree and appear in no ledger of this run's. Shape-guard discipline: tear down only what the ledger names.
- Worktree and branch left intact, per the flow — only the live environment was destroyed.
2026-09-17 08:27 CDT · done · Phase 7 complete: human QA passed, Build Record posted (40077), ticket In Code Review, PR #414 ready, QA env torn down

### CI never fired on PR #414 — observed at handoff
- After the `done` checkpoint (`cd62c5eb1`, a clean CI-triggering message, `--boundary`), `gh pr checks 414` reported **no checks**, and `check-suites` on that SHA returns only the `claude` and `cursor` apps — there is **no `github-actions` check suite at all**. `gh run list --branch claude/dev-2252-f748a2` is empty: `Pint & Tests` has never run for this PR.
- Not a workflow-config mismatch. `lint_check_and_test.yml` triggers on `pull_request` types `[opened, reopened, synchronize, ready_for_review]` with `branches: ["main","deploy"]`; PR #414 is `claude/dev-2252-f748a2` → `main`, non-draft, open. The same workflow ran green for `claude/DEV-2591` an hour earlier, so Actions itself is healthy.
- This is the `[skip ci]` trap: every prior commit on this branch ends `[skip ci]`, and the `ready_for_review` (undraft) landed on `946f70673`, a `[skip ci]` head. The documented recovery — push a clean-message commit — was performed (`cd62c5eb1`) and **did not** recover it either. Recorded because it contradicts the recovery note as written.
2026-09-17 08:31 CDT · done · Record that Pint & Tests never fired on PR 414 and retrigger it

### Session closeout
- `session-closeout.sh <run root> --rm <B> <C> <A>` → `CLOSEOUT: no background sessions in .../worktrees/DEV-2252 — nothing to do`. Session B (`87619a25`) is already gone. Session C (`248c8181`) is still running but `claude agents --json` shows its cwd is now `.../worktrees/metric-cards-org-help-27a357`, NOT this run root — so the script refused it, which is the guard working: a session that has moved to another tree is not this run's to stop. Surfaced to the operator instead of being force-stopped.
- Session A (`3efb3249-…`) does not appear in `list_sessions` at all. `state.json` already flagged that id as DERIVED from a scratchpad path rather than read from the API, so it was verified before acting on — and nothing matched. Nothing archived; no id was guessed.
2026-09-17 08:34 CDT · done · Record session closeout and the CI non-trigger on PR 414
2026-09-17 08:44 CDT · done · Merge main and reconcile built assets so the PR is mergeable and CI can run

## Post-done: merged main to unblock CI (2026-09-17)
### Root cause of the CI silence — found, and it was not `[skip ci]`
- PR #414 was `mergeable: CONFLICTING`, `mergeStateStatus: DIRTY`. GitHub cannot compute `refs/pull/414/merge` for a conflicted PR, and `pull_request` workflows run against that merge ref — so **Actions never scheduled a run at all**. That is why there was no `github-actions` check suite on any commit, why `Kamal PR Preview` (no branch filter) also never fired, and why three clean-message pushes changed nothing. The earlier `[skip ci]` hypothesis in this journal was WRONG; corrected here rather than left standing.
- main was 66 commits ahead. The conflicts were entirely generated build output: a rename/rename on the hashed theme CSS, `public/build/manifest.json`, and `public/clarity-scout-sw.js` (whose only delta is the `APP_VERSION` build stamp written by `scripts/version-sw.mjs`).

### How the assets were reconciled — deliberately scoped
- `git merge --no-ff origin/main`. **Source merged clean**: `resources/js/components/zone-manager.js` kept `/^\d{5}$/` with no conflict.
- Took main's build tree as the baseline, then rebuilt with `npm run build` to see what the merged source actually produces. The rebuild reproduced main's `birdseye-CP3YEPm2.js` and `main-CGMp7D71.js` hashes **exactly**, which is the evidence that the build is deterministic and main's committed JS is in sync.
- The rebuilt theme CSS did NOT match main's committed `theme-BkTA7y8e.css`. The whole delta is 47 bytes: one extra class, `.min-h-47`. Traced it rather than assuming Tailwind had scanned this worktree's artifacts (the known phantom-class trap): `min-h-47` is in REAL tracked source — `app/Filament/Widgets/Dashboard/Concerns/RendersDashboardStat.php` and `resources/css/filament/app/theme.css`. main's tip commit `b28821cf6` (DEV-2586, PR #415) added that usage and changed **only that one PHP file** — it never rebuilt `public/build`.
- **So main's committed theme bundle is stale, and that is main's drift, not this ticket's.** Shipping the rebuild would have silently fixed another ticket's bug inside a zone PR and added 656 KB of unrelated churn, against CLAUDE.md's "do not silently fix unrelated drift". Resolution taken instead: keep main's `theme-BkTA7y8e.css` and `public/clarity-scout-sw.js` byte-for-byte, take main's `manifest.json` and re-point ONLY the `zone-manager` entry at our bundle (a 1-line diff), and drop main's superseded `zone-manager-TYTk7nEp.js`.
- Net effect: the diff vs main went from 10 paths to **8** — the theme CSS and service worker left the diff entirely.

### Verification
- `gh pr view 414` → `mergeable: MERGEABLE`. **CI scheduled immediately**: `Pint & Tests (8.4, 1/2/3)` + `Deploy PR-414 preview`, run `35229050596`.
- **The reviewed code is untouched.** `git diff d2217b6e4..HEAD` over all six files review passed (`ManageZones.php`, `Materializer.php`, `zone-manager.js`, and the three test files) returns EMPTY — byte-identical. The merge changed only which of main's commits sit underneath.

### `REWORK_GATE: fail` after the merge is a FALSE POSITIVE — proven, not assumed
- It named `.deploy/authorization_main.txt.asc`, `app/Filament/Auth/CustomLoginPage.php`, `okf/concepts/billing-events.md`, `tests/Filament/Auth/CustomLoginPageTest.php`, `tests/e2e/helpers/auth.ts`.
- Every one arrived FROM MAIN, not from this run: they are absent from `git diff --name-only origin/main...HEAD` (which returns exactly our 8 paths), and their last-touching main commits are `2d8edc474` (DEV-2521), `012030f39` (DEV-2463) and `397cdd0c3` (a GHA deploy commit).
- Cause: `rework-gate.sh` compares `reviewed_sha..HEAD` (two-dot), so **any** merge from the base branch looks like un-reviewed rework. A three-dot comparison against the merge-base would not have fired.
- Therefore RN was NOT bumped and Phase 5 was NOT re-entered. Doing so would have meant re-reviewing 66 commits of other people's already-reviewed, already-merged work, while this ticket's own diff is provably unchanged. Recorded here so a later reader does not mistake the gate line for a real unreviewed change.
2026-09-17 08:46 CDT · done · Record the CI root cause, the scoped asset reconcile, and the rework-gate false positive

## okf wiki ingest (2026-09-17, post-approval)
Landed AFTER review + QA + operator approval, per CLAUDE.md's WRITE rule — never mid-flight.
- **PG-031** — `ST_Union` drops NULL members, so emptiness is not a completeness check. Carries the two traps this ticket actually hit: resolve outside the write transaction (a swallowed error inside an open transaction turns COMMIT into a silent ROLLBACK), and a NULL `the_geom` is schema-permitted so fixtures must seed one.
- **PG-032** — the harness's `AccessExclusiveLock` on `public.spatial_ref_sys` makes every `ST_Transform` path HANG, not fail, so the resolvable-input happy path is browser-QA-only. Includes the `platform`-vs-`zod` seeding trap.
- **`zone.md` and `run-area.md` were both STALE** — each documented the pre-fix behaviour as correct (`rebuildGeometryFromDefinition()` returning a geometry read as success; the Materializer section described the union and stamp with no failure mode). Corrected both against the merged code, not against memory.
- Refreshed the three catalog rows in `okf/index.md` (dates → 2026-09-17) and `okf/concepts/index.md`; bumped the `PG-001…PG-027` range line, which was already stale at PG-030 before this ingest.
- `php scripts/okf-lint.php` → **passed (108 files, 0 warnings)**. No `app/` code touched.
2026-09-17 08:50 CDT · done · okf: add PG-031/PG-032 and correct the stale zone and run-area pages

### CI green — the merge was the fix
`gh pr checks 414`: **Pint & Tests (8.4, 1/2/3) all PASS** (5m43s / 5m13s / 6m15s), Deploy PR-414 preview PASS, OKF wiki health check PASS. `gh pr view 414` → `mergeable: MERGEABLE`, `mergeStateStatus: **CLEAN**`. This is the first time CI has ever run for this PR; the conflict, not `[skip ci]` and not draft state, was the whole cause.
2026-09-17 08:58 CDT · done · CI green: Pint & Tests 3/3 pass, PR mergeable CLEAN
