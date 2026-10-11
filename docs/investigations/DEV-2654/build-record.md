# Insight AI — Build Record

> Mirror of Jira comment **41815** on DEV-2654 (posted 2026-09-24 ~18:02 CDT). Tip-fix **Jeff D** re-Verify. Prior BR **41650** (original) + **41779** (D+F tip-fix) remain cited.

**Sprint 41 · Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/436 (**OPEN**, not draft)

## TL;DR

Prior Build Records **41650** (original Staging Live Hit rollup + Recovery Leader on `31220a49`) and **41779** (tip-fix D+F on `f43af490`) remain cited. **This** Build Record is the tip-fix **Jeff D** re-Verify after FREEZE FAIL **41810** (invalid viewer timezone on the Company Metrics monthly path).

- **Product tip / reviewed_sha:** `f82250c368b585fcccd2bd7361ce445a9c4aa956` — `BuildsDateFilterChartSeries::viewerTimezone()` → `ScanPlateStatsBatchQuery::safeTimezone()`; DashboardWidget monthly + sibling overview Carbon; NotAReal/Zone → UTC
- **Docs tip / PR headRefOid:** `1248e0f1040d6285f4c30086226113d18936a337` (`[skip ci]` Review rn=4 stamp)
- Agent QA re-PASS on **real Docker ClickHouse** · Review rn=4 PASS · PR already OPEN

## WHAT changed (tip-fix Jeff D only)

* `BuildsDateFilterChartSeries::viewerTimezone()` routes raw `users.dti` through `ScanPlateStatsBatchQuery::safeTimezone()` (allowlist; unlisted → UTC; never throws).
* `DashboardWidget::getMonthlyData` / graph labels / highlighted months / headline window / current-period TTL arms use `viewerTimezone()` instead of raw `auth()->user()->timezone`.
* Sibling overview Carbon paths (UniqueScanCounts, UniqueScansLeaderboard, LprRepossessions, MissedOpportunities, UniqueLprCaseClaims) aligned to the same safe zone helper.
* Staging Live Hit rollup + Recovery Leader D+F from BR 41779 unchanged in intent; RecoveryCounts spine untouched.

No Cloud ClickHouse writes in the tip-fix diff. Leave PR #462 alone.

## WHY

Jeff FREEZE FAIL **41810** proved Company Metrics still called `now(tz:)` on the raw `users.dti` zone inside `DashboardWidget::getMonthlyData`, so `NotAReal/Zone` 500'd the overview before later cards mounted. Prior BR 41779 covered Staging Live / Recovery Leader D+F on `f43af490` but not this monthly overview path.

## HOW numbers were derived

* Agent QA tip-fix Jeff D (2026-09-24 ~18:00 CDT): Pint **PASS 23 files**; PHPUnit **172 passed / 0 fail / 0 skip / 795 asserts**; `CLICKHOUSE_DRIVER=clickhouse` @ `127.0.0.1:18124`; classic `clarity`/`zod` on Docker; keyed `clarity_dev_2654` table_count **31**; PG `:15434` `zod_testing_dev_2654`; `cloud_touched=false`; SCHEMA-DRIFT `:18125` untouched
* Jeff D methods green: `test_invalid_viewer_timezone_renders_the_base_monthly_card_in_utc`; `test_invalid_viewer_timezone_monthly_path_falls_back_to_utc`; `test_safe_timezone_falls_back_to_utc_and_never_throws`; RequiresClickhouse tip suites **0 skips**
* Live browser: **NOT_COVERED** (login 200 / `/app` 302) — soft; suites satisfy Agent QA PASS
* Review rn=4: scores **[93, 91, 90]** · min 90 · `reviewed_sha=f82250c` · `rerun_required=false` · act_on NONE · scope Company Metrics monthly timezone
* Thin conventions #16: conventions lane **91** green; Pint PASS 23; OKF `::query()` prose may be stale — **soft OK**
* Prior BR 41650 + 41779 cite: original AC-1…AC-7 + D+F evidence still stands; this BR refreshes #18 to **172/795** and extends AC-3 to the monthly overview path
* Cloud ClickHouse: READ-ONLY forever; no writes this ticket

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 | SATISFIED | rememberedRange every arm incl this_month; no fixed 13-mo (prior intact) |
| AC-2 | SATISFIED | ROLLUP key 0 = org total; one shared scan |
| AC-3 | SATISFIED | toUtcDatetimeFor + safeTimezone; Jeff D extends viewerTimezone → safeTimezone on Company Metrics monthly + overview Carbon |
| AC-4 | SATISFIED | uniqExactIf / kit_org_id / kit_id; 0 CH skips on real CH |
| AC-5 | SATISFIED | shared cache helpers; TTL + impersonation |
| AC-6 | SATISFIED | recovery-scans-audit.md accurate-as-is (fleet twin empty; Cloud delta 0 cite) |
| AC-7 | SATISFIED | TZ accepted (Y-m-d calendar); swallow + F warm put/forget (prior) |
| Preflight #18 | SATISFIED | clickhouse · :18124 · 172/795/0 skip · cloud_touched=false |
| Live browser | NOT_COVERED | curl-only — soft |

## Soft flags (non-blocking)

* Live NOT_COVERED
* sparkline still SharedHitStatsData path
* Recovery Leader still name-keyed (audit seed)
* OKF `::query()` prose may be stale
* AC6 accurate-as-is
* StagingLiveHits unrelated range-total `cache()->remember` not Jeff F
* RecoveryCounts / RecoveryTotalsBatchQuery spine untouched
* Leave PR #462 alone
* Hold DEV-2659 until 2654 merges

## What this ticket did NOT do

* Schema change / `lpr_assists` view FINAL
* Mutate RecoveryCounts / RecoveryTotalsBatchQuery
* Cloud ClickHouse writes
* QA Ready flip / merge / Status Board from Verify
* Authenticated live dashboard / OTP smoke
* Touch PR #462 or DEV-2659

## Review / conventions

Review rn=4 · 93 / 91 / 90 · min 90 ≥ 85 · reviewed_sha `f82250c` · rerun_required=false. Thin conventions #16 PASS (conventions 91; Pint 23).

## PR

https://github.com/insight-lpr/platform/pull/436 — **OPEN** (not draft) · product tip `f82250c` · docs tip `1248e0f`

## Next

**CI green + Jeff re-FREEZE→GO / merge-lane** (PM). No QA Ready flip from Verify. Do not Status Board from Verify/BR. Fleet env left **UP**; teardown on closeout is PM. Hold DEV-2659 until merge.

## Verify exit note (gates)

`phase=done`. `BUILD_RECORD_GATE` pass id=41815. `REWORK_GATE` pass (only investigation artifacts since reviewed_sha `f82250c`).
