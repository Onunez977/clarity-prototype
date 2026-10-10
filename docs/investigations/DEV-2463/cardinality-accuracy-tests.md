# DEV-2463 — testing the production cardinality against current Pro orgs

Read-only against production ClickHouse Cloud as `readonly_user`, **2026-09-08**. Server-side
figures from the `X-ClickHouse-Summary` response header (no `READ ON REMOTE` grant needed).
Aggregates and counters only — no raw rows committed. Helper: `prod-query-helper.py`
(stdlib `urllib`; the credential never leaves the process and is read from chlocal's `load_env()`,
so the worktree `.env` stays pointed at local ClickHouse).

## Why these tests exist

`perf-measurement.md` sized this card **platform-wide plus one org (165)**. The card only ever
renders for **entitled** orgs, and the "29 entitled orgs / 14% read zero" denominator came from a
**SQL predicate hand-reimplemented** from `Org::isProEnabled()`. That predicate had only ever been
verified by *reading* the code — the same weak evidence that `shipped-class-verification.md` exists
to avoid for the target figures. Two questions therefore remained open:

1. Is the entitled population actually 29, per the code rather than per my paraphrase of it?
2. Does the ~40M-row envelope hold **per Pro org**, or only platform-wide?

## Test 1 — population parity: PASS (exact)

Two independent evaluators over the same inputs (every production org carrying any entitlement
key: **4,118** of 23,091 `org_meta` rows).

- **(A) the shipped code.** `Org` + `OrgMeta` hydrated **in memory** from production
  `org_meta.properties`, then the real `Org::isProEnabled(FeatureEnum::COMPANY_METRICS)` called.
  Fields were pulled with `JSONExtractRaw` and `json_decode`d, so types are exact — a JSON `false`
  and a JSON `"false"` behave very differently in that method, and collapsing them to strings would
  have faked agreement.
- **(B) the SQL predicate** used to produce the denominator figures.

| | entitled |
| --- | --- |
| (A) shipped `isProEnabled()` | **29** |
| (B) SQL predicate | **29** |
| in B but not A (over-count) | **0** |
| in A but not B (under-count) | **0** |

**The two agree on the exact same set of 29 ids**, not merely the same count — an id-level diff was
run precisely because equal counts can hide offsetting errors. Resolved tiers among the entitled:
**27 `clarity_pro` + 2 `clarity_pro_plus`**. The other 4,089 resolve `basic` (4,076) or `pro_lite`
(13), neither of which grants `COMPANY_METRICS`.

### What the shape census showed (and why the predicate survives)

| field | production values |
| --- | --- |
| `platform_repo_pro` | `true` ×4,116, `false` ×2 — all real JSON booleans, no `"false"` string landmine |
| `platform_repo_pro_company_metrics` | **absent on all 4,118** |
| `platform_repo_pro_tier` | absent ×4,048, `"clarity_pro"` ×52, `"pro_lite"` ×14, `"basic"` ×2, `"clarity_pro_plus"` ×2 |
| `platform_repo_pro_downgrade_effective_at` | **present on 0** |

Three consequences worth recording:

- **The individual-flag branch never fires in production.** Every entitlement decision goes through
  the tier fallback, so `ProTierEnum::features()` is the whole story today.
- **`tier` absent → `CLARITY_PRO`** by `proTier()`'s backward-compat arm, and that tier *does* grant
  `COMPANY_METRICS`. So entitlement hinges almost entirely on `platform_repo_pro` **and a
  non-expired `platform_repo_pro_end`** — which is why 4,116 pro-flagged orgs collapse to 29.
- **The downgrade branch is dead code in production today** (`downgrade_effective_at` present on
  zero orgs). It is still exercised by the predicate and by `proTier()`, but nothing tests it live.

### A false alarm, recorded

While reading the code I suspected the tier-fallback branch skipped the `$pro` check, since it
returns `in_array($enum, $tier->features(), true)` with no `&& $pro`. **It does not.** `proTier()`
opens with `if (! $this->isProEnabled()) return ProTierEnum::BASIC;`, and `BASIC` has zero features,
so `$pro` is enforced transitively. No bug.

## Test 2 — per-org envelope invariance: PASS (0.00% spread)

SQL rendered **out of the shipped class by reflection**, not retyped. 11 orgs × 2 rounds = 22 runs,
**interleaved with a reshuffle each round** — never sequential per-org batches, which is how the
earlier 0.31s median came out unrepresentative (`perf-measurement.md` §5).
Window: Jul 2026 UTC, the month whose platform totals are already validated.

| org | all | successful | deduped | `read_rows` | peak mem | wall min/med/max |
| --- | --- | --- | --- | --- | --- | --- |
| platform-wide (0) | 15,128 | 14,288 | **11,975** | 40,046,830 | 241 MB | 0.36 / 0.37 / 0.38 s |
| 1143 Relentless Recovery | 429 | 419 | 383 | 40,046,830 | 236 MB | 0.36 / 0.37 / 0.38 s |
| 469 Reliable Recovery Services Inc. (IL) | 640 | 606 | 555 | 40,046,830 | 237 MB | 0.36 / 0.36 / 0.36 s |
| 1912 | 145 | 142 | 135 | 40,046,830 | 236 MB | 0.37 / 0.37 / 0.37 s |
| 1165 | 7 | 7 | 7 | 40,046,830 | 236 MB | 0.35 / 0.36 / 0.36 s |
| 28354 | 125 | 122 | 86 | 40,046,830 | 242 MB | 0.35 / 0.36 / 0.37 s |
| 665727 | 52 | 47 | 38 | 40,046,830 | 237 MB | 0.37 / 0.40 / 0.42 s |
| 697172 | 46 | 41 | 31 | 40,046,830 | 247 MB | 0.36 / 0.36 / 0.37 s |
| 2130 | 47 | 47 | 44 | 40,046,830 | 243 MB | 0.37 / 0.38 / 0.38 s |
| 2218 | 0 | 0 | 0 | 40,046,830 | 239 MB | 0.36 / 0.36 / 0.36 s |
| 714034 | 0 | 0 | 0 | 40,046,830 | 230 MB | 0.36 / 0.36 / 0.36 s |

**`read_rows` is 40,046,830 on all 22 runs — min == max, 0.00% spread.** An org-scoped render costs
exactly what a platform-wide one costs, including for orgs whose answer is `0`. This confirms the
"nothing prunes" mechanism directly rather than by inference, and it makes the fleet cost pure
arithmetic:

> **29 entitled orgs × 40.05M rows = ~1.16B rows read per full cold sweep** (~53 GB), at ~240 MB
> peak ClickHouse memory per query.

Two incidental confirmations: the 13-month **bucketed** query also read exactly 40,046,830 rows
(the span really is free), and platform-wide reproduced **15,128 / 14,288 / 11,975** again, from
the shipped class.

Note `read_rows` is now 40,046,830 vs 39,952,007 in `perf-measurement.md` — the table grew by ~95k
immutable appended rows between measurements. Expected, not drift.

Peak memory here (230–247 MB) is roughly half the 489 MB recorded earlier; that 489 MB was the
observed max across sessions including the 13-month bucketed shape under contention. Treat
~240 MB as typical and 489 MB as the observed ceiling.

## Still not tested

- **Concurrency.** Every figure is one query at a time. N orgs cache-missing simultaneously remains
  unmeasured — the same open item DEV-2464 carried. Testing it means a deliberate load test against
  production Cloud; not run.
- **Cold reads.** All runs are warm and the cluster was quiet (0.35–0.42 s). Treat ~2–3 s as the cold
  figure per `perf-measurement.md` §6.
- **The 30-minute entitlement cache.** `isProEnabled()` memoises `$pro` under
  `org_pro_enabled_<id>` for 30 minutes, so the app can lag a SQL snapshot by up to half an hour
  after an entitlement change. Test 1 bypasses the cache by construction (fresh in-memory models,
  array store), so it measures the *predicate*, not the runtime's staleness.
- **`org_meta_latest` has one fewer row than the base table has distinct ids** (23,091 vs 23,092
  across 23,093 rows). One org's meta is excluded by the view. It is not in the entitled 29, so it
  does not move these numbers, but the view is not a pure `argMax` over the base table.

## The population is orgs, but entitlement is per user

`Feature::define(COMPANY_METRICS, fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(...))`,
and `canViewCardData()` additionally passes on `session('admin_impersonator')`. So the set of
*viewers* who can render this card is strictly larger than the 29 orgs: InsightLPR staff render it
inside non-Pro orgs too. "29 entitled orgs" is the right denominator for **customer** exposure and a
**lower bound** on render volume.
