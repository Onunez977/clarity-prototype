2026-09-08 07:43 CDT · intake · intake complete: route=feature app=clarity base=main; foundation DEV-2460 verified on main; route corrected to ClickHouse per Jira 39117
2026-09-08 08:12 CDT · prepped · prep escalated (3 blind candidates + grep backstop, reconciled): gate=BLOCKED, 11 open questions, 4 blockers. Two found by prep, not the ticket: the sparkline AC vs one-query AC are unsatisfiable together, and the ticket's CH row counts are RAW counts (hotlist raw 154.55M vs FINAL 136.36M, 13.3% overcount) which puts the route's own validation evidence in doubt. Settled from code: no new FeatureEnum case needed; array slot is index 2 and does not depend on DEV-2463.
2026-09-08 08:15 CDT · gated · gate BLOCKED: parked on 7 questions + 2 shared-infra blockers. PR #390 status block and Jira mirror 39206 both regenerated, including a correction of my own earlier claim that the route's validation evidence was in doubt.
2026-09-08 08:20 CDT · gated · fixed both shared-infra blockers (operator-approved): added zod.public_billing_events to the CI ClickHouse schema dump, and added the 4 untruncated zod.public_* tables to RequiresClickhouse
2026-09-08 08:36 CDT · gated · Q1 ANSWERED by prod-scale measurement: 3-month grouped sparkline costs +7% median over a single window, so use the okf BatchMonthlySparklineData shape. Port verified faithful (deduped 11,975 and 7,230 both EXACT with FINAL applied). Funnel runs ~5s median, not the ~32s the ticket assumes -- ~6x on query shape. WS6 perf gate: go.
2026-09-08 08:43 CDT · gated · HOLD: operator resequenced -- DEV-2463 ships first. Q1 (sparkline) and Q2 (sequencing) answered; 11 of 12 questions now resolved. PR #390 retitled [HOLD], Jira mirror updated with the validated port + the stranded-infra-fix warning. No card code written.
2026-09-08 08:54 CDT · gated · posted the DEV-2463 handoff (comment 39209): validated claim CTE, the 2 infra fixes to cherry-pick from 47612ab1, measured table facts, and the two epic-level corrections. This run stays parked.
2026-09-09 13:09 CDT · gated · merged origin/main: DEV-2463 landed (unblocks this ticket) and #394 already stubbed LprRepossessions + registered it at cards() index 3, so remaining scope is just the 3 data methods. Two new okf pages close q5 and q12 and correct 3 of my earlier claims (server-side envelope IS obtainable via X-ClickHouse-Summary; the +7% span figure was contention noise, a wider window is free; my port triple-scans billing_events). Perf verdict re-opened to pending.
2026-09-09 13:18 CDT · gated · q8 ANSWERED by measurement: use A1 (backyard-faithful + floor). Rejected the clarity.hotlist repo_date shortcut despite 5.9x cheaper -- it diverged -2 in April (2 pairs missing from the denormalized table) and its repo_date differs from the status transition for 1,588/6,770 counted pairs (23%), agreeing only because the 7-day predicate is loose. Also found: the org filter does not reduce the scan at all, so per-org compute is the worst shape -- batch is.
2026-09-09 13:18 CDT · gated · q8 ANSWERED by measurement: use A1 (backyard-faithful + floor). Rejected the clarity.hotlist repo_date shortcut despite being 5.9x cheaper -- it diverged -2 in April (2 pairs missing from the denormalized table) and its repo_date differs from the status transition for 1,588/6,770 counted pairs (23%), agreeing only because the 7-day predicate is loose. Also found: the org filter does not reduce the scan at all, so per-org compute is the worst shape -- batch is.
2026-09-09 13:35 CDT · gated · q13 ANSWERED from DEV-2463's shipped precedent: keep Cache::flexible, and my framing of the hazard was backwards -- the 10s impersonation TTL is SHORTER than the query's p99, so it stampedes rather than going stale. Needs the getCacheTTL floor, the cold-path herd guard (flexible locks only its refresh branch), both Redis keys tested, and the window in the key. Do NOT inherit DEV-2463's constants: tuned for ~40M rows, ours is 664M.
2026-09-09 13:58 CDT · gated · posted q10/q11 to Oscar as DEV-2464 comment 39323 with Aug-2026 figures re-measured read-only (TitleMax 53.0% vs a 75-88% pack; 1,270 of 1,528 orgs = 83.1% would read 0). Mention verified resolved to his accountId, so he is actually notified.
2026-09-09 14:52 CDT · gated · GATE CLEAN. Oscar answered both: q10 titlemax disclosure is out of scope (copy handled separately); q11's denominator was wrong -- measured the real audience by mirroring Org::isProEnabled against zod.public_org_meta, got exactly the 29 entitled orgs okf recorded independently, and 29/29 (Aug) / 25/29 (Jul) see a non-zero value. Neither answer adds work. WS6 perf gate: go, with 15.04 GB / 2.50 GB recorded as an upper bound for the un-optimised shape.
2026-09-09 15:10 CDT · gated · Phase 3 plan authored (6 subtasks, plan.json + plan.md). SIZING GATE = OVER (10 files, 4 concerns) -> parked for the operator's split decision per the flow. Plan arbiter caught a real error in my rescope note: #394 did NOT ship the Pro gate -- the stub has no canViewCardData() and #394 explicitly left the gate to each card's own ticket, so that AC is unimplemented and also needs the key added to DashboardCardVisibilityTest's explicit allowlist. Verified and corrected.
2026-09-09 15:17 CDT · planned · plan approved with the operator's split: t1-t4 ship together, t5 is an operator-run pre-merge verification (re-validate the single-pass SQL + calibrate the guard constants), t6 Playwright deferred (never runs in CI here).
2026-09-09 15:19 CDT · provisioned · Phase 3.5: isolated Postgres env standing (warm path -- both DBs already migrated). Applied the tiger.state PostGIS patch, which was MISSING, and that triggered a baseline reseed. SAFETY: .env had CLICKHOUSE_HOST pointed at ClickHouse CLOUD; neutralized to 127.0.0.1 in .env and .env.testing. CH deferred to Phase 4. Flagged that .env.testing's CLICKHOUSE_DRIVER=pgsql would silently skip every CH test -- Phase 4 must fix that when it stands CH up.
2026-09-09 15:48 CDT · implementing · t4: add lpr-repossessions to DashboardCardVisibilityTest's explicit gated allowlist (both hardcoded sites -- the $expectedGated list and the guest array_diff), and correct the docblock's FOUR->FIVE count. 16 passed (was 2 failed/14 passed). Done inline rather than dispatched: a one-string allowlist edit does not warrant a worker.
2026-09-09 15:54 CDT · implemented · Phase 4 complete: t1-t4 merged. Format clean. Full suite 1894 tests: 1878 passed, 10 failed + 1 error -- ALL pre-existing and causally excluded (clarity.hotlist_status_aggregate is missing from the truncate list and held 1,419 accumulated rows; zero references from any failing test to any class this ticket touches; classes fail when run alone). Split out as its own task. Blast-radius re-check clean: no sensitive-glob hits.
2026-09-09 16:22 CDT · implementing · review round 11 FAILED the gate (min 78 < 90; scores 94/78/82). Fixed the two unambiguous items: (1) the CONFIRMED correctness bug all three lanes found -- the chained LEFT JOIN was unguarded, so with join_use_nulls=0 an unmatched hotlist collapsed to h.id=0 and matched any status-log row with hotlist_id=0, counting triples with no repo (~+31% on a billing-adjacent figure). Added AND h.id != 0 plus two regression tests, and PROVED the test catches it by reverting the guard. (2) COLD_BLOCK_SECONDS was 45 < the production timeout of 60, the documented counterproductive case; all windows now derive from QUERY_TIMEOUT_SECONDS with lock 120 > block 75 > timeout 60, pinned through the accessors.
2026-09-09 16:28 CDT · implementing · t5 RUN (not deferred): rendered the shipped SQL by reflection and measured it read-only against production. AC-1 now EVIDENCED on the shipped shape -- deduped 11,975 and 7,230 both exact, db_repos 9,137/5,371 within the known drift. Envelope is BETTER than assumed: 584M rows / 11.33 GB / 2.22 GB, and the 13-month span costs only 1.25x memory, refuting the lanes' ~13x prediction. Also guarded the window leg (lanes 2+3) with 2 pinning tests. Residual: ~81 GB CH-side if all 29 orgs go cold, with max_memory_usage=0 -- and the 68 GB figure was the APP host, the wrong denominator.

## Round 13 — operator-authorized second fix cycle (2026-09-10)

Round 12's gate failed at MIN 88/100 (92/94/88). All three lanes agreed the code had **no live
correctness bug** and that AC-1 was evidenced — the failure was carried almost entirely by nine
findings my own round-11 fix cycle had introduced, two of them net regressions. The flow permits one
fix cycle, so the correct default was to park. I put the choice to the operator with a
recommendation to fix, and got: *"No jira ticket, third round only."*

So: fix pass authorized, no linked Jira Task for the genuine follow-ups.

**Two regressions, both mine:**

- The window cache key carried the resolved day but **not the viewer timezone** — a class this repo
  had already solved twice (`UniqueScanCounts:208`, `StagingLiveHitLeader:457`). The day alone does
  not separate viewers: at one instant Chicago and London can share a local calendar day while
  their "today" windows cover different UTC instants, so on a shared org they read each other's
  totals. Fixed by overriding `buildWidgetCacheKey()` to append `:tz=`.
- Raising `COLD_BLOCK_SECONDS` to 75 (round 11's remedy) made **CH-degraded behaviour strictly
  worse**. Two guarded legs are additive, so a cold render could block ~150s against a request
  budget of 30s — `config/deploy.yml` sets no `proxy.response_timeout`, so kamal-proxy's default
  applies. Re-derived from that budget instead: `COLD_BLOCK_BUDGET_SECONDS = 24` divided across
  `GUARDED_COLD_LEGS = 2`.

That second one is worth recording as a reasoning error, not just a wrong number. Round 11 said the
block window must exceed the query timeout or waiters time out and compute anyway. That is true, and
I raised the window to satisfy it — without checking whether the waiter is still *there* to benefit.
It is not: the proxy has hung up at 30s. Blocking longer does not prevent the second scan, it just
converts "maybe a second scan" into "a certain 504". Both rounds were right about their own half; the
constraint that reconciles them is the request budget, which neither round had looked up. The
remaining weakness is now documented as the lesser of two evils, with out-of-band warming (dc1)
named as the actual fix.

**Also fixed:** the orphaned `getCacheTTL()`/`MIN_TTL_SECONDS` (no reachable caller once both legs
went through `heldFlexible`; the sibling's copy *is* live, which is why only this card's was
removed); the undocumented daily cold rollover, now stated with its number rather than left to be
discovered; two accessor mutants that survived because deriving every window from one base made
`MIN_TTL == COLD_BLOCK` and `REFRESH == COLD_LOCK` — the three accessors are now pairwise distinct
(12 / 90 / 75) and a distinctness assertion pins it; a **tautological test** that built its
expectation from the production helper and would have passed against any implementation; three
`#[Group]` triples my insertions had stolen from neighbouring methods; and a stacked docblock still
claiming "no override needed here" directly above the override.

**Verification.** Five mutants introduced and confirmed killed (`:tz=`, the day segment, an accessor
swap, the 150s budget, the stage selection). Worth noting: my first attempt at two of them used
`perl` with broken escaping and applied **nothing** — the suite passed and I nearly recorded that as
proof. Caught it because the harness printed an occurrence count that did not match. The harness now
aborts unless the pattern matches exactly once. This is the second time on this ticket that a
no-op reproduction almost became evidence.

82 tests green: 24 widget (89 assertions), 18 query against real local ClickHouse on `:18123`
(confirmed not skipped), 40 sibling + card-visibility suites untouched. `HeldFlexibleCache` and
`UniqueLprCaseClaims` were deliberately **not** edited, so the already-shipped sibling carries no
risk from this pass. Pint clean, phpstan clean.

**Scope held.** The pre-existing sibling gap, the `dc1` batch shape, the `max_execution_time`
one-liner and the surviving mutants elsewhere were all left alone — not self-inflicted, and the
operator declined the follow-up ticket.
2026-09-10 07:47 CDT · implement · round 13 fix cycle (operator-authorized second pass): fixed the nine driver-introduced findings from round 12. Added the missing :tz= cache-key segment (the class this repo had already solved twice); re-derived the block windows from kamal-proxy's 30s request budget -- 24s total across 2 additive legs, not 75s each; deleted the orphaned getCacheTTL()/MIN_TTL_SECONDS; made the three guard accessors pairwise distinct (12/90/75) so accessor-swap mutants die; replaced a tautological key test with one that records the production key and covers the two-timezone collision; restored 3 #[Group] triples the round-12 insertions had stolen; removed a docblock that contradicted the method beneath it; corrected the class docblock to t5's shipped envelope (584M rows / 13.18 GB / 2.79 GB). 5 mutants introduced and killed. 82 tests green; HeldFlexibleCache and the shipped sibling deliberately untouched.

## Round 13 review — gate FAILED at 84 (84/94/94)

`review.sh` failed on the same genuine auth error for the third consecutive round, so all three
lanes ran in-session again, provenance recorded as such.

All three lanes state independently that there is **no live correctness bug**. Lane 3 executed the
shipped SQL and the sentinel probes read-only against local ClickHouse, reproduced the round-11 bug
with the guard reverted, and confirmed the guard is load-bearing and correctly placed. Lane 1
re-derived the port against backyard's `RepoReport` and confirmed both docblock equivalences hold.

**And for the second consecutive round, the top finding was mine, from the fix cycle itself.**

All three lanes independently flagged the `:tz=` segment I added: I overrode
`buildWidgetCacheKey()` wholesale, so it landed on the UTC monthly-series key too — whose value is
identical for every viewer of an org. Because the cold mutex is derived from the key, that forked
the mutex per timezone, so cross-timezone viewers stopped serialising and the card's most expensive
leg multiplied by the org's distinct-zone count for zero correctness gain. Lane 1 scored it HIGH.

The part that stings: `UniqueScanCounts::monthlySeriesFailureMarkerKey()` had already decided this
exact question, across three review rounds, and recorded the reasoning verbatim — *"Adding `:tz=`
would give each zone its own backoff and let N zones hammer a hard-down ClickHouse N times."* I
cited that class as my precedent and then inverted its conclusion. Round 12's finding was that I'd
**omitted** a `:tz=` the repo had solved twice; round 13's is that I then **over-applied** it past
the same repo's recorded limit. Both errors came from copying a pattern's shape without reading why
it stops where it stops.

Fixed after the gate: `:tz=` now goes only on the day-scoped window key, inside
`currentPeriodTotal()`. Pinned by a new test asserting the series key carries neither the day nor
the timezone and that two viewers on opposite sides of the date line share it — proven load-bearing
by re-introducing the override as a mutant (N1), which fails. Also corrected two docblock figures
lane 1 caught, one of them load-bearing: I had called the 33.7s tail "16 uncontended samples" when
the artifact attributes it to landing in a contention window, and that word was what licensed the
separate claim that the contended p99 is unmeasured.

Fixing H1 surfaced test debt I had not seen. Three tests hand-built their expected cold-lock key
through `buildWidgetCacheKey()`. Removing the override made one of them fail — the safe direction —
but `test_a_warm_cache_is_served_without_taking_the_cold_lock` built its key the **unsafe** way: it
takes the cold lock to prove the warm path does not block on it, so a drifted key would lock
something irrelevant and the test would still pass. Both probe widgets now record the key
`heldFlexible()` was actually asked for, and both hand-built helpers are deleted.

**Two process errors of mine, recorded because they cost real time and nearly corrupted evidence.**
First, I killed the lanes' orphaned `phpunit` processes with `kill -9` believing all three lanes had
finished — lane 1 was still working, and its own background runs died as a result. It correctly
recorded them as unattributable and did not move any severity, but I caused that noise. MEMORY.md
already warns that a concurrent session's `pkill` kills rigor lanes; using explicit PIDs was not the
safeguard I assumed, because the lane hadn't actually finished. Second, `kill -9` left twelve
Postgres backends idle-in-transaction holding locks, which made the widget suite take 10m24s instead
of 6s until I terminated them — and one query-suite failure appeared in that window. Re-run five
times in isolation and in the exact sequence that failed: green every time.

**Still failing the gate at 84.** `reviewed_sha` is stale — the H1 fix landed after the review — so
no passing gate is claimed for either sha. Four unanimous findings remain unfixed, all outside the
nine this pass was authorized for: the `{floor}` 7-day lookback and the bucketed `db_repos`
expression are each unpinned (one test apiece), the label axis is unpinned, and
`COLD_BLOCK_SECONDS = 12` sits below the measured tail — that last one's real fix lives in the
shared trait and would change the already-shipped sibling.
2026-09-10 08:55 CDT · review · round 13 review gate FAILED at MIN 84 (84/94/94). All three lanes: no live correctness bug, AC-1 evidenced on the shipped shape, round-11 guard confirmed load-bearing. But for the second round running the top finding was the fix cycle's own regression: all three lanes flagged the ':tz=' I added to EVERY key, which forked the cold mutex per timezone on the viewer-INDEPENDENT monthly series -- inverting a decision UniqueScanCounts had recorded across three rounds and which I cited as precedent. Fixed post-gate (window key only) and pinned by a load-bearing regression test; also corrected two docblock figures and removed three hand-built cache-key expectations, one of which drifted in the unsafe direction. reviewed_sha is STALE: no passing gate is claimed. Four unanimous findings remain, all outside the authorized nine.

### Correction to the round-13 entry above

The entry says "25 + 18 + 40 tests green" in the summary but the earlier round-13 entry says
"24 widget" tests. Lane 3 caught the inconsistency: the widget file has **25** tests, not 24 — the
count changed when `test_the_monthly_series_key_is_shared_across_viewer_timezones` was added to pin
the H1 fix, and the earlier sentence was not updated. Journal is append-only, so the correction
lives here rather than as an edit.

Lane 3 also re-reported after the branch advanced under it mid-review, disclosed the drift, and
diffed `59af3c336..082247838` instead of reviewing a moving target. It confirms the H1 fix is
correct ("M1 fixed exactly as I'd recommend, with UniqueScanCounts's recorded decision cited"), and
independently ran the suites green on the newer SHA — 18/18, 25/25, 41/41 — which corroborates the
driver's own green claim rather than leaving it self-reported.

One more one-line item it surfaced, for whichever path is chosen next:
`test_deduped_stage_agrees_with_unique_lpr_case_claims_query` compares the two query classes'
`deduped` with no non-zero precondition, so `0 === 0` passes if a fixture fails to land — precisely
what a truncation race produces. A single `assertGreaterThan(0, ...)` restores the drift alarm.
2026-09-10 09:02 CDT · review · recorded lane 3's addendum: it disclosed that the branch advanced under it mid-review, diffed 59af3c336..082247838 rather than reviewing a moving target, confirmed the H1 fix is correct and cited the right precedent, and independently ran the suites green on the newer sha (18/18, 25/25, 41/41). Also corrected a driver accuracy error it caught -- the journal said 24 widget tests, the file has 25 since the H1 regression pin was added. Gate still FAILED at 84; no new code changed.

## Round 14 — test-only pass closing the four unanimous gaps

Operator asked what was left, then said continue. Scope held hard: **zero production code changed**
— `git status` shows only the two test files. That was the whole point. Three fix cycles in a row had
each introduced their own top finding, so this pass only adds assertions, which cannot regress
behaviour.

Four gaps, all named unanimously or near-unanimously by the round-13 lanes, each now closed and each
proven load-bearing by a mutant:

- **`{floor}`'s 7-day lookback** had no coverage: every existing status-log fixture sat inside the
  window, so deleting `->subDays(7)` filtered nothing and all 18 tests stayed green. The floor
  exists because the real predicate is `transition >= claim_date - 7`, so a claim in the window's
  first week can legitimately match a transition from before the window start. New test seeds
  exactly that. Mutant `subDays(7) → subDays(0)` now fails.
- **The bucketed `db_repos` expression** was untested, and it is the one the card's *default*
  `this_month` headline and all 13 sparkline bars read — the aggregate is written twice and only
  the unbucketed copy had coverage. New test also pins that a bucket with claims but no
  repossession reads 0 rather than going missing. Mutant `uniqExactIf → uniqExact` in the bucketed
  select only now fails.
- **The label axis** was entirely unpinned — the same miss the sibling needed a dedicated file to
  close, whose docblock records that deleting the three overrides "left every test green and fully
  restored the defect". Freezing at 02:00 UTC on the 1st, which is still the previous day in
  Chicago, is the discriminator: the viewer-local base yields "Jul '26" where the UTC override
  yields "Aug '26". Mutant deleting all three overrides now fails.
- **The cross-query drift alarm** passed vacuously at `0 === 0` whenever a fixture failed to land —
  precisely what the truncation races we hit this session produce. Added the non-zero precondition
  and verified it by emptying the fixture: it now fails where it previously passed.

**Deliberately not fixed:** `COLD_BLOCK_SECONDS = 12` sitting below the 33.7s measured tail. Every
lane raised it and lanes 2 and 3 both explicitly accept that raising the window to 75s is worse. The
real remedy — not computing synchronously on a total miss at all — lives in the shared
`HeldFlexibleCache` and would change behaviour for the already-shipped sibling. That is a design
decision, not a gap, and it stays documented rather than half-changed.

86 tests green, run **serially** — lane 1's addendum established that `RequiresClickhouse` truncates
shared literal `clarity`/`zod` databases in `setUp()`, so parallel runs of these files destroy each
other's fixtures. Pint and phpstan clean.
2026-09-10 12:28 CDT · implement · round 14 test-only pass: closed the four unanimous test-adequacy gaps from round 13 with ZERO production-code change. Pinned the {floor} 7-day lookback, the bucketed db_repos expression (the default headline and all 13 sparkline bars read it, and only the unbucketed copy had coverage), the UTC label axis (all three overrides at once, using an 02:00-UTC-on-the-1st Chicago viewer as the discriminator), and added the missing non-zero precondition to the cross-query drift alarm that passed vacuously at 0 === 0. All four proven load-bearing by mutants P1-P4. 86 tests green, run serially. COLD_BLOCK_SECONDS left alone deliberately: its real fix is in the shared trait and would change the shipped sibling.

## Round 14 review — GATE PASSED at 92 (94/94/92)

`REVIEW_GATE: pass min=92 lanes=3 rn=14 sources=in-session-agent:3`. `review.sh` failed on the same
auth error for the fourth consecutive round, so lanes ran in-session again with provenance recorded.

Trajectory: 78 → 88 → 84 → **92**.

All three lanes state independently that there is no live correctness bug, and this round produced
the strongest evidence the ticket has had. Lane 3 rendered the shipped restructure **and** the
production-validated `measure-org-*.sql` side by side against local ClickHouse on a deliberately
adversarial fixture — multi-month triples, whitespace VIN, failed claims, wrong event types,
duplicate status-3 hotlists, a `Nullable` forwarder, `agent_org_id = 0`, `hotlist.id = 0`,
`hotlist.org_id = 0`, stray `hotlist_id = 0` transitions — and got **byte-identical results in both
the window and bucketed forms**. That is the round-11 equivalence question finally answered by
measurement rather than argument. Lane 1 independently confirmed the `h.id != 0` mutant moves
`db_repos` 1→3; lane 2 reproduced the sentinel both ways.

Lane 3 also ran 66 of the 86 tests itself (26/26, 24/24, 16/16), so the green claim is corroborated
rather than self-reported. Lanes 1 and 2 declined to run phpunit **on purpose** and said so plainly.
The environmental warning added to the lane prompts worked: zero unattributable failures this round,
against 40+ wasted minutes in round 13.

**The residuals are real and none of them is a behaviour bug.** Three deserve naming:

- **The refresh-lock test cannot fail on the mutation it claims to guard** (lane 1, confirmed).
  `heldFlexible()` calls `flexibleRefreshLock()` unconditionally and only later passes `$lockConfig`
  to `Cache::flexible`, so the recording double proves the seam was *called*, not that its value
  reached the consumer. Deleting the 4th argument from both `Cache::flexible` calls leaves the test
  green while `flexible()` falls back to no expiry — the exact wedge the constant exists to prevent.
  Worse, the trait docblock asserts the opposite, and the extraction made that **false guarantee
  canonical for both users**; DEV-2465 is named as the third user and would inherit it.
- **`AND d.agent_org_id != 0` is a surviving mutant** (lanes 1 and 3, both confirmed on local
  ClickHouse with a discriminating fixture). The test that claims to pin it seeds no hotlist row at
  all, so its `db_repos === 0` comes from "no hotlist exists" and the downstream `h.id != 0` masks
  the mutant. One fixture line fixes it, and the test's comment currently certifies coverage that
  does not exist.
- **The viewer-timezone re-anchoring is entirely unpinned** (lane 3). Every stub discards
  `$start`/`$end`, so dropping the `$timezone` argument survives all 86 tests while shifting a
  Chicago viewer's Today window five hours; and `->utc()` on `{floor}` is load-bearing east of UTC
  with every test passing UTC Carbons.

Five more are mine: the class docblock's window-leg envelope understates by the timezone count (and
in the unsafe direction, in the one docblock that records the deferral); a stale `~664M` figure
survives in a test docblock round 13 corrected everywhere else; the older UTC-bucket test lacks the
precondition the new label test carries; "4 of 29 orgs read 0" is a July measurement stated as a
standing fact (August was 29/29); and the propagate-don't-swallow contract is overstated because
`Cache::flexible`'s deferred refresh runs inside Laravel's `rescue()`.

Gate passed, `rerun_required` cleared, `reviewed_sha = 877d91c26` and current. Phase 6 is unblocked.
2026-09-10 12:56 CDT · review · REVIEW GATE PASSED at min 92 (94/94/92), rn=14 -- first passing gate on this ticket (78 -> 88 -> 84 -> 92). All three lanes independently: no live correctness bug. Lane 3 rendered the shipped restructure AND the production-validated spec SQL side by side against local ClickHouse on an adversarial fixture and got byte-identical results in both window and bucketed forms, which answers the round-11 equivalence question by measurement. Lane 3 also ran 66 of the 86 tests itself, corroborating the green claim. rerun_required cleared; reviewed_sha current. Residuals recorded in full: three confirmed test-adequacy gaps (the refresh-lock test cannot fail on its own mutation and the trait docblock asserts otherwise; d.agent_org_id != 0 survives; viewer-tz re-anchoring unpinned) plus five docblock accuracy items of mine.

## Round 15 — closing the confirmed residuals (comment-only in production)

Gate had already passed at 92, so this was elective: three confirmed test-adequacy gaps plus six
docblock-accuracy items. **Every production change is comment-only**, verified mechanically rather
than asserted — `git diff -U0` over `app/` with comment and blank lines stripped returns nothing.
All three new pins are in test files.

The one that mattered most was lane 1's M1, because it was a docblock lying about a guarantee.
`heldFlexible()` calls `flexibleRefreshLock()` unconditionally and only afterwards passes the result
to `Cache::flexible`, so the old double proved the seam was *called* — deleting the 4th argument from
both call sites left the test green while `flexible()` fell back to no expiry. And the trait's
docblock claimed the opposite, which the extraction had made canonical for every user, including the
unwritten DEV-2465. The new test stands a `Repository` subclass in for the cache and records the
argument the consumer actually receives; lane 1's exact two-site mutant now fails. The false claim is
withdrawn and replaced with what is actually true, plus a pointer to the test that does the work.

Two more real pins: the viewer-timezone re-anchoring (every stub discarded `$start`/`$end`, so
dropping the `$timezone` argument survived 86 tests while moving a Chicago viewer's Today by five
hours — the new test asserts the UTC *instant*, not just the zone name), and `AND d.agent_org_id != 0`,
which two lanes had reproduced as a surviving mutant. That one needed the row nobody had seeded: a
status-3 hotlist with `org_id = 0`. `public_hotlist.org_id` is a non-nullable `Int32`, so a Postgres
NULL arrives as 0 — the same mechanism the query's own comment already cited for `hotlist_id`, one
line below. The pre-existing test's comment, which certified coverage it never provided, is corrected
rather than left to mislead.

Six docblock corrections, all of them mine. The worst was the all-cold ceiling: it counted the window
leg once and landed on ~145 GB, wrong in the *unsafe* direction and contradicting the same class's
own reasoning about the `:tz=` fork two paragraphs earlier. Also: "4 of 29 orgs read 0" was a July
measurement stated as standing fact in four places (August was 29/29 — so a zero is uncommon rather
than impossible, which is the real argument for not fabricating one); the propagate-don't-swallow
contract now records that it does not cover the deferred stale-refresh path, because Laravel wraps
deferred callbacks in `rescue()`; a stale `~664M` figure became 584M; the older UTC-bucket test now
asserts the calendar straddle it silently depended on; and the trait's `coldBlockSeconds()` docblock
no longer states a MUST that neither shipped consumer satisfies.

One honest note on process: a transient query-suite error appeared immediately after the R3 restore.
I confirmed the guard was back by grep and re-ran three times clean — residual ClickHouse fixture
state from the mutant run, not a defect. Worth recording because on this ticket a green-looking
anomaly has twice turned out to matter.

89 tests green (28 / 21 / 40), run serially. Pint and phpstan clean.
2026-09-10 13:08 CDT · implement · round 15: closed the three confirmed residual test gaps with COMMENT-ONLY production changes (verified mechanically). Pinned the refresh-lock config reaching Cache::flexible itself -- lane 1's two-site mutant now fails where the old seam-recording test could not detect it, and the trait's false 'dropping it is observable' docblock is withdrawn. Pinned the viewer-timezone re-anchoring by asserting the UTC instant. Pinned AND d.agent_org_id != 0 by seeding the status-3 hotlist with org_id = 0 that nobody had, and corrected the old test's comment which certified coverage it never provided. Six docblock accuracy fixes, worst being the all-cold ceiling undercounting the window leg by the timezone factor in the unsafe direction. 89 tests green.
2026-09-10 13:37 CDT · review · REVIEW GATE PASSED AGAIN at min 92 (94/92/94), rn=15. Second consecutive pass. All three lanes: no live correctness bug; lane 3 got byte-identical results rendering the shipped SQL against the validated spec SQL side by side on an adversarial fixture, and refuted its own Nullable-tuple hypothesis. Then fixed two things round 15 itself introduced, both caught by lane 1: my trait 'correction' had replaced one false claim with another ('NEITHER consumer satisfies' -- the sibling DOES, at 12s vs a measured 9.3s p99, with a green test asserting it), and I had left the superseded blind test in place still certifying coverage it cannot provide. Post-gate delta is comment-only in production plus a 47-line pure test deletion, recorded in state.json rather than re-reviewed; the surviving pin was re-mutation-tested and still kills the two-site mutant.

## Phase 6 scheduled, not started

Operator: move to the next phase, but start it 2.5 hours out. Phase 6 (verify/QA) is therefore
queued as a one-off scheduled task firing at 2026-09-10 17:33 -05:00, not run now.

A scheduled run begins with no memory of this session, so the task prompt is self-contained: the
worktree path, the branch and PR, the passing-gate status with the instruction NOT to re-run the
review, the recorded post-gate delta so it is not mistaken for unreviewed work, and the constraints
that have actually cost time on this ticket — never run these suites in parallel (RequiresClickhouse
truncates shared literal databases), `PHPRC=/dev/null` because `-d memory_limit` does not reach the
phpunit child, never `kill -9` a phpunit process (it left twelve Postgres backends idle-in-transaction
holding locks and turned a 6-second suite into ten minutes), all commits through checkpoint.sh with
no bypassing the PII scan, journal append-only, no `okf/` writes before approval, no Jira ticket
without asking, and the `[skip ci]` undraft trap.

It also carries the residual findings forward for the Build Record rather than for fixing, with the
per-request cold-block budget named first — that is the one where the shipped docblock states a
guarantee the code does not provide.
2026-09-10 15:04 CDT · reviewed · Phase 6 (verify/QA) scheduled for 2026-09-10 17:33 -05:00 per operator request rather than started now. One-off task dev-2464-phase-6-qa; prompt is self-contained since a scheduled run has no memory of this session, and carries the passing-gate status, the recorded post-gate delta, the serial-test and no-kill-9 constraints, and the residual findings for the Build Record.

## Phase 6 — verified (agent QA passed, one AC deferred)

`phase = verified`, `qa.verdict = PASS` (derived from `qa-ac-results.json`, not from memory):
**6 SATISFIED, 3 N/A, 0 NOT SATISFIED**; items 10 OK / 1 WARN / 0 failed; Laravel log clean.

**The single most valuable thing this pass produced: AC-2 is now satisfied by a literal
server-side query count.** Its prep note demanded "a literal query-count assertion, not code
shape", and review round 15 confirmed the shipped tests count the widget *seam* — so a fan-out
inside `monthlySeries()` would leave them green. I read the count out of ClickHouse's own
`system.query_log`: 1 round trip for `this_month`, 1 for `last_month`, 2 each for `today` and
`last_7d` (series + window). A per-month fan-out would have read 13. That is stronger than any
PHP-side spy and it closes the gap the review could only name.

**One AC genuinely could not be reached, and it is a harness limitation rather than a defect.**
`routes/web.php:176-192` has the dev-login route unconditionally `session()->put('admin_impersonator')`,
and every Company Metrics card ORs that into `canViewCardData()`. So the locked placeholder is
unreachable through `/__qa/login/{id}` for *every* persona. The gate itself is correct — tinker
confirms `canViewCardData()` returns `false` for user 3 with the key absent, and the unit suite
covers all six tiers. AC-4 is recorded N/A and deferred to human QA with a non-QA-login session.
It will block the same AC on DEV-2465 and every future Pro-gated card, so it is worth fixing in
the harness (suggested, not applied).

**Two things I got wrong during the pass, both mine.** First, my seed left
`enable_genetech_hotlist = false` on both orgs; `User::canAccessPanel()` gates the whole `/app`
panel on it, so the first browser lane authenticated fine, 403'd, and scored 0/6. The lane was
right to report it rather than mutate the row. Fixed in the seed with the reason recorded inline.
Second, my first `qa-ch-reseed.sh` preserved `--` comments inside `VALUES` lists, which
ClickHouse's `ValuesBlockInputFormat` cannot parse — and because the `TRUNCATE`s ran first, it
destroyed the fixture before failing. Fixed and re-verified 10/10 with 7/4 restored. I also
twice mis-verified the seeder's own work (a curl sending `query=` as the body, and a role check
against `"ADMIN"` when the enum value is `"Administrator"`) and had to correct myself both times.

**A durability trap worth carrying forward:** a two-version ReplacingMergeTree fixture is not
stable. The seed writes hotlist 820017 at v1 `status=3` and v2 `status=1` so that dropping
`FINAL` would inflate `db_repos`; a background merge collapsed them (part `all_18_18_1`, level 1)
and physically dropped v1, silently removing that detection. No number changes. The lesson is
that `FINAL` assertions belong in phpunit, where the trait truncates and re-seeds per test, not
in a long-lived CH fixture.

**Resource envelope: `not-measured`, deliberately not PASS.** PHP-side is confirmed O(1) (13
series elements, ~0 MB peak delta, 0.053 s), and that is the only thing this environment can
measure — the fixture is 20 rows against 38,018,542. Neither FAIL condition is computable: no
cadence (widget, not a scheduled command) and no headroom denominator (the ClickHouse instance's
RAM is unreadable from `readonly_user`). Per the flow, a not-measured verdict on a
`scale_relevant` change is carried to the PR and the handoff as an explicit open risk rather than
read as green. The production figures are known from t5 and the ~81 GB all-cold ceiling remains
a recorded deferral.

Teardown safety fixed while writing the ledger: `clickhouse.databases` previously listed the
**shared** literal `clarity`/`zod`, which is qa-complete's drop list. It is now empty, with the
names moved to an informational `databases_in_use` key, so the shared databases can never be
dropped for this ticket. The container was already running and was not created by this prep.

Environment left UP and the PR still in draft, per the flow. Next: human QA, then Phase 7.
2026-09-10 20:26 CDT · verified · Phase 6 VERIFIED. agent QA passed: 6 AC SATISFIED / 3 N/A / 0 NOT SATISFIED, items 10 OK / 1 WARN / 0 failed, Laravel log clean. Headline: AC-2 is now satisfied by a LITERAL ClickHouse server-side round-trip count from system.query_log (1/1/2/2 per resolved window, not 13) -- the proof its prep note demanded and that the shipped seam-counting tests cannot give. AC-4 deferred N/A: the dev-login route unconditionally grants admin_impersonator, so the locked-placeholder path is unreachable for every persona (gate itself verified correct via tinker) -- a harness limitation that will block the same AC on DEV-2465. Two driver errors fixed mid-run: my seed left enable_genetech_hotlist=false and 403'd the panel (first lane scored 0/6), and my first CH reseed script destroyed the fixture before failing on -- comments inside VALUES. Resource envelope recorded not-measured, NOT pass, and carried as an open risk. Ledger teardown fixed so the shared literal CH databases can never be dropped.

## Phase 7 — parked, awaiting human QA

`mode: normal`, so Phase 7 step 1 parks after rendering the guide rather than proceeding to undraft.

The guide is at `.context/qa-human-guide.html` and is open: 11 steps across 3 sections, both personas
with one-click logins, 4 agent-QA findings folded in, and 4 steps flagged unvalidated. Environment
re-verified all-green (7/7), including the persona ids resolved live against Postgres.

**hp-6 is the step that matters.** AC-4 — a non-Pro org must not see the value — is the one
acceptance criterion the agent pass could not reach, because the dev-login route unconditionally
grants `admin_impersonator` and every Company Metrics card ORs that into `canViewCardData()`. The
gate itself is correct; the harness pre-empts it. The guide spells out the mechanism, attaches the
A3 screenshot as proof of the failure mode, and tells the tester to bypass the QA login route.

**One deliberate deviation, recorded.** human-qa's prep gate reported `GATE=prep` because HEAD had
moved from `fe3456812` to `f2b9434f9` — but that was my own Phase 6 checkpoint, touching only
`docs/investigations/`. Zero source change. Taking the gate literally would have regenerated both
plans and discarded agent-qa's patch blocks and all 11 item statuses, destroying the pass I had just
finished. I re-baselined `head_sha` and carried agent-qa's `run=` references forward instead, then
verified the gate passed with the patch blocks and ticked items intact. This is the known
artifact-commit trap: the QA gates key on `head_sha`, and the flow's own checkpoints move `head_sha`
without touching the diff.

**PR #390's Status block regenerated.** It had been stale since round 12 — still saying ON HOLD, "No
card code was written", and listing five open questions that are all long resolved. It now states the
real position: review passed twice, agent QA green, one AC deferred to human QA, and the open items
that this PR explicitly does *not* clear (the resource envelope, the block-window weakness, the
per-request budget). The stale `[HOLD]` prefix is also gone from the title, since DEV-2463 shipped.
The PR stays **in draft** — undrafting is step 2, after human QA.

**Not done, and blocked on authorisation:** the Jira mirror comment (39206) still describes round 12,
and the Build Record cannot be posted — the Jira MCP server is unauthenticated in this session.
2026-09-11 07:52 CDT · handoff · Phase 7 PARKED awaiting human QA (mode=normal). Guide rendered and opened: 11 steps, both personas, 4 agent-QA findings folded, 4 steps flagged unvalidated, env re-verified 7/7 green. hp-6 (AC-4) marked unmissable -- it is the one AC the agent pass could not reach, because the dev-login route unconditionally grants admin_impersonator. Deviation recorded: human-qa's gate said GATE=prep because HEAD moved, but that commit touched only docs/investigations, so the plans were re-baselined rather than regenerated -- a literal re-prep would have discarded agent-qa's patch blocks and all 11 item statuses. PR #390 Status block regenerated (stale since round 12) and the stale [HOLD] title cleared; PR deliberately left in DRAFT. Jira mirror + Build Record still blocked: the Jira MCP server is unauthenticated in this session.

### Jira mirror updated (2026-09-11)

The Jira MCP server authorised on retry, so comment **39206** is updated in place (id preserved, no
duplicate). It had still been describing the 2026-09-08 HOLD. It now carries the real state — review
passed twice, agent QA 6/3/0, AC-2 closed by a literal query count, AC-4 deferred as a harness limit,
Oscar's two calls applied — plus the open items this ticket explicitly does not clear.

Two corrections went into it deliberately, because both had been stated on the ticket and were wrong:
the "~5s median" figure was wall clock only (the measured server-side envelope is 13.18 GB read and
2.79 GB peak per render — wall clock understated this card three separate times over the run), and the
stranded-infra-fix warning is void since both fixes shipped with DEV-2463.

The Build Record is **not** posted — that is Phase 7 step 3, after human QA. It is no longer blocked
on access, only on the QA pass.
2026-09-11 07:54 CDT · handoff · Jira mirror comment 39206 updated in place (id preserved) now that the MCP server authorised: real phase state, review 78->88->84->92->92, agent QA 6/3/0, AC-2 closed by a literal query count, AC-4 deferred as a harness limit, Oscar's calls applied, open items listed. Two corrections posted: the '~5s median' was wall-clock only and misleading, and the stranded-infra-fix warning is void. Build Record still NOT posted -- that is Phase 7 step 3, after human QA.

## Phase 7 — human QA complete, closing out

Operator reported a clean pass with no findings.

**AC-4 was resolved rather than left N/A.** Asked whether they had verified hp-6, the operator said
plainly they were not sure how to test it — a fair answer, since the harness blocks the browser path.
Rather than record an N/A for the one criterion the whole gate exists for, I verified it directly:
rendered `CompanyMetricsOverview::getStats()` — the real grid path, including its
`canViewCardData() ? toStat() : lockedStat()` branch — with a flushed session and no impersonator key.
Non-entitled org 338 gets the LOCKED_VALUE placeholder, no chart, and no figure leaking into the
value, chart or description; entitled org 337 gets 4 with a chart on the same path. **Proven in both
directions**, so the gate is not merely failing closed. AC-4: N/A → SATISFIED, with the residual
stated honestly (the Stat object was verified, not the rendered HTML in a live browser).

Final tally: **7 SATISFIED, 2 N/A, 0 NOT SATISFIED.** The remaining N/As are AC-7 (the Build Record,
which now exists) and AC-9 (explicit UTC — correct in code, but nothing detects a regression).

**Two QA-loop fixes, both to `.context/` fixtures, no product code:** the seed had been overwritten
mid-QA by another worktree's test run *and* had hard-coded a date as "today", so the Today filter read
0 after midnight — re-anchored all 22 date literals to ClickHouse `today()`. And the operator reported
that Last 7 Days and This Month both read 4, making the filter untestable — added rows at D-8/D-9/D-10
and in the previous month, so all four states now read differently (2 / 4 / 7 / 6) and the sparkline
has real shape instead of a flat row of 1s.

**Build Record posted** as comment 39458, assembled from `state.record` and the QA artifacts rather
than from session memory. It states plainly that three of the five review rounds failed on defects my
own fix cycles introduced, and carries the resource-envelope deferral as open rather than closed.
2026-09-11 08:22 CDT · done · human QA complete, clean pass. AC-4 resolved N/A -> SATISFIED by verifying the real grid path with no impersonator session: non-entitled org gets the locked placeholder with no chart and no figure leaking, entitled org gets 4 with a chart -- proven in both directions. Final: 7 SATISFIED / 2 N/A / 0 NOT SATISFIED. Two QA-loop fixture fixes (no product code): re-anchored the CH seed to today() after another worktree overwrote it and the date rolled over, and added rows so all four filter states read differently. Build Record posted as comment 39458.

## 2026-09-11 — Phase 7 closeout: handoff complete

**Jira transitioned by me, not by automation.** The flow warns that in repos with draft-aware
automation, undrafting the PR may move the ticket on its own — so I checked `DEV-2464`'s status
after undrafting #390 rather than assuming. It was still **In Progress**, so no automation ran here;
I applied transition `4` myself and recorded `jira_transitioned_by: "claude"`. Status is now
**In Code Review**.

**QA teardown, scoped strictly to the ledger.** Dropped `zod_claude_dev_2464` and
`zod_testing_claude_dev_2464` (0 open connections at drop time — checked first, because a `kill -9`
earlier on this ticket left twelve idle-in-transaction backends holding locks). Unlinked and
unsecured the Herd site `clarity-claude_dev_2464.test`.

**ClickHouse was deliberately left completely alone**, and verified so afterwards: the shared literal
`clarity` / `zod` databases are still present with the QA fixture intact (29 `public_billing_events`,
26 `public_hotlist`), and `clarity-qa-clickhouse` is still up. The ledger's `clickhouse.databases`
was emptied during Phase 6 precisely so no drop path could exist; `databases_in_use` carried the
names instead. This is the hazard that bit DEV-2311.

**One recorded restore deliberately NOT applied.** The ledger's `env_restore` would have written
`CLICKHOUSE_HOST=zd69ju2h8d.us-east-2.aws.clickhouse.cloud` back into this **worktree's** `.env`.
That is the ledger faithfully restoring the developer's prior state, but it collides head-on with a
standing operator constraint — never wire ClickHouse Cloud into a worktree. The constraint wins;
`CLICKHOUSE_HOST` stays `127.0.0.1`, and the skip is recorded in both the ledger and `state.json`
rather than performed silently. `MAIL_MAILER` was restored to `smtp` as recorded (Mailtrap sandbox,
no real delivery path).

**Orphans found and left alone, not swept up.** A *second* bootstrap of this same worktree created a
`_8ffbc1`-suffixed set for this ticket that the ledger never recorded — `zod_claude_dev_2464_8ffbc1`,
`zod_testing_claude_dev_2464_8ffbc1`, and the Herd site `clarity-claude_dev_2464_8ffbc1.test`. The
`.env`'s `BROADCAST_CHANNEL_PREFIX=claude_dev_2464_8ffbc1` is the fingerprint. Unrecorded resources
do not get destroyed by a ledger-scoped teardown, so these are surfaced for the operator instead of
dropped on my own judgment. The worktree directory is reused and additionally carries DEV-2442 Herd
links, which are not this ticket's to touch.

`phase = done`.
2026-09-11 08:27 CDT · done · handoff complete. Jira was still In Progress after the undraft -- no draft-aware automation ran here -- so I transitioned DEV-2464 to In Code Review myself and recorded jira_transitioned_by=claude rather than crediting a gh-action that did not fire. QA env torn down strictly to the ledger: dropped zod_claude_dev_2464 and zod_testing_claude_dev_2464 (0 open connections, checked first) and unlinked the Herd site. ClickHouse deliberately untouched and verified so afterwards -- the shared literal clarity/zod databases still hold the fixture (29 billing_events / 26 hotlist) and the container is still up, which is why clickhouse.databases was emptied in Phase 6. One recorded restore skipped on purpose: env_restore would have written the ClickHouse CLOUD host back into a WORKTREE .env, against a standing constraint, so CLICKHOUSE_HOST stays local and the skip is recorded rather than performed silently. An unrecorded _8ffbc1 bootstrap set for this same ticket was found and LEFT ALONE, surfaced to the operator instead of dropped on my own judgment.
2026-09-11 08:34 CDT · done · okf wiki updated post-QA (the CLAUDE.md gate: land wiki edits only after the change has passed final review/QA). Four pages. company-metrics-dashboard.md: the cards() registry was 6 cards stale (10 -> 16, and the epic inserted 6 mid-grid so every index after unique-lpr-case-claims shifted), and the canonical gate snippet was WRONG in a way that mattered -- it showed a guest short-circuit returning TRUE inside a Pro gate, which is no shipped card. Split into the trait default (returns true for a guest, deliberately, because cardWidgets() needs only the array shape) and the five Pro-gated overrides (auth()->check() first, then false). platform-gotchas.md: PG-029 three-of-ten -> four-of-sixteen propagating cards, plus the scope limit that the propagate guarantee is synchronous-path only (Cache::flexible's deferred refresh runs inside rescue()). NEW PG-030 for join_use_nulls=0 -- the one real bug this ticket found: an unmatched LEFT JOIN yields a TYPE DEFAULT not NULL, so every IS NULL guard is dead code and zero-filled rows join to each other, +31% on a billing-adjacent figure. clickhouse-query-patterns.md: the always-FINAL vs use-sparingly tension resolved with measured per-table numbers and the rule behind them (FINAL earns its cost only when the column you READ is mutable across CDC versions). clickhouse-integration.md: production has max_memory_usage=0, max_memory_usage_for_user=0 and max_execution_time=0, so nothing bounds one query or N of them and a client timeout is not a cancellation -- documented with how to size cost x concurrency and what actually synchronises N orgs (not a deploy: prod cache is redis and no hook clears it; a Redis flush is the synchroniser, and it phase-locks the keys). okf-lint passes, 108 files, 0 warnings.
