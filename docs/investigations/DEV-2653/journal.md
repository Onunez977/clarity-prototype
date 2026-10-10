<!-- ticket-status -->
**Ticket:** DEV-2653
**Phase:** implemented
**Done:** intake · Ultra-Prep · Question Gate (CLEAN) · Ultra-Plan · ticket provision (fleet Docker CH cite) · Implement · Review FAIL fix cycle 1
**Next:** Review
**Review:** fail at `f3a31dbf` (correctness 84, conventions 88, blast-radius 81; min 81, threshold 85). Fix cycle 1 ships the settle window on both `StagingLiveHitLeader` write paths, and writes the open-month shared scans key with the base ladder. `UniqueLprCaseClaimsQuery` still has no shared cache key. `ScanPlateStatsBatchQuery::safeTimezone` is unchanged.
**Open questions:** none
**Sprint:** Sprint 41
**Ship:** PHP cache sharing for `ScanPlateStatsBatchQuery` (primary); keep `LprRepossessionsQuery` shared keys; do not add `UniqueLprCaseClaimsQuery` billing-leg shared cache; closed-month settle window on `DashboardWidget::getCacheTTL` (~2h minimum, 8 days before the 1-year TTL). AC-5 is per class.
**Out:** Zod, Cloud ClickHouse writes, DEV-2659 query rewrites, DEV-2654.
**Schema on main:** `25870f75` has `clarity.billing_claim_events`, `clarity.billing_claim_events_v`, `clarity.billing_claim_events_mv` in `database/schema/clickhouse-schema.sql`.
**Provision:** fleet Docker cite. Site `http://127.0.0.1:18081` (`clarity-qa-app-schema-gate`). Postgres `127.0.0.1:15433` `zod_schema_gate` + `zod_testing_schema_gate`. ClickHouse strategy `docker`, container `clarity-qa-clickhouse`, HTTP `:18123`, keyed `clarity_schema_gate` + `clarity_testing_schema_gate` and zod twins `zod_schema_gate` + `zod_testing_schema_gate`. `billing_claim_events`, `billing_claim_events_v`, and `billing_claim_events_mv` are present on the keyed Docker database. `cloud_ch_writes` false. Mac Herd refused. Cloud ClickHouse not touched.
**Jira assignee:** DaltonMcCleery (unchanged; status not transitioned)
<!-- /ticket-status -->

# DEV-2653 product journal

Track: **product**. This directory supersedes the SCHEMA-GATE control plane.

## SCHEMA-GATE archive

PR #449 merged to `main` as `25870f75661f07a27a69124a414e557d4c11fff0` (2026-09-24). That commit added `clarity.billing_claim_events`, `clarity.billing_claim_events_v`, and `clarity.billing_claim_events_mv` to `database/schema/clickhouse-schema.sql`. The SCHEMA-GATE state, plan, and build record at that commit stay in git history. They are not the product track. Product work does not reuse branch `cursor/schema-gate-dev-2653-8def`.

## Phase 0 — intake (2026-09-24)

- Live Jira: DEV-2653, Task, To Do, Sprint 41, assignee Dalton McCleery. Assignee not changed.
- Parent DEV-2409. Relates DEV-2651 (QA Complete), DEV-2654 (To Do), DEV-2655 (Done).
- Lock comments: 41300 (re-scope), 41303 (settle window owned here). Hold-lift comments 41312 and 41447. Product pickup 41446.
- Base is `origin/main` `25870f75`, which is the #449 merge. Local `main` before fetch was `931d8425` and did not contain the billing objects. Fetch proved the three `CREATE` statements at lines 18, 85, and 102.
- Branch: `cursor/dev-2653-shared-cache-plan-af4f`.
- Stop line: `phase=plan`. No provision. No product PHP.

## Phase 1 — Ultra-Prep (2026-09-24)

Read `okf/index.md`, `okf/concepts/data-architecture.md`, `okf/concepts/company-metrics-dashboard.md`, `okf/concepts/billing-events.md`, `okf/concepts/platform-gotchas.md` (PG-009), and `okf/concepts/impersonation.md`. ClickHouse stays read-only. Wiki edits are queued, not made.

`ScanPlateStatsBatchQuery` consumers on this main:

- `UniqueScanCounts` writes `sharedMonthCacheKey` / `sharedRangeCacheKey`.
- `UniqueScansLeaderboard` reads the month keys and shares the range key.
- `RatioAgainstUniqueScans` writes the month keys. The three cards are `UniqueLprCaseClaims`, `LprRepossessions`, and `RecoveryCounts`.

Past months already share `Y-m`. The open month is split: `this_month` token versus `Y-m`. That is the remaining second `scans_cold` read. The date-filter value `this_month` is not that token.

`LprRepossessionsQuery` shared keys are present (lines 250 and 279) and are called with `heldFlexible([3600, 86400])`, which skips `getCacheTTL()`. `HeldFlexibleCache` requires the value key and `illuminate:cache:flexible:created:{key}`.

`UniqueLprCaseClaimsQuery` has no shared-key method. Its card uses a class-prefixed key. Comment 41300: do not build shared billing-leg cache; leave the per-card cache until DEV-2659. Confirmed out of scope.

`DashboardWidget::getCacheTTL()` is +1 year / +10 minutes / +10 seconds. `ClickhouseQuery::buildCacheKey()` is concat. The two funnel classes do not extend it.

## Phase 2 — Question Gate (2026-09-24)

Gate **CLEAN**. No open questions.

- Q1 concat versus hash: keep concat. Implement choice, not a park.
- Q2 TTL inside the settle window: 10 minutes until 8 days after month end, then +1 year. The ~2 hour minimum sits inside that span. No new duration.
- Q3 claims cache: do not add shared plumbing and do not delete the existing class-prefixed cache.

## Phase 3 — Ultra-Plan (2026-09-24)

`plan.json` and `plan.md`. Subtasks t1 ladder, t2 Y-m open month, t3 repossessions keys on the ladder, t4 per-class AC-5 docblocks.

Next at the close of this phase: **Provision (waits fleet ledger).** This phase did not mark provisioned.

## Phase 4 — Provision (2026-09-24)

Provision-only. Fleet Docker cite. Mac Herd refused. No second environment. No product PHP. No Cloud ClickHouse write.

Ledger `docs/investigations/DEV-2653/qa-prep-ledger.json` (timestamp `2026-09-24T07:31:10-05:00`, status `env_ready`). Handoff copy `.context/qa-prep-ledger.json` stays untracked. The ledger file is not committed.

- Site: `http://127.0.0.1:18081` (`clarity-qa-app-schema-gate`).
- Postgres: `127.0.0.1:15433`, databases `zod_schema_gate` and `zod_testing_schema_gate`.
- ClickHouse: strategy `docker`, container `clarity-qa-clickhouse`, HTTP `:18123`. Keyed databases `clarity_schema_gate`, `clarity_testing_schema_gate`, `zod_schema_gate`, `zod_testing_schema_gate`.
- `billing_claim_events`, `billing_claim_events_v`, and `billing_claim_events_mv` are present on Docker keyed `clarity_schema_gate` (ledger prove SELECT, table count 31).
- `cloud_ch_writes`: false. `mac_herd_refused`: true. `cloud_touched`: false.

`state.json` phase is `provisioned`. Gate stays CLEAN. Next is **Implement**.

## Drift versus the dispatch

Not material. No ping.

- The ticket **description** still states AC-5 as a single DEV-2655 pointer and still frames both funnel queries as cache work. Comments 41300 and 41303 replace that. The dispatch already used the comments. The description body was not edited.
- Comments 41312 and 41447 say a fleet ledger is ready and that provision should be marked against `docs/investigations/DEV-2653/qa-prep-ledger.json`. The plan phase stopped before that cite. Phase 4 cites the 2026-09-24 07:31 fleet refresh of that same ledger: keyed Docker databases, `billing_claim_events*` present, Mac Herd refused, Cloud ClickHouse not touched. Scope and AC are unchanged.

## Implement (2026-09-24)

Read `okf/concepts/company-metrics-dashboard.md` (cache-TTL ladder and the `class_basename` key trap) before the PHP edits. The wiki still documents the three-row ladder. The settle window is queued for a later wiki edit. No `okf/` change in this commit.

- `DashboardWidget::getCacheTTL($index, ?DateTimeInterface $monthEndedAt)` evaluates impersonation first (10 seconds), then index 0 (10 minutes), then a null month end (1 year), then a month end younger than `CLOSED_MONTH_SETTLE_FULL_SECONDS` (8 days, 10 minutes) or older (1 year). Month ends are threaded through the year-cache writers named in the plan.
- Open-month `sharedMonthCacheKey` values are `Y-m` in the viewer safe timezone for `UniqueScanCounts`, `UniqueScansLeaderboard`, and `RatioAgainstUniqueScans`. `-v1` is unchanged. The date-filter value `this_month` and `sharedRangeCacheKey` are unchanged.
- `LprRepossessions` keeps the existing shared key strings and passes `[seconds, seconds]` from `getCacheTTL(0)`. The series key still has no `:tz=`.
- AC-5 docblocks: `ScanPlateStatsBatchQuery` names the `scans_cold` N-org ceiling (60,738,996 rows / 2.44 GB) and does not mention DEV-2655. `UniqueLprCaseClaimsQuery` points at DEV-2659 / `billing_claim_events_v` and still has no `shared*CacheKey`. `LprRepossessionsQuery` points the billing leg at DEV-2659 and the hotlist legs at DEV-2657 / DEV-2660.
- `ScanPlateStatsBatchQuery::safeTimezone` was not edited.

Tests: `php artisan test --compact --filter=DashboardWidgetCacheTtlTest|UniqueScanCountsTest|UniqueScansLeaderboardTest|RatioAgainstUniqueScansTest|ScanPlateStatsBatchQueryContractTest|LprRepossessionsTest|CompanyMetricsCacheScopeTest` — 109 passed, 416 assertions, 19.47s. `vendor/bin/pint --dirty --test` passed.

On this machine, Postgres `:15433` and the site `:18081` were not listening. The filters ran against the worktree `.env.testing` (Postgres `127.0.0.1:5432`, database `zod_testing`, `CLICKHOUSE_DRIVER=pgsql`). Docker `clarity-qa-clickhouse` on `:18123` was up; its databases were `clarity_cursor_dev_2652_eeaf` and `zod_cursor_dev_2652_eeaf`, not `clarity_schema_gate`. No Cloud ClickHouse writes.

Phase is implemented. Next is Review. The pull request stays draft. Jira status was not changed.

## Review (2026-09-24)

Tip matched `f3a31dbf00559832f2780abcf3e5b14f019f11f7`. Product diff is against `origin/main` `25870f75` (SCHEMA-GATE #449). `database/schema/clickhouse-schema.sql` is not in that diff. Soft OK versus that track.

`review-gate.sh` is not in this repo. A local parser read the three lane scores and `.ai/review-manifest.json` (on disk only, gitignored, not committed).

`REVIEW_GATE: fail min=81 threshold=85 lanes=3 rn=1 scores=84/88/81 conventions=88 sources=in-session-review:3 source=lane-min`

`vendor/bin/pint --test` on the touched PHP exited 0. The filtered suite from Implement was not re-run. That run remains the worktree `.env.testing` Postgres `127.0.0.1:5432` result (109 passed), not fleet `:15433` / `:18081`.

Phase stays `implemented`. `rerun_required` is true. Product PHP is unchanged.

Act on Implement:

1. Make `StagingLiveHitLeader::dashboardCacheTTL` follow `DashboardWidget::getCacheTTL`, including the month-end settle window. Pass each month's `endOfMonth()` from `resolveLeaderMonths` and `resolveSharedMonths`. Do not apply one year TTL to every uncached `{prefix}:unique:{Y-m}` key. Test that a month ended one day ago is 10 minutes on both write paths, and that impersonation is still 10 seconds.
2. Write the open-month `sharedMonthCacheKey` in `RatioAgainstUniqueScans::fetchUniqueScansSeries` with the base ladder (index 0), not the consuming card's `getCacheTTL` override. `UniqueLprCaseClaims` floors that override at 60 seconds, and `UniqueScanCounts` skips `handleRange` when the `Y-m` key is already warm. Do not add a `shared*CacheKey` method on `UniqueLprCaseClaimsQuery`.

What held: impersonation is checked first on `DashboardWidget::getCacheTTL`; the three scan cards share a `Y-m` open-month key; `LprRepossessions` flexible pairs are `[seconds, seconds]` from `getCacheTTL(0)`; `UniqueLprCaseClaimsQuery` has no shared cache key; `ScanPlateStatsBatchQuery::safeTimezone` was not edited.

## Review FAIL fix cycle 1 (2026-09-24)

Phase stays `implemented`. Next is Review.

- `StagingLiveHitLeader::dashboardCacheTTL` now follows the `DashboardWidget::getCacheTTL` ladder, including the month-end settle window. `resolveLeaderMonths` and `resolveSharedMonths` pass that month's `endOfMonth()`. A month that ended one day ago is 10 minutes on both write paths. Impersonation is still 10 seconds, evaluated first.
- `RatioAgainstUniqueScans::fetchUniqueScansSeries` writes the open-month `sharedMonthCacheKey` with `parent::getCacheTTL(0)`, the base ladder, not the consuming card's `getCacheTTL()` override. `UniqueLprCaseClaims` still floors its own class-prefixed keys, and its monthly pair stays `[3600, 86400]`. No `shared*CacheKey` was added on `UniqueLprCaseClaimsQuery`.
- `ScanPlateStatsBatchQuery::safeTimezone` was not edited. The settle-window wiki page stays queued. No `okf/` change.

`vendor/bin/pint --dirty --test` passed. `php artisan test --compact --filter=StagingLiveHitLeaderTest|DashboardWidgetCacheTtlTest|UniqueScanCountsTest|UniqueScansLeaderboardTest|RatioAgainstUniqueScansTest|ScanPlateStatsBatchQueryContractTest|LprRepossessionsTest|CompanyMetricsCacheScopeTest` — 126 passed, 5 skipped, 489 assertions, 20.92s. Worktree `.env.testing`, Postgres `127.0.0.1:5432` `zod_testing`, `CLICKHOUSE_DRIVER=pgsql`. No Cloud ClickHouse writes. Four skips were already `requireRealClickhouse()`. The overview last-month test now uses that same guard, because UniqueScanCounts' uncaught `WITH ROLLUP` query fails on the pgsql stand-in (reproduced with `origin/main`'s `UniqueScanCounts`). CI still runs it under `CLICKHOUSE_DRIVER=clickhouse`.

## Query-owned scan cache (2026-09-24)

Phase stays `implemented`. Next is Review.

cache TTL ladder extracted to ResolvesDashboardCacheTtl; shared writes use baseDashboardCacheTTL. `ScanPlateStatsBatchQuery` now owns the shared scan cache: `rememberedRange()` stores `handleRange()` under `sharedRangeCacheKey`, and `rememberMonthSeries()` / `rememberOpenMonthTotal()` read or fill the `Y-m` ints under `sharedMonthCacheKey`. `UniqueScanCounts`, `UniqueScansLeaderboard`, and `RatioAgainstUniqueScans` call those methods. Card-owned keys (ported scan totals, `UniqueLprCaseClaims` floor, `LprRepossessions` `[seconds, seconds]` from `getCacheTTL(0)`) stay on the widgets. `StagingLiveHitLeader` still writes the hit shared keys and uses `baseDashboardCacheTTL`, including `endOfMonth()` per month. `UniqueLprCaseClaimsQuery` has no shared cache key. `ScanPlateStatsBatchQuery::safeTimezone` was not edited. No `okf/` change.

`vendor/bin/pint --dirty --test` passed. The same filter — 126 passed, 5 skipped, 489 assertions, 23.40s. Worktree `.env.testing`, Postgres `127.0.0.1:5432` `zod_testing`, `CLICKHOUSE_DRIVER=pgsql`. No Cloud ClickHouse writes.

## Agent QA PASS (2026-09-24)

Phase is **Agent QA PASS**. Next is **Verify/Build Record**.

Tip `eddf64399101f5f2dd18d65264823f531d0cbcdb` matches PR #450. Suites on Mac Herd (worktree `.env.testing`, Postgres `127.0.0.1:5432` `zod_testing`, `CLICKHOUSE_DRIVER=pgsql`):

- `vendor/bin/pint --dirty --test` passed.
- `php artisan test --compact --filter=StagingLiveHitLeaderTest|DashboardWidgetCacheTtlTest|UniqueScanCountsTest|UniqueScansLeaderboardTest|RatioAgainstUniqueScansTest|ScanPlateStatsBatchQueryContractTest|LprRepossessionsTest|CompanyMetricsCacheScopeTest` — **126 passed, 5 skipped, 489 assertions**. Skips are `requireRealClickhouse()` under the pgsql stand-in.

All acceptance criteria SATISFIED (AC-1 execution-count ceiling, AC-2 class-neutral keys, AC-3 TTL ladder + impersonation, AC-4 open-month `Y-m`, AC-5 per-class docblocks / no claims shared key, closed-month settle window). Live browser **N/A** — plan `ui_impact` false; ACs are suite-proven contracts. Coverage note: agent suites only. Mac cannot reach fleet `:18081`. No Cloud ClickHouse writes.

Evidence stays on disk only (not committed): `.context/qa-agent-report.md`, `.context/qa-ac-results.json`, `.context/qa-plan-agent.md`, `.context/qa-plan-human.md`, `.context/qa-prep-ledger.json`. `docs/investigations/DEV-2653/live-qa-evidence/` is empty.

Draft PR stays draft. Jira status was not changed. Build Record not posted.

## Verify / Build Record (2026-09-24 ~09:25 CDT)

Product tip `eddf64399`. Board confirm: HOLD lifted; real Docker CH Agent QA **223/0/0/793** at `CLICKHOUSE_DRIVER=clickhouse` `127.0.0.1:18123`; `cloud_touched=false`.

Hard bar PASS (STRONG). Soft flags recorded: Leaderboard compositional (not Ratio-style `scansCalls==0` E2E); seam counters are stubs; Mac `pgsql` bridge does not clear Preflight #18 — Build Record cites real-CH only for hit/miss/#18.

Thin conventions #16 PASS (Pint; conventions score 88; no okf/). SCHEMA-GATE soft OK.

`record.review.rerun_required=true` at fail sha `f3a31dbf` with 9 material PHP/test files since — build-record-gate + rework-gate blocked until RN=2. Phase stays `verify` (not `done`). Do not undraft / QA Ready / merge. No Status Board post from Verify.


## Review RN=2 — 2026-09-24 09:35 CDT

- Product tip reviewed: `eddf64399101f5f2dd18d65264823f531d0cbcdb` (not closeout HEAD).
- Lanes: correctness 93 · conventions 88 · blast-radius 92 · min 88 ≥ 85.
- Act-ons from fail-review `f3a31dbf` cleared (settle ladder on hit keys; open shared scans via query-owned TTL).
- `record.review.rerun_required=false` · `reviewed_sha` set to tip.
- Next: PM re-runs build-record-gate + rework-gate → undraft/CI/QA Ready. No undraft from Review.
