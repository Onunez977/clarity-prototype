# Insight AI — Build Record

> Mirror of Jira comment **41334** on DEV-2555 (posted 2026-09-23 ~13:39 CT).

## TL;DR

The prior Total Repossessions batch landing already pointed Total Repossessions at `RecoveryTotalsBatchQuery`. This PR only (1) corrects `RecoveryTotalsBatchQuery` docblocks to match that path and (2) pins `RecoveryCountsTest` so removing the shared window int write fails. No SQL or key-string change. Agent QA PASS; live browser N/A (no UI delta).

## WHAT changed

* `app/Clickhouse/Queries/RecoveryTotalsBatchQuery.php` — docblocks only (truth after that landing)
* `tests/Filament/Widgets/Dashboard/RecoveryCountsTest.php` — `assertIsInt` on shared month cache (9); after today render, `sharedWindowCacheKey` `assertIsInt` / `assertSame(40)`

Product tip: `268e66ed7`. Reviewed tip: `05491bd47`. Status HEAD before this record: `ecd8f5cb9`.

## WHY

* Jira body still described thirteen Eloquent queries and a live `UniqueScansToRepo`; main already batches via `handle()` and deleted that card (prior Total Repossessions batch landing / Q1)
* Window test was vacuous (both doubles share the class-prefixed outer key) — same trap as the earlier window-key correction; reading `sharedWindowCacheKey` makes the shared int write provable
* Payload stays bare int; do not bump `-v1` (Q3). Window key stays shared with raw viewer tz (the re-shared window key / Q2)

## HOW numbers were derived

* Path A / agent-QA (Pippin): Pint `--test` on ship PHP **passed**; `RecoveryCountsTest` **28 passed (95 assertions)**; `RecoveryTotalsBatchQueryTest` **9 skipped** (CH deferred). **Cloud / production ClickHouse never written.**
* Live browser: **N/A** — `behavior_change=false`, no user-visible delta
* Structural verify (Merry): non-comment diff vs main empty for the query class; key strings unchanged; tip still `handle()` from `fetchRepoSeries`

## AC verdict (Path A mapped)

| AC | Verdict | Evidence |
| --- | --- | --- |
| 1 | SATISFIED | On main via the prior Total Repossessions batch landing; tip still `handle()` one round trip; month test `reposCalls===1` @ `05491bd` |
| 2 | SATISFIED | UniqueScansToRepo deleted; ratio denominator `getMonthlyData()` on RecoveryCounts (Q1) |
| 3 | SATISFIED | `assertIsInt` month (9) + window (40); `-v1` not bumped |
| 4 | SATISFIED | Month round-trip + window shared-key read fails if write removed |
| 5 | SATISFIED | Docblocks + assertions only; live browser N/A |
| 6 | SATISFIED | Window key stays shared as int + tz (RN4); docblocks + window pin |

## What this ticket did NOT do

* SQL / cache key string / `-v1` changes
* Restoring UniqueScansToRepo or touching ScanPlateStatsBatchQuery / the parked scans-stats cache follow-up
* Cloud ClickHouse writes (READ-ONLY absolute)
* Undraft / QA Ready / merge (Galadriel next)

## Review / conventions

Sam 3-lane MIN **91** (correctness 93 / conventions 92 / tests 91). reviewed_sha `04babea2de2e98e0b61c7bc3d84dfa785227c7ef`. Thin conventions #16 pass. OKF `okf/concepts/company-metrics-dashboard.md` cited.

## PR

https://github.com/insight-lpr/platform/pull/439 (draft HEAD after this commit)

## Next

Galadriel: undraft → CI (no `[skip ci]`) → QA Ready on green. Then Jeff Cobb owns merge.
