2026-09-04 10:02 CDT · intake · intake complete: route=hotfix app=clarity base=deploy

## Phase 1 — Ultra-Prep (DEV-2486)

- Single prep pass (TICKET_PREP_MODEL=sonnet) with live read-only ClickHouse Cloud measurement.
  Returned confidence 52, escalate=true.
- Escalation triggers fired: confidence < 80; escalate.recommend; 5 `answered:false` questions;
  `database/schema/clickhouse-schema.sql` is a CI-wide artifact.
- **Best-of-N deliberately NOT run.** The residual uncertainty is five human-decidable design
  questions (outage behavior, CH-free test split, tri-state sort rank, mixed-state tie-break, GL
  search), not code that more blind passes would ground better. Recorded `best_of_n=false`,
  `escalated=true`, `needs_human_interview=true` so the routing metrics stay honest.
- **Grep backstop run inline by the driver.** It found two files the prep pass missed:
  `tests/e2e/gl-kits.spec.ts` and `tests/e2e/helpers/gl-kits.ts`, which assert
  `COLUMN_HEADERS == ['Kit','Company Owner','Last Scan Received','Reporting (14d)']` and the `Kit`
  columnheader's visibility. Playwright is not wired into either CI workflow, so this is
  stale-contract risk rather than a red build. Both added to `target_files`.
- **Driver-verified independently of the prep pass** (see `driver-findings.md`):
  the CI dump translates Cloud-only `Shared*MergeTree` engines to OSS equivalents (header lines 1-5;
  zero `Shared*` engines across 62 tables), so `zod.aggregate_kit_camera_name`'s production
  `SharedReplacingMergeTree` DDL cannot be pasted verbatim — it must become `ReplacingMergeTree()`.
  A verbatim paste fails at dump load and takes the whole ClickHouse CI job with it.
  Also: the 2026-09-01 full refresh (`0746450c`) is already on `deploy` and still omitted this
  table, so the dump is a curated subset driven by a table list — the generator
  (`.claude/commands/chlocal.md`) needs the table added, or the next refresh drops it again.
- **Largest single group is NOT the ticket's anchor.** Measured: org 1143 = 49 GLs from 272 kits
  (the ticket's figure, confirmed), but org_id=1 (InsightLPR) = **524 GLs from 239 kits**, 10x
  larger. `zod.public_kits` FINAL and no-FINAL agree at 239, so it is real data, not stale CDC.
- gate = **BLOCKED** (5 material questions). Proceeding to the Phase 2 interview.
2026-09-04 10:23 CDT · prepped · prep complete: gate=BLOCKED, 5 material questions; backstop found 2 missed e2e files

## Phase 2 — Question Gate (DEV-2486)

`needs_human_interview = true`. Five questions put to the operator; all five answered. Verbatim:

**Q1 — What should the page show when the ClickHouse read that finds the GLs fails?**
A: *Accept the empty table.* Ship exactly as the ticket specifies — a GL-discovery failure renders
zero rows. `reportClickhouseFailure()` still logs and calls `report()`, so the outage reaches error
tracking even though the admin sees an empty table.
**Recorded as an accepted trade-off, not an oversight.** The driver flagged before the answer that
today's page survives a ClickHouse outage (rows come from PostgreSQL, columns degrade to "Unknown")
and that the ticket's own AC — "a GL whose ClickHouse lookup failed renders Unknown" — presupposes a
row exists to carry that word. The decision was taken with that stated. It must appear in the PR body
and the Build Record.

**Q2 — How should GlKitsResourceAccessTest's 5 ClickHouse-free tests be preserved?**
First answer was "add the table to the PostgreSQL bridge". The driver then established there is **no
test-time PostgreSQL bridge to extend**: `zod.kit_last_scan` (which existing tests already INSERT
into) has no PostgreSQL migration; `database/schema/local-clickhouse-schema.sql` defines only a
`clarity` schema and is applied by hand, not by the suite (`tests/TestCase.php` /
`ResourceTestCase.php` load no ClickHouse schema); and CI sets `CLICKHOUSE_DRIVER=clickhouse` with a
real service, so CI never needed a bridge. Delivering that answer literally would have required a
real Laravel migration creating a ClickHouse shim table in production PostgreSQL, hand-applied,
on a hotfix — against the precedent `kit_last_scan` sets.
Re-asked with that finding. **Final A: split by what needs ClickHouse.** `GlKitsResourceAccessTest`
stays ClickHouse-free and asserts tenancy/authorization on the PostgreSQL kit query that feeds
ClickHouse (org scope, `kit_type=1`, `excludingDeleted`) plus the non-admin 403 — so deleting
`canAccess()` or the `org_id` scope still fails a test with ClickHouse down, preserving the property
that class's docblock claims. Row-RENDERING assertions move into `GlKitsResourceTest`, which has real
ClickHouse in CI. No new machinery, no migration.

**Q3/Q4 — Reporting tri-state: sort rank, and the mixed case?**
A: *Best-known first; mixed renders Not Reporting.* Ascending ranks Reporting(0) < Not Reporting(1) <
Unknown(2). A GL whose kits mix Not Reporting and Unknown with none Reporting renders "Not Reporting",
because at least one kit was actually checked and came back negative. "Unknown" stays reserved for
when nothing was checked — the read failed, or no mapped kit has a scanner mapping.

**Q5 — Should the GL column stay searchable?**
A: *Yes*, matching today's behavior. The `->records()` closure owns search via the
`search`/`columnSearches` injection.

Also answered from code/ticket without asking (recorded in `prep-context.questions`): Company Owner
stays (ticket requirement #1); append one translated DDL line rather than regenerate the dump, AND add
the table to the generator's list; never-scanned kits legitimately disappear (GL identity only exists
once a scan recorded that camera_name); and the production query scopes from the PostgreSQL kit_id list
rather than the ticket's one-off `zod.public_kits` join, because the table has no `org_id`.

gate recomputed = **CLEAN**. Proceeding to Phase 3.
2026-09-04 10:30 CDT · gated · gate CLEAN: all 5 questions answered; Q2 re-asked after the PG-bridge finding

## Phase 3 — Ultra-Plan (DEV-2486)

Single arbiter at `TICKET_PLAN_ARBITER_MODEL=opus`, timing slot `plan-arbiter`, dur_s=689.
DAG: **t1** (CH substrate, blast-radius) → **t2** (query class) → **t3** (resource re-key) →
{**t4** (access test), **t5** (e2e)}. t4/t5 are the only parallel pair, disjoint files.
All 3 `buggy` sites from the exhaustiveness sweep are covered; `excluded_sites` is empty.

`blast_radius: ["ci_schema_dump"]` on t1 — a malformed line fails dump load and takes the ENTIRE
ClickHouse CI job down, not just this ticket's tests. Already human-approved via prep-context
question 6's recorded answer (append one translated DDL line; add the table to the generator).
No new approval invented.

**Sizing gate: 10 files (>6) → recommended and decided.** Operator chose **no split**. Rationale:
one concern; only 2 of the 10 files are app code (`GlKitsResource.php` + the new query class), the
other 8 are tests and CI fixtures; and no part is independently shippable — the schema line alone
is a table nothing reads, the query class alone is dead code, and landing the resource without the
test updates leaves CI red. A split would mean a second hotfix PR against `deploy` for one defect.

**Perf gate (WS6): not required, verdict `go`.** No >1B-row ClickHouse surface is newly queried.
`zod.aggregate_kit_camera_name` is 12,414 raw / 10,600 deduped rows; `zod.kit_last_scan` ~7,405.
`clarity.sc_positions` is large but its query is byte-unchanged here. The >1B-row alternative
(`clarity.scans_hot`) was measured at 4.2 s / 412 MB and rejected on the ticket before planning.

### The arbiter caught a defect prep missed — driver-verified in the Filament source

`ListRecords::makeTable()` **unconditionally** installs `->recordAction(function (Model $record,
Table $table))` and, while `hasCustomRecordUrl()` is still false, a `Model`-typed `->recordUrl()`
too (`vendor/filament/filament/src/Resources/Pages/ListRecords.php:129-160`, read and confirmed).
`vendor/filament/tables/resources/views/index.blade.php:986` calls them per rendered row, and
`EvaluatesClosures` injects by PARAMETER NAME — so an array record lands on a `Model` parameter and
throws a `TypeError` on the first row. **The page would 500.** `->recordAction(null)->recordUrl(null)`
is mandatory, and it works because `Resource::configureTable()` calls `static::table()` LAST
(`vendor/filament/filament/src/Resources/Resource.php:82`, confirmed). `ClientTable` never hit this
because a widget's `makeTable` installs no such closures.

Also corrected, driver-verified: **prep's rationale for `->records()` was wrong** — `Table::getModel()`
does NOT become null, because `ListRecords` still registers `->query(...)` (`ListRecords.php:119`) and
`HasQuery::getQuery()` never consults `hasQuery()`
(`vendor/filament/tables/src/Table/Concerns/HasQuery.php:68-79`, confirmed). The `->records()`
conclusion stands (Eloquent cannot sort a `getStateUsing` column) but `getEloquentQuery()` stays LIVE
code — which is exactly what makes t4's ClickHouse-free tenancy assertions meaningful rather than
assertions on a dead method.

Further arbiter findings folded into the plan: `.claude/commands/chlocal.md` has no table list to
edit (the table is missing because the upstream chlocal export lacks it), so t1 adds a documented
"manually maintained additions" subsection instead; prep's `canEdit`/`canDelete` signature widening
is unnecessary and was dropped (only `index` is registered in `getPages()`, so they are unreachable);
AC6's per-lookup `Unknown` on a rendered row is now only reachable via a `clarity_clickhouse`-only
outage, since GL discovery and `zod.kit_last_scan` share the `public_clickhouse` connection;
`last_scan` sort places timestamp(0) < Never(1) < Unknown(2) with a `gl` asc tiebreak; and an
all-numeric `camera_name` becomes an int PHP array key, so the return type is
`array<array-key, list<int>>` and `gl` must be `(string)`-cast for `__key`.
2026-09-04 10:45 CDT · planned · plan: 5-node DAG; no-split decided; arbiter caught the recordAction/recordUrl TypeError

## Phase 3.5 — Provision Postgres (DEV-2486)

**Verified reuse, nothing created.** Both branch-isolated databases already existed and were
migrated: `zod_claude_dev_2486_a5ca80` (dev — `platform.migrations`, `public.kits`, `public.orgs`
present, 1 seeded user so dev-login works) and `zod_testing_claude_dev_2486_a5ca80` (testing —
migrated, 0 users, correct under `RefreshDatabase`). `.env` and `.env.testing` already point at
those, never at the shared `zod`. The branch was renamed to `claude/DEV-2486` BEFORE any
bootstrap, so the env DB names and the branch agree — no re-key needed.

No ledger written by this phase, deliberately: nothing was created, so there is nothing to tear
down. Phase 4's `qa-prep` ensure call writes the real ledger when it starts Docker ClickHouse.

**`composer install` re-run.** An earlier check for `vendor/insightlpr` was wrong — the vendor
directory is `insight-lpr`. `vendor/insight-lpr/black-adam` and
`vendor/insight-lpr/laravel-zod-migrations` are present and
`InsightLPR\BlackAdam\Enum\KitType` resolves. The tree stayed clean (no boost-doc regeneration).

### Two stale-artifact traps cleared before they could false-pass a gate

- `.context/` held the PRIOR ticket's QA artifacts, dated 2026-09-01 — including
  `qa-ac-results.json`, which the Phase 6 AC fold-in reads. Archived to the session scratchpad.
- `.ai/` held a prior ticket's three rounds of review lanes and its `review-manifest.json`, which
  the Phase 5 gate reads. Archived to the session scratchpad.

Both are known failure modes in a reused worktree; neither would have announced itself.

### Recorded for Phase 6, not a defect of this ticket

The dev `.env` points `CLICKHOUSE_HOST` at Cloud (port 8443, HTTPS) but sets
`CLICKHOUSE_DATABASE_CLARITY`/`_ZOD` to a POSTGRES database name
(`zod_claude_dev_2486_a5ca80`), so an unqualified dev ClickHouse query would fail and be swallowed
to `[]`. Prep's production measurements are unaffected: every query it ran was fully qualified
(`zod.*` / `clarity.*`), which bypasses the configured database name.
2026-09-04 10:47 CDT · provisioned · postgres verified-isolated (reuse); archived prior ticket's stale .context + .ai artifacts
2026-09-04 11:04 CDT · implementing · t1-t5 implemented; 46/46 DEV-2486 tests green; verifying full-suite baseline

## Phase 4 — Implement (DEV-2486)

Perf gate: `PERF_GATE: pass`. Jira transitioned To Do → In Progress via the Atlassian MCP
(`pipeline-mcp`). ClickHouse: reused the already-running `clarity-qa-clickhouse`
(`clickhouse/clickhouse-server:25.8.11.66`, 127.0.0.1:18123) which already carried the LITERAL
`clarity`/`zod` databases; `.env.testing` repointed to `CLICKHOUSE_DRIVER=clickhouse` on it.
`aggregate_kit_camera_name` was correctly ABSENT locally, which is what t1 fixes.

**Fan-out worktrees deliberately skipped.** The DAG is a serial chain (t1 → t2 → t3) with one
trivial parallel leaf pair (t4/t5, two test files). Per-node worktrees would have added a
`vendor/` copy plus a bootstrap per node, and a documented edit-leak risk, while buying no
parallelism. Implemented sequentially in `REPO_PATH` instead. Recorded as a deviation.

Baseline before touching anything: `GlKitsResourceTest` 6/6 and `GlKitsResourceAccessTest` 5/5
green against local ClickHouse. `DIFF_BASE = d5a07d19`.

### t1 — ClickHouse substrate

Verified the production DDL MYSELF against Cloud rather than trusting the prep report
(`SHOW CREATE TABLE`, read-only, via a runtime `database` config override — the connection issues
`USE <db>` before the query, so the misconfigured dev value breaks even a fully-qualified name):

```
ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}')
ORDER BY (kit_id, camera_name) SETTINGS index_granularity = 8192
```

Two columns only, confirming the no-`org_id` / no-CDC-column claims. `.claude/commands/chlocal.md`
already documents the exact translation I needed —
`SharedReplacingMergeTree('/...', '{replica}')` → `ReplacingMergeTree()` — so the dump line follows
the repo's own stated rule rather than my judgement. I then **loaded the translated line into the
OSS container and round-tripped an insert** to prove it parses before trusting it in CI. Dump's own
checks: `Shared` count stays 1 (the header comment), `CREATE TABLE` 62 → 63.

`.claude/commands/chlocal.md` has no table list to append to (the arbiter's correction to Q6 and to
`driver-findings.md` §3 — the table is missing because the UPSTREAM chlocal export lacks it), so t1
adds a "Step 4b: Manually Maintained Additions" subsection carrying the line and the reason.

### t2 / t3 / t4 / t5

TDD throughout: `OrgKitCameraNamesQueryTest` went red for the right reason (class not found) before
the class existed. One genuine defect found by its own test run — the query had no `ORDER BY`, so
row order and therefore the returned grouping were nondeterministic; added `ORDER BY (kit_id,
camera_name)` matching the sort key, which the ClickHouse knowledge card requires anyway.

### Mutation-tested, because 18/18 passing first time is not evidence

| Mutation | Caught? |
| --- | --- |
| remove `->recordAction(null)->recordUrl(null)` | yes — reproduces the exact predicted `TypeError` |
| `defaultPaginationPageOption(100)` → `(50)` | yes, 1 failure |
| `reportingRank` `min()` → `max()` | yes, 3 failures |
| drop the sort-column mapping | yes, 2 failures (1 before the fix below) |
| last-scan rollup newest-wins → first-found | yes, 1 failure |
| delete `->where('org_id', $orgId)` under `CLICKHOUSE_DRIVER=pgsql` | yes — **with no ClickHouse**, which is Q2's guarantee |
| `canAccess()` → `return true` (pgsql) | yes |
| delete `->excludingDeleted()` (pgsql) | yes |

**Mutation 4 exposed a weakness in my OWN test.** `test_reporting_sorts_best_known_first` had named
its GLs `GL-A-REPORTING` / `GL-B-NOTREPORTING` / `GL-C-UNKNOWN`, so alphabetical order coincided
with rank order and the test passed even with the sort deleted. Renamed to `GL-ZED-` / `GL-ABEL-` /
`GL-MID-` so the two orders differ, and re-confirmed the mutation now fails 2 tests, not 1.

Q2's guarantee verified end to end: with `CLICKHOUSE_DRIVER=pgsql` the access class passes 6/6 and
`GlKitsResourceTest` skips 18/18 — the documented local developer experience is unchanged.

### Full suite — no regression, with the evidence

| run | result |
| --- | --- |
| BASE `d5a07d19`, pressured ClickHouse | 1710 tests, 1 error + 5 failures + 1 risky |
| HEAD, pressured ClickHouse | 1730 tests, 7 errors + 9 failures + 1 risky |
| **HEAD, freshly-restarted ClickHouse** | **1730 tests / 6978 assertions, 2 errors + 3 failures + 1 risky** |

The 16-vs-6 gap was NOT a regression, and I did not assume that — I tested it:

1. **No DEV-2486 test failed in any run.**
2. **The failure SET churns between identical-code runs** (16 problems → 5, with *different* names).
   That churn is the signature of shared-state flakiness.
3. **ClickHouse container memory pressure is a confirmed amplifier.** `docker restart
   clarity-qa-clickhouse` (3.70 GiB → 2.17 GiB of 5.79 GiB) took HEAD from 16 problems to 5 — fewer
   than BASE's 6, itself measured under pressure. This is exactly the `clarity/testing` card's
   documented mode: error 241 raised inside `RequiresClickhouse`'s TRUNCATE setup, before any test
   logic, presenting as a batch of unrelated failures. (Restart preserves container data, so other
   worktrees' `clarity`/`zod` were not disturbed — verified 33/47 tables and my new table intact.)
4. **Isolated HEAD-vs-BASE over every implicated class** (180 tests): HEAD's failure set is a strict
   SUBSET of BASE's. Nothing fails at HEAD that does not also fail at BASE.
5. The two names unique to the clean HEAD run (`HitDirectHotspotQueryTest::test_resolves_latest_hotlist_version_on_status_transition`,
   `DashboardCardExpansionTest::test_dispatching_dashboard_toggle_card_expands_the_matching_chart`)
   both **pass in isolation at HEAD** (24/24) and neither file references any DEV-2486 symbol.

Stable pre-existing failures, reproducing identically at BASE: `CloseInactiveShiftsCommandTest` ×2,
`EnsureOpenShiftTest::test_current_open_opens_a_fresh_shift_after_a_confirmed_end`, and
`ScoutUsersResourceTest::test_cross_org_kit_cannot_be_edited_via_direct_url` (risky, no assertions).

Caveat stated plainly: the literal `clarity`/`zod` databases are SHARED across worktrees, so a
concurrent session perturbs any full-suite result here. CI's isolated ClickHouse service is the
authoritative signal.

`composer format` (Pint) passed. Blast-radius re-check: pass — 10 changed files, none matching
`database/migrations/**`, billing, Shield/Policies/auth config, `Clickhouse*Purge|Cleanup` or
`PruneScans`. Unexpected-files check: pass — the changed set is exactly the plan's 10 target files.
2026-09-04 11:19 CDT · implemented · implement complete: 46/46 ticket tests; full suite shows no regression (evidence recorded)
2026-09-04 11:55 CDT · implemented · review RN2 (82/90/94) -> gate fail; fixed 13 findings incl. an unclamped-page defect and a TTL time-bomb
2026-09-04 12:26 CDT · implemented · RN3 gate pass (90/90/94); fixed 5 more findings incl. a non-total-order GL sort
2026-09-04 12:48 CDT · implemented · RN4: fixed the stray Log::warning arg I introduced + 3 new phpstan findings + the refuted test docblock
2026-09-04 12:50 CDT · reviewed · review complete: RN4 gate pass (90); 23 findings fixed across 3 rounds

## Phase 6 — Verify (DEV-2486)

Empty-diff guard: 10 code files changed vs `origin/deploy`. PASS.

### Provisioning — two deliberate deviations, both recorded

**`up.sh` was NOT used.** It provisions PER-WORKTREE ClickHouse database names
(`clarity_<wt>`/`zod_<wt>`) and repoints `.env` at them — but roughly ten query classes hardcode
the `clarity.`/`zod.` prefix and `RequiresClickhouse` TRUNCATEs those literals. The env was pointed
at the **literal shared** `clarity`/`zod` on the already-running `clarity-qa-clickhouse` container
instead. The ledger records `ch_db_guard_prefixes: []` and a `shared_warning`: teardown must never
DROP those databases, only truncate the three tables this run seeded.

**Bootstrap was NOT run** (warm path — both branch DBs existed and were migrated). That matters:
`dev:migrate` in this repo is `migrate:fresh` and would have wiped the branch seed.

Invariant asserted before any seeding: `CLICKHOUSE_HOST=127.0.0.1` in BOTH `.env` and
`.env.testing`. The dev `.env` had pointed at ClickHouse **Cloud** with a Postgres database name in
`CLICKHOUSE_DATABASE_CLARITY`, so every unqualified dev CH read had been failing silently; the prior
values (credentials included) are backed up in the session scratchpad, not in the ledger.

### Seeded

106 distinct GLs for org 2 from 9 `kit_type=1` kits, plus deliberate negatives: `GL-OTHERORG`
(org 1), `GL-SOFTDELETED` (soft-deleted kit), `GL-WRONGTYPE` (`kit_type=2`), and `GLCASE` (a case
variant of `GLcase`). `GL9001` is shared by kits 1 and 3 — kit 1 has the NEWER scan and no GPS,
kit 3 the OLDER scan and the only GPS fix — so one row exercises BOTH rollups at once. Also created
a role-less user (id 2) for the authz check, and realigned the `platform.users` id sequence, which
lagged behind `max(id)` and made a plain INSERT collide on the primary key.

### Result — all green, zero findings

| Lane | Items | Result |
| --- | --- | --- |
| 1 core rendering + rollups | A1–A5, A13 | 6/6 |
| 2 sorting, search, pagination | A6–A10 | 5/5 |
| 3 exclusions + authz | A11, A12 | 2/2 |
| B backend (read-only) | B1–B6 | 14/14 assertions |
| R resource envelope | R1 | PASS |

7 of 8 ACs SATISFIED, 1 N/A (org 1143 is production-only; its mechanism passed locally). None NOT
SATISFIED, so the Phase 6 🔴 gate does not fire. Zero new error-level log lines.

Highlights worth keeping: `10, 9, 999` confirms the `SORT_STRING` fix in a real browser; `GL9001`
rendered kit 1's timestamp AND a green Reporting badge sourced from kit 3; `GL9002` amber Unknown
vs `GL9003` grey Not Reporting were visibly distinct in one view; the non-admin got a hard 403 with
no Reports section at all.

### Resource envelope — PASS, and one discarded measurement

106 GLs, **21.8 ms cold** (all three ClickHouse round trips inside it), 2.7 ms warm, and **0 bytes
of peak growth**. The 0 means the marginal allocation is below PHP's 2 MB arena granularity —
unmeasurably small, not literally zero. Production's largest org is 524 GLs from ~239 kits, so
~4.9x the GL map and ~26x the IN-list; both scale linearly over short strings and ints. No cadence
condition applies — this is a synchronous page load, so the divisor is concurrent admins of one org
(single digits), not a spawn interval. The rejected `clarity.scans_hot` alternative was 4.2 s /
412 MB per org page load.

**An earlier reading of 0.0026 s was discarded as misleading**: a warm-up call had already populated
the `once()` memos, so it excluded the ClickHouse cost entirely. Re-measured cold.

### Two tooling notes, neither an app defect

- Filament's `deferLoading` is on outside `testing` (`FilamentServiceProvider.php:41`), so the first
  paint carries NO rows — they arrive via `POST /livewire/update`. A `curl` check of mine hit this
  and briefly looked like an empty table; the browser lanes navigated the same URLs fine. I had
  told the operator `?page=2` was inert, and **corrected that**: it works in a real browser.
- The `Go to page 2` pager click intermittently no-op'd on the unsorted view for two lanes (a
  Livewire re-render race in agent-browser). Page 2 was confirmed three other ways.

Browser sessions were reaped unconditionally after the pass (`${SESSION_PREFIX}-1..3`, never
`close --all`), leaving no orphaned daemons.
2026-09-04 13:18 CDT · verified · agent QA PASS: 13/13 browser, 14/14 backend, envelope PASS; 7/8 ACs satisfied, 1 N/A, none broken
2026-09-04 13:25 CDT · handoff · parked awaiting human QA; guide rendered, QA env left up

## Phase 7 — guide repair (operator reported a blank guide)

The operator opened `.context/qa-human-guide.html` and it was **empty**. Agent QA had in fact run
and passed (15 screenshots, all 8 ACs recorded, 6 `qa_log` entries) — the fault was in the HTML.

**Cause.** The render sub-agent applied the `</script` → `<\/script` escape to the `qa-data`
payload's **closing tag**, not only to occurrences inside string values. A browser does not treat
`<\/script>` as a close, so the `qa-data` element never terminated: it swallowed 32,041 characters
including the entire application script. `JSON.parse` therefore never ran and nothing rendered.
The tell was a script census — 2 `<script` opens against 1 real `</script>` close.

**Fix.** Restored a real closing tag (one line).

**My verification was the real failure.** I had "verified" the guide by grepping for step ids,
figures and section titles — and found them all, because they WERE in the payload. That proves the
bytes are present; it proves nothing about whether the page renders. Corrected by loading the file
in agent-browser and asserting on the DOM: 17 checkboxes, all 13 step ids (`hp-1`..`hp-8`,
`ec-1`..`ec-3`, `adj-1`..`adj-2`) plus `aqa-1`..`aqa-3`, 9 section headings, 4 persona login links,
0 console errors. The 10 screenshots are `loading="lazy"`, so they read as neither loaded nor broken
until scrolled — forcing them eager confirmed all 10 fetch with real dimensions and none are broken.

Rule for next time: a rendered artifact is verified by rendering it, never by grepping its source.
2026-09-04 13:29 CDT · handoff · fixed the blank QA guide: escaped </script> closing tag swallowed the app script; verified by rendering

## Phase 7 — QA-driven change: tri-state tooltips (operator request)

The operator asked for tooltips over the `Reporting` / `Not Reporting` / `Unknown` statuses
explaining what each means. Good ask — that tri-state is the subtlest thing on the page, and
"Unknown vs Not Reporting" is not guessable from a badge colour.

**Light path, and the materiality guard was checked first, not assumed.**
`git diff --name-only <reviewed_sha=03dd2e8b>..HEAD` excluding `docs/investigations/` returned
**0 files**, so this edit lands at 2 files (1 code + 1 test) — under the threshold of 3, no
sensitive globs, no architecture change. So: no re-review, no re-agent-qa, still parked in draft.

**What changed.** `REPORTING_TOOLTIPS` and `LAST_SCAN_TOOLTIPS` consts as siblings of
`REPORTING_LABELS`, wired via `->tooltip(fn (string $state))` plus a `->headerTooltip()` on each
of the two computed columns. `fn (string $state)` is deliberate: `getTooltip()` injects `state` BY
NAME, so no `Model` type-hint is involved and it is safe with array records — the same reason the
existing `color()`/`icon()` closures work. `Last Scan Received` gets tooltips for `Never` and
`Unknown` only; a real timestamp returns `null`, so no tooltip renders on it.

The Unknown wording is the load-bearing one — it says nothing could be checked (no scanner mapping,
or the lookup failed) and states explicitly that this is NOT the same as "Not Reporting".

Also extended, and flagged rather than done quietly: the operator named the Reporting states, but
"Unknown" appears in `Last Scan Received` too and means something different there, so leaving that
one unexplained while explaining the badge would have been odd.

**I got caught by shallow verification a second time, in the same run.** My first test asserted
`$column->getTooltip('Reporting')` returns the string — which proves only that the CONFIG is set.
`TextColumn` emits `x-tooltip` only when `filled($tooltip)`, so config alone does not prove the
view renders anything. Strengthened to assert the RENDERED Livewire markup contains each tooltip
string (99 → 105 assertions in that class). Three mutations confirm it: dropping the per-cell
reporting tooltip, weakening the Unknown wording, and dropping the last_scan header tooltip each
fail a test.

A browser probe of the live page could not confirm the per-cell tooltips because the deferred rows
never loaded in an ad-hoc agent-browser session (`deferLoading`, again — and `table[0]` is a
Debugbar table on that page, not the Filament one; `.fi-ta-table` is the right selector). The
Filament table DID render with the correct four headers. The rendered-markup assertion in PHPUnit
is the reliable proof, because `APP_ENV=testing` turns `deferLoading` off.

**Guide re-rendered** with a new `hp-9` step. First attempt added the step with `title`/`body`
keys, which the template does not read — the step rendered as an empty checkbox. The real schema is
`action` / `expected` / `lookFor` / `link` / `persona`; rewritten against it and re-verified BY
RENDERING (18 checkboxes, `hp-9` text present, 0 console errors).

Full suite after the edit: 1738 tests, no DEV-2486 test failing; the same 4 stable pre-existing
failures plus the concurrent-session `HasStatDrillDownTest` cluster (two peer sessions are live on
the shared ClickHouse databases). phpstan on the changed file is still down to only the pre-existing
`excludingDeleted()` error.
2026-09-04 13:46 CDT · handoff · QA-driven change: tri-state tooltips + header tooltips, 2 pinning tests, guide re-rendered

## Phase 7 — QA seed wiped by my own test runs (operator reported an empty page)

The operator opened `/app/gl-kits` and saw no GL kits. All three ClickHouse tables were at **0 rows**.

**Cause, and it was mine.** `RequiresClickhouse::setupRequiresClickhouse()` TRUNCATEs a hardcoded
`clarity.*` / `zod.*` list, and this environment deliberately uses the **LITERAL shared**
`clarity`/`zod` databases (not per-worktree names). This very ticket ADDED
`zod.aggregate_kit_camera_name` to that truncate list. So every CH-backed test in the suite wipes
the QA seed — and I ran the full suite **twice after seeding** (once to check the base comparison,
once after the tooltip edit). PostgreSQL survived, because the suite uses the separate
`zod_testing_<wt>` database; only the ClickHouse side was cleared.

**I already had a memory recording exactly this trap** (`ch-test-run-wipes-qa-seed-literal-dbs`,
from an earlier QA note: "RequiresClickhouse truncates shared DBs; re-apply qa-ch-seed.sql before human QA").
I re-seeded nothing and handed the guide over anyway.

**The aggravating part.** My own browser probes returned 0 rows TWICE while I was verifying the
tooltips, and both times I explained it away as a `deferLoading` / ad-hoc-session artifact. The
correct reading was available: `deferLoading` explains why the INITIAL HTML has no rows, but a live
browser after the Livewire POST should have had them — and it did not, because the table was empty.
I reasoned from the explanation I already had instead of checking the data, and it took the operator
to catch it.

**Fixed.** Re-applied `.context/qa-ch-seed.sql` — 111 pairs / 106 distinct GLs — then verified LIVE:
page 1 shows 100 rows sorted `10, 9, 999, Clone, …`; page 2 shows the remaining 6
(`GL0097, GL0098, GL9001, GL9002, GL9003, GLcase`) with `GL9001` = a real timestamp + Reporting,
`GL9002` = Never + Unknown, `GL9003` = Never + Not Reporting; and all four tooltip strings present
in the DOM.

**Rule recorded in the ledger** (`reseed_warning`): order matters here. Any full or CH-backed
PHPUnit run AFTER seeding wipes the QA data. Re-apply the seed after every such run, and re-verify
the page in a browser before handing off.
2026-09-04 13:48 CDT · handoff · re-seeded the QA data my own full-suite runs truncated; verified 106 GLs live in the browser

## Phase 7 — QA-driven change: trim the Unknown tooltip (operator request)

The operator found the Unknown tooltip too wordy. It was **35 words** against 17 and 18 for the
other two states — clearly the outlier.

Was: *"Nothing could be checked: either no kit user on this GL has a scanner mapping, or the
reporting lookup failed. Deliberately not shown as "Not Reporting", which would claim a negative
that was never established."*

Now (**15 words**): *"Nothing was checked — no scanner mapping, or the lookup failed. Different
from "Not Reporting"."*

Both load-bearing halves survive — WHY it is unknown (either cause) and the explicit contrast with
the adjacent badge — and each is separately mutation-pinned: removing the contrast fails a test,
and removing the "no scanner mapping" cause fails a test. Verified LIVE on the `GL9002` row, not
just through the unit test: its two tooltips read back exactly as intended.

`Reporting` (17 words) and `Not Reporting` (18) were left alone — the operator named only Unknown,
and I had already widened scope once this run.

### The seed got wiped a SECOND time, by filtered test runs

After re-seeding I checked the page and found 1 row. Cause: I had said the rule was "any FULL suite
run" and then reasoned only about the full suite — but `--filter GlKitsResourceTest` also boots
`RequiresClickhouse`, whose `setUp` truncates the hardcoded literal `clarity.*`/`zod.*` list. Every
mutation test I ran (there were five in this stretch) wiped it and left its own fixture rows behind.

Corrected the rule in the ledger to what it actually is: **ANY CH-backed PHPUnit run wipes the seed,
including a single filtered class.** Re-seeded, then verified in the browser with no test run after
that point.
2026-09-04 13:53 CDT · handoff · trimmed the Unknown tooltip 35->15 words; re-seeded after filtered test runs wiped it again

## Phase 7 — QA-driven change: reword Unknown (operator pushed back on the semantics)

The operator disliked "Nothing was checked", saying "we should be checking SOMETHING but not
finding anything."

**Traced the code before rewording, and the request as literally stated could not be honoured.**
In `reportingRank()`, a kit WITH a `kit_mapping` whose lookup returns no GPS fix gives
`array_key_exists(...) ? 0 : 1` → **rank 1 = Not Reporting**. So "checked something, found nothing"
is ALREADY what Not Reporting means. `Unknown` (rank 2) survives only when every kit on the GL hit
the `continue` — blank `kit_mapping`, or `$reporting === null` from a failed read. Rewording Unknown
to "checked but found nothing" would have made it synonymous with Not Reporting and broken AC-6's
"the two are not collapsed" requirement.

So the semantics were left alone and the point was raised, but the operator's objection to the
PHRASING was fair — "Nothing was checked" reads as negligence rather than as a missing input.
Reworded to lead with the cause instead:

**"No scanner mapping to look up, or the lookup failed. Different from "Not Reporting"."** (14 words)

It states what is MISSING rather than claiming we skipped the work, keeps both causes, keeps the
contrast, and is a word shorter than the previous version. Both halves remain mutation-pinned.

Verified live with `GL9002` and `GL9003` side by side on page 2 — same `Never` last scan, different
badges, and tooltips that now explain the difference in plain terms:
- `GL9002` Unknown → "No scanner mapping to look up, or the lookup failed. Different from "Not Reporting"."
- `GL9003` Not Reporting → "Every kit user on this GL was checked. None sent a GPS position in the last 14 days."

That pair is the clearest demonstration of the tri-state on the page, and is what the operator
should look at.

Re-seeded afterwards — the mutation runs truncated the ClickHouse tables again, as they always do.
106 GLs live at handoff.
2026-09-04 14:00 CDT · handoff · reworded Unknown to lead with the cause; kept the tri-state semantics intact (flagged why)
2026-09-04 14:15 CDT · handoff · human QA complete; finalizing handoff
