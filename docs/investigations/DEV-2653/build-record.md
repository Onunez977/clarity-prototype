# Insight AI — Build Record

> Product track DEV-2653 (Company Metrics shared cache) — **not** SCHEMA-GATE.
> SCHEMA-GATE Build Record remains Jira comment 41362 / dump at `25870f75`.

## TL;DR

`ScanPlateStatsBatchQuery` owns the shared scan cache (`rememberedRange` / `rememberMonthSeries` / `rememberOpenMonthTotal`) under class-neutral `shared*CacheKey`s. TTL ladder + closed-month settle window live in `ResolvesDashboardCacheTtl::baseDashboardCacheTTL`. Product tip `eddf64399101f5f2dd18d65264823f531d0cbcdb`. Soft OK vs SCHEMA-GATE `#449` (`25870f75`). **Hit/miss evidence required from real Docker ClickHouse** (`CLICKHOUSE_DRIVER=clickhouse` `127.0.0.1:18123`) — Mac `pgsql` bridge alone does **not** clear Preflight #18.

## WHAT changed

* `ScanPlateStatsBatchQuery` — owns shared remember/put for range + month series; AC-5 docblock (scans_cold N-org ceiling; no DEV-2655 pointer)
* `ResolvesDashboardCacheTtl` — shared TTL ladder (impersonation 10s → open 10m → settle 10m → year)
* `DashboardWidget::getCacheTTL` delegates to base ladder; card overrides stay for card-owned keys
* `UniqueScanCounts` / `UniqueScansLeaderboard` / `RatioAgainstUniqueScans` — call query-owned remember helpers; open month `Y-m`
* `StagingLiveHitLeader` — settle window + `baseDashboardCacheTTL` on hit shared keys (still widget-owned)
* `LprRepossessions` — shared keys kept; flexible pair `[seconds, seconds]` from `getCacheTTL(0)`
* `UniqueLprCaseClaimsQuery` — **no** `shared*CacheKey`; AC-5 → DEV-2659 / `billing_claim_events_v`
* Tests covering execution-count, key neutrality, TTL/settle, cross-card reuse

No Zod. No `database/schema/clickhouse-schema.sql` in product diff. No Cloud ClickHouse writes. No `okf/` edit (settle wiki queued post-approval).

## WHY

Five-plus consumers of `ScanPlateStatsBatchQuery` per dashboard render each cold-scanned `clarity.scans_cold`. Class-neutral shared keys already existed; open-month `this_month` vs `Y-m` drift and card TTL floors broke reuse. Closed-month year-cache needed an 8-day settle window before DEV-2659 reads `billing_claim_events_v`. Review FAIL round 1 required settle on `StagingLiveHitLeader` and base-ladder writes for open-month shared ints.

## HOW numbers / AC were derived (real-CH required)

**Primary (Preflight #18):** real Docker ClickHouse Agent QA at tip `eddf64399`:

* `CLICKHOUSE_DRIVER=clickhouse` · `CLICKHOUSE_HOST=127.0.0.1` · `CLICKHOUSE_PORT=18123` · DB `clarity`/`zod`
* PG `127.0.0.1:15433` `zod_testing_schema_gate`
* **223 passed / 0 failed / 0 skipped / 793 assertions** (~1m19s)
* `cloud_touched=false` (host is loopback only; no Cloud writes)
* Evidence on disk only (never commit): `agent-qa-real-ch-hit-miss.md`, `phpunit-real-ch-hit-miss.log`, worktree `.context/qa-agent-report-real-ch.md`

**Prior suite-only (does not clear #18):** Mac Herd `CLICKHOUSE_DRIVER=pgsql` — 126 passed / 5 skipped / 489 assertions. Kept as implement/review bridge only.

### Dalton hard bar — hit/miss cite map (real-CH log ✔)

| # | Claim | Verdict | Real-CH test cite |
| --- | --- | --- | --- |
| 1 | Cold miss → query fires once | **STRONG** | `UniqueScanCountsTest::test_a_fully_cold_render_calls_each_fetch_seam_exactly_once` (monthlySeriesCalls=1, rangeRollupCalls=1); `StagingLiveHitLeaderTest::test_a_fully_cold_render_issues_exactly_one_round_trip_per_series`; `UniqueScansLeaderboardTest::test_a_fully_cold_render_calls_fetch_range_rollup_exactly_once` |
| 2 | Warm hit → 0 second query | **STRONG** | `LprRepossessionsTest::test_a_warm_cache_is_served_without_taking_the_cold_lock` (fresh.calls=0); `StagingLiveHitLeaderTest::test_warm_cache_is_never_overwritten_by_a_real_clickhouse_fetch` (**`requireRealClickhouse()`** — skipped under pgsql; green on :18123); Ratio warm path `scansCalls` stays 1 |
| 3 | Cross-card Counts → Ratio/Leaderboard without second `ScanPlateStatsBatchQuery` handle | **STRONG** (Ratio E2E; Leaderboard compositional) | **Ratio:** `test_ratio_reuses_the_open_month_y_m_int_without_a_second_scan` (`scansCalls=0`) + `test_month_series_cache_keys_carry_no_class_name_and_are_shared_across_cards`. **Leaderboard:** `test_fetch_range_rollup_reads_the_shared_range_cache_key_unique_scan_counts_writes` (sentinel under `sharedRangeCacheKey`; `cache()->remember` miss-only ⇒ no `handleRange`) + `ScanPlateStatsBatchQueryContractTest::test_unique_scan_counts_and_the_leaderboard_share_the_this_month_key_on_an_unlisted_viewer_zone` |

### Soft claims flagged (not HARD FAIL)

* **Leaderboard path is compositional**, not a single stub with `handleCalls==0` after a live UniqueScanCounts write (unlike Ratio). Still not TTL-shaped: sentinel hit proves remember closure did not run.
* **Seam counters** (`monthlySeriesCalls` / `scansCalls`) are in-process stubs (CH driver does not fire `DB::listen`). Execution-count AC is by design; real-CH re-ran the same asserts with driver=`clickhouse`.
* **Mac pgsql Agent QA** must not be cited as Preflight #18 or as sole hit/miss proof. Real Docker `:18123` is required.

## AC verdict (live Jira + rescope comments 41300/41303)

| AC | Verdict | Evidence |
| --- | --- | --- |
| 1 At most one shared-query execution per render | SATISFIED | Hard bar #1–#3 @ real-CH 223p |
| 2 Class-neutral key + org/window/tz | SATISFIED | Contract + Ratio key tests (real-CH ✔) |
| 3 TTL ladder + 10s impersonation | SATISFIED | `DashboardWidgetCacheTtlTest` + StagingLiveHitLeader settle/impersonation |
| 4 Monthly maps carry window / open `Y-m` | SATISFIED | Leaderboard/Ratio/Counts open-month `Y-m` tests |
| 5 N-way ceiling per class (rescope: claims→DEV-2659; scans no DEV-2655) | SATISFIED | `CompanyMetricsCacheScopeTest` + docblocks (Jira body still says DEV-2655; scored to rescope) |
| SETTLE 8-day / ~2h via 10m arm | SATISFIED | TTL tests + StagingLiveHitLeader month-ended-one-day-ago |
| Preflight #18 real Docker CH DSN | SATISFIED | `clickhouse` `127.0.0.1:18123` clarity/zod; PG `:15433`; 223/0/0/793; `cloud_touched=false` |
| Live browser / :18081 | SATISFIED/N/A | Dalton suite-only; `ui_impact` false; this round real-CH not live UI |

## Thin conventions (#16)

Pint PASS at tip. Conventions review score **88** (≥85). No `okf/` in product revision. **PASS.**

## Review residual (blocks done / undraft)

Review RN=1 **FAIL** at `f3a31dbf` (min 81 / threshold 85). Fix cycle + query-owned cache shipped at `eddf64399`. `record.review.rerun_required=true` — **must not hand-clear**. `build-record-gate` / `rework-gate` stay blocked until **RN=2** reviews tip `eddf64399` and clears the flag. **Do not undraft / QA Ready / merge** until gates pass.

## PR

https://github.com/insight-lpr/platform/pull/450 (draft). Assignee DaltonMcCleery unchanged. Jira status not transitioned.

## Next

Re-review RN=2 → clear `rerun_required` → Undraft / CI (no `[skip ci]`) / QA Ready (PM lane). Not Status Board from Verify.
