2026-08-27 10:42 CDT · intake · intake complete: route=bug app=clarity base=main
2026-08-27 10:59 CDT · prepped · prep complete: gate=BLOCKED — root cause verified (sc_positions-only read vs scan-only kits), duplication risk vs DEV-2222/PR#320

## Phase 2 — Question Gate (BLOCKED), normal mode

Gate computed BLOCKED: 5 `answered:false` questions, 3 blockers.

Escalation note (deliberate, not a skip): every Phase-1 escalation trigger fired
(confidence 55, escalate.recommend, competing hypotheses, unmerged-branch dependency).
Best-of-N deep prep was DEFERRED rather than run, because the single blocking question is a
scope/duplication DECISION only the operator can make — three blind candidates cannot resolve
it, and the technical diagnosis is already verified against production ClickHouse and
independently corroborated by DEV-2222's own ticket text plus its drafted PR #320. If the
operator elects to implement inside DEV-2441 (option B), escalate THEN with scope settled.

Verified duplication facts (deterministic, driver-run):
- DEV-2222 "Clarity — Kit User Path: fall back to kit_scan_paths for scan-only kits (no
  continuous GPS)" — Task, status **To Do**, assignee Dalton, updated 2026-08-26.
- PR #320 "[WIP] DEV-2222 | ..." — **OPEN, DRAFT**, head `claude/dev-2222-cf9a90`, base main,
  +1950/-26 over 11 files (4 code: ClickhouseQuery, KitRangePathsQuery, KitScanPathsQuery,
  KitUserPathController; + KitUserPathControllerTest +461/-4), last updated 2026-08-17,
  now 358 commits behind main / 16 ahead.

Interview presented to the operator. Parked awaiting answers.
2026-08-27 11:00 CDT · gated · gate=BLOCKED — interview presented, parked awaiting operator answers

## Phase 2 — answers folded in, gate recomputed CLEAN (Dalton, 2026-08-27)

Six decisions, all recorded to prep-context.questions with source `human:Dalton 2026-08-27`:

1. **Scope** → implement fresh under DEV-2441; close DEV-2222 as superseded. Reuse PR #320's
   review-hardened code as prior art rather than reinventing it.
2. **Permission hypothesis** → REFUTED. Don can open the page and the map renders blank, so
   `canAccess()` passes and the permission is granted. This also proves the kits are selectable,
   which refutes the `kit_metadata` empty-dropdown theory as the reported symptom.
3. **AC-1/AC-2 prod lookups** → Backyard admin console, as ops confirmations, not root causes.
4. **R3 (whole-window fallback)** → FIX IT. Per-kit-per-DAY granularity. Reverses the 2026-08-17
   deferral on the strength of a confirmed customer instance: kit 10588 has GPS on 1 of 30 days.
5. **Bird's Eye sibling** → RATIFIED OUT OF SCOPE, document the boundary in-code so review lanes
   read it as a decision rather than a missed exhaustiveness site. Stays on DEV-2228.
6. **R1 (mapping-less kits)** → FIX IT HERE, and close DEV-2341 once R1/R2/R3 are all in.
7. **R7 (trip_date day-key divergence)** → FIX PROPERLY in the shared `KitScanPathsQuery` using
   the timezone all three callers already pass. Closes R7 instead of institutionalising it.

Standing instruction from the operator, recorded: **do not create new Jira tickets without
asking.** Residuals get fixed in-ticket or raised for a decision. DEV-2341 (auto-created by
DEV-2222's run) is the cautionary case — it moved the block somewhere less visible and DEV-2222
sat parked for two weeks.

Escalation: best-of-N prep NOT run. Every Phase-1 trigger was resolved by these answers
(confidence 55 -> 88, 0 unanswered, 0 blockers). Re-running prep would re-derive grounding already
verified against production ClickHouse and already hardened by five adversarial review rounds on
PR #320. Remaining risk is in DESIGN (R1 + R3 + the R7 day-key interaction), which Phase 3 owns at
the opus arbiter tier and Phase 5 will attack with 3 lanes (date-boundary logic => 3-lane trigger).

Two driver findings folded into the plan's premises, neither from the fast pass:
- **R3 requires R7.** Tier 1 dates days viewer-local (`KitRangePathsQuery:197`
  `toDate(toDateTime(...,'UTC'), '<tz>')`); tier 2 dates them UTC (`KitScanPathsQuery`
  `toDate(timestamp)`, no tz arg). A per-day set-difference across two day definitions mis-diffs
  for the ~6h/day where the local and UTC dates disagree. It cannot be repaired in PHP — once
  ClickHouse collapses `timestamp` to a UTC date the time-of-day is gone — so it must be fixed
  in SQL. Verified all three call sites already pass `'timezone' => $timezone`, and that exactly
  one other consumer reads `trip_date` (`ClarityScoutBirdsEyeController:544`).
- **SQL injection risk in that fix.** `$timezone` is currently only used via `Carbon::parse()`
  (safe). Interpolating it into `toDate(timestamp, '<tz>')` makes it an injection vector, and it
  resolves from `$user->timezone` -> `$this->dti['timeZone']`, user-influenced JSON. Must be
  validated against `timezone_identifiers_list()` as `KitRangePathsQuery::safeTimezone()` does.
2026-08-27 11:15 CDT · gated · gate=CLEAN — 7 operator decisions folded in; scope = fallback + R1 + R3 + R7

## Phase 3 — Plan (single arbiter, authored inline at the opus driver tier)

Authored inline rather than by a fresh dispatch, recorded for audit honesty: the driver holds every
premise the plan rests on — PR #320's diff, DEV-2341's residual ledger, the five-round review
history, the per-tier `trip_date` semantics read from source, the three call sites' timezone args,
and the injection risk in the R7 fix. A fresh-context arbiter would have taken all of it on trust.

- Sizing gate **PASS**: 4 code files (< the >6 threshold) + 3 test files, one concern.
- Perf gate **NOT REQUIRED**: `sc_positions` 331,106,659 rows and `kit_scan_paths` 1,680,578 —
  neither is >1B, so no deferred-perf-at-scale gate applies.
- No formal `blast_radius` tag (no sensitive glob matches), but R7 changes a SHARED query class
  feeding a live Scout surface, so Phase 5 must run 3 lanes (date-boundary logic anyway).
- DAG: **1 node** (t1). Files interlock, so a split would serialize on `depends_on` while adding
  worktree/merge risk for zero parallelism → single-node fast path, no fan-out.
- `excluded_sites` records the three ratified out-of-scope sibling surfaces (Bird's Eye
  historical + KitHistoricPositionsQuery + OrgLivePathsQuery), all on DEV-2228.
2026-08-27 11:17 CDT · planned · plan: 1 node — fallback + R1 + R3 + R7; sizing PASS, perf not required

## Phase 3.5 — Provision Postgres (qa-prep provision-only, CH deferred)

WARM PATH — no bootstrap. Both per-worktree Postgres DBs already existed and were migrated
(`zod_claude_dev_2441_6805e6`, `zod_testing_claude_dev_2441_6805e6`), user 1 seeded, so
`.env`/`.env.testing` already point at the isolated instances. Deliberately did NOT re-bootstrap:
the branch was renamed at intake (`claude/dev-2441-6805e6` -> `claude/DEV-2441`), so bootstrap
would have re-keyed to `zod_claude_dev_2441` and `dev:migrate` is `migrate:fresh` — it would have
wiped a perfectly good, already-isolated env for a cosmetic name change.

- **SECURITY-RELEVANT:** the worktree `.env` had `CLICKHOUSE_HOST` pointing at ClickHouse **CLOUD**
  (`readonly_user`). Neutralized to `127.0.0.1` so a stray CH hit fails loud-and-local rather than
  reaching Cloud. Prior value recorded in the ledger's `env_restore` for teardown.
- `MAIL_MAILER` smtp -> log (both `.env` and `.env.testing`).
- PostGIS `tiger.state` patch **applied** (`simplified_geom` + `geom_4326` added; `the_geom`
  already present).
- Baseline reseed **SKIPPED** despite `TIGER_CHANGED=yes`, deliberately: the DB is already fully
  seeded and `LocalDataSeeder` is not idempotent — on DEV-2222 this identical trigger failed on a
  unique-constraint violation and duplicated fixture kits. Task data comes from the Phase 6 full
  qa-prep run instead.
- ClickHouse **deferred** per the Phase 3.5 contract.

Two things Phase 4 MUST do, both recorded as ledger caveats:
1. **Ensure CH** (`QA_PROVISION_CH=yes`) — this change reads `clarity.sc_positions` and
   `clarity.kit_scan_paths`, and its tests are `RequiresClickhouse`-gated.
2. **Flip `.env.testing`'s `CLICKHOUSE_DRIVER=pgsql` -> `clickhouse`.** Under `pgsql` every
   `RequiresClickhouse` test SKIPS, producing a green suite with ZERO ClickHouse coverage. This
   exact trap bit DEV-2222.

**Environment fault, outside the ticket:** Herd is not serving — nothing is listening on :80 or
:443 machine-wide, and every `.test` site returns 000, not just this one. `herd secure` issued a
fresh cert and `herd start` reported success, but no listener appeared. Postgres (this phase's
deliverable) is unaffected and Phase 4 needs no web server, so the run continues — but this must
be fixed before Phase 6/7 browser QA.
2026-08-27 11:20 CDT · provisioned · isolated Postgres standing (warm); CH deferred to Phase 4; Herd not serving (machine-wide, noted)

## Phase 4 — Implement (single-node fast path)

- `perf-gate.sh` → **pass** (not a deferred-perf CH-at-scale design; no prod-scale measurement required).
- Jira **To Do → In Progress** via the Atlassian MCP (transition id 21). Note the DEV board's
  transitions are all `isGlobal: true`, so the flow's "offered ⇒ not there yet" idempotency
  assumption does NOT hold — decided from the status fetched at intake (`To Do`) instead. Same
  finding DEV-2222 recorded.
- **CH ensured (lazy).** Container `clarity-qa-clickhouse` was already up on `127.0.0.1:18123`
  with the **LITERAL** `clarity`/`zod` databases present (this repo hardcodes those names in ~15
  query classes, so per-worktree CH DB names break ~10 Scout files). Pointed BOTH `.env` and
  `.env.testing` at them.
- **CAUGHT (the DEV-2222 trap, exactly as predicted at Phase 3.5):** `.env.testing` had
  `CLICKHOUSE_DRIVER=pgsql` and `CLICKHOUSE_DATABASE_*` pointing at the Postgres testing DB. Every
  `RequiresClickhouse` test would have SKIPPED — a green suite with **zero** ClickHouse coverage.
  Flipped to `clickhouse` on `127.0.0.1:18123` with the literal DB names, then verified through
  Laravel: `clarity.sc_positions` and `clarity.kit_scan_paths` both readable on the testing env.
- **Baseline before any change:** `KitUserPathControllerTest` → **12 passed, 0 skipped**. The zero
  skips are the proof CH is genuinely exercised rather than silently bypassed.
- `DIFF_BASE=bea2f0590d579152b76928d94cb724e1b706adfe` (captured before any code change).
- t1 dispatched: sonnet worker (`complexity: complex`), on-branch, TDD, with PR #320's code diff as
  prior art plus the explicit do-not-re-litigate list from its five review rounds.
2026-08-27 11:24 CDT · implementing · Phase 4 entry: perf gate pass, Jira In Progress, CH ensured (literal DBs), .env.testing pgsql->clickhouse trap caught; baseline 12 passed/0 skipped

### t1 implemented (sonnet worker, single-node fast path, on-branch)

4 code files + 3 test files, +324/-46 in `app/`. Exactly the planned target files — the
unexpected-files check found no strays, and the blast-radius re-check found no sensitive-glob hits.

- **R7** — `KitScanPathsQuery` `trip_date` is now `toDate(timestamp, '<tz>')` (viewer-local),
  matching `KitRangePathsQuery`. Guarded by a new `safeTimezone()` that whitelists against
  `timezone_identifiers_list()` with a UTC fallback, mirroring `KitRangePathsQuery::safeTimezone()`.
- **R1** — new `kitsById()` keys the full Kit collection by id; the `$byMapping === []` early return
  became a no-kits-at-all check; tier 1 is gated on `$mappings !== []`, so a purely mapping-less
  selection goes straight to tier 2.
- **R3** — `scanPathFallbackRows()` builds `coveredDays[kit_id][YYYY-MM-DD]` from tier-1 segments,
  enumerates window days via `localWindowDays()`, marks a kit tier-2-eligible only when
  `count(coveredDays[kit]) < windowDayCount`, issues ONE batched read for the eligible set, and
  drops any row whose `(kit_id, trip_date)` tier 1 already renders. A fully GPS-healthy org issues
  **zero** tier-2 reads (pinned with an HTTP-client select-count spy).
- **R2/R6** and the ratified boundary comment for `historicalContinuousPaths()` all landed.

`localWindowDays()` enumerates from the **naive** local wall-clock strings, so `addDay()` advances
the calendar date and DST transitions cannot skip or duplicate a day. Good choice — worth keeping.

**DRIVER-VERIFIED, not taken on trust.** Re-ran the tests myself: **143 passed, 0 failed,
0 skipped** (27 primary + 116 blast-radius). Then **mutation-tested all three load-bearing pins**,
reverting each and confirming the intended test — and only that test — failed:

| Mutation | Result |
| --- | --- |
| R3 → per-kit-whole-window (PR #320's behaviour) | ⨯ `a sparse track falls back on the other days only` |
| R7 → `toDate(timestamp)` without the timezone | ⨯ 3 tests across all 3 files (controller day-key agreement, Bird's Eye viewer-local `trip_date`, unit-level `trip_date`) |
| whitelist bypass → raw `$timezone` into SQL | ⨯ `a non iana timezone falls back to utc and never reaches sql` |

All three reverted; working tree confirmed clean of residue by re-diffing the specific lines; final
re-run **143 passed**. The pins are real, not decorative.

**Transient, recorded not dismissed:** one combined run showed 1 failure that vanished on re-run,
and the worker saw the same on 2 of its 4 combined runs — a local ClickHouse
`MEMORY_LIMIT_EXCEEDED` (error 241) on unrelated heatmap/hits tests. Substantiated: the
`clarity-qa-clickhouse` container sits at ~2.9 GiB of a 5.8 GiB cgroup cap (~50%) with
`max_memory_usage=0`, so sustained runs can trip it. Zero coupling to this diff (the flaking area
has no KitUserPath references). Root cause is local container sizing, not a regression.

**Full suite deliberately NOT run locally** (stated, not hidden): `CLAUDE.md` says CI runs tests
post-commit, and the suite cannot finish under the local 128M `memory_limit`. Covered the changed
surface plus every consumer of the shared `KitScanPathsQuery` instead.
2026-08-27 11:45 CDT · implemented · t1: per-(kit,day) kit_scan_paths fallback + R1 + R7; 143 tests green, 3 pins mutation-verified, Pint clean

## Phase 5 — Review round 1 (3 lanes) — GATE FAIL, MIN 62

Lane selection: **3 lanes**, triggered twice over — semantic-correctness territory (this change is
date-boundary + counting/dedup logic) and `resource_envelope.scale_relevant` true (ClickHouse query
classes). All three lanes ran as real `harness-claude-p` (no in-session substitution).

Scores **claude1=72 · claude2=78 · claude3=62 → MIN 62**. `review-gate.sh` → FAIL (<90).
69 unique flagged locations, 66 single-lane-only.

### The HIGH all three lanes raised independently — and it was the DRIVER's design error

The `$windowDayCount` eligibility test was **inert**. Driver-verified empirically rather than taken
on trust — measured window-day counts per toggle:

| toggle | windowDayCount |
| --- | --- |
| `yesterday` | 1 |
| `7_days` | **8** |
| `15_days` | 16 |
| `30_days` | 31 |

A kit is skipped only if it has tier-1 segments on **every** window day, *including today, still in
progress*. Essentially no kit qualifies — so tier 2 fired for **all** selected kits on every
multi-day request, against a read with no `LIMIT` and no point budget, hydrated into PHP arrays and
then cached. That is **strictly worse than PR #320**, which only read for zero-coverage kits.

This originated in the driver's Phase 3 plan, which asserted the design "preserves PR #320's
allocation property" and instructed the worker accordingly. It does not. Owning that plainly:
the lanes were right and the plan was wrong.

claude3 additionally noted the change reintroduces exactly the per-point PHP materialization that
`segmentRows()`' own docblock forbids, and caches a payload the neighbouring Bird's Eye code
explicitly documents as too large to cache. Both fair.

### Also confirmed from source

- **F2** — the "GPS-healthy org issues no tier-2 read" comment is false for every toggle but
  `yesterday`, and the test certifying it uses only `yesterday`. Confirmed.
- **F3** — `handle()` filters the window with the RAW timezone but labels `trip_date` with the
  WHITELISTED one; they diverge for a legacy identifier and the R7 fix then does nothing. The
  driver had *noticed* this asymmetry while reviewing the diff and wrongly dismissed it as
  defensive depth. The lanes were right.
- **F4** — the new `kit_scan_paths` fixtures use hardcoded dates under the table's 90-day DELETE
  TTL (verified on the container: `TTL toDateTime(timestamp) + toIntervalDay(90)`), so they go red
  on a date certain. The Bird's Eye test in the SAME diff does it correctly via
  `Carbon::today('UTC')` — the diff is internally inconsistent, and it ignored an explicit
  instruction to anchor fixtures to `now()`.
- **F5** (single lane) — no zero-geo `(0,0)` guard on the newly-exposed tier-2 polylines.
- **F6** — the 503 test never asserts the "does not fire tier two" half of its own name.

### One finding REJECTED, with evidence

claude3 M2 claimed the over-sized-selection signal was reclassified 422→503. **Misread:** the code
returns 422 for a *successful* over-budget count and 503 only on a *throw*
(`KitUserPathController.php:105-125`). Signal intact. Recorded as rejected rather than silently
dropped.

### Operator decision on the fix (2026-08-27)

Presented three ways to bound tier 2. Operator chose **revert R3 to PR #320's per-kit-whole-window
eligibility** rather than add a tier-2 budget or move assembly into ClickHouse.

**Residual risk surfaced to the operator BEFORE the fix cycle was spent, and reaffirmed:** the
revert mitigates the unbounded read for GPS-*healthy* orgs but not for scan-only ones — eligibility
becomes "zero tier-1 coverage", which for org 469 is most of its 151 kits. A scan-only org
selecting All Kits over 30 days still issues a large unbounded tier-2 read. This is the same
exposure PR #320 carried into five failed gates, so RN2 may score it again.

**Consequence for DEV-2341 (flagged, not silently actioned):** closing it was predicated on R1, R2
and R3 all being fixed here. R3 is now deliberately re-deferred, so DEV-2341 cannot be cleanly
closed. Raising at handoff rather than mis-closing it — and per the operator's standing
instruction, no new ticket will be created for the remainder.

Fix cycle dispatched (F1–F6). RN2 to follow. `rerun_required=true`, `reviewed_sha=null`.
2026-08-27 12:04 CDT · reviewed · RN1 gate FAIL min 62; driver's window-day skip was inert (own design error); operator chose revert-R3; fix cycle F1-F6 dispatched

### Fix cycle F1–F6 applied (sonnet worker) + driver stale-prose sweep

- **F1** — tier-2 eligibility reverted to **per-kit-whole-window**: `$coveredKitIds` replaces the
  per-(kit,day) map; a kit is eligible only with **zero** tier-1 segments in the window.
  `localWindowDays()`, the `$windowDayCount` machinery, the per-row `(kit_id, trip_date)` drop, and
  a newly-unused `Carbon` import were all **deleted** (dead code is itself a finding). The accepted
  trade-off is now *pinned*, not silent:
  `test_a_sparse_track_renders_only_its_gps_day_whole_window_fallback_is_per_kit`, with a docblock
  telling the next person not to "fix" it by reintroducing per-day tracking.
- **F2** — the false invariant comment and its `yesterday`-only test replaced by
  `test_a_partially_covered_kit_issues_no_tier_two_read_on_a_multi_day_window` (exercises `7_days`
  with partial-but-nonzero GPS).
- **F3** — `handle()` now uses `$safeTimezone` for **both** the window bounds and the `trip_date`
  label; pinned with a legacy-alias test (`US/Central`) alongside the retained whitelist test.
- **F4** — every ClickHouse fixture and `setTestNow` re-anchored to a `frozenNow()` helper
  (`Carbon::today('UTC')->setTime(12, 0)`), reusing `ClarityScoutBirdsEyeControllerTest`'s existing
  pattern rather than inventing a second one. **Zero hardcoded absolute dates remain** (verified by
  grep). The 90-day TTL time bomb is defused.
- **F5** — zero-geo guard added via `zeroGeoFilteredPathSql()`, mirroring
  `OrgScanPathPositionsQuery`'s existing `arrayFilter` predicate rather than inventing a filter, and
  applied to both the selected `path` and the `length(...) >= 2` guard.
- **F6** — the 503 test now asserts `kitScanPathSelects === 0`, so it actually covers the
  "does not fire tier two" half of its own name.

Untouched as instructed: the 422/503 split, no `countPoints`-zero-segments guard, the Bird's Eye
sibling and its DEV-2228 note, no tier-2 budget, `KitUsersFilter`/`Kit::getKits()`/migrations.

**Driver caught what the fix cycle missed — stale rationale.** The revert removed the per-day diff,
but three comments still justified R7 *by* that diff ("so a per-(kit, day) diff … compares like
with like") in `KitScanPathsQuery` and two test docblocks. Left alone, that is precisely the
stale-prose finding a lane scores. Rewritten to R7's actual surviving rationale: one response must
not label tier-1 and tier-2 rows with two different definitions of "day", and the Org Map's
fade-by-age must be right for a non-UTC viewer. The deliberate deferral note and the append-only
journal line keep their per-day references — those are intentional.

**Driver-verified:** 147 passed / 0 failed / **0 skipped** (31 primary + 116 blast-radius). Pint
clean. Diff now +990/-53 across 7 files.

Transient recurred (recorded, not dismissed): the worker saw ClickHouse error 241 twice more, each
in a different *untouched* blast-radius file under sustained load, each passing clean in isolation.
Same local container-sizing cause as before.
2026-08-27 12:20 CDT · implementing · RN1 fix cycle: F1-F6 + stale-prose sweep; 147 green, Pint clean

## Phase 5 — Review round 2 (3 lanes) — GATE FAIL, MIN 62. RUN PARKED.

Scores **claude1=62 · claude2=70 · claude3=68 → MIN 62**. All lanes real `harness-claude-p`.
`review-gate.sh` → FAIL. The flow allows **one** fix cycle, which RN2 was, so the run **parks
non-blocking** here rather than looping further. Score history: RN1 min 62 → RN2 min 62.

### H1 recurred, exactly as predicted

The unbounded/unbudgeted/PHP-materialized/cached tier-2 read is still the top HIGH in all three
lanes. This was **surfaced to the operator before the fix cycle was spent** — the revert-R3 option
was stated to mitigate GPS-healthy orgs but not scan-only ones — and the operator reaffirmed it.
claude1 now quantifies it: All Kits + `30_days` at org 469 = 151 kits × 31 days × up to 96 buckets
≈ **449k rows**; at PG-022's measured 1.5 KB/point that is ~1.3 GB at 2 points/bucket and ~6.7 GB
at 10, against a 256M web `memory_limit`. Squarely the PG-022 class.

### The fix cycle INTRODUCED a production regression — and it was the driver's instruction

**F3-REGRESSION (HIGH, all three lanes).** Using `$safeTimezone` for the **window bounds** — not
just the SQL label — shifts the query window for any valid-but-non-canonical IANA identifier, and
it does so in **three already-shipped production callers** beyond this ticket's page
(`ClarityScoutMapController::todayScanPaths`, `::teammatePaths`,
`ClarityScoutBirdsEyeController::todayScanPaths`).

Driver-verified empirically: `timezone_identifiers_list()` returns **canonical zones only** (419),
so `US/Central`, `US/Eastern`, `US/Pacific` and `Asia/Calcutta` all **fail** the whitelist and fall
back to `UTC` — a 5–6 hour window shift. Before F3, `Carbon::parse($start, 'US/Central')` handled
the alias correctly. **So F3 as implemented is a net regression versus not having touched the bounds
at all.**

Root cause is the **driver's own F3 instruction**, which said "use `$safeTimezone` for both" without
accounting for the whitelist rejecting legacy aliases. The correct shape is to *canonicalize* the
alias, not fall back to UTC. Aggravating: the worker's new
`test_window_bounds_and_trip_date_label_use_the_same_safe_timezone` uses the `US/Central` alias and
asserts the unified-to-UTC behaviour — it **certifies the defect** (claude2 M4).

**Minimal correction, driver-verified but NOT applied:** whitelist against
`timezone_identifiers_list(DateTimeZone::ALL_WITH_BC)` (598 zones) instead of the canonical-only
list. Checked against the live ClickHouse container — `toDate(..., 'US/Central')`, `'US/Eastern'`
and `'Asia/Calcutta'` all resolve correctly, so SQL stays valid, the whitelist still closes the
injection vector, and the window stops shifting. Left unapplied deliberately: the fix cycle is
exhausted, and landing it unreviewed would trip `rework-gate.sh`.

**F5-INCOMPLETE (MEDIUM, all three lanes).** The zero-geo guard is an unannounced behaviour change
to three untouched production callers with no coverage for them; it is incomplete (a sibling
`path_points` reader at `ClarityScoutMapController:564-589` still renders a null-island dot); it is
internally inconsistent (the bbox predicate still tests the *unfiltered* `path_points` while the
length guard three lines above tests the filtered array); and its test cannot fail for the reason
it claims.

**claude2 M1** — a tier-2 failure returns HTTP 200 with a silently empty map while a tier-1 failure
returns 503. For a scan-only org, tier 2 *is* the whole response, so the degradation path is
backwards for exactly this ticket's population.

### Park state

`rerun_required = true`, `reviewed_sha = null`, PR stays **DRAFT**.
**Merge recommendation: DO NOT MERGE** — the diff contains a verified production regression
affecting endpoints beyond this ticket, and the motivating HIGH is unresolved.
Phases 6 (agent QA) and 7 (human QA / Build Record / undraft) did **not** run.

Per the operator's standing instruction, **no Jira ticket was created** for these residuals; they
are posted to the PR and raised in-session for a decision.
2026-08-27 12:33 CDT · reviewed · RN2 gate FAIL min 62; fix cycle exhausted; F3 introduced a verified prod regression; PARKED, do not merge
2026-08-27 12:34 CDT · reviewed · parked: residuals posted to PR + Jira mirror; QA env left standing for resume; no new tickets created

## Environment fix — Herd outage root-caused and repaired (2026-08-27)

The machine-wide Herd outage recorded at Phase 3.5 is **resolved**. The operator supplied the
decisive line from nginx's startup log:

```
[emerg] 53431#52951608: empty replacement in
  .../config/valet/Nginx/clarity-claude_dev_2426.test:27
```

`[emerg]` is a **startup** failure, which explains what the earlier diagnosis could not: a single
malformed site config aborted nginx before it bound anything, so **all 77** `.test` sites returned
000 — and `nginx-error.log` looked four months stale because nginx never got far enough to log a
request error. Looking only at the request log was the wrong place; the startup log was.

`clarity-claude_dev_2426.test` (belonging to a *different* worktree, last written 2026-08-25) had
**8 empty directive values** across its two server blocks — `rewrite ^ "" last`,
`error_page 404 ""`, `fastcgi_index ""`, `fastcgi_param SCRIPT_FILENAME ""` — where all 76 other
configs carry `/Applications/Herd.app/Contents/Resources/valet/server.php`. The two files are
otherwise byte-structurally identical (same directives on the same line numbers), so the repair was
an unambiguous substitution rather than a guess.

Backed the file up first, substituted the path into all 8 slots, then `herd restart`. Verified:
nginx master + 2 workers bound on `127.0.0.1:80` and `:443`; this worktree's site returns **302** on
`/__qa/login` and **200** on `/app/login`; the repaired `clarity-claude_dev_2426.test` returns
**302** (so the repair is functionally correct, not merely non-fatal); `platform.test` is back.

`reverb.herd.test` also carries an empty `error_page 404 ""`, but it is a proxy config with no
`rewrite` directive and predates the outage, so nginx evidently tolerates it. Left alone
deliberately rather than "fixing" something that works.

**Consequence for this run:** the QA venue now exists, so Phase 6/7 are no longer environment-blocked.
The run nonetheless stays **parked at review** — `rerun_required=true` and the residual HIGH findings
are unresolved, and the standing recommendation is still DO NOT MERGE. Herd being fixed removes a
blocker; it does not clear the gate.
2026-08-27 13:00 CDT · reviewed · Herd outage root-caused (empty replacement in a foreign site config) and repaired; QA venue restored; run still parked at review

## Scope strip + tier-2 bound (operator-directed, 2026-08-27)

Operator reframed: **"we just want to solve the ticket's problem."** That dissolved two of the three
parked problems by deletion rather than by patching.

### Stripped (operator: revert R7 + zero-geo, keep R1)

- **R7 reverted entirely.** `KitScanPathsQuery` was restored to baseline. R7 only ever existed to
  make the per-day diff correct, and the per-day diff was already reverted — so it was serving
  nothing while carrying the production regression. Reverting **deleted** the regression instead of
  patching it, and with it the change to a shared query class feeding three shipped endpoints.
- **Zero-geo guard reverted** (unneeded for the ticket; it silently changed three untouched callers).
- `ClarityScoutBirdsEyeControllerTest.php` reverted wholesale — its entire addition was the one R7 pin.
- Removed the tests that pinned reverted behaviour, plus the dead `frozenNow()` helper and a
  now-unused `Carbon` import in the unit test.
- **DEV-2341 answers itself:** none of its residuals land here, so it stays open, untouched.

### Caught a FALSE PASS while stripping

`test_cached_handle_propagates_a_clickhouse_throw` did `expectException(\Throwable::class)` and then
called a method the strip had deleted. PHP raised `Error: Call to undefined method` — which *is* a
`Throwable` — so the test **passed for entirely the wrong reason**. Exactly the vacuous-test class
the lanes had flagged elsewhere. Rewritten to call `handle()` and to expect the specific
`LaravelClickhouseEloquent\Exceptions\ClickhouseException` (the repo's existing idiom, per
`OrgHotlistCacheTest`), so it cannot pass vacuously again.

### The remaining problem, measured before choosing

Rather than accept the lanes' extrapolation, measured the real hydration cost:

| Scenario | Points | Hydrated in PHP |
| --- | --- | --- |
| Org 469, the 8 ticket kits, 30d | 423,375 | **104 MB** |
| Org 469, All Kits, 30d | 1,638,210 | **308 MB** |
| Worst org (1271), 30d | 3,659,502 | **516 MB** |

~150–260 bytes/point, **not** the 1.5 KB/point the lanes extrapolated from — so their 1.3–6.7 GB
figure was overstated by roughly 10–20×. The defect is real; its magnitude was not. Recorded because
a gate should be argued from measurements, not from the scariest available number.

The useful consequence: the existing `MAX_RENDER_POINTS = 1_500_000` already lands between the case
that must work (423k) and the cases that are genuinely unsafe (1.64M / 3.66M).

### Implemented (operator: budget + no cache)

- `KitScanPathsQuery::countPoints()` added, mirroring `KitRangePathsQuery::countPoints()`. Both it
  and `handle()` now share a new private `filteredQuery()`, so the pre-flight count can never drift
  from what `handle()` would actually hydrate. `handle()`'s SQL is unchanged — proved by the three
  shared callers' 115 tests staying green.
- Tier 2 now calls **`handle()` directly, uncached**: `query()` swallows `Throwable` to `[]`, and
  caching a payload this size mints multi-hundred-MB entries — the same reason the adjacent Bird's
  Eye segment reader does not cache. `cachedHandle()` on `KitScanPathsQuery` therefore deleted.
- Tier 2 is now inside the `MAX_RENDER_POINTS` budget; the "deliberately NOT covered" comment is gone
  because it stopped being true.
- `scanPathFallbackRows()` returns an explicit `{status: ok|over_budget|unavailable, rows}` so
  "refused" and "outage" can no longer collapse into a silent empty 200.
- **New:** a tier-2 outage while tier 1 rendered nothing now returns **503**, not an empty 200. For a
  scan-only org tier 2 *is* the whole response, so the old degrade path was indistinguishable from
  the very bug this ticket is about (addresses claude2 RN2 M1).

### Verified

141 passed / 0 skipped (26 primary + 115 blast-radius), Pint clean. **Both new guards
mutation-verified** — removing the budget check fails only `tier_two_over_budget_returns_422`;
removing the 503 branch fails only `a_tier_two_throw_with_no_tier_one_rows_returns_503`.

Diff is now 6 files, +822/-59 (was 7 files, +990 before the strip). One blast-radius run showed the
familiar CH container `MEMORY_LIMIT_EXCEEDED` transient; re-ran clean at 115.
2026-08-27 14:10 CDT · implementing · scope strip (R7+zero-geo reverted) + tier-2 budget & uncached read; false-pass test fixed; 141 green, 2 new pins mutation-verified
2026-08-27 14:50 CDT · implementing · tier-2 pre-encoded in ClickHouse (95 B/pt vs 325, 3.4x); shared budget; tz ALL_WITH_BC; 151 tests green

## Phase 6 — Verify (agent QA) — PASS, 19/19

qa-prep ran in full mode and **reused** the standing provision-only env (`PROVISION_REUSE`); ledger
upgraded to `provision_mode: full`, CH strategy `docker` on the literal `clarity`/`zod` databases.

**Seeded an org-469-shaped scenario** (org 5, persona user 3): 6 scan-only kits (`kit_scan_paths`
only, zero `sc_positions`), 1 sparse kit in Ron Johnson's shape (GPS on exactly one day), 1
healthy-GPS kit, 1 mapping-less kit. All CH fixtures anchored to `now()` against the 90-day TTL.

**19/19 items PASS** — 10 browser across 3 lanes, 6 backend, 3 resource. **Laravel log clean, zero
new bytes**, which matters because this change deliberately makes CH failures propagate rather than
return `[]`.

The two things worth recording as *proof* rather than assertion:

- **The customer's bug is fixed end-to-end in a real browser.** Three scan-only kits returned three
  rendered rows (A3) where they previously produced a blank map and a "no data found" toast.
- **Both other hypotheses in the ticket are disproven in the live app**, not just by argument: the
  dropdown lists all 9 kits (A1) and `Kit::getKits(withDeleted:false)` returns 9 (B6) — exactly what
  a `kit_metadata` soft-delete would have emptied.

**Resource lane, scoped to org 469 per operator instruction, measured at factor 1.00** — a direct
measurement, not an extrapolation: 423,375 points (vs 424,929 in production for its 8 reported kits
over 30 days) → 37.1 MB PHP payload at 92 bytes/point, 157.4 MB peak RSS, **~116 MB projected
web-request peak of a 256 MB limit (45%)**. The same case cost 325 bytes/point before the ClickHouse
pre-encoding. The budget ceiling (1.5M points) now projects **~217 MB vs ~567 MB** — that delta is
what actually closes the HIGH all three RN3 lanes raised. Org 469's All-Kits selection (1.64M points)
is refused with 422.

**One finding chased rather than waved through.** Lane 1 noted kit 23 (healthy-GPS) missing from the
All-Kits 30-day result and called it "worth a human glance". Rather than pass that along, measured
it: broad windows use `BROAD_DEDUP_METERS = 50.0` and the seeded points are **36.3 m apart**, so each
day's pair collapses to one point and `KitRangePathsQuery` drops sub-2-point segments — 7 Days
(dedup 5.0) → 12 points / 6 segments; 30 Days (dedup 50.0) → 6 points / **0** segments. That is the
documented PG-022 design; real highway GPS at 3s is 40–90 m apart and survives. **Fixture weakness,
not a defect** — and the knock-on (kit becomes tier-2 eligible, has no scan rows, contributes
nothing) is the fallback logic behaving correctly.

Also recorded: the unconditional "Please select at least one kit user" helper text (pre-existing
cosmetic, untouched) and Filament multi-select flakiness in the browser harness (not an app defect).

`state.qa.verdict = PASS`. `record.ac` = N/A / N/A / SATISFIED / N/A / SATISFIED — the three N/As
being the ratified Backyard-console ops actions, not failures.

**Still blocking `done`:** the review gate has no passing round (`rerun_required` true,
`reviewed_sha` null), so `rework-gate.sh` and `build-record-gate.sh` both correctly refuse.
2026-08-27 15:13 CDT · verified · agent QA PASS 19/19; resource envelope measured at org 469 production factor 1.00 (~116MB of 256MB)

## Phase 5 — Review round 4 (SCOPED, 3 lanes) — 94 / 93 / 82, gate FAIL on MIN 82

**Scope narrowed at the operator's instruction** to Kit User Path plus the code it directly touches.
`review.sh` has no scope passthrough (only `--exclude=`, which filters the *diff* — and Bird's Eye was
never in the diff; the lanes reached it via their exhaustiveness sweep). So RN4 ran the lanes **in-session**
via `insightlpr:adversarial-reviewer`, recorded honestly as `in-session-agent` in the manifest.

**This score is NOT comparable to RN1-RN3** — different lane mechanism AND a narrower scope. Recorded so
nobody later reads 94/93/82 as an improvement on 62.

**The scoping worked and did not muzzle anyone.** Each lane was explicitly invited to file a
`SCOPE CHALLENGE` if it thought an out-of-scope item was a blocking defect of this diff. **None did** — all
three re-derived the out-of-scope items independently and agreed. Bird's Eye's pre-existing gap drew **zero**
score deductions, against 9-14 mentions per round in RN1-RN3. Two lanes landed at 93-94.

### The converged finding — a real regression, now fixed

Two lanes independently (claude3 HIGH, claude1 MEDIUM): **the over-budget path returned 422 and discarded
already-assembled tier-1 rows that had already been proved under the ceiling.** Org 469's one-click
"All Kits" + 30 days is 1,638,423 tier-2 points, over the 1.5M ceiling — so a selection that *previously
returned 200 with kit 15349's route* returned **422 with an empty map**. It also contradicted the
controller's own stated policy ("must never wipe the kits that ARE rendering"), and claude3 found it
contradicts `okf/log.md:272-274`, which records that removing exactly that "Selection too large to render"
message for KUP All-Kits selections was the point of DEV-2177. The diff had partially undone a shipped fix.

Worth recording the lane disagreement: **claude2 saw the same code and called it "consistent with pre-diff
behaviour."** It was wrong — pre-diff there was no tier-2 count to push the sum over. A clear case where the
MIN-across-lanes gate earned its keep rather than just being harsh.

**Fixed** (operator decision): over-budget now degrades to **tier-1-only with 200** plus a logged warning;
422 is reserved for when tier 1 has nothing to show either. The shared-budget test was restructured to
assert 200 + tier-1-only — it still pins sharedness, because a per-tier budget would have admitted the
scan-only kit — and `test_over_budget_with_no_tier_one_rows_still_returns_422` was added for the refusal
that remains correct. **Mutation-verified:** restoring the unconditional 422 fails exactly the new test.
37 primary + 115 blast-radius green, Pint clean.

### Recorded, deliberately not fixed (operator decisions)

- **Bucket count is unbounded** (claude3, new and forward-looking). The budget bounds *points* while tier 2
  allocates per *bucket*; at 2 points/bucket, 1.5M points would admit 750,000 rows ≈ 400-500 MB, ~2x the
  limit, with the counter reading only 1.5M. Does not materialise today — the fleet's largest per-org bucket
  count is 55,447 (~55 MB of row overhead vs 256 MB, ~10x margin) and org 469 runs ~68 points/bucket.
  claude3's framing is worth preserving verbatim: the envelope is *"safe by an accident of current bucket
  density, not by construction."* Operator: record, don't guard now.
- **Bird's Eye `driverPositions()` swallow** (claude1). Its `kit_scan_paths` tier still fires org-wide on a
  swallowed ClickHouse throw. claude1 argues correctly that this is **not** DEV-2228 — that ticket is "this
  surface lacks a fallback", whereas this is "this surface's fallback misfires on an outage", the very class
  this diff closed, and our own comment cites `driverPositions()` as the model we mirrored. Real,
  pre-existing, on the ruled-out page, neither introduced nor worsened here. Operator: out of scope, note it
  distinctly from DEV-2228. **No ticket created**, per standing instruction.

### Two fair criticisms of the driver's own reporting

- claude2: the 217 MB projection was shown alongside a 157.4 MB **peak RSS** figure — mixing PHP-allocator
  and RSS units. `memory_limit` governs only the former. The projection is internally consistent; presenting
  it next to RSS as if comparable was sloppy.
- claude3: the measurement likely **excludes** `Cache::remember`'s serialisation copy, because phpunit forces
  the `array` store (by reference) while file/redis serialise. Pre-existing and unchanged, but it makes the
  stated margin optimistic.

Both accepted. Neither changes the conclusion; both are corrections to how the numbers were presented.

Residuals not actioned (all recorded in `state.record.review.rn4.residual_not_actioned`): the half-done
timezone whitelist, `cachedHandle()` having no direct test, the duplicated `COORDINATE_DECIMALS`, the absent
zero-geo guard, and two unasserted `Log::spy()` calls.

`rerun_required` stays true; `reviewed_sha` stays null. The gate has still never passed.
2026-08-27 15:51 CDT · reviewed · RN4 scoped: 94/93/82; fixed the converged 422 regression (over-budget now degrades to tier-1); bucket-count + Bird's Eye recorded per operator
2026-08-27 15:58 CDT · reviewed · DEV-2441 | Kit User Path: fall back to kit_scan_paths for scan-only kits (deploy-targeted)

## PR retargeted to `deploy` (operator instruction) + Phase 7 step 1 — human QA guide rendered

**Base changed from `main` to `deploy`.** Route detection had chosen `main` (bug route); the operator
corrected it. A straight `gh pr edit --base deploy` was the wrong move: `main` and `deploy` have genuinely
diverged — deploy +4 commits, main +**83** — so retargeting would have shown a **43-file** diff carrying 83
commits of unrelated work.

Verified first, then acted: all six touched files are **byte-identical** between `main` and `deploy`, and
DEV-2177's `sc_positions` rework (which this builds on) is already on deploy. So `claude/DEV-2441-deploy`
was cut from `origin/deploy` and **only those six files** applied — diff vs deploy is the same shape as the
diff vs main (6 files), and the suite passes identically on both bases (37 + 115).

A first attempt used `git checkout claude/DEV-2441 -- app/ tests/`, which pulled in all the main-only work
(DEV-2406, DEV-2414 and friends) — 43 files, exactly the problem being avoided. Reset and re-applied
file-by-file instead. Worth recording: the wholesale-directory checkout is the trap here.

Dual-PR, per the flow's hotfix pattern:
- **#373 → `deploy`** — PRIMARY. Created ready by mistake and immediately converted to **draft** via
  `pr-draft.sh`; the review gate has not passed and human QA had not run, so a ready PR on deploy was a real
  risk.
- **#372 → `main`** — companion, retitled and annotated, so the fix is not lost from main at the next
  release.

Also checked the repo's actual convention rather than assuming: of the last 40 merged PRs, 29 targeted
`main` and 7 `deploy`, the deploy ones being release candidates and hotfixes. So deploy-targeting means
shipping ahead of the release train — appropriate for a named-customer bug.

### Human QA guide

`GATE=pass` — head/wip matched after rebaselining, so **no re-prep**, which is what preserved agent-qa's
patch block. Rendered `.context/qa-human-guide.html` (revision 3) via the sonnet render sub-agent.

**Caught before rendering: the ClickHouse QA seed had been WIPED.** `clarity.kit_scan_paths` for org 5 read
**0** rows. Cause is the known trap — `RequiresClickhouse` truncates the shared literal `clarity`/`zod`
databases, and this session ran the suite many times after seeding. Human QA against that would have shown a
blank map for every kit, i.e. indistinguishable from the very bug being verified. Re-applied the persisted
idempotent seeder (9 `kit_scan_paths` + 14 `sc_positions` restored; Postgres was untouched, since tests only
wipe the *testing* DB) and added a prominent standing-risk warning to both the plan and the guide with the
one-line re-seed command.

Guide contents: 8 env rows (7 ok, 1 warn — the truncation standing risk, 0 bad), 4 agent findings folded, 10
step pitfalls, 5 curated screenshots, 16 steps across Happy path → Edge cases → ops-only (not pass/fail) →
Adjacent (optional, last). `hp-4` and `hp-6` are presented as **decisions, not pass/fail**. The resource
envelope is surfaced as `measured` with the factor-1.00 provenance.

Two judgement calls the sub-agent made well and worth keeping: it declined to attach agent screenshot A9 to
`hp-8` because that shot predates the over-budget fix and would misrepresent it, and it flagged that the
folded agent findings carry `run=c70d55d3` — the pass before that fix — rather than letting them read as
current.

Phase → `handoff — awaiting human QA`. PRs stay draft; Jira not transitioned; env left standing.
2026-08-27 16:09 CDT · verified · PR retargeted to deploy (#373 primary, #372 companion); human QA guide rendered rev 3; CH seed re-applied after test-run wipe

## Laravel-conventions audit + second production-cardinality pass (2026-08-28)

### Conventions audit — no MUST FIX; five SHOULD FIX applied

Audited against `okf/concepts/laravel-boost-guidelines.md` (the standard this repo is reviewed against),
`CLAUDE.md`, `clickhouse-query-patterns.md` and `testing-conventions.md`. Clean on every black-letter rule:
explicit return/param types on all new methods including private helpers, braces everywhere, no `env()`
outside config, validation left in the Form Request, named routes, PHPUnit-only, permissions team id handled
via the existing helper. Pint clean.

Applied all five idiom findings:

1. **Duplicate Kit query removed.** `kitsById()` and `kitsByMapping()` each ran `Kit::query()->whereIn('id',
   $kitIds)->get()` — two round trips, two sets of hydrated models. The audit checked the precedent the
   docblock cited and found it says the opposite: `ClarityScoutBirdsEyeController::kitsByMapping()` takes an
   **already-fetched** collection and never re-queries. Replaced with `mappingIndex(array $byId)`, derived
   in memory from the single fetch. `Kit::query()` now appears **once** in the controller.
2. **`COORDINATE_DECIMALS` moved to the top of the class.** It was declared mid-class between two methods;
   every other `app/Clickhouse/Queries/*` class groups constants before any method. (First attempt used a
   greedy regex that relocated the wrong block — caught it, restored from HEAD, redid it with an exact-string
   replace. Worth recording: `php -l` passed on the mangled version, so syntax checking alone would not have
   caught it.)
3. **`@param array{org_id?: int, ...}` corrected to `org_id: int`** in three docblocks. `filteredQuery()`
   dereferences `$arguments['org_id']` with no `??` fallback — unlike every other key in the method — so the
   optional marker was simply wrong.
4. **Over-commentary trimmed**, which is what I had asked the audit to be blunt about:
   - The `over_budget` comment carried the org-469 anecdote and the DEV-2177 backstory. That is PR/ticket
     archaeology baked permanently into source; trimmed to the durable rule.
   - A 7-line inline block inside `scanPathFallbackRows()` restated the method's own docblock almost verbatim
     and used `{@see}` inside a `//` comment, where it is inert text rather than a real cross-reference. Cut
     to one line.
   - Controller inline comments: **55 → 46**. The audit explicitly argued to KEEP the `Carbon::utc()`
     mutation note, the cachedHandle-not-query note and the 200-empty-vs-503 note — those clear the "something
     very complex" bar; the two above did not.
5. **Hardcoded line-number cross-references dropped** (`(:169-215)`) in favour of `{@see}` — they go stale
   silently the next time the referenced file moves.

Explicitly confirmed FINE AS-IS, with the sanctioning source, so nobody "fixes" a non-problem later: the
string-concatenated JSON response (PG-022's prescribed pattern, mirrored at
`ClarityScoutBirdsEyeController:651`), `report()` paired with `Log::` (matches
`CloseInactiveShiftsCommand:99` and `ScoutRecordCheckIns:112`, not the simpler one-shot API controllers),
`session()?->has('admin_impersonator')` (verbatim from `ClickhouseQuery::query()`), `protected`
`buildCacheKey()`, partial `@param` coverage on scalars (matches `KitRangePathsQuery::segmentsQuery()`), the
literal-union array shape, and the `Log::spy()`/`ReflectionProperty` test patterns.

### Cardinality pass 2 — ticket solved, DEV-2177 not regressed

**Solved:** all 8 reported kits now have a source — 6 via tier-2 fallback (previously blank: 38,508 /
19,525 / 44,843 / 12,439 / 140,238 / 18,283 scan points), and 10588 + 15349 via tier-1 GPS unchanged.

**No DEV-2177 regression.** Tier 2 fires only for kits with zero tier-1 segments, so the continuous-GPS
population is structurally untouched. Fleet-wide over 30 days: **610 kits** GPS+scan (tier 1 unchanged),
**0** GPS-only, **494 kits scan-only that NEWLY RENDER**, 4,462 with neither (unchanged), 5,566 total.

**Shared-budget interaction** — the only place a regression could hide. Per-org worst case (All Kits, 30d)
against the 1.5M ceiling, tier 1 proxied by 3s-decimated distinct buckets: of 269 orgs with data, **4** have
tier 1 alone over (unchanged before and after), **5 are in the band the degrade fix protects** (418753, 1912,
1143, 24715, 1688), and 260 fit entirely. Those 5 are concrete proof the round-4 finding was real rather than
hypothetical.

### A wrong claim of mine, corrected

I had asserted — in the PR body, the journal and to the operator — that org 469's All-Kits + 30-day selection
*"previously returned 200 with kit 15349's route"* and regressed to a 422. **Not established.** The figure
behind it used only the 8 reported kits' GPS (~23K rows); org 469 has **151** kits and the rest carry heavy
GPS — 6,961,015 raw `sc_positions` rows, decimating to 2,404,245. That decimated figure is *above* the
ceiling; applying PG-022's ~40-45% dedup reduction puts it ~1.3-1.4M, *below*. It straddles the boundary and
cannot be settled without running the real `countPoints` pipeline against production.

What holds regardless: the fix cannot make org 469 worse (either tier 1 was already over — unchanged — or it
fits and the degrade protects it), and the ticket's actual reported scenario (8 kits, 423,549 points) is
comfortably inside the budget. Correction posted to PR #373 and recorded in `.context/qa-prod-cardinality.json`.

**Durable lesson:** raw row counts are not rendered point counts on this surface. Decimation alone is a ~3×
factor (measured ratio 0.345). I had been reasoning from raw rows.
2026-08-28 07:46 CDT · reviewed · conventions audit applied (single Kit query, const placement, array shapes, comment trim 55->46); cardinality pass 2: 8/8 kits render, 610 GPS kits unchanged, 494 newly render; corrected a wrong org-469 claim

## Phase 7 — Handoff. DONE.

Human QA completed by the operator: **clean, nothing to report**. hp-4 ratified — kit 10588 rendering only its
single GPS day is acceptable to ship; per-day top-up stays tracked on DEV-2341.

**Review gate: explicit operator override, recorded not hidden.** The gate never passed — MIN 62, 62, 62, then
82 against a threshold of 90. `reviewed_sha` and `rerun_required` were set by operator decision on 2026-08-28,
and `state.record.review.gate_override` carries the accepted residuals alongside the countervailing evidence
(agent QA 19/19, human QA clean, cardinality pass confirming 8/8 kits fixed and no DEV-2177 regression, the
resource envelope measured at production factor 1.00, every guard mutation-verified, conventions audit with no
MUST FIX). Anyone reading the record sees an override, not a green gate.

- **Build Record** posted as Jira comment **38636** — assembled from `state.record` and the QA artifacts, not
  from session memory. Includes the what/why per change, all 11 resolved questions with sources, what changed
  because of review, the full derivation table, the override with its accepted residuals, and the correction to
  the org-469 claim.
- **PR #373 → `deploy` undrafted** (primary, ships). **#372 → `main` left DRAFT** with a comment explaining it
  is a safety net: the release process merges deploy back into main, so it should be **closed** once that
  merge-back is confirmed rather than merged (merging both would conflict).
- **Jira To Do → In Progress → In Code Review**, both via the Atlassian MCP. Recorded as `pipeline-mcp` — the
  designed fallback working, not a human intervention. The DEV board's transitions are all `isGlobal: true`, so
  the flow's "offered means not there yet" idempotency assumption does not hold; decided from the fetched
  status each time.
- **Teardown: MANUAL and surgical, deliberately not `qa-complete`'s drop path.** This worktree used the SHARED
  LITERAL `clarity`/`zod` ClickHouse databases, and the same container hosts `clarity_claude_dev_2409` and
  `clarity_claude_dev_2426`. Dropping databases or stopping the container would have broken two other
  worktrees. Deleted only the seeded rows (verified 0 remaining), dropped the two per-worktree Postgres DBs
  behind a name guard (shared `zod`/`zod_testing` verified intact), restored `MAIL_MAILER`, unlinked the
  per-worktree Herd site (6 other sites verified still linked), deleted the ledger.
  `CLICKHOUSE_HOST` deliberately left local: the original Cloud password was replaced in Phase 4 and is not
  recoverable, so restoring the host alone would leave a broken half-config — and the pipeline's own rule is
  never to wire Cloud into a worktree.
- Both gates **pass**: `REWORK_GATE` (HEAD == reviewed_sha) and `BUILD_RECORD_GATE` (id=38636).

Final: 6 files, 152 tests green / 0 skipped, Pint clean.
2026-08-28 07:57 CDT · done · DEV-2441 complete: Build Record 38636, #373 ready on deploy, Jira In Code Review, QA env torn down surgically
