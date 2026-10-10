# DEV-2468 — Plan

**Route:** feature · **Base:** main · **Node count:** 1 (single-node fast path)

## Why one node

Strictly sequential: the widget cannot be written before the query payload it consumes, and
the sibling's lockstep edit cannot be verified before both exist. No disjoint file partition
exists, so a fan-out would serialise anyway with extra worktree cost. Same shape as DEV-2467.

## What this ticket actually is

Smaller than the ticket text implies. DEV-2409 #394 already shipped the card as a registered
stub at the exact mockup slot, and DEV-2467 #393 already shipped the query and a deliberately
class-neutral cache key so this card could ride its scan. The work is: swap the base class,
wire the two reads, and avoid two traps this repo has already been bitten by.

## Operator decisions folded in

| # | Decision |
| --- | --- |
| Q1 | **Option A.** The 13 bars are the ORG-TOTAL monthly trend behind a new class-neutral shared month key both cards read. The ranked kit list covers the selected window only. Option C (per-kit stacked chart) rejected: it needs an unmeasured shape, and a stack would visually overstate the org total because per-kit distinct plates do not sum. |
| Q2 | **Re-baseline first — done.** DEV-2466's envelope reproduces. |

## Perf gate — go, on a fresh measurement

`perf_validation.required = false`: Option A adopts no new query shape. Re-measured by the
driver on 2026-09-10, **interleaved** (never sequential batches — DEV-2464's lesson):

| shape | run 1 | run 2 | run 3 | DEV-2466 recorded |
| --- | --- | --- | --- | --- |
| 13-month org-total (`handle()`) | 1,152 ms | 1,103 ms | 1,056 ms | 1,102 ms |
| single-window `WITH ROLLUP` (`handleRange()`) | 676 ms | 656 ms | 584 ms | 606 ms |

Both reproduce within noise. Adding `anyLast(kit_name)` reads a column already present in the
rows already being scanned — no extra rows, no extra round trips.

### The 20 s timeout, explained and dismissed

An earlier probe saw DEV-2467's **already-shipped** 13-month query killed at 20,000 ms. That
was neither a regression nor the query shape:

- `readonly_user` carries a **standing** `max_execution_time = 20` (seconds) profile setting.
- A **cold** 13-month range costs ~5.7× its warm cost — measured 4,861 ms cold vs 853 ms warm
  on the immediate repeat.

A fully cold first touch (cold TLS path + cold partitions) exceeded the cap. No defect exists
against DEV-2467, and no Jira ticket was filed. Recorded as a residual: Option A adds zero new
scans, so it adds zero new exposure, and DEV-2467's existing handler already absorbs this
correctly (`report()` + a 60 s marker + render zeros, never a fabricated value cached for a year).

## Sizing gate — fired at 8 files, split REJECTED

One metric, one card, one concern. Four of the eight files are the tests this ticket's contract
mandates, one is a single-token deletion, one is an optional additive e2e const, and one is a
small lockstep edit forced by sharing the payload. The DEV-2409 epic is already split
one-metric-per-ticket, so this ticket **is** the split unit — splitting further ships a
query-class change with no consumer. DEV-2460 and DEV-2467 each fired this gate at 8 files and
recorded the same rejection.

## Ordered steps

1. Baseline the AC-relevant tests **before** touching anything.
2. TDD `ScanPlateStatsBatchQuery`: `anyLast(kit_name)`, richer payload, **key version bump**,
   new class-neutral `sharedMonthCacheKey()`. Red → green.
3. Lockstep-update `UniqueScanCounts` for the payload + month key. Its tests stay green.
4. TDD the `UniqueScansLeaderboard` rewrite. Red → green.
5. Remove the dead `getStatColor()` key; add the card to the gated allowlist.
6. **Mutation-verify the two trap tests** — the `chartSeries()` test must FAIL against the
   inherited `array_sum` default, and the ranking test must FAIL if `kit_id = 0` is left in.
7. `composer format`.
8. Full suite via `php -d memory_limit=2G vendor/bin/phpunit`, against the step-1 baseline.

## Invariants the worker may not violate

- No `FINAL` on `scans_cold` — now justified on **both** axes (`ocr` by DEV-2466 sec 5, `kit_id`
  by this ticket's own measurement: 0 divergent of 4,951 fleet-wide duplicate-id groups).
- No hardcoded `clarity.` prefix — `static::clarityDb()` only. No `scans_hot`.
- No routing an org through `kit_id` — `org_id` leads the sort key.
- No call through `ClickhouseQuery::query()` — it swallows `Throwable` to `[]`, caching an outage
  as a confident zero.
- **Never sum per-kit values into a displayed total.** The `kit_id = 0` rollup is the only org
  total. This is exactly why `chartSeries()` must be overridden.
- **Never key a result by `kit_name`** — names are not unique within an org.
- Shared cache keys must be **byte-identical** across both cards, or both miss and each scans.
- **Version the range key** when its payload shape changes — an in-flight old-shaped entry read
  by new code is a live break, not a test failure.
- Preserve DEV-2467's raw-vs-allowlisted timezone split exactly.
- `getCardKey()` keeps returning `'unique-scans-leaderboard'`.
- Do **not** re-attempt a `Cache::lock` — two attempts were withdrawn on DEV-2467.
