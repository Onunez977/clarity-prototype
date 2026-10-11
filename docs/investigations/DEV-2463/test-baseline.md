# DEV-2463 — full-suite result and the pre-existing-failure baseline

**2026-09-08.** Run as `PHPRC=<ini with memory_limit=3G> php vendor/bin/phpunit --no-coverage`.
`php artisan test` is NOT usable for a full local run — it OOMs during test *discovery* at the
default 128M and still exits 0, so a naive `&& echo ok` reports a clean pass on a suite that
never ran. `-d memory_limit=` does not reach the phpunit child; `PHPRC` does.

## Headline

```
Tests: 1807, Assertions: 7328, Errors: 1, Failures: 10, Skipped: 4, Incomplete: 1, Risky: 1
```

**None of the 11 failures/errors is in a file this ticket touched.** They were verified
pre-existing, not assumed to be — see below.

## How they were verified pre-existing

The knowledge card warns that these ~8 failures are largely *ordering* artifacts of the shared
literal `clarity`/`zod` ClickHouse databases, and that a long local CH session degrades into
**false** failures as the container accumulates memory pressure. So a single run proves nothing.
Method: run each suspect suite in isolation, then run the same suites on a **detached
`origin/main`** with none of this ticket's changes present.

Baseline runs on `origin/main` (`ad29de3b`) — the same commit, no code changes at all:

| suite | run 1 | run 2 | run 3 |
| --- | --- | --- | --- |
| `HasStatDrillDownTest` | 4 failed | 4 failed | **7 failed** |
| `ClarityScoutTripsControllerTest` | 0 failed | **1 failed** | 0 failed |

The same commit produces 4, 4 and 7 failures on one suite and 0, 1, 0 on another. **These suites
are non-deterministic on `main` itself**, which is exactly what the card describes. Container
memory at that point was **4.277 GiB / 5.786 GiB (73.9%)** — the documented false-failure
condition.

`docker restart clarity-qa-clickhouse` reclaimed it to **2.086 GiB (36.1%)**, after which both
branches became stable and identical:

| suite | `origin/main` | `claude/DEV-2463` |
| --- | --- | --- |
| `HasStatDrillDownTest` | 5 failed | 5 failed |
| `ClarityScoutTripsControllerTest` | 0 failed | 0 failed |
| `CloseInactiveShiftsCommandTest` | 2 failed | 2 failed |
| `EnsureOpenShiftTest` | 1 failed | 1 failed |
| **total** | **8** | **8** |

Eight pre-existing failures, matching the card's stated expectation, reproducing **identically**
with and without this ticket's diff.

**Conclusion: zero regressions introduced.** Two apparent discrepancies in the first pass
(`HasStatDrillDownTest` 5-vs-4 and `ClarityScoutTripsControllerTest` 1-vs-0) were both inside the
baseline's own run-to-run variance, and both disappeared once container pressure was cleared.

## This ticket's own suites

```
php artisan test --filter="UniqueLprCaseClaimsQueryTest|UniqueLprCaseClaimsTest|
  DashboardCardExpansionTest|DashboardCardVisibilityTest|FeatureEnumTest|
  MissedOpportunitiesTest|StagingLiveHitLeaderTest"
→ 94 passed (536 assertions)
```

- `UniqueLprCaseClaimsQueryTest` 12/12 at the time of this run — **now 15/15** after review round 1
  added three dedup/success regression tests, and a further
  `UniqueLprCaseClaimsLabelAxisTest` (3 tests) was added in round 2. Current totals are in the
  round-2 section below. **Confirmed really executing, not skipped.** `.env.testing`
  shipped with `CLICKHOUSE_DRIVER=pgsql`, which makes `RequiresClickhouse` `markTestSkipped` every
  ClickHouse test silently (all-green having run nothing). It was flipped to `clickhouse` on the
  local Docker container, and the per-test durations confirm real execution.
- `UniqueLprCaseClaimsTest` 13/13 · `DashboardCardExpansionTest` 16/16 ·
  `DashboardCardVisibilityTest` 16/16.
- `FeatureEnumTest` 3/3 — no new `FeatureEnum` case was added, so `assertCount(18)` still holds.
- `MissedOpportunitiesTest` 13/13 and `StagingLiveHitLeaderTest` 21/21 — the two sibling cards,
  green, confirming the index-2 insert did not disturb them.

## Note for QA

A full suite run **truncates the literal `clarity`/`zod` ClickHouse databases** (that is what
`RequiresClickhouse` does, and those names are shared across worktrees). So any QA seed must be
applied **after** the last suite run, and re-applied after any subsequent one.

---

# Round 2 full suite — and why its bigger number is not a regression

```
Tests: 1810, Assertions: 7288, Errors: 5, Failures: 16, Skipped: 4, Incomplete: 1, Risky: 1
```

21 failures/errors against round 1's 11. **None in a file this ticket touches**, but the jump is
large enough that it was investigated rather than waved off — and one of the new entries was
`MissedOpportunitiesTest`, a *sibling card* that had been 13/13 green.

## Isolation

| suite | in the full run | in isolation |
| --- | --- | --- |
| `MissedOpportunitiesTest` | failed | **13/13 passed** |
| `HotlistsResourceTest` (6 entries) | failed | **11/11 passed** |
| `RdnHitHourCountQueryTest` | failed | 1 failed |
| `ScoutRecordCheckInsCommandTest` | failed | 1 failed |

So seven of the new entries are pure ordering artifacts of the shared literal `clarity`/`zod`
ClickHouse databases. The container was at **52%** memory by then.

## The two stubborn ones, against `origin/main`

After `docker restart clarity-qa-clickhouse` (back to 35%):

| suite | `claude/DEV-2463` | `origin/main` |
| --- | --- | --- |
| `RdnHitHourCountQueryTest` | **5 passed** | 1 failed |
| `ScoutRecordCheckInsCommandTest` | **30 passed** | 1 failed |
| `ClarityScoutDashboardControllerTest` | **48 passed** | 48 passed |
| `ClarityScoutMapControllerTest` | **65 passed** | 1 failed |

**This branch ran 4/4 clean where `origin/main` failed three of the same four**, in the same
session, minutes apart. The container had accumulated pressure again during the baseline runs.

## Conclusion

The failing population is the documented flaky set, and its *size* tracks ClickHouse container
memory pressure rather than the diff. Three independent demonstrations across both rounds:

1. Round 1: `HasStatDrillDownTest` gave **4, 4, then 7** failures on the *same* `origin/main`
   commit at 73.9% memory.
2. Round 1 post-restart: `origin/main` and this branch were **identical at 8**.
3. Round 2 post-restart: this branch **4/4 green**, `origin/main` **3 failures**, same four suites.

There is **no evidence of a regression from this ticket, and positive evidence against one.** What
this does establish is that the local full-suite number is not a reliable signal past ~50% container
memory — restart the container before trusting any full-suite comparison. CI runs a fresh
container per job and is the authority.

This ticket's own suites, post-restart: **28/28** (query 15 + widget 13), plus
`DashboardCardExpansionTest` 16/16 and `DashboardCardVisibilityTest` 16/16.

---

# Current test counts (post review round 3)

Flagged stale by all three lanes in **both** rounds 2 and 3 — the count had not been refreshed
since round 1. Corrected, and this section is now the single place to update.

| suite | tests | what it pins |
| --- | --- | --- |
| `UniqueLprCaseClaimsQueryTest` | **15** | the port rules; incl. round 1's three dedup/success regressions |
| `UniqueLprCaseClaimsTest` | **13** | the card contract, gate matrix, one-round-trip-per-seam |
| `UniqueLprCaseClaimsLabelAxisTest` | **3** | the UTC label axis co-varies with the UTC data axis |
| `UniqueLprCaseClaimsHerdGuardTest` | **6** | the cold-path herd guard: ordering, sizing, existence, the torn-key state, and the refresh-lock expiry |
| `UniqueLprCaseClaimsWindowSeamTest` | **2** | the today/last_7d seam's funnel stage and viewer-timezone anchoring |
| | **39 total** | |

## Every added test was mutation-verified, not just written

| round | mutant | before | after |
| --- | --- | --- | --- |
| 1 | `uniqExactIf(3-col, success AND vin != '')` → `countIf(vin != '')` (drops dedup **and** the success filter) | passed all 12 | fails 2 |
| 2 | delete the three label-axis overrides | passed all 28 | fails 3 |
| 3 | delete `heldFlexible()` entirely | passed all 3 guard tests | fails the lock probe |
| 3 | lock/gate **before** the cache read (round 2's exact outage bug) | — | fails on a 13.8s block |
| 3 | `COLD_BLOCK_SECONDS` 12 → 5 (round 2's non-binding value) | — | fails the sizing test |
| 3 | drop `Cache::flexible`'s 4th `$lock` arg (restores finding 8) | **passed all 4 guard tests** | fails via the `flexibleRefreshLock()` seam |
| 3 | evict only the `…:created:` companion key (torn state) | **passed everything** | fails — the guard now tests both keys |
| 3 | `['deduped']` → `['successful']` on the window seam | **passed all 35** | fails the stage test |
| 3 | drop the viewer timezone from `Carbon::parse()` | **passed all 35** | fails the bounds test |

The four rows marked **passed** are the point: each was a guard or a fix that existed in the code
and was provably deletable without a single test noticing. Three of them were mine, found by the
lanes; two I found by mutating my own work after writing the test that was supposed to cover it.
