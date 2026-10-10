# Insight AI — Build Record

> Mirror of Jira comment **41264** on DEV-2652 (posted 2026-09-23).

## TL;DR

Company Metrics ratio numerators now window in the viewer’s timezone via `numeratorZone()` → `ScanPlateStatsBatchQuery::safeTimezone()` + the existing `toUtcDatetime()` path. Headline / labels stay on UTC. Claims `ts` (AC5) stays UTC — deferred with Brek Repo Report reconciliation impact recorded (comment 41117). Pippin live QA PASS (round 4, fleet box).

## WHAT changed

* `RatioAgainstUniqueScans::numeratorZone()` delegates to `safeTimezone()`
* `UniqueLprCaseClaims`, `LprRepossessions`, `RecoveryCounts` pass that zone into the unique-scans numerator batch
* Docblock pointers on `UniqueScanCounts` / `ScanStatsBatchQuery` for the open mixed-clock / `repo_date` seams
* PHPUnit: RatioAgainstUniqueScans + UniqueLprCaseClaims (+ LabelAxis) + LprRepossessions + RecoveryCounts — **105 passed** / 385 assertions

## WHY

* Jira AC1–4: numerators were pinned to `SERIES_ZONE = UTC` while scan searches are viewer-TZ aware (`okf/concepts/scan.md`)
* Invalid legacy `users.dti` (`CST` / `PST` / `+05:00`) must fall back to UTC without taking down the cards
* Cache keys that gain TZ must keep `:tz=`; `RecoveryTotalsBatchQuery::sharedMonthCacheKey` must stay unchanged (`repo_date` has no time axis)

## HOW numbers were derived (live QA)

* Fleet site `http://127.0.0.1:18080` · viewer America/Chicago · form login `test@tester.com`
* This Month: Unique LPR Case Claims **616**, LPR Repossessions **517**, Total Repossessions **4,730**
* Unique Scan Counts unique=**0** this month (org2 `scans_cold` empty for Sep — seed shape; observation only)
* AC2: dti `CST` reload still renders Company Metrics without 500; restored Chicago
* AC3: This Month / Last Month toggles without error (cache-key depth covered by PHPUnit)
* Docker CH seed sized from Cloud READ-ONLY measure (e.g. `hits_hot` 19328; `scan_report_hourly` remapped org 1143→2). **Cloud never written.**

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| 1 | SATISFIED | Code + live QA: numeratorZone + CH-backed cards render |
| 2 | SATISFIED | Code fallback + live CST smoke |
| 3 | SATISFIED | sharedMonthCacheKey `:tz=` on scan path; RecoveryTotals key unchanged; live month toggle + PHPUnit |
| 4 | SATISFIED | Docblocks on RecoveryCounts / LprRepossessions / UniqueScanCounts; live mixed seed tolerated |
| 5 | DEFERRED | Claims `ts` still `toDate(..., 'UTC')`; Eric/Brek gate; comment 41117 |

## What this ticket did NOT do

* Claims `ts` viewer-zone shift (AC5)
* Staging Live Hit TZ (DEV-2654)
* Cloud ClickHouse writes (READ-ONLY absolute)

## Review

Sam 3-lane MIN **88** (correctness 89 / blast-radius 88 / tests 88). reviewed_sha `bd34326d`.

## PR

https://github.com/insight-lpr/platform/pull/434 (draft HEAD `4626512`)

## Next

Galadriel: undraft → CI (no `[skip ci]`) → QA Ready on green. Then Jeff Cobb owns merge.
