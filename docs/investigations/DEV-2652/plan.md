# DEV-2652 — Ultra-Plan

Input: `docs/investigations/DEV-2652/prep-context.json`. Gate **CLEAN**. AC5 **defer**.

Machine contract: `plan.json`. This page is the reading copy.

## What ships

The three ratio numerators move onto the viewer's clock. They already share `RatioAgainstUniqueScans` and call `ScanPlateStatsBatchQuery`, which already sanitises the zone, converts bounds with `toUtcDatetime()`, groups the monthly series in that zone, and leaves the `WHERE` on raw UTC `scanned_at`. This plan changes the zone the three cards pass in. It does not re-derive those helpers.

`SERIES_ZONE = 'UTC'` stays on each card for the headline, the labels, and the denominator. The numerator gets a separate `numeratorZone()`.

## What does not ship

Claims `ts` stays `toDate(fromUnixTimestamp(be.ts), 'UTC')` in `UniqueLprCaseClaimsQuery` and `LprRepossessionsQuery`. Source: Dalton via Galadriel. Those two query files are not in any subtask `target_files`.

Reconciliation impact: historical claims-funnel figures stay on UTC, which is the clock Brek's Repo Report reconciles against. A viewer-zone claims shift would move every historical figure off that reconciliation. `db_repos` on `LprRepossessions` is that same `be.ts` bucket, not `repo_date`.

`RecoveryTotalsBatchQuery::sharedMonthCacheKey()` stays `recovery-totals:month-v1:org={id}:{month}`. `repo_date` has no time-of-day, so a `:tz=` segment would fork a timezone-free value.

Staging Live Hit stays on DEV-2654.

## The seam, stated

Near a zone boundary the numerator's "this month" and the headline's "this month" are different calendar months. Example already used by the headline tests: `2026-08-01 02:00 UTC` is still July in `America/Chicago`.

- Headline `getMonthlyData()` keeps returning the UTC August bucket. Existing tests stay green.
- The numerator batch is called with `timezone=America/Chicago` and a span that ends in July.
- The ratio zips positionally: index i is "i months before now" on each clock. The docblock says so. The labels stay on the headline clock, because they already co-vary with the bars on purpose.

That is the mixed clock. Do not relabel the bars onto the viewer calendar, and do not run `repo_date` through a timezone.

For a real IANA zone, `safeTimezone()` is the identity, so today/last_7d agrees with a viewer-local day. For `CST`, `PST`, and `+05:00` it returns `UTC`. Mint the numerator bounds with `now(tz: numeratorZone())` after sanitising. Do not call `now(tz: $raw)` first: `US/Central` throws inside `DateTimeZone` on this PHP, and `safeTimezone()` only stays non-throwing because it uses `in_array`.

Month formulas stay inside `fetchUniqueScansSeries()` (it already hands naive local strings to `handle()`, which calls `toUtcDatetime()`). Do not add a second `currentMonthWindow()` / `lastMonthWindow()` fetch. Those helpers are the reference if a new month-bounded `handleRange()` is ever added. This plan does not add one. today/last_7d keep `resolveCardRange()`'s day expressions, evaluated in `numeratorZone()` rather than the raw `users.dti` value.

## Cache keys

| key | action |
| --- | --- |
| `ScanPlateStatsBatchQuery::sharedMonthCacheKey` | function unchanged. `:tz=` already present. The new zone forks it. |
| `ScanPlateStatsBatchQuery::sharedRangeCacheKey` | same, for the today/last_7d numerator. Pass the zone the bounds were minted in. |
| `RecoveryTotalsBatchQuery::sharedMonthCacheKey` | unchanged. No `:tz=`. |
| `LprRepossessionsQuery::sharedMonthlySeriesCacheKey` | unchanged. UTC-identical while claims stay UTC. A `:tz=` segment would rescan the funnel per viewer. |
| UniqueLprCaseClaims `monthly-series:` widget key | unchanged. Same reason. |

## DAG

`t1` adds `numeratorZone()` on the trait. Then `t2`, `t3`, and `t4` (the three cards) run in parallel. `t5` updates the two "DEV-2652 owns the open question" docblock pointers after the cards land.

One ticket. About ten files, one concern. A trait without callers is dead, and a caller without the helper would copy `safeTimezone()` into three classes, which is the duplication DEV-2651 just removed.

## Tests

Keep green:

- `UniqueLprCaseClaimsTest::test_month_buckets_align_on_utc_not_the_viewers_timezone`
- `LprRepossessionsTest::test_month_buckets_align_on_utc_not_the_viewers_timezone`
- `UniqueLprCaseClaimsLabelAxisTest::test_graph_labels_follow_utc_not_the_viewers_timezone`

Add, per card, a numerator assertion at `2026-08-01 02:00 UTC` for an `America/Chicago` viewer: the batch zone is `America/Chicago`, the span ends in July, and `getMonthlyData()` is still August. Add CST / PST / `+05:00` (and `US/Central`) cases that record zone `UTC` and do not throw. Add one assertion that `RecoveryTotalsBatchQuery::sharedMonthCacheKey(1, '2026-08')` equals `recovery-totals:month-v1:org=1:2026-08`.

## Perf

Not a new query shape. `scans_cold` is already read this way by `UniqueScanCounts` and the leaderboard, in the viewer zone. Non-UTC viewers take one cold miss on `sharedMonthCacheKey` because the key gains their zone in the existing `:tz=` segment. No pre-build measurement.

## After approval, not in this plan

`okf/concepts/company-metrics-dashboard.md` and `okf/concepts/billing-events.md` still describe the calendar as open, or do not mention the Brek deferral. Update them after the change is approved. Not during implementation.

## Next

Ticket provision / Aragorn CLI (qa-prep). No ledger in this run.
