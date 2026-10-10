# DEV-2654 — Ultra-Plan

**Phase:** plan. **Gate:** CLEAN. **Base:** `main` @ `d8e70005479e14ba3ea4db1d194ab30a332e3e3e` (the shared cache-helper work, PR #450).

## Ship surface

Reshape the Staging Live Hit leaderboard onto the Unique Scans rollup, point the Staging Live Hits headline at rollup key `0`, and replace the remaining hand-rolled ranking cache with a class-neutral `rememberedRange` that uses the shared cache-helper TTL ladder. Part 1b (viewer-local → UTC) is that same window rewrite. AC6 is a written audit. AC7 is decided below.

No Zod. No Cloud ClickHouse writes. No `okf/` edits. Do not mark `phase=provisioned`.

## What is already true

`d8e70005` is an ancestor of this branch. These helpers are on that commit:

- `ResolvesDashboardCacheTtl::baseDashboardCacheTTL` — impersonation 10 seconds, then index 0 is 10 minutes, a closed month younger than 8 days stays at 10 minutes, otherwise 1 year.
- `ScanPlateStatsBatchQuery::rememberedRange`, `sharedClosedMonthTtl`, `rememberMonthSeries`, `rememberOpenMonthTotal`.
- `StagingLiveHitLeader` already `use`s the trait. `dashboardCacheTTL()` is gone. The ticket text still names it.

Still hand-rolled: `LEADER_CACHE_PREFIX` and `leaderCacheKey()` (the 13-month per-kit ranking cache). `this_month` still returns the last bucket of `getMonthlyLeaderData()` (`StagingLiveHitLeader.php` around line 330).

`UniqueScansLeaderboard::resolveRangeAndTimezone` (lines 350-366) is the zone split to copy. `fetchRangeRollup` (lines 390-393) is `ScanPlateStatsBatchQuery::rememberedRange`. Do not call that method from the hit leader. It reads `scans_cold`.

`StagingLiveHits` headline is `SharedHitStatsData::getCardTotal` → `HitPlateStatsBatchQuery`. That is a second scan. Live, Historical, and Staging Historical stay on that batch.

## Decisions

| # | Decision |
| --- | --- |
| Q1 | **A, satisfied.** The shared cache-helper work is merged. AC5 stays. B and C stay closed. |
| Ranking | One window for every date arm, including `this_month`. No `LIMIT 10 BY month`. |
| Sparkline | Stays the 13-point **org-total** series already shared with the hit cards. It is not the ranked list. Per-kit `uniqExactIf` does not sum to the org total. |
| Share | Card headline and leaderboard read one `rememberedRange` on **all four** arms, including `last_month`. Unique Scans exempts `last_month`. AC2 does not. |
| Key 0 | Org total only. Drop it before the top-10 sort. Never render it as a kit. |
| Zone | `this_month` / `last_month`: `safeTimezone` + `currentMonthWindow` / `lastMonthWindow`. `today` / `last_7d`: raw viewer zone + `resolveCardRange()`. Null timezone is `'UTC'`. |
| UTC | `ScanPlateStatsBatchQuery::toUtcDatetimeFor` inside the hit rollup, before interpolation. Do not edit that class. `safeTimezone` does not throw. |
| AC5 | New `StagingLiveHitLeaderQuery::sharedRangeCacheKey` (`-v1`, `:tz=`, no class basename) and `rememberedRange`. TTL is `baseDashboardCacheTTL(0)` (10 minutes, 10 seconds while impersonating). Do not year-cache the selected window. Do not restore `dashboardCacheTTL`. |
| AC7 dates | **Accept** viewer-local `Y-m-d` against `repo_date`. It is a Date. UTC conversion would move the calendar day. Replace `addslashes` with a `Y-m-d` assert. |
| AC7 swallow | **Resolve** on `RecoveryLeader` only. Stop calling `ClickhouseQuery::query()`. `report()` and return `[]` without caching the failure. Do not edit `ClickhouseQuery`. |
| AC6 | Written finding. Cite the view-without-FINAL Cloud measure for that gap. Do not close or implement that bug. Schema `ORDER BY` is `(recovery_id, scan_id)` (`clickhouse-schema.sql` line 37). The view-without-FINAL ticket sentence that says `hit_id` is stale. |
| RecoveryCounts batching | QA Ready, separate claim. Do not edit `RecoveryCounts.php` or `RecoveryTotalsBatchQuery.php`. |

## Subtasks

1. **t1 — query.** Rollup SQL, UTC bounds, `rememberedRange`. Test: `StagingLiveHitLeaderQueryTest`.
2. **t2 — leader widget.** Depends on t1. Every arm uses the rollup. Delete the 13-month ranking cache. Keep the org-total sparkline. Test: `StagingLiveHitLeaderTest`.
3. **t3 — card headline.** Depends on t1. `StagingLiveHits::getCardTotal` reads `totals[0]` for every arm. Do not edit `SharedHitStatsData`. Same test file as t2; can run beside t2 after t1.
4. **t4 — Recovery Leader.** Independent. `Y-m-d` assert, leave `ClickhouseQuery::query()`, `timezone ?? 'UTC'` on the graph labels. Test: `RecoveryLeaderTest`.
5. **t5 — audit.** Independent write-up `docs/investigations/DEV-2654/recovery-scans-audit.md`. Read-only aggregates on the fleet Docker ClickHouse once that ledger exists. A pgsql mirror is not the measurement. No `CREATE` / `ALTER`.

t4 and t5 can run beside t1. t2 and t3 wait on t1.

## Invariants

- `uniqExactIf(ocr, hit_type = 'S')`, `kit_org_id`, `kit_id`. No bare `count()` on `hits_cold`. No `hotlist_org_id` scope (`forOrg()` drops Staging Live).
- Never sum per-kit values into the displayed org total.
- Never key the Staging Live result by `kit_name`.
- Shared range key is byte-identical on the card and the leaderboard, or both miss.
- `ClickhouseQuery::query()` stays as it is (PG-029). New Staging Live and Recovery Leader reads do not use it.
- `company-metrics-dashboard.md` still calls this leader Pro-gated. The class has no `canViewCardData()`. Code wins. Wiki edit waits until approval.
- 90-day assist window stays in the Cloud scoring job. This query filters `repo_date`.

## Next

Provision (waits fleet ledger: site, Postgres, Docker ClickHouse). Agent QA time caps apply at Verify, not here.
