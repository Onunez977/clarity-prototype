# Insight AI — Build Record

> Mirror of Jira comment **41869** on DEV-2736 (Verify/Build Record after Agent QA PASS on product tip `dd61b327…`). Posted 2026-09-25 ~12:37 CDT.

**Sprint 41 · Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/468 (**DRAFT** — Verify does not undraft)

## TL;DR

Reshape LPR Recovery leaderboard expanded chart from a 13-month per-kit stack to **one selected-window series** (kit-name labels, fractional values) matching Scans / Staging Live Hits. Scans chrome + Distinct strings (Q1/Q2). Collapsed headline stays leader+runner-up. `RecoveryLeaderQuery::handleSql` **unchanged** (Q3 / #25 N/A). No Unique in new chrome.

## WHAT changed

* `RecoveryLeader.php`: extract `rankingForSelectedRange()` (name→float before `summarizeMonth`); `leaderboardForSelectedRange()` → `summarizeMonth(rankingForSelectedRange())`; `getData` one dataset `Distinct Recoveries`; `getOptions` / `makeDataset` from Scans (`LEADER_FILL` green max bar, zinc field, mode `nearest`, subtitle `Top 10 kits by recoveries`, axis `Distinct recoveries`); `chartSeries` stays `BuildsDateFilterChartSeries`
* `RecoveryLeaderTest.php`: single-series + Q2 chrome asserts; fractional headline + `Y-m-d` SQL stay; no Unique in new chrome
* **Not touched:** `app/Clickhouse/Queries/RecoveryLeaderQuery.php` (0-line diff vs `main`)

Product tip `dd61b327e3b15691e254b28cb9dd1367fb320fd3` — **2 files**, 229+/118−. Query stay.

## WHY

Jeff/Oscar demo notes (2026-09-25): LPR Recovery leaderboard should match Scans and Staging Live Hits top-10 / single-series pattern locked on [DEV-2654](https://insightlpr-team.atlassian.net/browse/DEV-2654). [DEV-2586](https://insightlpr-team.atlassian.net/browse/DEV-2586) was kit-user name only. Dalton Q-gate 2026-09-25: Q1 Scans chrome · Q2 Distinct Recoveries / Top 10 kits by recoveries / Distinct recoveries · Q3 keep monthly top-10 sum / no second query.

## HOW numbers were derived

* **Agent QA (2026-09-25 12:34:46 CDT):** Pint **PASS 2 files**; PHPUnit **24 passed / 0 fail / 0 skip / 138 asserts** (`RecoveryLeaderTest`); **`CLICKHOUSE_DRIVER=clickhouse`** @ **`127.0.0.1:18127`** · keyed `clarity_testing_dev_2736` (process prove: `BOOT`/`PROCESS_CLICKHOUSE_DRIVER=clickhouse HOST=127.0.0.1:18127`); PG `:15436` `zod_testing_dev_2736`; site `:18084` login **200**; **`cloud_touched=false`**; refused sibling ports untouched; fleet left **UP**
* Review rn=1: scores **[94, 92, 95]** · min 92 · `reviewed_sha=1930a8c42699ae63c351f87da153f9886714c6f4` · product tip `dd61b327…` · `rerun_required=false` · act_on **NONE**
* Thin conventions #16: conventions lane **92** ≥ 85; Pint PASS **2** — refuse-only
* Ancestry: product tip `dd61b327` ⊂ reviewed `1930a8c` ⊂ PR head / docs stamp `6685cf3`. Suites exercise product tip tree. Post-review diffs = investigation docs only.
* Preflight **#18 PASS (required):** Agent QA ran tip suite on **real fleet Docker ClickHouse** (`CLICKHOUSE_DRIVER=clickhouse`, port **18127**, `cloud_touched=false`). Postgres/pgsql mirror does **not** clear this gate. Soft: query stubs dominate most asserts — live CH path not hit for most cases; **driver still proven clickhouse against Docker :18127** (not a stub/PG stand-in for the process).
* Preflight **#25 N/A (`does_not_fire`):** Q3 keeps `BuildsDateFilterChartSeries` / monthly top-10 sum; `handleSql` stay; product tip has **zero** `RecoveryLeaderQuery.php` diff vs `main`. No old-vs-new parity table.
* Cloud ClickHouse: READ-ONLY forever; `cloud_touched=false`. Fleet left **UP** (`:18084` / `:15436` / `:18127`).

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 UI matches Scans / Staging Live Hits (top-10, single-series) | PASS | `getData` one dataset from `rankingForSelectedRange`; Scans `makeDataset`/`getOptions`; Agent QA Q1 PASS; RecoveryLeaderTest chrome asserts |
| AC-2 Counts accurate vs recovery source (no silent query regression) | PASS | `RecoveryLeaderQuery.php` 0-line diff vs main; fractional headline test retained; #25 N/A |
| AC-3 Visual/parity vs Scans + Staging Live Hits same org/window | PASS | Chrome constants match UniqueScansLeaderboard; data list matches StagingLiveHitLeader pattern (plan #24); unit chrome/parity asserts; live browser NOT_COVERED (soft) |
| Q1 Scans chrome | PASS | LEADER_FILL / Distinct strings / mode nearest — Agent QA + tests |
| Q2 Distinct Recoveries / Top 10 / Distinct recoveries | PASS | Dataset label + subtitle + axis — Agent QA q_checks |
| Q3 keep monthly top-10 sum / no second query | PASS | chartSeries stay; no org-total cache; query stay |
| Preflight #18 | PASS | Real Docker CH `:18127` · driver=clickhouse · cloud_touched=false · phpunit BOOT prove |
| Preflight #25 | N/A | Query stay / does_not_fire |
| Live OTP / Playwright | NOT_COVERED | curl login 200 only — soft OK |

## Soft flags (non-blocking)

* Live OTP / Pro / Playwright **NOT_COVERED** (login 200 only)
* RecoveryLeaderTest **query stubs dominate** — live CH not exercised for most asserts; process driver still **clickhouse** @ `:18127` (clears #18)
* Product tip commit is `[skip ci]` — CI trigger is next lane

## What this ticket did NOT do

* Edit `RecoveryLeaderQuery` / any `app/Clickhouse/` SQL
* Add a second query or recovery org-total cache
* Invent a third leaderboard class
* Cloud ClickHouse writes
* Undraft / QA Ready / merge / Status Board from Verify
* Authenticated live dashboard / OTP / Playwright

## Review / conventions

Review rn=1 · 94 / 92 / 95 · min 92 ≥ 85 · `reviewed_sha` `1930a8c42699ae63c351f87da153f9886714c6f4` · `rerun_required=false`. Thin conventions #16 PASS (conventions 92; Pint 2).

## PR

https://github.com/insight-lpr/platform/pull/468 — **DRAFT** · product tip `dd61b327e3b15691e254b28cb9dd1367fb320fd3` · reviewed `1930a8c42699ae63c351f87da153f9886714c6f4` · PR head (pre-BR docs) `6685cf3361b9ed2ea02085bef0cfc82ba90c5e2e`

## Next

**Undraft / CI / QA Ready** (PM). Verify does **not** undraft, flip QA Ready, merge, or post Status/Fleet Board. Product tip may be `[skip ci]` — CI trigger is next lane. Fleet env left **UP**; teardown on closeout is PM.

## Verify exit note (gates)

`phase=done`. `BUILD_RECORD_GATE` + `REWORK_GATE` run after comment id recorded. Post-review product code: none (investigation artifacts only since `1930a8c…`).
