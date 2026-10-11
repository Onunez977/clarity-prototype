# DEV-2653 — Ultra-Plan

**Phase:** plan. **Gate:** CLEAN. Input: `prep-context.json`. Base: `main` @ `25870f75661f07a27a69124a414e557d4c11fff0`.

## Ship surface

PHP cache sharing for `ScanPlateStatsBatchQuery` (primary). Keep `LprRepossessionsQuery`'s class-neutral keys and run them through the TTL ladder. Do not add billing-leg shared cache on `UniqueLprCaseClaimsQuery`. Closed-month settle window on `DashboardWidget::getCacheTTL`. AC-5 is a docblock per class.

No Zod. No Cloud ClickHouse writes. No DEV-2659 query rewrites. No DEV-2654. `okf/` waits until approval.

## What is already true

`sharedMonthCacheKey()` and `sharedRangeCacheKey()` are class-neutral concat keys with `:tz=`. `UniqueScanCounts` writes them. `UniqueScansLeaderboard` reads the month keys and shares the range key when the window matches. `RatioAgainstUniqueScans` (used by `UniqueLprCaseClaims`, `LprRepossessions`, and `RecoveryCounts`) reads and writes the same month namespace.

Past months already use `Y-m`. The open month does not: `UniqueScanCounts` and the leaderboard sparkline use the rolling token `this_month`, and the ratio trait uses `Y-m`. A cold dashboard render still scans `clarity.scans_cold` again for that bucket. The date-filter value `this_month` is a different string and stays on `sharedRangeCacheKey()`.

`LprRepossessionsQuery::sharedMonthlySeriesCacheKey()` and `sharedWindowCacheKey()` exist. `LprRepossessions` passes them to `heldFlexible()` with `[3600, 86400]`, so the 10-second impersonation collapse never runs. `HeldFlexibleCache` only treats a value as warm when both the key and `illuminate:cache:flexible:created:{key}` are present.

`UniqueLprCaseClaimsQuery` has no shared-key helper. The card caches through `buildWidgetCacheKey()`, which prefixes the class name. Comment 41300 leaves that cache in place until DEV-2659.

`getCacheTTL($index)` is +1 year for a non-zero index, +10 minutes for index 0, and +10 seconds while impersonating. A just-closed month is year-cached on the first read.

Schema objects for the later DEV-2659 read are already on this main: `clarity.billing_claim_events` (line 18), `clarity.billing_claim_events_v` (line 85), `clarity.billing_claim_events_mv` (line 102). This plan does not query them.

## Decisions

- **Key shape:** keep concat. Hashing would fork keys that are already warm.
- **Settle window:** optional `$monthEndedAt` on `getCacheTTL`. Younger than 8 days → the existing 10-minute TTL. That covers the ~2 hour minimum. Eight days or older → +1 year. Impersonation is still 10 seconds, checked first. Callers that omit the month end keep +1 year.
- **Claims cache:** do not add it, and do not remove the existing class-prefixed `heldFlexible` pair.

## Subtasks

1. **t1 — ladder.** `DashboardWidget::getCacheTTL($index = 0, ?DateTimeInterface $monthEndedAt = null)`. Pass each known month end from `getMonthlyData`, the `last_month` headline arm, `UniqueScanCounts` past-month puts, `RatioAgainstUniqueScans`, `RecoveryCounts`, and `SharedHitStatsData`. Keep `UniqueScanCounts::IMPERSONATED_PAST_MONTH_TTL_MINUTES`. Test: `DashboardWidgetCacheTtlTest`.
2. **t2 — one open-month key.** Depends on t1. Month-series readers and writers use `Y-m` for the open month. Do not bump `-v1`. Do not touch range keys or the date-filter value `this_month`. Count executions, not time. Tests: `UniqueScanCountsTest`, `UniqueScansLeaderboardTest`, `RatioAgainstUniqueScansTest`, `ScanPlateStatsBatchQueryContractTest`.
3. **t3 — repossessions keys stay.** Depends on t1. Same key strings. TTL pair comes from `getCacheTTL(0)` as `[seconds, seconds]`. Series key stays free of `:tz=`. Docblock: billing leg DEV-2659; hotlist legs DEV-2657 / DEV-2660. Test: `LprRepossessionsTest`.
4. **t4 — AC-5 text.** `ScanPlateStatsBatchQuery` names the scans_cold N-org ceiling and does not point at DEV-2655. `UniqueLprCaseClaimsQuery` points at DEV-2659 and `billing_claim_events_v`. A source test asserts that class still has no `shared*CacheKey` method. No SQL.

t3 and t4 can run beside t2 after t1. t4 should not duplicate the repossessions paragraph if t3 already wrote it.

## Next

Provision (waits fleet ledger). Do not mark `phase=provisioned` here. The SCHEMA-GATE database name `clarity_schema_gate` is not the product cite.
