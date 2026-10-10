2026-09-21 08:45 CDT · intake · intake complete: route=feature app=clarity base=main

## Phase 1 — grep backstop (haiku, 66s)
Swept every removed key/class + survivor class across the repo against the fast pass's
file inventory. Found 15 referencing sites NOT in that inventory. The material ones are
NINE dedicated PHPUnit widget-test files for the SURVIVING cards (UniqueScanCountsTest,
UniqueLprCaseClaimsTest + HerdGuard/LabelAxis/WindowSeam variants, LprRepossessionsTest,
RecoveryCountsTest, UniqueScansLeaderboardTest) plus UniqueScansToCaseClaimsWindowSeamTest
and tests/Unit/Clickhouse/ScanPlateStatsBatchQueryContractTest.php. None appear in the
ticket's AC-7. UniqueLprCaseClaimsLabelAxisTest.php is squarely in AC-4's blast radius.
Two stale docblocks also cite the pairs being merged (DashboardMetricCard.php:41,
SharedHitStatsData.php:693). False positives excluded: AggregateScanCount* models/factory/
seeder and RecoveriesKitUserScansReport (substring matches on "ScanCount").

## Phase 1 — driver-verified premise (settles a candidate's "could not confirm")
Candidate 1 flagged, but could not execute, a suspicion that two e2e specs assert an
adjacency that no longer matches cards(). VERIFIED BY THE DRIVER, read-only:

  current cards() indices : unique-lpr-case-claims = 2, recovery-counts = 3,
                            lpr-repossessions = 4, staging-live-hit-leader = 15
  tests/e2e/unique-lpr-case-claims.spec.ts:121,272-276,325-329
  tests/e2e/lpr-repossessions.spec.ts:138-142,307-314,363-370
      assert  claimsAt === recoveryCountsAt + 1      -> 2 === 4   FALSE
      assert  leaderAt === claimsAt + 1              -> 15 === 3  FALSE

Both specs are therefore ALREADY RED ON main, independent of DEV-2651. Provenance: the
assertions were written by an earlier card-slot mockup ticket (012030f39) and an earlier adjacency-spec ticket (d85d327e5) against the old
mockup-relative slot map; the recent card-reorder (df3ad2560, 2026-09-16) reordered cards() and did not
update them. Nothing caught it because Playwright is NOT wired into CI — no workflow in
.github/workflows references playwright or tests/e2e; the only entry point is the manual
`npm run test:e2e` script in package.json.

Consequences for this ticket:
  - "e2e green" cannot be a regression baseline here; the baseline is already red.
  - AC-7 asks for green specs, so the pre-existing drift has to be either fixed in this
    PR or explicitly excluded. That is a scope question for the operator, not a silent call.

## Phase 1 — escalated prep complete (4 candidates reconciled)
Fast pass (sonnet, 548s, conf 55) recommended escalation. Deep path: 3 blind sonnet
candidates dispatched in parallel with the grep backstop (564s / 611s / 615s;
conf 58 / 62 / 62). Reconciled inline by the driver (opus = TICKET_PLAN_MODEL) rather
than via a 5th dispatch — all four JSONs were already in driver context and re-emitting
them to disk for a same-tier sub-agent would have cost ~25k tokens for no analytic gain.
Zero-tolerance floor honoured: every single-candidate finding was carried through.

SETTLED BY AGREEMENT (4/4): none of the four surviving cards uses SharedHitStatsData, so
the SharedHitStatsData dual-axis pattern "pattern" must be REBUILT as a new concern, not inherited; the dual-axis seam
is a pure-PHP getOptions() override plus per-dataset yAxisID; no persisted card-toggle
state is stranded; AC-7's test list is materially incomplete; RecoveryCounts is by far the
largest of the four merges.

NEW, EACH FROM ONE CANDIDATE, ALL DRIVER-VERIFIED AGAINST THE CODE:
  - scan-count gating regression (candidate 2): ScanCount has NO canViewCardData();
    UniqueScanCounts gates on COMPANY_METRICS at :84-89. The merge silently removes the
    total-scan figure from every non-Pro org. NOT in the ticket. -> Q3, material.
  - timezone axis mismatch (candidate 1): 2 of the 4 merges zip series bucketed in
    different zones. -> Q9, material.
  - UniqueScansLeaderboard has FIVE user-facing "unique" sites, not the 2 named
    (verified: L58, L110, L141, L255, L276-277). -> Q7.
  - UniqueScansToLprRepossession has ZERO UNAVAILABLE_VALUE/numeratorUnavailable
    occurrences vs 6/9 and 4/14 in its siblings. -> Q8.
  - ThrowingQueryCard.php:8 extends ScanCount; DashboardMonthLabelsTest and
    DashboardCardMetricsTest use it as a generic fixture. Constrains Q4.
  - RecoveryTotalsBatchQuery's docblock: the two recovery-count paths can disagree within
    a TTL, which becomes visible once both sit on one card. -> Q5.

GATE: BLOCKED. 9 open questions, 5 material. 4 questions were answered from code.
2026-09-21 09:12 CDT · prepped · prep complete (escalated, 4 candidates reconciled): gate=BLOCKED, 9 open questions

## Phase 2 — Question Gate: interviewed, now CLEAN
needs_human_interview = true. All 9 open questions answered by Dalton, 2026-09-21.
Verbatim decisions:

  Q1 order          "yes, as written"
  Q2 recovery gate  "gate the scans to repo"  -> PARTIAL gate: the Total Repossessions
                    headline stays visible to ALL orgs (as today); only the ABSORBED
                    'N scans per repo' ratio is Pro-gated.
  Q3 scan-count     "Scan count should show both numbers for all orgs, that one is not
                    pro-gated" -> UNGATE the merged unique-scan-counts card entirely.
  Q4 classes        "delete them if unneeded"
  Q5 the recovery-totals batching work       "Pull it"
  Q6 e2e            "fix all"
  Q7 unique wording "that is true, we do want to drop the unique labeling from all 5"
  Q8 degrade path   "standarize"
  Q9 timezone       "Isnt there a ticket already for that?" -> yes, the viewer-timezone follow-on, which itself
                    says "land DEV-2651 first". Out of scope here except for internal
                    consistency between each card's two datasets.
  sizing            "Sizing is okey" -> keep as ONE ticket.

Q2/Q3 were asked together because they are the same question; Dalton answered "a mix of
A and B" and named each side explicitly.

DRIVER CORRECTION, recorded because it changed the load argument: I hypothesised that
ungating unique-scan-counts would make the recovery-counts ratio gate pointless for load
(same shared scan). WRONG. ScanPlateStatsBatchQuery::sharedMonthCacheKey() embeds
':tz={timezone}' (L447-452); UniqueScanCounts keys at safeTimezone(viewer) while
UniqueScansToRepo keys at SERIES_ZONE='UTC'. Two distinct cache entries, i.e. two distinct
scans, for any non-UTC viewer. The ratio gate therefore DOES still bound the tz=UTC scan
at ~29 orgs. Verified before telling Dalton, so the wrong version never reached him as
fact. After the viewer-timezone follow-on shifts SERIES_ZONE to the viewer zone the two keys collapse into one.

ACCEPTED RISK (operator, informed — the figure was stated twice before the decision):
ungating unique-scan-counts widens the tz=<viewer> ScanPlateStatsBatchQuery scan
(60,738,996 rows / 2.44 GB peak solo; ~10 GB at 4 concurrent copies, no lock) from ~29
orgs to every ADMIN/USER org's first render. Past months cache for a year, so this is a
cold-start cost. Phase 6's resource lane must measure it.

NEW DESIGN CONSEQUENCE of the Q2 answer: canViewCardData() is all-or-nothing, so
recovery-counts showing its headline to everyone while hiding one figure needs a
finer-grained per-figure check. No existing pattern covers this.
2026-09-21 09:50 CDT · gated · gate CLEAN: all 9 questions answered by operator

## Phase 3 — Ultra-Plan (opus arbiter, 517s)
9 subtasks, 7 excluded_sites. Waves: [t1 concern, t2 scan-count merge, t6 leaderboard
wording] parallel -> [t3, t4, t5] parallel on t1 -> t7 registry cutover -> t8 class
deletion + fixture repointing -> t9 e2e. Zero target_files overlap between parallel
non-ancestor nodes (verified by the planner).

Registry cutover is deliberately LAST, not first: registry-first would make every
intermediate commit a regression (a figure deleted before it is folded onto its
survivor). Class deletion is split from the registry edit because deleting a class still
named in cards() fatals at load.

SIZING GATE: fired (~40 files). PRE-ANSWERED by the operator at the Phase 2 interview,
which is why it was asked there — the plan was not authored twice.

BLAST RADIUS ['auth'] on t1, t2, t5, t7 — all four touch Pennant entitlement. Needs an
explicit operator approval line before Phase 4 may start them, in every mode.

PERF GATE: required=false, reasoning recorded in state.perf_validation.reason so it can
be challenged. No new query shape; the per-query cost is already measured in-repo. The
residual is production concurrency, which Phase 6's resource lane must measure.

TWO PLANNER FINDINGS, BOTH DRIVER-VERIFIED — neither was caught by any of the four prep
passes:

  1. CACHE KEY COLLISION (material). DashboardWidget::buildWidgetCacheKey() is
     '{class-basename}:{dateType}:org={id}', and UniqueScanCounts overrides it to append
     ':tz='. After the merge BOTH legs run inside UniqueScanCounts, so the obvious port of
     ScanCount's leg (which calls $this->buildWidgetCacheKey at ScanCount.php:66,118,154,163)
     produces the IDENTICAL key the unique-plate window already uses
     (UniqueScanCounts.php:220-225). Total scans would silently overwrite unique plates for
     a 10-minute-to-one-year TTL. The ported leg needs its own sub-namespace. Covered by t2.

  2. RecoveryCounts's month axis MOVES, and this ticket ships it. RecoveryCounts uses the
     base getGraphLabels() (DashboardWidget.php:156-166), i.e. viewer-local month
     boundaries. Pulling the recovery-totals batching work in moves its headline onto RecoveryTotalsBatchQuery,
     which buckets toStartOfMonth(repo_date) with NO timezone axis at all
     (RecoveryTotalsBatchQuery.php:54-61). So a few recoveries can move between adjacent
     months and the "this month" headline can shift slightly. This is the CORRECT direction
     — that same docblock calls the viewer-tz boundaries a day-level version of the
     mixed-clock defect the mixed-clock shared-cache-key defect had to fix — but it is user-visible and t5 must document
     it rather than leaving it to the viewer-timezone follow-on.
2026-09-21 10:01 CDT · planned · plan: 9-node DAG; blast_radius on t1/t2/t5/t7 awaiting approval

## Phase 3 — blast-radius approval
Dalton approved t1/t2/t5/t7 (Pennant entitlement) explicitly on 2026-09-21, after being
shown both planner findings. the recovery-totals batching work inclusion was re-affirmed, not withdrawn, with the
RecoveryCounts month-axis move understood as a shipped, user-visible consequence.

Session parked here at operator request ahead of a context compaction. Phase 4 has NOT
started: no code file has been modified, no worktree fanned out, no DB provisioned.
Resume with `/ticket DEV-2651 --resume`, which re-enters at Phase 3.5 (provision).
2026-09-21 10:08 CDT · planned · blast-radius approved (t1/t2/t5/t7); parking for session compaction

## 2026-09-21 — Phase 3.5 provision (Postgres only, ClickHouse deferred)

Resumed after session compaction. `phase-plan.sh --resume` → `PHASE_START: provision`,
`PHASE_PLAN: provision implement review verify handoff`, `PLAN_VERDICT: ok`;
`resume-validate.sh` → `RESUME_VERDICT: ok` (PR #427 OPEN/draft, claim mine, not stale).

Isolation asserted before anything else: run root is the worktree, not the main checkout.

`insightlpr:qa-prep` invoked `QA_PREP_MODE=provision-only QA_PROVISION_CH=no`
`QA_PREP_CONTEXT_PATH=docs/investigations/DEV-2651/prep-context.json`. Warm path — no bootstrap:

- `zod_claude_dev_2651_c29a86` exists, `migrate:status` rc=0.
- `zod_testing_claude_dev_2651_c29a86` exists, `migrate:status --env=testing` rc=0.
- `.env.testing` already points at the isolated testing DB, `CLICKHOUSE_DRIVER=pgsql`,
  `CLICKHOUSE_HOST=127.0.0.1`. Phase-4 test runs cannot reach the shared `zod_testing`.

Env writes (both recorded in the ledger's `env_restore` for qa-complete):

- `MAIL_MAILER` `smtp` → `log`.
- **`CLICKHOUSE_HOST` `zd69ju2h8d.us-east-2.aws.clickhouse.cloud` → `127.0.0.1`.** The worktree
  `.env` was still wired to ClickHouse Cloud. With `QA_PROVISION_CH=no` the container is not
  started, so a stray CH read now fails loud-and-local instead of hitting Cloud. `CH_STRATEGY=deferred`.
- `DEV_LOGIN_ENABLED=true` / `APP_ENV=local` already set — no write.

`tiger.state.simplified_geom` was **missing**, so the PostGIS patch was a real change
(`TIGER_CHANGED=yes`) and Step 5's baseline reseed ran: `db:seed --force` rc=0, `LocalDataSeeder`
created the hotlist, 15 kits (#16–#30) and their scans; `ClarityScoutClickhouseSeeder` no-op'd
("CLICKHOUSE_DRIVER is not pgsql") as expected on the deferred-CH path.

Probe: `https://clarity-claude_dev_2651_c29a86.test/` → **302**. A bare `curl` reports `000`
because the Herd root CA is not in curl's system bundle — `-k` returns 302 and a browser is fine.
Not a site fault; recorded so a later probe failure is not misread.

Ledger: `.context/qa-prep-ledger.json` (`provision_mode: provision-only`, `plans_written: false`,
`clickhouse.strategy: deferred`). `state.qa.{ledger,provisioning,provisioned_early}` recorded,
`state.phase = provisioned`.

Carried forward for Phase 4: the change touches no `app/Clickhouse/**` file and no new test will
reference the `clickhouse` connection, so CH stays down. If a test does reach CH it will fail
locally — that failure is the cue to re-run qa-prep with `QA_PROVISION_CH=yes`, not to repoint
`.env` by hand.
2026-09-21 10:32 CDT · provisioned · provision: isolated Postgres up (warm), PostGIS patch + reseed, CH deferred + Cloud host neutralized

### t6 merged — leaderboard wording (worker: sonnet)

`php artisan test --filter=UniqueScansLeaderboardTest` → 17 passed, 0 failed.
RED before: `Failed asserting that 'Unique Scans Leaderboard' ... does not contain "unique"`
(1 failed / 16 passed). GREEN after: 17 passed / 52 assertions. Pint clean.

All five sites were exactly where the plan said. Heading → `'Scans Leaderboard'` (the ticket's
verbatim wording). The other four became **"distinct"** rather than dropping the qualifier:

| site | before | after |
|---|---|---|
| `:58` heading | `Unique Scans Leaderboard` | `Scans Leaderboard` |
| `:110` chart subtitle | `Top 10 kits by unique plates` | `Top 10 kits by distinct plates` |
| `:141` axis title | `Unique plates` | `Distinct plates` |
| `:255` dataset label | `Unique Plates` | `Distinct Plates` |
| `:276-277` description (x2) | `N unique plates` | `N distinct plates` |

**OPEN FOR OPERATOR CONFIRMATION (not a blocker).** Decision 7 was "drop the unique labeling
from all 5". The worker read that as "no user-facing string may contain 'unique'" and swapped in
a synonym for the four non-heading sites, on the grounds that the metric really is deduplicated
plates (`uniqExact(ocr)`) and bare "plates" / "plates scanned" would assert raw scan volume — a
different and wrong claim. That is defensible, but a synonym swap is not literally a *drop*, and
this is user-visible copy. Surfaced to the operator; a one-word revert either way. The card key
`unique-scans-leaderboard`, the class name, and the cache namespace are unchanged (asserted).

Note for t9: `getDescription()` carries an in-code comment that
`tests/e2e/staging-live-hit-leader.spec.ts` asserts on this string. That spec is already in t9's
target_files; the assertion tail now reads "distinct plates".

### Wave-1 hazard found and cleared: bootstrap regenerates the Laravel Boost doc set

Every one of the three node worktrees came out of `bootstrap.sh` with `CLAUDE.md`, `AGENTS.md`
and `.junie/guidelines.md` modified — the known `boost:install` regeneration, none of it in any
node's `target_files`. Reverted in all three (`git checkout --`) before any merge, so it cannot
ride into the diff. `boost.json` survived in all three. Re-check this after bootstrapping the
wave-2 and wave-3 node worktrees.

### Instrumentation gap (recorded, not back-filled)

The wave-1 dispatches (t1/t2/t6) were NOT bracketed with `timing.sh`, so `state.timings[]` has no
entry for them. Back-filling a start time would invent data of exactly the kind the script exists
to prevent, so the gap is left visible. Waves 2 and 3 are bracketed properly. For reference, the
t6 agent reported 178,016 ms wall-clock and 136,409 subagent tokens in its completion envelope.
2026-09-21 10:41 CDT · implementing · t6: drop 'unique' from the five leaderboard copy sites (17 tests green)

### t1 merged — RatioAgainstUniqueScans concern (worker: sonnet)

`php artisan test --filter=RatioAgainstUniqueScansTest` → **11 passed, 32 assertions**, re-run in
`REPO_PATH` after the merge (not just in the node worktree). Pint clean.

RED was demonstrated properly, not asserted: the worker temporarily swapped in a deliberately
anti-pattern trait (keys via `buildWidgetCacheKey()`, no marker honour, no `numeratorUnavailable`,
naive `ratio()`, hardcoded `canViewRatioFigure(): true`) and got **8 of 11 failing** — the
class-name-in-key pin failed on the sibling re-querying (1 vs 0), the throw pin failed first with
an uncaught `RuntimeException` and then with a fabricated 0-filled series, the marker pin returned
a zero-filled series instead of `[]`, `ratio()` threw `DivisionByZeroError`, and the ungated
`canViewRatioFigure` pin failed against the hardcoded `true`. Each of the five plan pins therefore
discriminates. The real implementation was restored and `diff`ed against a pre-mutation backup to
prove no artifact of the RED demo leaked in.

Scope the worker widened, deliberately and reported: beyond the five pins it also lifted
`windowUniqueScans()`, `runUniqueScansBatch()`, `fetchUniqueScansRange()` and `pastMonthCacheTTL()`
into the trait. Justified — `fetchUniqueScansSeries()` cannot be correct without the past-month TTL
policy, and the window arm needs the identical degrade shape or decision 8's "missing fail-safe"
reappears on the window leg. All four are verbatim duplicates across the three `UniqueScansTo*`
classes today; those classes still carry their private copies until t8 deletes them.

Three design calls recorded for the reviewer:

- **`SERIES_ZONE` is NOT a trait constant.** All three source classes declare their own private
  `SERIES_ZONE = 'UTC'`; hoisting it would risk a PHP trait/class constant collision at t3/t4/t5
  adoption. It is a parameter instead: `fetchUniqueScansSeries(..., string $seriesZone = 'UTC')`.
- **`ratio()` takes `(numerator, denominator)`**, not the associative `array $stage` shape the
  three classes use privately — correct for a denominator-agnostic seam; each adopter zips its own.
- **The trait references `UniqueScanCounts::IMPERSONATED_PAST_MONTH_TTL_MINUTES`.** A `Concerns/`
  trait reaching into a concrete widget is mildly smelly, but it keeps one source of truth for a
  TTL floor that card documents and owns. Flagged for Phase 5 rather than pre-emptively changed.

PHP behaviour the worker hit and documented inline: `ReflectionProperty` cannot resolve a
**private trait-declared** property against a test double's *subclass* name — it must be reflected
against the class that actually `use`s the trait.

**Driver edit after the merge (not the worker's):** the trait shipped at roughly 40% comment lines.
The operator's standing preference is sparse commentary, and the worker brief said so explicitly,
so the mechanical restatements were cut and the hazard notes kept verbatim — the
`NEVER buildWidgetCacheKey()` warning, the fabricated-0-at-a-one-year-TTL hazard, the
not-LOCKED_VALUE distinction, and the past-month TTL-floor rationale. Code unchanged; Pint clean;
the 11 tests re-run green after the trim.

All seams the trait depends on were verified present with matching signatures before merge:
`sharedMonthCacheKey` `:447`, `sharedRangeCacheKey` `:400`, `sharedFetchFailureMarkerKey` `:442`,
`FETCH_FAILURE_TTL_SECONDS` `:429`, `handle` `:192`, `handleRange` `:284`, and
`DashboardWidget::getCacheTTL($index = 0)` `:268` (the abstract's untyped `$index` matches the
parent exactly — it is signature compatibility, not a guideline violation).
2026-09-21 10:49 CDT · implementing · t1: RatioAgainstUniqueScans concern - shared numerator, standardized degrade path, canViewRatioFigure seam (11 tests green)

### t2 merged — scan-count merged into unique-scan-counts, card UNGATED (worker: sonnet)

`php artisan test --filter='UniqueScanCountsTest|ScanPlateStatsBatchQueryContractTest'` →
**29 passed, 97 assertions**, re-run in `REPO_PATH` after the merge. Pint clean.

RED for pin 1, written before any production edit:
`Failed asserting that false is true.` at `UniqueScanCountsTest.php:95`
(`$this->assertTrue(UniqueScanCounts::canViewCardData())` for an ADMIN on a non-entitled org).
GREEN after removing the override.

**Decision 2 was implemented by DELETING the `canViewCardData()` override, not rewriting it.**
`RendersDashboardStat::canViewCardData()` `:121-133` is `auth()->guest() ? true : static::canView()`
— the ADMIN/USER role check and nothing else, which is exactly what "show both numbers for all
orgs" means, and exactly what the ungated `ScanCount` always inherited. Verified by reading the
trait, not assumed.

**Load consequence, stated plainly and accepted by the operator:** the per-viewer-timezone
`ScanPlateStatsBatchQuery` scan (60.7M rows / 2.44 GB peak, the scan-volume measurement note §4) is no longer bounded to
the ~29 entitled orgs on this card. The separate `tz=UTC` scan remains bounded, because the
`recovery-counts` ratio gate (t5, decision 3) still gates that one and the two key at different
zones. This is recorded in the class docblock so it cannot be lost.

Cache sub-namespace for the ported total leg: `TOTAL_NAMESPACE = 'scan-total:'`, keyed via
`buildWidgetCacheKey('scan-total:'.$dateType, $orgId)` →
`unique-scan-counts:scan-total:<dateType>:org=<id>:tz=<zone>`. Distinct from both hazards: the
unique-plate series lives in `ScanPlateStatsBatchQuery`'s own `scan-plate-stats:unique-month-v1:`
namespace (untouched, so `UniqueScansLeaderboard`'s sparkline still reads it), and the existing
`today`/`last_7d`/`last_month` unique-headline keys carry no `scan-total:` segment.

`ScanCount`'s `resolvePastMonthsFromAggregate()` / `resolveCurrentMonthFromHot()` were ported
verbatim — including the known setTable-discard bug `Scan::hot()::query()` setTable-discard bug and the
no-try/catch contract on the current-month leg — renamed with a `Total` prefix, rebucketed on
`bucketZone()` per decision 9, behind new overridable seams (`fetchTotalMonthlySeries`,
`fetchTotalCurrentMonthCount`, `fetchTotalRangeTotal`). `getOptions()` deliberately has **no**
`scales.y1`; the new test asserts its absence so a copy-paste from t3/t4 cannot slip in.

**Two pre-existing tests were rewritten because decision 2 inverts their premise** — recorded
because deleting or inverting coverage is the easy mistake here:
- `test_non_entitled_org_renders_locked_and_issues_zero_clickhouse_calls` →
  `test_non_entitled_org_is_no_longer_locked_now_that_the_card_is_ungated`. The old test pinned
  the load guarantee the operator has now deliberately given up; the new one asserts the unlocked
  stat **and** the real fetch-seam counts, so the call count is still pinned, just at its new value.
- `test_to_stat_for_today_issues_exactly_three_clickhouse_round_trips` → `..._four_...`
  (`toStat()` now also resolves the ported total's today/last_7d headline).
- `test_admin_impersonator_bypasses_the_pro_gate` kept, docblock updated: the gate it bypassed
  no longer exists.

**Driver fix after the merge (not the worker's):** the worker's docblock explaining the ungating
was attached to `getRgbColor()`, which it does not describe. Moved the statement to the class
docblock where a reader will actually find it, and dropped the misplaced copy. Pint clean; the
29 tests re-run green after the move.

Worker-reported, left as-is and flagged for Phase 5: phpstan says
`Expression on left side of ?? is not nullable` on the new `currentPeriodScanTotal()`. The same
warning already fires on `DashboardWidget::currentPeriodTotal()`, whose shape this mirrors — a
pre-existing pattern, not a new defect. Confirm against `composer analyse` at review.

The two `ScanPlateStatsBatchQuery*` test files in t2's `target_files` needed no edits:
`app/Clickhouse/Queries/ScanPlateStatsBatchQuery.php` was never touched, so they were a guard
rail, not expected work.
2026-09-21 10:56 CDT · implementing · t2: merge scan-count into unique-scan-counts (two datasets, one axis) and ungate the card (29 tests green)

### t4 merged — unique-scans-to-lpr-repossession absorbed into lpr-repossessions (worker: sonnet)

`php artisan test --filter=LprRepossessionsTest` → **32 passed, 120 assertions** (27 pre-existing
+ 5 new), re-run in `REPO_PATH` after the merge. Pint clean.

RED for the degrade pin `test_a_numerator_failure_degrades_the_ratio_but_not_the_headline`:
`Undefined constant App\Filament\Widgets\Dashboard\LprRepossessions::UNAVAILABLE_VALUE` (trait not
yet used); the other four new tests failed alongside it (empty description, one dataset instead of
two, no `y1`). GREEN: all 32.

**Decision 8 discharged — this is the node's reason for existing.** The absorbed card had zero
`UNAVAILABLE_VALUE`/`numeratorUnavailable` occurrences, so a numerator failure rendered a
fabricated ratio. Now: headline (`getCardTotal()`, db_repos) renders unchanged, the description
renders `'-- scans per LPR repo'`, and `getData()` omits the ratio dataset entirely rather than
plotting against a non-numeric value.

**Decision 9 verified by the driver, not taken on the worker's word.** `getRatioMonthlyData()`
reuses `$this->getMonthlyData(formatted: false)` — the card's own existing 13-bucket denominator,
never re-fetched — and re-derives each index's calendar month to pull the matching numerator
bucket. The skew hazard was checked directly: `LprRepossessions::getMonthlyData()` overrides the
base and buckets on `$through = now('UTC')`, `SERIES_ZONE` is `'UTC'`, and `getGraphLabels()`
also walks `now('UTC')->startOfMonth()`. All three axes therefore derive from the same UTC month
origin. The `subMonths()` vs `subMonthsNoOverflow()` difference between `getMonthlyData()` and
`getGraphLabels()` is **not** a defect: both call `startOfMonth()` first, so the day is always 1
and overflow can never trigger.

Residual noted, deliberately not changed: `getRatioMonthlyData()` reads `now(SERIES_ZONE)` into
`$through` one line before calling `getMonthlyData()`, which computes its own `now('UTC')`. A
month boundary crossing between those two statements would skew the zip. The window is
sub-microsecond and tests freeze the clock, so hardening it was judged a worse trade than
disturbing a green node's memoisation. Flagged for Phase 5 to weigh.

`COLD_BLOCK_SECONDS` / `COLD_LOCK_SECONDS` / `REFRESH_LOCK_SECONDS` confirmed byte-identical —
`git diff` reports **0** touched lines matching those names. `canViewRatioFigure()` correctly left
un-overridden (the card is fully Pro-gated, so the trait default is right).

**Worker environment deviation, checked for blast radius.** The worker's `Edit`/`Write` tools were
sandboxed to a sibling worktree path and refused to write into its own worktree; it fell back to
`Bash` + `python3` to patch both files, verifying with `php -l`. Because that class of failure is
exactly how an edit leaks into the wrong checkout, `git status` was run across the main checkout
and all four live worktrees before the merge: **no stray edits anywhere** — t4's two files in t4's
worktree, nothing else. (The main checkout's modified `.serena/project.yml` is unrelated
pre-existing drift, not touched by this run.)
2026-09-21 11:00 CDT · implementing · t4: absorb unique-scans-to-lpr-repossession into lpr-repossessions (dual Y axis, degrade fail-safe) (32 tests green)

### t3 merged — unique-scans-to-case-claims absorbed into unique-lpr-case-claims (worker: sonnet)

`php artisan test --filter=UniqueLprCaseClaims` → **32 passed, 114 assertions, 15 skipped**,
re-run in `REPO_PATH` after the merge. Pint clean.

The 15 skips are pre-existing `UniqueLprCaseClaimsQueryTest` cases behind `RequiresClickhouse`,
unrelated to this change and unchanged by it. Recorded because a class-level `RequiresClickhouse`
can silently hide auth/tenancy coverage (the phpunit memory-limit quirk) — these skips are the query test, not the
widget tests, all 32 of which ran.

RED was demonstrated by reverting the widget to its pre-t3 state and re-running: exactly the
four new pins failed (the three ratio-behaviour pins in `UniqueLprCaseClaimsTest` and the axis pin
in `UniqueLprCaseClaimsLabelAxisTest`); the other 28 stayed green. Implementation restored → 32.

**Decision 9 verified by the driver.** `getRatioMonthlyData()` `:432-454` consumes
`$this->getMonthlyData(formatted: false)` — the same memoised array `getData()`'s count dataset
renders — and indexes it positionally with `$index = 12 - $i`. Checked the alignment directly:
`getMonthlyData()` buckets on `$through = now('UTC')` and appends `$data[]` over
`for ($i = 12; $i >= 0; $i--)`, so it is a 13-entry positional array oldest→newest;
`SERIES_ZONE` is `'UTC'` and `getRatioMonthlyData()` walks the identical loop. The only genuinely
new read is the trait's numerator. At the window grain the same principle holds:
`getCurrentPeriodRatio()` divides by the already-cached `getCardTotal()` rather than re-deriving
the deduped stage.

**Decision 8.** `getData()` calls `getRatioMonthlyData()` first (that is what sets
`$numeratorUnavailable` through the trait), then appends the ratio dataset only
`if (! $this->numeratorUnavailable)`. `getRatioDescription()` substitutes `UNAVAILABLE_VALUE`
for the number alone, leaving the headline intact.

**Scope the worker added, deliberately and reported:** a `refreshData()` override re-declaring
`#[On('refresh-dashboard')]`, clearing `$ratioMonthlyDataMemo` and `$numeratorUnavailable`.
Livewire does not inherit the attribute onto an override, so without the re-declaration a live
filter switch on the long-lived expanded-card instance would keep a stale ratio or a stuck
unavailable flag. It mirrors `UniqueScansToCaseClaims::refreshData()`, which t8 deletes. In
target_files, and correct. (Note the override writes the trait's `private $numeratorUnavailable`
— legal, because a trait is flattened into the using class.)

`getMonthlyData()` itself was left byte-for-byte unchanged and the ratio lives in a separate
`getRatioMonthlyData()`, which is why `UniqueLprCaseClaimsHerdGuardTest` and
`UniqueLprCaseClaimsWindowSeamTest` needed zero edits and stayed green — their doubles never
reach the new numerator path. `getCardQueryStatements()` (ExplainsCardQuery) deliberately
untouched: no AC or decision names it, and editing it would add untested surface.

**Same worker-sandbox deviation as t4**, same check applied: the Edit/Write tools were anchored
to `dev-2651-c29a86` rather than the node worktree and refused the cross-worktree write, so the
worker patched via Bash + `python3` with `php -l` after each step. `git status` across the main
checkout and every live worktree before the merge showed **no stray edits** — t3's three files in
t3's worktree and nothing else.
2026-09-21 11:06 CDT · implementing · t3: absorb unique-scans-to-case-claims into unique-lpr-case-claims (dual Y axis) (32 tests green)

### t5 merged — unique-scans-to-repo absorbed into recovery-counts + the recovery-totals batching work + partial gate (worker: sonnet)

`php artisan test --filter='RecoveryCountsTest|RecoveryTotalsBatchQueryTest'` →
**16 passed, 51 assertions, 9 skipped**, re-run in `REPO_PATH`. Pint clean. The 9 skips are
`RecoveryTotalsBatchQueryTest`'s ClickHouse-only cases; the local CH is deliberately down.

RED for pin 1 (`the headline issues one batched call not thirteen`) and pin 3
(`a non entitled admin never computes the ratio`) against the pre-change file:
`SQLSTATE[42P01]: Undefined table: clarity.recoveries does not exist` — the old `getMonthlyData()`
still made 13 Eloquent `count()` calls so the recording seam was never invoked, and
`BadMethodCallException: Method RecoveryCounts::canViewRatioFigure does not exist.`

**Decision 3 — the gate was traced by the driver, not accepted on report.** Every path to the
numerator is guarded BEFORE any trait read:
- `toStat()` `:99` — `if (static::canViewRatioFigure())` wraps the ONLY call to
  `ratioDescription()` (verified: `ratioDescription` has exactly one caller, `:100`);
- `getData()` `:203` and `getOptions()` `:229` both route through `ratioSeriesForChart()`
  `:184-190`, which returns `null` before `ratioMonthlySeries()` is ever reached.
`ratioMonthlySeries()` `:156` and `windowRatio()` `:132` are the sole callers of the trait's
`fetchUniqueScansSeries()` / `windowUniqueScans()`, and both sit behind those guards.
`canViewRatioFigure()` is a pure Pennant/session check with no ClickHouse dependency. Pin 3
asserts `scansCalls === 0` through a recording seam over `runUniqueScansBatch()` after calling
all three entry points — an invocation count, not an output assertion.

**Decision 5 (the recovery-totals batching work).** The headline now batches through `RecoveryTotalsBatchQuery` instead of
13 Eloquent round trips. `tests/Feature/Clickhouse/RecoveryTotalsBatchQueryTest.php` needed no
edits: it already exists (the ClickHouse isolation guidance) and already pins `handle()`/`handleRange()`/
`sharedMonthCacheKey()`/`sharedWindowCacheKey()` plus byte-for-byte parity between
`handleRange()` and `Recovery::query()->forOrg()->between()->count()` against real ClickHouse.
That parity test is the evidence the headline's rendered value is unchanged.

**Decision 9.** `ratioMonthlySeries()` consumes `$this->getMonthlyData(formatted: false)` — the
same array the headline reads — and zips `self::ratio($scans[$monthKey] ?? 0, $repos[12 - $i] ?? 0)`.
Pinned by `test_the_ratio_is_zipped_against_the_headlines_own_month_array`, which stubs *only*
`getMonthlyData()` with an arbitrary fixture and proves the ratio reads exactly those values.

Both required docblock statements are present: the labels move viewer-local → UTC with the label
axis co-varying, and `repo_date` is a ClickHouse `Date` with no timezone axis (the viewer-timezone follow-on owns it).

**Driver fix after the merge — decision 8 was NOT satisfied as delivered.** `ratioDescription()`
returned the sentence `'Scan data is temporarily unavailable'` and never used `UNAVAILABLE_VALUE`,
while `UniqueLprCaseClaims:490` and `LprRepossessions:422` both render
`$this->numeratorUnavailable ? self::UNAVAILABLE_VALUE : number_format($ratio, 1)`. Decision 8 is
explicitly "standardize `UNAVAILABLE_VALUE`/`numeratorUnavailable` across all three ratio merges",
and three adjacent cards on one grid degrading with different copy is the failure it exists to
prevent. Aligned to `$value.' scans per repo'` and the assertion updated to compare against
`RecoveryCounts::UNAVAILABLE_VALUE` rather than a hardcoded literal. 16 tests re-run green.

*(Worth an operator glance later: `'-- scans per repo'` is terser than a plain-English outage
sentence. If that reads badly, change all THREE cards together — not this one alone.)*

### Baseline comparison — the local dashboard suites (evidence, not assumption)

`tests/Filament/Widgets/Dashboard` + `tests/Filament/Pages`, run with `PHPRC` set to a php.ini
carrying `memory_limit = 2048M` (the `-d` flag does not reach the phpunit child — the phpunit memory-limit quirk):

| | failed | skipped | passed |
|---|---|---|---|
| baseline `ced898b40` (merge-base with `main`) | **16** | 74 | 545 |
| this branch after t1-t6 | **15** | 74 | 582 |

Diffed test-by-test rather than compared by count:

- **13 failures are identical and pre-existing** — all `ViewException` from the local pgsql-bridge
  ClickHouse having no `scans_cold` table (`DashboardCardMetricsTest` ×1, `StagingLiveHitLeaderTest`
  ×1, `UniqueScansToRepoTest` ×1, `DashboardCardExpansionTest` ×7, `DashboardTest` ×3). Not CI
  failures — CI runs a real ClickHouse.
- **3 baseline failures are FIXED by this diff**: `RecoveryCountsTest > can render`,
  `> can render stats`, `> can switch stats with date`. the recovery-totals batching work's batched headline no longer
  reaches the missing table for the headline path.
- **2 NEW failures, both expected and both owned by t7**: `DashboardCardVisibilityTest >
  only the known gated cards are withheld from a non entitled viewer` (`:78` — its
  `$expectedGated` list still names `unique-scan-counts`) and `> can view card data is total with
  no authenticated user` (`:488` — the guest `expandableCards()` list now contains
  `unique-scan-counts`). Both are the gated allowlist t2 deliberately invalidated and t7's
  target_files already cover (`:59-73` and `:481-497`). Confirmed by reading the actual failure
  diffs, not inferred from the test names.

A baseline worktree is parked at `.claude/worktrees/dev2651-baseline` (detached at `ced898b40`)
so t9 can establish the e2e red baseline against `main` without re-provisioning.
2026-09-21 11:21 CDT · implementing · t5: absorb unique-scans-to-repo into recovery-counts, batch the headline (the recovery-totals batching work), partial Pro gate on the ratio (16 tests green)

### Finding while t7 ran: the e2e specs CANNOT run locally until ClickHouse is up

Checked rather than assumed, against the real site:

```
GET /__qa/login/1  -> 302   (dev-login works)
GET /app           -> 500   (7.8 MB error page; 18 x "ViewException", 5 x "Server Error")
```

The Company Metrics page 500s because Phase 3.5 provisioned Postgres only
(`QA_PROVISION_CH=no`, `CLICKHOUSE_HOST` neutralised to `127.0.0.1` with no container). Every
one of t9's seven Playwright specs navigates to that page, so today they would all fail on a
500 long before reaching their own assertions — a red run that says nothing about the specs.

Consequence for t9, decided now rather than discovered by the worker: ClickHouse must be stood
up (`qa-prep QA_PREP_MODE=provision-only QA_PROVISION_CH=yes`) **before** t9 executes anything.
Phase 6 needs it regardless. Deliberately NOT done yet — `up.sh` rewrites this worktree's
`.env`/`.env.testing` CH keys, and changing the test environment mid-flight would invalidate the
t7/t8 merge verification against the baseline recorded below. Sequence: finish t7 → t8 → bring CH
up → t9.

Carried risk when that happens (already in the plan): the hardcoded clarity-prefix issue (`ScanStatsBatchQuery` hardcodes
`clarity.`) is expected to 500 the page under a per-worktree local CH. Work around it; do not fix
it in this ticket. Note also that the isolated per-worktree CH database names are the correct
approach — a hardcoded prefix is a bug to fix upstream, not a reason to de-isolate onto literal
`clarity`/`zod` databases (the ClickHouse isolation guidance).

**The red baseline t9 needs is already established analytically, from `cards()` on the merge-base**
— and this is conclusive, because the assertions are pure index arithmetic over the grid's
`data-card-key` order:

`main` order: `scan-count`=0, `unique-scan-counts`=1, `unique-lpr-case-claims`=2,
`recovery-counts`=3, `lpr-repossessions`=4, …

- `unique-lpr-case-claims.spec.ts:121` asserts `claimsAt === recoveryCountsAt + 1` → `2 === 4` → **RED**
- `lpr-repossessions.spec.ts:138` asserts the same → `2 === 4` → **RED**
- `lpr-repossessions.spec.ts:141` asserts `reposAt === claimsAt + 1` → `4 === 3` → **RED**

Against t7's new 12-card order (`unique-lpr-case-claims`=1, `lpr-repossessions`=2,
`recovery-counts`=3):

- `claimsAt === recoveryCountsAt + 1` → `1 === 4` → still RED, and correctly so: in the new
  layout Unique LPR Case Claims sits at row 1 col 2 and Total Repossessions at col 4, so the
  spec's own premise ("claims occupies mockup row 1 col 3", "the slot after Total Repossessions")
  no longer describes the design. t9 must re-derive these from the new layout, not nudge the offsets.
- `reposAt === claimsAt + 1` → `2 === 2` → would pass by coincidence.

That coincidence is exactly why the plan requires index-of-key lookups over
`recoveryCountsAt + N` arithmetic: offset assertions are what let these two specs rot silently
through the recent card-reorder's reorder while Playwright sat outside CI.

Playwright itself is ready: `chromium-1228` is installed, `playwright.config.ts` takes `BASE_URL`
(falling back to `APP_URL`), and `E2E_START_SERVER=1` spawns its own `php artisan serve` on
127.0.0.1:8000 — so a baseline run against `main` needs no extra Herd site, just the
`dev2651-baseline` worktree (now carrying `node_modules`) and a working ClickHouse.

### t7 merged — registry cutover, 16 -> 12 cards (worker: sonnet)

The grid is now 12 cards, in exactly the approved order. Verified by reading the applied
`cards()` in `REPO_PATH`, index by index:

`unique-scan-counts`(0) · `unique-lpr-case-claims`(1) · `lpr-repossessions`(2) ·
`recovery-counts`(3) · `live-hits`(4) · `historical-hits`(5) · `staging-live-hits`(6) ·
`staging-historical-hits`(7) · `missed-opportunities`(8) · `unique-scans-leaderboard`(9) ·
`recovery-leader`(10) · `staging-live-hit-leader`(11)

All three test-pinned constraints hold: leaderboards last three in order (9,10,11); four hit
cards contiguous (4-7); `unique-scan-counts`(0) precedes `unique-scans-leaderboard`(9).

RED first: the order test was rewritten to the 12-key map and run against the untouched
`cards()` → `Failed asserting that two arrays are identical`. Then `cards()` and the docblock
were rewritten → GREEN. The docblock was rebuilt from the TEST file's row table, not from the
class's own prose, which already contradicted its own array on `main`.

**The two reds t2 left behind are repaired**: `DashboardCardVisibilityTest > only the known gated
cards are withheld from a non entitled viewer` and `> can view card data is total with no
authenticated user` both pass.

Gated allowlist is now exactly five keys — `staging-live-hit-leader`, `missed-opportunities`,
`unique-lpr-case-claims`, `unique-scans-leaderboard`, `lpr-repossessions` — kept as an explicit
list, not a tautology over `cards()`. `recovery-counts` is correctly absent: it has no
`canViewCardData()` override at all, only `canViewRatioFigure()`, so the headline stays visible
to every org (decision 3).

`getStatColor()` arms removed: `'scan-count'` (from the `unique-scan-counts` pairing arm),
`'unique-scans-to-repo'` and `'unique-scans-to-lpr-repossession'` (from the `lpr-repossessions`
arm), and the standalone `'unique-scans-to-case-claims' => 'success'`. Unreachability was proved
by grepping every `getStatColor` call site: the only caller is each card's own `toStat()` /
`SharedHitStatsData::getData()`, reached solely through `CompanyMetricsOverview::getStats()`
iterating `cards()` — and none of the four keys are in `cards()` any more.

### Baseline re-comparison after t7 — one failure the plan did NOT anticipate

`tests/Filament/Widgets/Dashboard` + `tests/Filament/Pages`, same `PHPRC` harness:

| | failed | passed |
|---|---|---|
| baseline `ced898b40` | 16 | 545 |
| after t1-t6 | 15 | 582 |
| after t7 | **17** | 564 |

Reconciled exactly: 15 − 2 (the visibility pair t7 fixed) + 4 new = 17.

Three of the four new ones were predicted by the worker and are t8's: `UniqueScansToCaseClaimsTest`,
`UniqueScansToLprRepossessionTest` and `UniqueScansToRepoTest` each fail
`stat color is explicit and not gray` — those suites call `getStatColor()` directly and now get
`'gray'` because their key left the registry. t8 deletes all three files.

**The fourth was NOT in the plan and is a genuine exhaustiveness gap.**
`DashboardCardQuerySectionTest > an impersonator gets the statement for the expanded card` fails
`Failed asserting that actual size 0 matches expected size 1` at `:65`. Cause found by reading
the file: `expandedPage()` at `:31` defaults to `string $card = 'scan-count'` — a **real registry
key used as a generic fixture**, now resolving to nothing. The plan enumerated three `ScanCount`
fixture sites (`ThrowingQueryCard.php:8`, `DashboardMonthLabelsTest`, `DashboardCardMetricsTest`);
this is a fourth. Folded into t8's file list.

Full sweep of every surviving `scan-count` reference, so t8 and t9 work from a complete list
rather than the plan's partial one:

| site | owner | action |
|---|---|---|
| `DashboardCardQuerySectionTest.php:31` | t8 | repoint the default to `unique-scan-counts` (it implements `ExplainsCardQuery`, so `supported` stays true) |
| `DashboardCardQuerySectionTest.php:104,107` | t8 | arbitrary key inside a locally-overridden `cards()`; self-contained and passing — rename only for clarity |
| `DashboardMonthLabelsTest.php:91` | t8 | cache key string `"scan-count:{$dateType}:org={$orgId}"` — must become `unique-scan-counts:…` when the fixture class is repointed. **This is precisely the "fixture swap that silently defeats the regression" hazard**: get it wrong and the month-bucket overflow guard guard still passes while guarding nothing |
| `DashboardCardExpansionTest.php:39,40,212,213,214` | t7 (left deliberately) | generic toggle-mechanics only; never resolved against the registry. Cannot be verified locally (they are inside the ViewException set) — re-check under CI's real ClickHouse |
| `tests/e2e/helpers/company-metrics.ts:10` | t9 | `SCAN_COUNT_CARD_KEY` export |
| `tests/e2e/unique-scans-leaderboard.spec.ts:450` | t9 | asserts `scan-count` is present in the Pro grid |
| `DashboardWidget.php:289`, `ClarityScoutBirdsEyeController.php:1097` | none | prose examples only, harmless |
| `UniqueScanCounts.php:86-87` | t8 | stale comment: the green "matches ScanCount so the two scan-family cards read as one group" — true in spirit, but ScanCount no longer has a slot |

The 7 `DashboardCardExpansionTest` ViewExceptions and the rest of the environmental set are
unchanged from baseline; the worker confirmed each throws
`SQLSTATE[42601] syntax error at or near "WITH"` against `clarity.scans_cold` — the pgsql bridge
cannot execute ClickHouse's `WITH ROLLUP`.
2026-09-21 12:07 CDT · implementing · t7: registry cutover 16->12 cards in the approved order, five-key gated allowlist, getStatColor cleanup

### t8 merged — four absorbed classes deleted, fixtures repointed (worker: sonnet)

10 files deleted (4 production, 2,637 lines; 6 test files). `tests/Feature/Clickhouse/
UniqueScansToCaseClaimsQueryTest.php` correctly **preserved** — despite the name it exercises the
surviving ClickHouse query class, and only matched a grep on its own class name.

### The plan's mutation check was based on a wrong premise — corrected and re-verified

The plan said: "revert the `monthKeyStart()` anchoring by hand and confirm `DashboardMonthLabelsTest`
goes red. If it does not, the fixture swap lost the coverage." The worker reported that
`DashboardMonthLabelsTest` never exercises `monthKeyStart()` at all — it pre-seeds every month, so
the aggregate-batch path (the only caller) never runs.

**The driver re-ran the mutation independently rather than accepting that**, since a wrong answer
here means silently losing the month-bucket overflow guard guard. Mutation applied to
`UniqueScanCounts::monthKeyStart()` `:214`:

```php
-        return Carbon::createFromFormat('Y-m-d', $monthKey.'-01', $timezone);   // the month-bucket overflow guard fix
+        return Carbon::createFromFormat('Y-m', $monthKey, $timezone);           // pre-the month-bucket overflow guard bug
```

Result:
- `DashboardMonthLabelsTest` → **6 passed** (stays GREEN — it does NOT guard this)
- `DashboardCardMetricsTest > unique scan counts month key start does not overflow when today is
  the 31st` → **RED** (`'2026-06-01'` expected, got `'2026-07-01'`)

So the guard is real, it is intact after the fixture repoint, and it lives in
`DashboardCardMetricsTest`, not where the plan believed. Mutation reverted; `git diff` on
`UniqueScanCounts.php` is empty against the staged state. Had the plan's literal instruction been
followed, a green `DashboardMonthLabelsTest` would have been read as "coverage lost" and sent this
node into a false rework.

Cache key repointed from `"scan-count:{$dateType}:org={$orgId}"` to
`"unique-scan-counts:scan-total:{$dateType}:org={$orgId}:tz={$tz}"` — note it picks up BOTH the
`scan-total:` sub-namespace t2 introduced and the `:tz=` segment
`UniqueScanCounts::buildWidgetCacheKey()` appends, neither of which a naive prefix swap would have
produced.

### Coverage map — what the 6 deleted suites asserted, and where it lives now

- **`ScanCountTest`** (3): render / permission-forbidden / today-stat — all already reproduced in
  `UniqueScanCountsTest`. Nothing folded.
- **`UniqueScansToRepoTest`** (34): numerator-side behaviour (backoff marker, degrade,
  impersonation floor, UTC pinning) already generic in `RatioAgainstUniqueScansTest`. The
  **denominator-side mechanics were NOT reproduced anywhere** — 7 tests folded forward into
  `RecoveryCountsTest`: cache-key-string pin, per-month caching / no-refetch, whole-current-month
  window batching, ClickHouse-failure-propagates, failed-batch-writes-no-fabricated-zero,
  window-headline-cached-across-instances, null-org short-circuit.
  Genuinely obsolete: `get_card_key` (key gone), `stat_color_is_explicit_and_not_gray`,
  and the two "both cards address the same numerator month keys" tests — structurally impossible
  now that decision 9 zips against one card's own array and the trait is the single numerator owner.
- **`UniqueScansToCaseClaimsTest`** (23): 3 folded into `UniqueLprCaseClaimsTest` — the
  `#[On('refresh-dashboard')]` re-declaration regression (driven through a real Livewire dispatch)
  and the `numeratorUnavailable` flag surviving `refreshData()`. `refresh_data_clears_the_window_
  ratio_memo` does not apply: `UniqueLprCaseClaims` has no `$windowRatioMemo`.
- **`UniqueScansToCaseClaimsHerdGuardTest` / `WindowSeamTest`**: both tested a hand-rolled
  duplicate `heldFlexible()` on the deleted class. The survivor uses the shared `HeldFlexibleCache`
  trait, whose identical mechanism is covered by `LprRepossessionsTest`'s guard suite. No fold needed.
- **`UniqueScansToLprRepossessionTest`** (23): the four shared-key reconciliation tests are
  obsolete (`LprRepossessions` is now sole owner); gates/degrade/UTC pinning already covered.

Worker honesty worth recording: it attempted a null-org test on `UniqueLprCaseClaims`, found that
`fetchMonthlySeries()`/`fetchWindowTotal()` have **no null-org guard** unlike the deleted sibling,
judged it pre-existing an earlier card-slot mockup ticket-era behaviour, and **dropped the test rather than silently patching
unrelated drift**. Flagged here for Phase 5 to decide whether it deserves its own ticket.

### A site neither the plan nor the driver's sweep had found

`tests/Filament/Pages/DashboardCardExpansionTest.php:~350` held a live `ScanCount::class` inside a
legend-leak regression array. The worker found it by re-grepping the whole repo for the deleted
class names *after* staging the deletions — the right reflex — and dropped `ScanCount` from the
array; `RecoveryCounts` alone still exercises the "must not leak" assertion because it stays
non-entitled in that test. Reported as a deviation rather than slipped in.

Stale comments updated: `UniqueScanCounts.php:86-87`, `UniqueLprCaseClaims.php:48` and `:120`,
`LprRepossessions.php:225` and `:341` (the class-neutral-key rationale now names
`RatioAgainstUniqueScans` / `UniqueScansLeaderboard` as the co-readers — **the key itself is
unchanged**), `UniqueLprCaseClaimsLabelAxisTest.php:19`. Deliberately left:
`DashboardWidget.php:365`, which is historical justification for why `currentPeriodTotal()` is
unified and stays true regardless of `ScanCount`'s deletion.

### Baseline re-comparison after t8 — zero new failures, and a strict improvement

| | failed | passed |
|---|---|---|
| baseline `ced898b40` | 16 | 545 |
| after t1-t6 | 15 | 582 |
| after t7 | 17 | 564 |
| **after t8** | **12** | 478 |

The 12 are a **strict subset** of the baseline's 16 — `DashboardCardMetricsTest` ×1,
`StagingLiveHitLeaderTest` ×1, `DashboardCardExpansionTest` ×7, `DashboardTest` ×3 — every one an
environmental `ViewException` (`SQLSTATE[42601] syntax error at or near "WITH"`: the pgsql bridge
cannot run ClickHouse's `WITH ROLLUP`). Gone versus baseline: `RecoveryCountsTest` ×3 (fixed by
t5's batched headline) and `UniqueScansToRepoTest` ×1 (file deleted). **No non-environmental
failure remains, and t7's four new ones are all cleared.** The drop in passing count (564 → 478) is
the ~3,300 deleted test lines, as designed.
2026-09-21 12:55 CDT · implementing · t8: delete the four absorbed cards + their suites, repoint every fixture, fold forward 10 tests

### t9 DEFERRED by operator — "Ignore playwright for now"

This **supersedes decision 6** ("fix all seven e2e specs, including the two already red on
`main`"). Recorded as an operator scope reduction, not a silent drop.

**What it blocks: nothing mechanical.** Re-confirmed by grep — no workflow in `.github/workflows/`
references `playwright`, `test:e2e`, or `tests/e2e`. `npm run test:e2e` is the only entry point.
The PR's own checks are unaffected.

**What it leaves broken, stated precisely** so the state is not rediscovered later as a surprise:

- `tests/e2e/unique-scans-to-case-claims.spec.ts` and
  `tests/e2e/unique-scans-to-lpr-repossession.spec.ts` test cards that **no longer exist**. The
  plan's instruction was to delete them, folding any surviving assertion into the survivors' specs.
- `tests/e2e/helpers/company-metrics.ts` still exports `SCAN_COUNT_CARD_KEY`,
  `UNIQUE_SCANS_TO_CASE_CLAIMS_CARD_KEY` and `UNIQUE_SCANS_TO_LPR_REPOSSESSION_CARD_KEY`, and its
  `ALL_CARDS` table (`:38-52`) still lists **15** cards including all three deleted ones. Every
  spec importing that table inherits the staleness.
- `tests/e2e/unique-scans-leaderboard.spec.ts:450` asserts `scan-count` is present in the Pro grid.
  It is not; the key is gone.
- `tests/e2e/unique-lpr-case-claims.spec.ts:121` and `tests/e2e/lpr-repossessions.spec.ts:138`
  were **already red on `main`** (`claimsAt === recoveryCountsAt + 1` → `2 === 4`) and remain red
  against the new order (`1 === 4`). Pre-existing, not caused by this diff — but also not repaired.
- `tests/e2e/staging-live-hit-leader.spec.ts` asserts on the leaderboard description text, whose
  tail t6 changed from "unique plates" to "distinct plates".

**Also unresolved by the deferral:** the specs cannot execute locally at all until ClickHouse is
stood up — the Company Metrics page currently 500s (`GET /app` → 500, 18x `ViewException`). So
"run them later" additionally requires `qa-prep QA_PREP_MODE=provision-only QA_PROVISION_CH=yes`,
plus a workaround for the hardcoded clarity-prefix issue's hardcoded `clarity.` prefix.

Recommendation carried forward for whoever picks this up: re-derive the adjacency assertions from
an index-of-key lookup rather than `recoveryCountsAt + N` offsets. Note one of the current offset
assertions (`reposAt === claimsAt + 1`) would pass **by coincidence** under the new order while
its sibling fails — which is exactly how these two specs rotted unnoticed through the recent card-reorder.

### Phase 4 exit — blast-radius re-check: PASS

`git diff --name-only <merge-base>...HEAD`, excluding `docs/investigations/`, is **33 files**, all
under `app/Filament/Widgets/Dashboard/**` or `tests/**`.

- **No sensitive-path file changed**: no `Policy`/`Policies`, no `Middleware`, no
  `database/migrations`, no `app/Clickhouse/**`, no `routes/**`. The ticket's own rule "do not
  touch `app/Clickhouse/*`" held across all eight nodes.
- **Every entitlement CHANGE is inside the pre-approved set.** Files matching entitlement tokens
  were diffed line-by-line for changed gate lines: `LprRepossessions`, `UniqueLprCaseClaims`,
  `UniqueScansLeaderboard` and `DashboardWidget` have **zero** changed lines mentioning
  `FeatureEnum` / `Feature::active` / `canViewCardData` / `canViewRatioFigure` /
  `admin_impersonator` / `lockedStat` — they only match on pre-existing references.
  `DashboardMetricCard.php` changed exactly one such line, and it is a **docblock**:
  `- * For the other 7 cards canView() is the same role check…`
  `+ * and LprRepossessions all override it. For the other 7 cards canView() is the same role`
  (t7's hygiene item — the list no longer names `UniqueScanCounts` as an overrider).
- The real gate changes are exactly the three the operator approved: `UniqueScanCounts`'s override
  **removed** (t2), `RecoveryCounts::canViewRatioFigure()` **added** (t5), and the five-key gated
  allowlist in `CompanyMetricsOverview` (t7) — plus the seam's default on the t1 trait.

No hit outside the Phase-3 approval. No HARD STOP.

### Phase 4 exit — full suite, set-differenced against the baseline: ZERO REGRESSIONS

Both runs used the same `PHPRC` harness (a php.ini carrying `memory_limit = 2048M`; `-d` does not
reach the phpunit child, the phpunit memory-limit quirk) and the same standing isolated Postgres env.

| | failed | passed | skipped | assertions | duration |
|---|---|---|---|---|---|
| baseline `ced898b40` (merge-base with `main`) | 66 | 1563 | 583 | 6053 | 352.6s |
| branch after t1-t8 | **62** | 1496 | 583 | 5765 | 343.0s |

Compared by **set-differencing the failing test names**, not by count — a matching count can hide
an equal number of fixes and regressions.

- **Failing on the branch but NOT at the baseline: _none_.** Zero regressions across the whole
  suite.
- **Failing at the baseline but NOT on the branch: 4.** `RecoveryCountsTest > can render`,
  `> can render stats`, `> can switch stats with date` (fixed by t5's batched headline — it no
  longer reaches the missing `scans_cold` on the headline path), and
  `UniqueScansToRepoTest > the card still listens for the dashboard refresh event` (file deleted
  by t8).

The 62 remaining failures are identical in both runs and concentrate in suites this diff never
touches — `HitsResourceTest` ×20, the three disposition controllers ×21, `DispatchRdnMetricsHourlyTest`
×5, `ScanReportResourceTest` ×2, `ClientTableBranchFilterTest` ×1, `ScanMetaDataDtoTest` ×1 — plus
the 12 dashboard ones already characterised. All are the local ClickHouse-down failure mode; CI
runs a real ClickHouse.

The drop in passing count (1563 → 1496) and assertions (6053 → 5765) is the ~3,300 deleted test
lines from t8, as designed.

`composer format` (Pint) run across the tree before the checkpoint.

**Phase 4 complete: 8 of 9 nodes merged, t9 deferred by the operator.** `state.phase = implemented`.
2026-09-21 13:10 CDT · implemented · Phase 4 complete: 8/9 nodes merged (t9 e2e deferred by operator); full suite shows ZERO regressions vs the merge-base

### Phase 5 review 1 — 3 lanes (opus), scores 92 / 78 / 91 → GATE FAIL at 78

**Driver error worth recording before the findings.** The first `review-gate.sh` call omitted
`--lanes=3`. The script defaults to `lanes=1`, so its stale-lane guard kept only the newest file
and reported `REVIEW_GATE: pass score=91 ... lanes=1 stale_excluded=2`. All three lanes were from
this same run and share an mtime tick, so "newest" was an arbitrary tie-break. **That was a false
pass, caused by the invocation, not the script** — the UNION file states the rule plainly
("Gating score = MIN across lanes = 78"). Re-run with `--lanes=3`:
`REVIEW_GATE: fail score 78 < threshold 90 (source=lane-min)`. Recorded because a silently
false-passing review gate is the single most expensive failure this pipeline can have.

Four scoring defects, each **verified by the driver against the source** before any fix:

**H1 (claude2, HIGH) — an unsatisfiable assertion that is red on CI.**
`tests/Filament/Pages/DashboardCardExpansionTest.php:384` asserted
`data-description="Every plate read your kits captured in this period`, which was
`ScanCount::getTooltipDescription()` — deleted by t8. Confirmed: zero occurrences in `app/` or
`resources/`; the only occurrence in the tree was the assertion itself. The sibling assertion on
the same line group (`data-dashboard-card="unique-scan-counts"`) HAD been updated, which makes it
a slip rather than a decision. Fixed to `Distinct plates your kits read in this period`, and the
prefix-match against `UniqueScanCounts::getTooltipDescription()` `:36` was verified programmatically.

**This finding also exposed a genuine weakness in the driver's own verification method**, which is
worth more than the fix: `test_admin_can_render_dashboard_with_the_card_grid` is in the
environmental `ViewException` set, so it fails identically before and after. **Set-differencing
failing test NAMES cannot distinguish "still failing for the old reason" from "now also failing
for a new one."** Every "zero regressions" claim in this journal carries that caveat for the 62
environmentally-failing tests. The lane caught it by reading the diff, not the test output. H1's
fix is therefore verified by reading, not by a green run — that test still cannot execute locally.

**M1 (ALL THREE lanes, MEDIUM) — `LprRepossessions` was the only merged card with no
`refreshData()` override.** Confirmed by grep: `UniqueLprCaseClaims` HAS, `RecoveryCounts` HAS,
`LprRepossessions` had none, while declaring `$windowRatioMemo` `:448` and
`$ratioMonthlyDataMemo` `:465`. The expanded card is a long-lived Livewire component, so a
numerator failure left `numeratorUnavailable` stuck true for the component's life: the ratio
dataset stayed dropped under an empty, titled right-hand axis long after the 60s
`FETCH_FAILURE_TTL_SECONDS` marker expired, recoverable only by a full page reload. No test
covered it. Added the override (redeclaring `#[On('refresh-dashboard')]`, which Livewire does not
inherit) plus `test_refresh_data_resets_the_numerator_unavailable_flag_and_the_ratio_memos`.
**Mutation-checked**: removing the override turns the new test RED
(`Failed asserting that true is false`); restoring it turns it green.

**M2 (claude2 + claude3, MEDIUM) — the AC-3 legend distinguished nothing on two cards.**
- `UniqueLprCaseClaims:169` called `makeDataset()` with **no** `$backgroundColor`, so
  `DashboardWidget::makeDataset()` `:189-201` filled all four colour keys from `generateColors()`
  — byte-identical to the count dataset, both bars, all 13 months.
- `LprRepossessions` gave the ratio a static `rgba(0, 45, 211, 0.85)` while `generateColors()`
  paints the **highlighted** month of the count series `rgba(0, 45, 211, 0.8)` — same rgb, on the
  one month that is always present.
Both now use one stable neutral (`rgba(156, 163, 175, 0.45)` / `0.8` border), the same shape
`UniqueScanCounts` and `SharedHitStatsData` already use for a secondary series.

**M3 (claude3, MEDIUM — authorization, LATENT) — the disclosure gate was open to guests, and the
test certified it.** The trait's `canViewRatioFigure()` delegated bare to `canViewCardData()`,
whose documented contract is `auth()->guest() ? true : static::canView()` — true for a guest by
design, because it asks "may the viewing ORG see this". On any host leaving `canViewCardData()` at
the ungated default — precisely the case this seam was invented for — the ratio answered `true`
with no viewer. Latent today only because `RecoveryCounts` overrides with `auth()->check()` first
and the other two hosts are Pro-gated. `okf/concepts/company-metrics-dashboard.md:113` names this
exact anti-pattern. Fixed: `auth()->check() && static::canViewCardData()`.

The test half was worse than the code half. `test_can_view_ratio_figure_defaults_to_can_view_card_
data_for_a_guest` asserted `assertSame(canViewCardData(), canViewRatioFigure())` — **equality, not
value** — so on an ungated stub it certified "guest ⇒ ratio disclosed" as the intended default,
under a name that reads like a safety property. Replaced with
`test_can_view_ratio_figure_is_closed_for_a_guest_even_on_an_ungated_card`, asserting the card gate
is open AND the ratio gate is closed.

**Not fixed, by prior operator decision:** claude2's M3 (RecoveryCounts' UTC month axis disagreeing
with neighbouring cards for ~5-6h at each month boundary) is decision 9's known, documented
residual — the viewer-timezone follow-on owns it, and it is stated in the class docblock. claude2's M4 (the e2e suite
left broken) is the operator's t9 deferral.

### Post-fix full suite

| | failed | passed | assertions |
|---|---|---|---|
| baseline `ced898b40` | 66 | 1563 | 6053 |
| branch pre-fix | 62 | 1496 | 5765 |
| **branch post-fix** | **62** | **1497** | **5772** |

Set-differenced both ways: **nothing fails on the post-fix branch that passes at the baseline**,
and **nothing fails post-fix that passed pre-fix** — the four fixes introduced no new failure.
The +1 test / +7 assertions is the new M1 regression test.
2026-09-21 13:35 CDT · reviewing · review-1 rework: fix H1 unsatisfiable assertion, M1 missing refreshData, M2 colliding dataset colours, M3 guest-open ratio gate

### Phase 5 review 2 — 3 lanes, scores 74 / 94 / 82 → GATE FAIL at 74

`REVIEW_GATE: fail score 74 < threshold 90 (source=lane-min)`, run with `--lanes=3` this time.

claude2 moved 78 → 94 (its round-1 findings were the ones fixed). claude1 moved 92 → 74 and
claude3 91 → 82 — **not** because the rework regressed anything, but because two lanes
independently surfaced a HIGH that all three had missed in round 1. That is lane variance doing
its job, and it is the argument for `--lanes=3` over 1.

**H1 (claude1 + claude3, HIGH) — the ported total leg persisted a fabricated 0 at a ONE-YEAR
TTL. Driver-verified, fixed.** `resolveTotalPastMonthsFromAggregate()` caught the Throwable, set
`$batchResult = []`, and **fell through to the write loop**, writing `0` for every uncached past
month at `getCacheTTL(1)`. No marker, so no self-heal: the refill only re-queries `null` buckets
and `0` is not null. Recoverable only by `cache:clear`.

This is the fourth occurrence of a hazard this very ticket catalogued three times:
`resolvePastMonthsFromCold()` **in the same file** documents the four correct moves (report,
short marker, write NO bucket, return), and t1 extracted exactly those into the trait for the
three ratio cards. t2 ported `ScanCount`'s leg without them. Fixed by mirroring the sibling, with
its **own** marker key (`…:scan-total:fetch-failed:…`) rather than the shared
`ScanPlateStatsBatchQuery` one — two different queries must not suppress each other, and that
shared namespace is read by `UniqueScansLeaderboard`. Marker is also read *before* the query so a
hard-down `scan_report_hourly` is not re-hit every render.
New test `test_a_clickhouse_failure_while_filling_the_ported_total_months_does_not_cache_a_
fabricated_zero`. **Mutation-checked**: restoring the `$batchResult = []` fall-through turns it RED.

**claude1 M2 — the driver's OWN round-1 colour fix was inadequate. Fair, and fixed.** Round 1
replaced an accent collision with `rgba(156, 163, 175, 0.45)`. `DashboardWidget::makeDataset()`
`:194-197` reaches for greys `0.3` / `0.5` / `0.6` / `0.8` from the same hue — so the "fix"
swapped an accent collision for a grey one, and shipped with no test. Both ratio cards now use
amber (`rgba(245, 158, 11, 0.75)` fill, `rgba(180, 83, 9, 1)` border), off every palette either
card can produce. New test
`test_the_ratio_series_colour_collides_with_no_colour_make_dataset_can_produce` asserts
separation from **all four** makeDataset greys and from each card's accent — not just from the
accent, which is what made the first attempt look adequate.

**Deferred to the operator — M1/M2 (claude1 + claude3): the two co-plotted series on
`UniqueScanCounts` are bucketed on DIFFERENT month axes.** Driver-verified at the SQL level:

- unique leg → `ScanPlateStatsBatchQuery` `:181`:
  `toStartOfMonth(toTimeZone(scanned_at, '{$timezone}'))` — viewer-zone months.
- total leg → `ScanStatsBatchQuery` `:17`: `toStartOfMonth(scan_hour)` — **no timezone at all**,
  so UTC months.

Both legs derive their *cache keys* from `bucketZone()`, so the keys agree while the *values*
behind them are cut on different calendars. The class docblock asserts they share an axis.
Consequence for a Chicago viewer: scans from 2026-08-01 00:00–05:00 UTC are 2026-07-31 local, so
they land in Jul '26 for Unique Scans and Aug '26 for Total Scans — and a low-volume org whose
activity clusters in the local evening can render a Total Scans bar **lower** than the Unique
Scans bar for the same month, which is structurally impossible. Invisible while `ScanCount` stood
alone; juxtaposing the two series is what surfaces it, so this ticket created the visible defect.

Not fixed unilaterally because the clean repair edits `app/Clickhouse/Queries/ScanStatsBatchQuery.php`,
which this ticket's own cross-cutting rules forbid and which every worker brief repeated.
Blast radius measured before asking: **`ScanStatsBatchQuery` now has exactly ONE production
caller — `UniqueScanCounts` itself** (`ScanCount`, the only other, was deleted by t8), and
`CardQuerySqlFidelityTest:138` pins its SQL so a change cannot pass unnoticed.
2026-09-21 13:52 CDT · reviewing · review-2 rework: fix the fabricated-zero one-year cache on the ported total leg; replace the inadequate round-1 colour fix; add tests for both

### Review-2 M1/M2 resolved — ScanStatsBatchQuery made timezone-aware (OPERATOR-APPROVED SCOPE CHANGE)

Operator decision: *"Lets fix the query but note it that timezone-aware work is the viewer-timezone follow-on"*. This
**overrides this ticket's own rule "Do not touch `app/Clickhouse/*`"**, so it is recorded as a
scope change in `state.scope_changes`, not buried in a node note.

Blast radius was measured before the ask, not after: `ScanStatsBatchQuery` now has **exactly one
production caller**, `UniqueScanCounts` itself — `ScanCount`, the only other, was deleted by t8 —
and `CardQuerySqlFidelityTest:138` pins its SQL so a silent drift is impossible. Re-confirmed
after the change.

The SQL mirrors `ScanPlateStatsBatchQuery::handleSql()` rather than inventing a second dialect:

```sql
SELECT toStartOfMonth(toTimeZone(scan_hour, 'America/Chicago')) AS month, uniqExactMerge(scan_count) ...
WHERE org_id = 7 AND scan_hour >= '2026-08-01 05:00:00' AND scan_hour <= '2026-09-01 04:59:59'
```

Grouping is zone-aware; the bounds are converted to UTC, so the `WHERE` still filters the raw
`scan_hour` column and `org_id`/`scan_hour` sort-key pruning is unaffected — only the SELECT-list
grouping expression changed. ONE zone feeds both, which is the invariant
`ScanPlateStatsBatchQuery`'s docblock says a split silently violates by emitting month keys
outside the caller's write set.

`toUtcDatetime()` is private on the sibling; rather than duplicate a validated
convert-then-assert helper, it is exposed as `toUtcDatetimeFor()`. Duplicating that validation is
exactly the drift the two queries cannot afford now that they are co-plotted.

**the viewer-timezone follow-on recorded in BOTH docblocks** (the query's and the card's), each stating the same
boundary: this makes the card's two series agree *with each other*, and does NOT settle which
calendar Company Metrics should use overall — the viewer-timezone follow-on owns that, including `RecoveryCounts`'
`repo_date` axis, which carries no time-of-day and cannot be shifted either way.

Full suite after the change: **62 failed / 1499 passed / 5783 assertions**. Set-differenced both
ways — no regression against the baseline, and nothing new against the previous branch run. The
+2 tests / +11 assertions are the review-2 regression tests.
2026-09-21 14:11 CDT · reviewing · review-2 M1/M2: make ScanStatsBatchQuery timezone-aware so the two co-plotted series share a calendar (operator-approved scope change; the viewer-timezone follow-on owns the rest)

### Phase 5 review 3 — 3 lanes, scores 62 / 74 / 74 → GATE FAIL at 62

Score trend across rounds: **78 → 74 → 62**. Declining, not converging. The cause is not that the
rework regressed anything — the full suite is clean against the baseline at every round — it is
that each round's lanes read deeper and found that the PREVIOUS round's fix was shallow.

**All three lanes, independently: the review-2 H1 fix was INERT. Driver-verified.**
`fetchTotalMonthlySeries()` called `ScanStatsBatchQuery::query()`. `ClickhouseQuery::query()`
`:29-33` catches `Throwable` and returns `[]` in `production|testing`. So a real ClickHouse
failure never surfaced as an exception, the `catch` added in review 2 never ran, and the write
loop still persisted twelve fabricated zeros at a one-year TTL — the exact bug review 2 claimed
to close. The working sibling `fetchMonthlySeries()` `:492` has always called `::handle()`.
Fixed to `::handle()`.

**This was a known, recorded trap and the driver walked into it anyway.** There is a standing
memory note — *"ClickhouseQuery swallows Throwable → any did-it-fail guard is dead code; returns
[] in production|testing, so a failed CH query looks empty"* (the empty-on-failed-query behavior). Having the knowledge
available was not enough; nothing in the fix path forced it to be checked.

**Worse: the regression test written in review 2 CERTIFIED the dead guard** (claude2 H2). It
stubbed `fetchTotalMonthlySeries()` itself and threw from the stub — i.e. above the swallow — so
it exercised a path production could never take. It passed, green, while the production bug was
fully intact. A seam-stubbing test can only prove "the guard fires when something reaches it",
never "something can reach it". Added
`test_the_total_seam_uses_a_non_swallowing_entry_point`, which pins the missing half directly.

**H2 (claude1) — a second mixed-calendar defect, introduced by t2 and verified.**
`fetchTotalCurrentMonthCount()` mints bounds with `now(tz: bucketZone())` but called
`->between($start, $end)`, and `Scan::scopeBetween()` `:55` defaults to
`auth()->user()?->timezone` — the RAW zone. `bucketZone()` sanitises through `safeTimezone()`,
which maps a non-IANA string (`+05:00`, `CST`, both writable straight from the request into
`users.dti`) to `UTC` while Carbon accepts it verbatim. For those viewers the current-month bar
shifted by the full UTC offset against the distinct-plate bar beside it — re-opening, on the
current month, precisely the defect the `ScanStatsBatchQuery` change closed on the past months.
The deleted `ScanCount` minted raw and parsed raw, so it was self-consistent; moving the minting
to `bucketZone()` without threading it is what broke it. Fixed: `->between($start, $end, $timezone)`.

Both fixes **mutation-checked**: reverting each turns its new test RED, restoring turns it green.

### Standing caveat this round reinforces

Two of the three rounds found that a driver fix from the previous round was inadequate — the
review-1 colour fix (grey-on-grey) and the review-2 fabricated-zero fix (inert). The common
failure is the same one in both cases: **the fix was validated by a test written at the same
level of understanding as the fix**. The colour test compared against the accent but not against
makeDataset's greys; the fabricated-zero test stubbed above the swallow. Both passed. Neither
proved the property that mattered.
2026-09-21 14:28 CDT · reviewing · review-3: the review-2 fabricated-zero guard was inert (query() swallows Throwable) - use handle(); thread bucketZone into between(); pin both with tests that discriminate

### Phase 5 review 4 — DUAL MODE (3 Claude lanes + a genuine Codex lane) → 76 / 94 / 94 / 94, GATE FAIL at 76

`REVIEW_GATE: fail score 76 < threshold 90 (source=lane-min)`.

**Codex was verified present on PATH BEFORE the run** (`/Users/daltonmccleery/.local/bin/codex`),
not assumed from the manifest's `codex_available: true`. That matters: with `codex` absent,
`--mode=dual` silently degrades to a second secluded-Claude pass "labeled weaker" — which would
have produced the appearance of cross-vendor review without the substance. The manifest confirms
the real thing: `lane_sources: [harness-claude-p x3, harness-codex]`.

**The score trend broke.** 78 → 74 → 62 → **76**, and three of four lanes scored 94. Codex, the
independent vendor, scored **94 and raised no HIGH** — its top finding (M1) is the same
"failed total renders as genuine zero" issue two Claude lanes had already surfaced. That
convergence is the most useful signal of the whole exercise: after four rounds, an independent
reviewer's worst finding is a MEDIUM that the existing lanes had also found. The gate fails on a
single outlier lane (claude1 at 76), not on a consensus.

The lanes were handed the driver's own track record this round — verbatim, near the top of the
test-evidence file: that two prior fixes had been found inadequate, both because the fix and its
test shared one blind spot, with instructions to check whether each test could still pass with its
subject removed. Finding R4-D below is exactly that check coming back positive.

**STOPPED HERE BY OPERATOR INSTRUCTION ("Stop after the review is finished"). Nothing below was
fixed. The review gate has NEVER passed on this ticket.**

### Open findings — a live list, not a formality

| id | lanes | what |
|---|---|---|
| R4-A | claude1 H1, claude2 M1 | **Ungating widens a grid-500 blast radius from ~29 orgs to every ADMIN/USER org.** `resolveCurrentMonthFromCold()` is deliberately catch-free and unguarded by the failure marker; pre-diff a non-entitled org hit `lockedStat()` and never called `getMonthlyData()`. With `scans_cold` hard-down the whole grid now 500s for everyone. `getStats()` has no per-card try/catch. |
| R4-B | claude1 M2, claude3 M1 (+ every prior round) | `scales.y1` is declared unconditionally on `UniqueLprCaseClaims` and `LprRepossessions`, so the degraded path renders a titled, EMPTY right-hand axis. `RecoveryCounts` gets it right. Flagged in all four rounds; never fixed. |
| R4-C | claude2 M2 | The legend-leak regression test now points at `RecoveryCounts` — the one survivor that is SUPPOSED to have a legend. It passes only because the fixture is a bare ADMIN with no Pro meta. Same class as the `DashboardMonthLabelsTest` fixture hazard the plan explicitly warned about, and it still slipped through t8. |
| R4-D | claude1 M3 | **The `ScanStatsBatchQuery` timezone change — the only executable query change in the diff — has no test.** Reverting the grouping expression restores review-2's mixed-calendar defect with the entire suite green. |
| R4-E | codex M1, claude3 | A failed total-scan fetch renders as genuine zero data rather than "unavailable": the ported total leg has no `numeratorUnavailable` equivalent. |
| R4-F | claude3 M2 | The `today`/`last_7d` arm divides a timezone-scoped numerator by a timezone-blind denominator on 2 of 3 cards. Both deleted cards keyed their window denominator per-timezone; one shipped a test for it. |
| R4-G | claude3 M4 | `ExplainsCardQuery` explains only the headline on all four merged cards; every absorbed query's statement was deleted rather than migrated. |

**R4-A deserves an operator decision, not a code fix.** It is a direct consequence of decision 2
(ungate `unique-scan-counts`), which the operator made deliberately. What was never stated at the
time is the RELIABILITY half: the accepted risk was recorded as scan volume and cold-start cost,
but ungating also removes the `lockedStat()` short-circuit that kept a ClickHouse outage from
500ing the grid for non-entitled orgs. That may well be acceptable — but it should be an informed
choice, and right now the class docblock documents only the cost, not the blast radius.

**R4-D is the standing caveat proving itself again.** A reviewer applied the "could this test pass
with its subject removed?" check to the driver's own scope-change fix, and the answer was yes.
2026-09-21 15:23 CDT · reviewing · review-4 dual-mode: 76/94/94/94 (codex 94, no HIGH); gate fails at 76. STOPPED by operator; 7 findings left open and unfixed
2026-09-21 15:25 CDT · reviewing · record review-3 in state.reviews (was missing); run parked

## Post-review triage with the operator — working the open findings one at a time

### t6 copy question — CLOSED
*"Distinct plates is fine."* The four non-heading leaderboard sites keep "distinct"; the heading
stays `Scans Leaderboard`. No change.

### R4-A — CLOSED as OVERSTATED (operator chose option 1)

The claim (claude1 H1 / claude2 M1) was that ungating `unique-scan-counts` widened a grid-500
blast radius **from ~29 orgs to every ADMIN/USER org**.

**The mechanism is real and was verified:** `CompanyMetricsOverview::getStats()` `:180-185` is a
bare `foreach` over `cards()` with a ternary on `canViewCardData()` and no per-card try/catch;
`resolveCurrentMonthFromCold()` `:442-455` has no try/catch and no marker check and reaches
`ScanPlateStatsBatchQuery::handleRange()`, which does not swallow. A failure there does take the
whole grid down.

**The POPULATION half of the claim is wrong, and was checked against the merge-base rather than
relayed.** In `dev2651-baseline` at `ced898b40`:

- `'scan-count' => ScanCount::class` was in the registry (`CompanyMetricsOverview.php:95`);
- `ScanCount` had **no** `canViewCardData()` override — it inherited the ungated default;
- `ScanCount::resolveCurrentMonthFromHot()` `:161-175` had **no try/catch** either.

So pre-diff, every ADMIN/USER org already reached an unguarded ClickHouse read on every render,
via `ScanCount` sitting on the same page. A `scans_cold` outage already 500'd the grid for
everyone. The exposed population did not change; the number of unguarded legs on that path went
from one to two, and both read `scans_cold`, so they fail together.

claude2 had the reasoning half-right — it noted the ported total leg's population was unchanged
*because `ScanCount` was already ungated* — but did not carry that across to the unique leg, and
scored the finding on the widened-population framing.

**Resolution:** no behavioural change. The class docblock now states the reliability posture
explicitly — both current-month legs are deliberately catch-free, `getStats()` has no per-card
guard, ungating did not widen the exposure, and neither leg should be "fixed" by swallowing
(each method's own docblock records why a fabricated 0 under a read-back key is the worse
failure).

**Process note worth keeping:** this is the second time a review finding survived three lanes and
a scoring pass while resting on an unverified premise. Adversarial review is very good at
surfacing *mechanisms* and noticeably weaker at establishing *counterfactuals* — what the code
did BEFORE the diff. Check the merge-base before accepting a "this widens X" claim.
2026-09-22 07:53 CDT · reviewing · triage R4-A: closed as overstated (population was already all orgs pre-diff via the ungated ScanCount); document the reliability posture. t6 copy question closed.

### R4-B — CLOSED, FIXED (operator: "Fix It")

`UniqueLprCaseClaims::getOptions()` and `LprRepossessions::getOptions()` emitted `scales.y1` and
the legend unconditionally, while their own `getData()` drops the ratio dataset when the numerator
is unavailable. A degraded card therefore rendered a titled right-hand axis with nothing plotted
against it and a one-entry legend. `RecoveryCounts` has always guarded both. Flagged in all four
review rounds; deprioritised each time behind correctness bugs, including by the driver.

Guard is **gate-first**, mirroring `RecoveryCounts::getOptions()`:

```php
$ratioAvailable = static::canViewRatioFigure();
if ($ratioAvailable) {
    $this->getRatioMonthlyData();          // this is what SETS the flag; memoised
    $ratioAvailable = ! $this->numeratorUnavailable;
}
```

Gate-first is not stylistic: `getRatioMonthlyData()` dereferences `auth()->user()->org_id`, and
`canViewRatioFigure()` opens with `auth()->check()`. Checking availability first would null-deref
on an unauthenticated widget.

One new test per card — degraded numerator ⇒ no `y1`, no legend, count axis keeps its title —
both **mutation-checked** (removing either guard turns both RED).

**The fix exposed three pre-existing bad tests, which is the more interesting result.**
`LprRepossessionsTest::test_legend_is_shown_only_when_expanded`,
`::test_get_options_adds_a_labelled_right_hand_y_axis_for_the_ratio` and
`UniqueLprCaseClaimsLabelAxisTest::test_get_options_adds_a_titled_right_hand_axis_for_the_ratio_dataset`
each built a bare `new <Card>` with **no authenticated user** and asserted the ratio axis renders
— i.e. they asserted a Pro-gated figure's axis appears for a viewer who cannot see the figure.
They passed only because `getOptions()` never consulted the gate. Repointed at
`createUser(role: ADMIN, pro: true)` and at stubs that supply a numerator;
`SeriesStubbingLabelAxisWidget` gained a `runUniqueScansBatch()` stub so it no longer reaches real
ClickHouse.

Same "passes for the wrong reason" class as the two defects the reviews caught in the driver's own
work — but surfaced here by the fix itself rather than by a reviewer.

Full suite: **62 failed / 1503 passed / 5792 assertions**. Set-differenced both ways — no
regression vs the merge-base, nothing new vs the previous run.
2026-09-22 08:17 CDT · reviewing · R4-B fixed: gate the y1 axis + legend on the degraded path (2 cards); repoint 3 pre-existing tests that asserted the axis for an unauthenticated viewer

### R4-C — CLOSED, FIXED (operator: "Repoint")

`DashboardCardExpansionTest::test_the_chart_legend_is_enabled_only_on_expanded_hit_widgets` has a
positive half (the four hit cards must show the legend when expanded) and a REGRESSION half (the
AC-4 legend must not leak onto `DashboardWidget` base-class consumers). t8 replaced the deleted
`ScanCount` exemplar with `RecoveryCounts` — which, in this same ticket, was given its own
conditional legend. The assertion then passed only because the fixture is a bare ADMIN whose
`canViewRatioFigure()` is false: it was observing a GATE, not detecting a leak.

Exemplar repointed to `MissedOpportunities`: extends `DashboardWidget`, does not use
`SharedHitStatsData`, and contains **zero** occurrences of `legend` or `getOptions`, so its legend
comes from the base `$options` default (`display => false`) and can only turn on if something
leaked. Surveyed all twelve registry cards to pick it; after R4-B all three ratio cards carry
conditional legends, so none of them can serve this role any more.

**Proved by differential mutation rather than asserted.** The base `DashboardWidget` legend
default was flipped `false → true` to simulate the exact leak the half exists to catch:

| exemplar | result under the simulated leak |
|---|---|
| `RecoveryCounts` (as t8 left it) | **PASSED** — leak undetected |
| `MissedOpportunities` (repointed) | **FAILED** — leak caught |

Both the production file and the test file were restored afterwards; `git diff --stat` on
`DashboardWidget.php` is empty.

This is the same fixture-swap hazard the plan explicitly warned about for
`DashboardMonthLabelsTest` — "a fixture swap that silently defeats the regression it guards". That
one the driver caught and mutation-checked at t8; this one it did not, because the plan named only
the month-labels case and the driver checked only what the plan named.
2026-09-22 08:20 CDT · reviewing · R4-C fixed: repoint the legend-leak guard from RecoveryCounts (which now has a legend) to MissedOpportunities; proved by differential mutation

### R4-D — CLOSED, FIXED (operator: "Add the test")

New `tests/Unit/Clickhouse/ScanStatsBatchQueryContractTest.php` — 4 tests, 12 assertions, no
ClickHouse connection required (`handleSql()` is a pure string function). Pins: the
timezone-aware grouping expression; that the bounds are UTC-converted with the SAME zone that
feeds the grouping; that an unlisted zone (`+05:00`, `CST`, `Not/AZone`) falls back to UTC and
never reaches the SQL; and that a malformed bound raises `InvalidArgumentException` rather than
being interpolated.

Placed in `Tests\Unit\Clickhouse`, not `Tests\Feature\Clickhouse`, deliberately: the Feature CH
classes `use RequiresClickhouse` at class level, which skips every method when
`CLICKHOUSE_DRIVER != clickhouse` — these guards would be invisible on a normal local run. Same
reasoning that split `ScanPlateStatsBatchQueryContractTest` out.

**Mutation-checked two ways:**

| mutation | new contract test | `CardQuerySqlFidelityTest` |
|---|---|---|
| grouping reverted to raw `toStartOfMonth(scan_hour)` | **2 of 4 FAIL** | **64/64 PASSED** |
| bounds zone split from the grouping zone | **1 FAIL** (the invariant test) | — |

**Driver correction, recorded against the original claim rather than quietly fixed.** When the
operator approved the `app/Clickhouse` scope change, they were told "`CardQuerySqlFidelityTest:138`
pins the SQL so a silent drift is impossible." That was **wrong**, and it was part of the
information the decision was made on. That test asserts only that `handle()` delegates to
`handleSql()` and contains no inline `<<<SQL`; it says nothing about the grouping expression, the
zone, or the bounds. The mutation table above proves it empirically. `state.scope_changes[0]` now
carries a `correction` field next to the original wording.

Full suite: no regressions vs the merge-base, nothing new vs the previous run.
2026-09-22 08:33 CDT · reviewing · R4-D fixed: add ScanStatsBatchQueryContractTest (4 tests); correct the earlier false claim that CardQuerySqlFidelityTest pinned this SQL

### R4-E — CLOSED, FIXED (operator: "Fix R4-E")

Review-3 stopped a failed total being **cached** as zero for a year. It did not stop it being
**displayed** as zero: `getTotalMonthlyData()`'s read loop ends `cache()->get($cacheKey) ?? 0`, so
the months the guard declined to write render as `0`, and the description was unconditional
`number_format(...).' total scans'`. During a partial outage the card showed "0 total scans" and
twelve flat bars beside a healthy unique-plate series — a raw total below a distinct-plate count,
which cannot happen. Codex found this independently of the Claude lanes.

Fixed by extending the pattern decision 8 already standardised on the three ratio cards, to the
fourth series of the same shape: a `$totalUnavailable` flag set on BOTH the failure guard and the
marker-suppressed early return; `totalScanDescription()` renders `UNAVAILABLE_VALUE.' total scans'`;
`getData()` drops the Total Scans dataset. `UNAVAILABLE_VALUE` is declared locally (same value as
the trait's) because this is not a ratio card and does not use `RatioAgainstUniqueScans`.

A `refreshData()` override was added at the same time — applying review-1 M1's lesson proactively
rather than waiting for a round 5 to find it. Without it the flag sticks for the expanded
component's life. `#[On('refresh-dashboard')]` re-declared, since PHP does not inherit method
attributes onto an override.

**Three mutations, each failing independently:** description ignoring the flag; `getData()`
plotting regardless; `refreshData()` not resetting.

**Two corrections the implementation forced, both of which changed the test's meaning:**

1. The first stub failed the total leg and nothing else, so the healthy unique leg hit real
   ClickHouse and the test failed for an unrelated reason. The stub now keeps the UNIQUE legs
   healthy with real values. That asymmetry IS the scenario — it is the only shape that produces
   the impossible reading.
2. The first stub also threw from `fetchTotalCurrentMonthCount()`. That leg is deliberately
   catch-free, so the throw propagated and would 500 the grid — a different failure mode, not
   this one. The realistic shape is the expensive 13-month batch timing out against the 20s cap
   while the cheap single-month query returns; the stub now models exactly that.

Full suite: **62 failed / 1509 passed / 5810 assertions**. No regressions vs the merge-base,
nothing new vs the previous run.

### R4-F and R4-G — ACCEPTED, documented and NOT fixed (operator decision)

Operator: *"document the rest and continue working through the ticket pipeline"*, after the driver
raised that the run was past the point of good returns.

**R4-F — the window arm divides a timezone-scoped numerator by a timezone-blind denominator.**
`RecoveryCounts::windowRatio()` → `currentPeriodTotal()` and
`UniqueLprCaseClaims::getCurrentPeriodRatio()` → `getCardTotal()`. Both deleted cards keyed their
window denominator per-timezone — `UniqueScansToRepo::fetchWindowTotal()` used
`RecoveryTotalsBatchQuery::sharedWindowCacheKey(..., $timezone)` directly, and
`UniqueScansToCaseClaims::windowRatio()` carried a 12-line comment explaining why the tz segment
is load-bearing plus a shipped test
(`test_two_same_org_viewers_in_different_timezones_do_not_share_a_window`). Affects `today` and
`last_7d` only, for non-UTC viewers, on 2 of 3 cards. **Worth a follow-up ticket**; it interacts
directly with the viewer-timezone follow-on's scope.

**R4-G — `ExplainsCardQuery` explains only the headline.** All four merged cards now run queries
their `getCardQueryStatements()` does not disclose: `UniqueScanCounts` omits `ScanStatsBatchQuery`
and the two `Scan::hot()::query()` reads; the three ratio cards omit the numerator. The deleted
cards each emitted a "Numerator -- unique plates scanned" statement. Impersonator-only debug
surface; no user-facing number is wrong.

**Why stop here, recorded so the decision is legible later:** the genuinely important defects were
fixed across rounds 1-4 (a CI-breaking assertion, a stuck failure flag, a guest-open disclosure
gate, a one-year fabricated-zero cache, two co-plotted series on different calendars, a dead
regression guard, an untested query change, and this one). What remained was a ratio slightly off
for non-UTC viewers on two date filters, and an admin debug panel not listing every query — on a
ticket whose purpose is merging four pairs of cards and dropping the word "unique". Three of four
review lanes, including the independent vendor, already scored 94. Every further change is another
chance to introduce a defect, which happened twice in this run, and the diff has still had no
human review.
2026-09-22 08:50 CDT · reviewing · R4-E fixed: a failed total renders as unavailable, not as zero (3 mutations checked). R4-F/R4-G accepted and documented.

## Phase 6 — Verify

### the hardcoded clarity-prefix issue fixed for this query (OPERATOR-APPROVED, second app/Clickhouse scope expansion)

`ScanStatsBatchQuery:43` hardcoded `FROM clarity.scan_report_hourly`, so the query could only ever
address the production database name. Local QA provisions per-worktree databases
(`clarity_<worktree>`), making this the one query on the card that could not run there.

Two recorded instructions conflicted and the operator resolved it:
- the PLAN said "the hardcoded clarity-prefix issue will 500 the Company Metrics page under local ClickHouse QA. Work around
  it; do not fix it here";
- the workaround that implies — pointing QA at literal `clarity`/`zod` databases — is what the ClickHouse isolation guidance
  records as the wrong trade ("hardcoded prefixes are a bug to fix, not a reason to de-isolate").

Operator chose the fix: `FROM {$clarityDb}.scan_report_hourly` via `static::clarityDb()`, the
existing parent helper the sibling `ScanPlateStatsBatchQuery` has always used. Pinned by
`test_the_database_prefix_is_resolved_not_hardcoded`; **mutation-checked** (restoring the hardcoded
prefix turns it RED). **the hardcoded clarity-prefix issue remains OPEN for the other query classes carrying the same flaw** —
only this one was in the diff, and the comment says so.

### ClickHouse stood up — up.sh is partly broken here, recorded rather than smoothed over

`up.sh --also-testing` created the per-worktree databases (`clarity_claude_dev_2651_c29a86`: 33
objects, `zod_claude_dev_2651_c29a86`: 48) but **exited with an error**:

```
ERROR: schema verification failed: 66 objects landed in plain 'clarity', 'zod' databases
       — prefix rewrite missed something
```

The literal `clarity` (28 tables) and `zod` (38) databases did **not** exist in the container before
this run — `status.sh` listed only the other worktree's pair. So this run created them. Local Docker
only; nothing shared or production. Two consequences worth carrying:
- the per-worktree databases may be MISSING exactly those 66 objects, so a card depending on one
  could still fail;
- those two literal databases are invisible to teardown, which only removes what the ledger records.

**`up.sh` also wrote three wrong values into `.env`**, corrected by hand (`.env` backed up to
`/tmp/claude-501/env.before-ch-fix`):
- `CLICKHOUSE_PORT=8443` + `CLICKHOUSE_HTTPS=true` — the ClickHouse **Cloud** shape, not the
  container's `18123` HTTP;
- `CLICKHOUSE_USERNAME` / `CLICKHOUSE_WRITE_*` left on the Cloud credentials;
- **`CLICKHOUSE_DATABASE_CLARITY` set to the ZOD database name** — the exact mis-set the standing
  `platform-local-ch-qa-provisioning-gotchas` memory warns about.

After correction, `DB::connection('clarity_clickhouse')` reaches
`clarity_claude_dev_2651_c29a86.scans_cold` (0 rows — empty schema; the task seeder owns the rows).

### First end-to-end render of the deliverable

```
GET /__qa/login/1 -> 302
GET /app          -> 200   (347,746 bytes)
```

Twelve `data-dashboard-card` keys, in exactly the approved order:
`unique-scan-counts` · `unique-lpr-case-claims` · `lpr-repossessions` · `recovery-counts` ·
`live-hits` · `historical-hits` · `staging-live-hits` · `staging-historical-hits` ·
`missed-opportunities` · `unique-scans-leaderboard` · `recovery-leader` · `staging-live-hit-leader`

Every previous render this run was a 500. **Scope of this evidence:** the schema is empty, so the
cards render with zero data. This confirms AC-1 (12 cards, absorbed keys gone), AC-6 (surviving keys
unchanged) and that nothing fatals. It does NOT confirm AC-2/3/4 (merged figures, dual axes, legend)
or the degrade paths — those need seeded data.
2026-09-22 08:59 CDT · verifying · the hardcoded clarity-prefix issue prefix fixed for ScanStatsBatchQuery (operator-approved); local CH stood up; dashboard renders 200 with 12 cards in order

### Phase 6 agent QA — 2 browser lanes + backend, via agent-browser

**Tooling correction first.** The driver started driving the in-app MCP browser and the operator
corrected it: the sanctioned path is `insightlpr:agent-qa` spawning `qa-browser-lane` subagents on
Vercel's **agent-browser** CLI (v0.27.0, on PATH). The MCP call had already been interrupted, which
matches the recorded `mcp-browser-preview-hangs-use-agent-browser` note. Pane closed, lanes re-run
properly. Recording it because the wrong tool nearly produced a QA pass of unknown provenance.

**Two environment artifacts, in OPPOSITE directions — the most important result of this phase.**

1. **dev-login false-PASSES gating.** `routes/web.php:196-202` — `/__qa/login/{id}` deliberately sets
   `session('admin_impersonator')`, and every Pro gate here reads
   `Feature::active(COMPANY_METRICS) || session()->has('admin_impersonator')`. So a dev-login persona
   is entitled regardless of org. Proved empirically: the non-entitled persona rendered the Pro-gated
   "5.0 scans per repo". Items A8/A9 — the two gating decisions this ticket turns on — would have
   passed while testing nothing.
2. **tinker false-FAILS it.** Outside a request the Spatie permissions team id is unset, so every
   role check returns false and ALL 12 cards look gated. After `setPermissionsTeamId()`:
   `unique-scan-counts` canViewCardData=**true** (decision 2 ✓), `recovery-counts`
   canViewCardData=**true** / canViewRatioFigure=**false** (decision 3 ✓),
   `unique-lpr-case-claims` canViewCardData=**false** ✓.

Both lanes were therefore run through the **real form login**, and lane 2 was given a sanity check to
run BEFORE grading — all five fully-gated cards must render locked — so "no ratio visible" could not
pass vacuously. It passed that check, which is what makes its gating verdict trustworthy.

**Results.** Lane 1 (Pro) 8/8 ✅ · Lane 2 (Basic) 4/4 ✅ · Backend 4/4 ✅. No console errors, no
4xx/5xx, **0** error-level Laravel log lines since the baseline.

Headline evidence (Pro, org 337): `unique-scan-counts` 40 / "55 total scans" · `unique-lpr-case-claims`
5 / "8.0 scans per case claim" · `lpr-repossessions` 3 / "13.3 scans per LPR repo" · `recovery-counts`
5 / "8.0 scans per repo". Non-entitled (org 338): `recovery-counts` headline **3 with NO ratio line**
and no right-hand axis; `unique-scan-counts` shows **15 + "21 total scans"**, ungated. Both gating
decisions confirmed against a genuinely non-entitled session.

**total ≥ distinct verified on real data**, which is the arithmetic form of the co-plotted-calendar
fix: Aug 30/50, Sep 40/55, `IMPOSSIBLE_BARS=none` across all 13 bars. First evidence of that fix
outside a unit test. (An earlier raw-query read looked like a missing September total; that was a
misread — `ScanStatsBatchQuery` covers only past months, the current month comes from the hot path.)

**One finding neither lane was asked to look for**, visible in lane 1's own evidence and fixed on
operator approval: `RecoveryCounts` labelled its ratio series `Unique Scans to Repo` (`:209`) and its
right axis `Unique Scans per Repo` (`:240`) — **the title of the card t8 deleted** — while its
siblings read `Scans per Case Claim` / `Scans per LPR Repo` and its own description line read
`N scans per repo`. Three namings for one concept. Both changed to `Scans per Repo`, plus
`test_the_three_ratio_cards_label_their_ratio_series_consistently`, which asserts the invariant
**across all three cards at once** — a per-card assertion is precisely what let them drift.
Mutation-checked: restoring the deleted card's name fails with a message naming the problem.

**Lane self-corrections worth crediting:** lane 1 nearly false-positived A1 because a substring search
for `scan-count` matches the legitimate `unique-scan-counts`; it switched to exact attribute-selector
checks and reported the mistake. Both lanes found the driver's login spec wrong (no password field —
email + "Send OTP", the non-prod OTP bypass) and adapted rather than failing.

**AC-7 is NOT SATISFIED, deliberately.** Its text is "Tests updated and green" and it names four
Playwright specs. The PHP suite is clean, but `t9` was deferred by the operator, so those specs are
knowingly broken. Phase 6's gate would normally draft the PR and loop on a NOT SATISFIED AC; that
response is wrong here because the cause is the operator's own scope reduction. Recorded as such
rather than marked SATISFIED, which would be false.

Full suite after the label fix: **62 failed / 1511 passed / 5830 assertions** — no regressions vs the
merge-base, nothing new vs the previous run.

Artifacts: `.context/qa-plan-agent.md` (statuses + findings block), `.context/qa-plan-human.md`
(patch block + corrected personas), `.context/qa-ac-results.json`, `.context/qa-screenshots/` (10
shots + manifest), ledger `qa_log` (6 entries). Browser lane sessions reaped
(`qa-dev-2651-c29a86-1..3`).
2026-09-22 09:32 CDT · verified · Phase 6 agent QA: 16/16 items green across 2 browser lanes + backend; 6/7 ACs satisfied (AC-7 = the accepted t9 deferral); fixed RecoveryCounts labels naming the deleted card

## 2026-09-22 — human QA finding: legend swatch colour + counts-vs-ratio legibility

Operator, mid human-QA, on the expanded `unique-lpr-case-claims` chart: the legend swatches did
not match the bars, and counts vs ratio were hard to tell apart.

**Root cause (one defect, two symptoms).** `DashboardWidget::makeDataset()` paints every colour
key with `generateColors()`, a PER-MONTH array: grey for each month, the card accent for the
highlighted one. Chart.js builds a legend swatch from index 0 of that array — the LEFTMOST month,
which is never the highlighted one. So every charted count series showed a grey swatch while the
bar carrying data showed the accent. Invisible until this ticket, because `legend.display` was
false on these cards until the ratio series turned it on.

This is the same defect `DashboardCardMetricsTest::test_expanded_hit_chart_uses_one_static_colour_per_series_not_a_per_month_array`
already pins for the hit cards ("caught in human QA, Aug rendered red/red"). The ratio cards were
never covered by it.

**Fixes.**
1. `staticColourDataset()` lifted to `DashboardWidget` (it was duplicated privately in
   `SharedHitStatsData` and `UniqueScanCounts`; both duplicates deleted). NOTE: the trait copy
   was a hard PHP error once the parent had the method — "Access level must be protected or
   weaker" — caught by `composer analyse`, not by any test.
2. `RatioAgainstUniqueScans` gains `countSeriesDataset()` (one stable colour) and
   `ratioLineDataset()` (the shared amber line). All three ratio cards use both.
3. The ratio is now a LINE on all three. `recovery-counts` already did this; the other two drew a
   second bar, so two bars of equal height meant "5 claims" and "8 scans per claim". Operator
   chose the line over relabelling or dropping the series.
4. The degraded/single-series path deliberately KEEPS `makeDataset()`'s month highlight — no
   legend exists there to be wrong about.
5. `RecoveryCounts`' hardcoded orange replaced by the shared constant, so the review-2 M2
   colour-separation test now covers all three cards instead of two.

**Evidence.** 106/106 pass in the four card test files. 3 differential mutations, all killed:
count series back to `makeDataset` → red; `type: 'line'` → `'bar'` → red; degraded path forced to
the static colour → red. The 8 failures in `DashboardCardExpansionTest`/`DashboardCardMetricsTest`
are the recorded pre-existing ClickHouse-bridge `WITH ROLLUP` ViewException set (×7 and ×1), not
this change. Visually re-checked in the QA env with agent-browser on all four merged cards:
swatches match bars, ratio renders as an amber line, no console errors, no 4xx/5xx.

`phpstan` introduces no new findings in the touched files (the reported ones are pre-existing;
`SharedHitStatsData:653` only shifted line number).
2026-09-22 10:09 CDT · handoff · human QA: legend swatch matched the leftmost month's grey, not the bars (makeDataset's per-month colour array); count series now one stable colour on all three ratio cards, ratio drawn as a line like RecoveryCounts already did

## 2026-09-22 — human QA follow-up: the ratio line rendered BEHIND the bars

Operator, on the reworked chart: the amber ratio line disappeared where it crossed a bar.

**Mechanism, read from the vendored source (chart.js 4.5.1), not recalled.**
`Chart._sortedMetasets = metasets.slice(0).sort(compare2Level('order', 'index'))` sorts ASCENDING
by `order` then `index` (dist/chart.js:5855), and `_drawDatasets()` then iterates that list in
REVERSE — `for (let i = metasets.length - 1; i >= 0; --i)` (dist/chart.js:6123-6126). So the
element that sorts FIRST is drawn LAST, i.e. on top.

Both datasets defaulted to `order: 0`, so the tie broke on `index`: the count bars (index 0)
sorted first and therefore painted last, over the line. The stacking was never explicit — it was
whatever the dataset order happened to produce.

**Fix.** `RATIO_SERIES_DRAW_ORDER = 0` on the line, `COUNT_SERIES_DRAW_ORDER = 1` on the count
series, both on `RatioAgainstUniqueScans`, so the intent is stated rather than inherited from
array position. Adding the ratio dataset at index 0 instead would have worked too, but it would
reorder the legend and invert every `datasets[0]`/`datasets[1]` assertion in three test files.

Guarded by an `assertLessThan($count['order'], $ratio['order'])` on all three ratio cards, so a
future reorder that re-buries the line fails rather than silently regressing. 106/106 pass.

Visual confirmation in the QA env was still in flight when this was committed; the mechanism is
pinned by the source read and the tests above.
2026-09-22 10:14 CDT · handoff · human QA: draw the ratio line in front of the bars. Chart.js sorts by (order, index) ascending then draws the list in REVERSE, so the bars (index 0) painted last and buried the line; both series now carry an explicit order

## 2026-09-22 — handoff

Operator completed human QA clean (2 findings raised during the pass, both fixed in-session and
re-verified visually). ACs 1-6 SATISFIED; AC-7 NOT SATISFIED, which is the accepted `t9` Playwright
deferral, not a QA failure.

- Build Record posted to DEV-2651 as comment **40954**. `build-record-gate.sh` → `pass id=40954
  (route=feature, ac=7, review_rounds=4, rerun_required=false)`.
- Jira DEV-2651 transitioned In Progress → **In Code Review** (transition id 4).
- PR #427 title de-WIP'd and marked **ready for review**. `mergeable=MERGEABLE`,
  `mergeStateStatus=CLEAN` checked FIRST — a conflicting PR gets no merge ref and Actions never
  schedules, which is the usual cause of a silently CI-less PR.
- This handoff commit is a `--boundary` commit: no `[skip ci]`. **CI runs on this branch for the
  first time here** — every prior checkpoint skipped it.

The review gate never passed (best lane-min 76 of a 90 threshold). That is stated plainly at the
top of the PR Status block and in the Build Record, so a reviewer decides with it in hand rather
than discovering it.
2026-09-22 10:24 CDT · done · handoff — human QA complete and clean, Build Record 40954 posted, Jira in In Code Review, PR #427 ready for review. First commit on this branch without [skip ci], so CI runs here for the first time

## 2026-09-22 — the handoff commit skipped CI anyway (driver error)

`199b4193c` was a `--boundary` commit, so `checkpoint.sh` correctly used the plain
`DEV-2651: <msg>` form and appended no skip marker. But the MESSAGE I wrote said
"First commit on this branch without `[skip ci]`" — and GitHub matches that token
ANYWHERE in the commit message, not just as a trailer. So the sentence announcing that CI would
finally run is precisely what stopped it running. `gh run list --branch claude/dev-2651-c29a86`
returned no runs at all.

The marker is a magic string; never name it in prose in a commit message. Re-triggered with
`199e...`'s successor, whose message does not contain the token.

Worth noting the near-miss: `gh pr checks 427` said "no checks reported on the branch", which is
also what a CONFLICTING PR looks like. `mergeable=MERGEABLE` / `mergeStateStatus=CLEAN` had been
checked before undrafting, which is what ruled that cause out immediately.
2026-09-22 10:25 CDT · done · re-trigger CI: the previous handoff commit named the CI-skip marker in its own prose, and GitHub matches that token anywhere in the message, so the commit announcing that CI would run is what stopped it

## 2026-09-22 — QA teardown, run done

Ran `qa-complete` in **teardown-only mode**. The skill's own rule: inside a live `/ticket` run,
"I've completed QA" is Phase 7's resume signal, and Phase 7 had already posted the Build Record,
transitioned Jira and readied the PR — running the full flow would have posted a second closeout
comment and duplicated the PR handoff.

Destroyed (all ledger-named, all shape-guarded): both Postgres databases, both ClickHouse
databases + their testing set, the Herd site, and five agent-browser sessions. The ClickHouse
CONTAINER was kept — `claude_dominick_scout_login_error_0a31b1` still has 2 QA databases in it and
the refcount held. Shared `zod` / `zod_testing` and five other worktrees' databases untouched.

Two things teardown could not do cleanly, both recorded in `.context/qa-complete.md`:

1. `down.sh` restored nothing — `.env.ch-qa-backup` was never written, so `.env` was left pointing
   at databases that had just been dropped. The only other snapshot was the CORRUPTED one `up.sh`
   produced, so restoring from it would have re-introduced the bug. Reverted by hand against the
   ledger's `env_restore` plus the main checkout's shape.
2. The literal `clarity` (28 objects) / `zod` (38) ClickHouse databases — this run's `up.sh` leak —
   were left standing. They are shared-named and invisible to ledger-driven teardown, and another
   worktree may since depend on them. Dropping a bare shared database on a guess is exactly what
   the guards exist to prevent.

`phase=done`.
2026-09-22 10:28 CDT · done · QA environment torn down; run complete

## 2026-09-22 — post-handoff scope addition: the whole grid is un-gated

Operator, after the run was handed off: "all metrics on the Company Metrics dashboard, including
Missed Ops, are NOT pro-gated anymore." Operator chose to add it to PR #427 rather than a separate
branch, and to remove `FeatureEnum::COMPANY_METRICS` entirely rather than leave it vestigial.

This REVERSES this ticket's own decisions 2 and 3, which deliberately kept a five-card gated
allowlist and a partial gate on `recovery-counts`. Stated plainly in the PR and the Build Record
so a reviewer is not surprised by it.

**Six overrides removed:** `canViewCardData()` on `UniqueLprCaseClaims`, `LprRepossessions`,
`UniqueScansLeaderboard`, `StagingLiveHitLeader`, `MissedOpportunities`; and
`canViewRatioFigure()` on `RecoveryCounts` (the partial gate). Plus `MissedOpportunities::canView()`
— see the trap below. `ADMIN`/`USER` role is now the only gate on every card.

**The trap, flagged by the code itself.** `MissedOpportunities`' docblock said "Do NOT delete this
override while canView() still delegates here -- the trait default calls static::canView(), so the
pair would recurse rather than deny." True: `canView()` returned `static::canViewCardData()`, so
deleting only `canViewCardData()` gives infinite recursion, not a denial. Both had to go together.
The card now inherits `DashboardWidget::canView()`'s role check like the other eleven.

**`COMPANY_METRICS` removed entirely** (FeatureEnum case, both ProTierEnum tier memberships,
the Pennant define). Checked before removing: Backyard and Clarity contain NO reference to
`platform_repo_pro_company_metrics` — Backyard writes the TIER and platform maps tier→features
locally, so the mapping is entirely ours. An org carrying the key in `org_meta.properties` just
leaves inert JSON; `Org::isProEnabled()` falls through to tier resolution and nothing asks for it.
`MISSED_OPPORTUNITY` was deliberately NOT removed — it still gates twelve other surfaces (policy,
client widgets, resource page, activity, notifications, alert settings).

**The upsell had to go too, and that was not in the request.** `MissedOpportunities` rendered
"Upgrade to Pro" + an `upgrade.from` link on its locked card. The card can now only lock for a
viewer with no ADMIN/USER role, and buying Pro would not unlock it — the copy would have been
actively misleading in the one case it could still render. Removed. The sidebar badge on
`MissedOpportunityResource` is untouched and still correct, because that resource IS still gated.

**canViewCardData()/lockedStat() were KEPT.** The seam is still live (a role-less viewer still
renders locked cards) and is what any future gate would be built on. Deleting it would have been
scope creep that threw away the zero-compute guarantee.

**Tests: inverted, not deleted.** ~25 gate tests across 8 files. Every "denies without the
feature" became "needs no entitlement" — a test that fails if a gate is ever silently re-added is
worth more than a deleted one. The two guarantees worth keeping were re-pointed at a ROLE-LESS
viewer, the only surviving withholding path: "a withheld card issues zero ClickHouse calls"
(`UniqueScansLeaderboard`, `StagingLiveHitLeader`) and "a withheld figure never invokes the
numerator seam" (`RecoveryCounts`).

**New file `MissedOpportunitiesGateTest.php`.** `MissedOpportunitiesTest` carries
`RequiresClickhouse` at CLASS level, so all 13 of its tests skip locally and only run in CI —
the DEV-2448 trap. The three new gate assertions touch no ClickHouse, so they went into a sibling
class without the trait and now run everywhere. They passed locally; the inverted assertions left
inside the CH-bound class remain CI-only.

**Evidence.** Baseline-differenced against HEAD in the same environment: 35 failed before, 35
after, byte-identical names — zero regressions. (Same caveat as always: name-differencing cannot
tell "still failing environmentally" from "now also failing on a new assertion" for those 35.)
Four differential mutations, all killed: re-gate MissedOpportunities → red; restore the upsell
copy → red; re-gate UniqueLprCaseClaims → red in both its own test and the visibility allowlist.
`phpstan` adds nothing new (the `FeatureEnum::title()` finding is the pre-existing dynamic-attribute
pattern — the same error appears on `DispositionEnum` in a file this change never touched).

**Note: the test database had to be recreated.** Teardown had dropped it an hour earlier, so
`zod_testing_claude_dev_2651_c29a86` was re-created and migrated (not seeded) to run any of this.

**Owed after merge, NOT done here** (CLAUDE.md forbids `okf/` writes while work is in flight):
`okf/concepts/company-metrics-dashboard.md` documents the Pro gate at lines 98 and 116-122 and is
now wrong. `tests/e2e/` specs and `helpers/company-metrics.ts` also carry stale gate prose, but
`t9` is deferred and that suite is knowingly broken against this branch.
2026-09-22 12:38 CDT · done · remove every Pro gate from the Company Metrics grid, including Missed Opportunities, and delete FeatureEnum::COMPANY_METRICS with them. Role check is now the only gate; gate tests inverted rather than deleted

## 2026-09-22 — PR #431 CI failure: the CI-only test I predicted, plus a finding

`Pint & Tests (8.4, 3)` failed on **one** test — `MissedOpportunitiesTest > the real grid renders
the card locked for a non-pro org`, `Failed asserting that true is false`. Shards 1 and 3 showed as
"fail" in `gh pr checks`, but shard 1's conclusion was **cancelled** (fail-fast killed it mid-run
after its own 645 tests had already passed). Only shard 3 failed for real. Reading the shard
conclusions rather than the check names is what separated the two.

This is exactly the trap flagged when the change was made: `MissedOpportunitiesTest` carries
`RequiresClickhouse` at CLASS level, so all of its tests skip locally and only ever run in CI. The
inverted assertions in that class were written blind, and this one was missed — it asserted
`canViewCardData()` is false for a non-Pro ADMIN, which is now true.

**The finding, which is worth more than the fix.** The test drove the REAL `getStats()` through
`Livewire::test(CompanyMetricsOverview::class)`. Re-pointing it at the only viewer who still
withholds a card — a role-less one — returned **403**: `mount()` does `abort_unless(static::canView())`.

So **the locked path is no longer reachable through the rendered grid at all.** With nothing
Pro-gated, any viewer who can load the widget sees every card, and the only viewer who withholds one
cannot load the widget. The `canViewCardData()`/`lockedStat()` seam is still live and still worth
pinning — it is what a future gate would be built on — but it no longer has an end-to-end rendering
that exercises it. The test now drives `getStats()` directly by reflection, which keeps the mutation
it exists to kill (routing a withheld card to `toStat()` instead of `lockedStat()`, which would
serve the poisoned cache value) without depending on a rendering that can no longer happen.

Moved into the CH-free sibling `MissedOpportunitiesGateTest`, where it RUNS locally: a role-less
viewer withholds every card, so `getStats()` routes all twelve to `lockedStat()` and issues no query.
4/4 pass locally now, and the routing mutation was confirmed to kill it (the first mutation attempt
was a regex that silently did not match and "passed" — caught by checking that `lockedStat` still
appeared 4 times in the file, not by trusting the green result).

**Swept for the same class of breakage rather than fixing just the reported one.** Of the 42 test
classes carrying `RequiresClickhouse`, three touch gating at all; the other two
(`HasStatDrillDownTest`, `MissedOpportunityResourceTest`) use `pro: true` for surfaces that are
still genuinely Pro-gated, and `MissedOpportunitiesTest`'s remaining `lockedStat()` test calls it
directly and is unaffected. No other CI-only assertion depends on the removed gates.
