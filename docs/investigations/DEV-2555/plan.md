# DEV-2555 — Ultra-Plan

Input: `docs/investigations/DEV-2555/prep-context.json`. Gate **CLEAN**.

Machine contract: `plan.json`. This page is the reading copy.

## What is already on main

The prior Total Repossessions batch landing pointed Total Repossessions at `RecoveryTotalsBatchQuery::handle()` and deleted `UniqueScansToRepo`. On `main` @ `4833e815`:

- `RecoveryCounts::fetchRepoSeries()` probes `sharedMonthCacheKey()` and gap-fills with one `handle()` call.
- The write is a bare int. The key is `recovery-totals:month-v1:org={id}:{month}` with no `:tz=`.
- The scans-per-repo denominator is `getMonthlyData()` on this same card. It does not run a second repo query.
- Today / Last 7 Days call `handleRange()` and `cache()->remember()` the int under `sharedWindowCacheKey()`, which includes the raw viewer timezone.
- `test_past_months_are_cached_per_month_and_not_refetched` asserts one batch call and reads the shared month key back as `9`.

The Jira description still describes the pre-merge card (thirteen Eloquent queries, a live `UniqueScansToRepo`, and the earlier window-key correction's withdrawn un-share). The code won.

## What ships

Two files. No SQL, no key-string change, no rendered-value change.

1. `RecoveryTotalsBatchQuery` docblocks. Delete the claim that `RecoveryCounts` still uses Eloquent and that sharing the key is a future one-liner. Say what main does. Say the month key stays `-v1` and timezone-free. Say the window key stays shared as an int, with `tz`, because the window is viewer-local. Say the outer `buildWidgetCacheKey()` remember in `currentPeriodTotal()` stays. Cite the earlier window-key correction's re-share.

2. `RecoveryCountsTest`. `assertIsInt` beside the existing month `assertSame(9, ...)`. On the today path, `cache()->get(sharedWindowCacheKey(...))` must be int `40`. That read fails if the shared write is removed. The current window test does not: both doubles are `WindowStubbingRecoveryCounts`, so they share the class-prefixed outer key. That is the vacuous shape the earlier window-key correction already hit.

## What does not ship

- Restoring `UniqueScansToRepo`.
- `ScanPlateStatsBatchQuery`, its cold mutex, or any cache helper from the parked scans-stats follow-up.
- A `-v1` bump. The payload stays a bare int.
- `:tz=` on the month key. `repo_date` has no time-of-day. The viewer-timezone numerator landing left this key alone.
- `safeTimezone()` on the repo window. `resolveCardRange()` and the window key both use the raw user timezone. Moving one would split the window.
- Removing `currentPeriodTotal()`'s class-prefixed cache. The ratio's today / last_7d arm calls that method, so the headline and the description already share it.

## Cache keys

| key | action |
| --- | --- |
| `sharedMonthCacheKey` | unchanged. int. no `:tz=`. |
| `sharedWindowCacheKey` | unchanged. int. keeps `:tz=`. Test must read it. |
| `buildWidgetCacheKey` on the today/last_7d headline | unchanged outer layer. Documented, not deleted. |

## DAG

`t1` and `t2` are independent. One PR. About two files.

## Tests

Keep green:

- `RecoveryCountsTest::test_past_months_are_cached_per_month_and_not_refetched` (`reposCalls === 1`)
- `RecoveryCountsTest::test_the_shared_cache_key_strings_are_pinned`
- `RecoveryTotalsBatchQueryTest::test_the_window_total_matches_the_eloquent_path_recovery_counts_uses` (ClickHouse group; parity oracle, not the live card path)

Add:

- `assertIsInt` on the cached closed month.
- `assertIsInt` / `assertSame(40, ...)` on `sharedWindowCacheKey` after one today render.

## Perf

Not a new query. No measurement in this ticket.

## Next

Aragorn provision (qa-prep). No ledger in this run. Frodo waits until provision.
