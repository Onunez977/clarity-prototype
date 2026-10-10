# DEV-2463 — review round 1: findings and disposition

**Lanes:** 3 · **Scores:** claude1 **94**, claude2 **88**, claude3 **94**
**Gate:** `REVIEW_GATE: fail — MIN score 88 < threshold 90` (the gate takes the MIN, never the average).

## Provenance — recorded, not fabricated

`review.sh` ran and **all three of its lanes failed**: `"Not logged in · Please run /login"` — a genuine
nested-`claude -p` auth error, which is the documented condition for the blessed in-session fallback.
The lanes were re-run in-session via `insightlpr:adversarial-reviewer`. `lane_sources` records
`in-session-agent` for all three. No manifest was hand-authored to look like a harness run.

A second, independent `review.sh` problem: it swept **all of `docs/investigations/**`** into the lane
prompt — **302 KB** against a **67 KB** source diff, including a 48 KB inherited DEV-2464 artifact. The
in-session lanes were given a source-only patch (`review-1-source-only.patch`) instead. This is the
same defect class as a known built-assets bloat, generalised to run artifacts.

## FIXED this round — each confirmed by me before fixing, not taken on the lane's word

### M1 — UTC data axis vs viewer-timezone label axis (lanes 1, 2, 3)
`getMonthlyData()` bucketed in UTC, but `getGraphLabels()`, `getHighlightedMonthsFromFilter()` and
`getDescription()` all read `auth()->user()->timezone`. **Confirmed** at `DashboardWidget.php:161,171,172`.
For ~5–6 h at the start of every UTC month a Chicago viewer saw all 13 bars labelled one month off their
data, and "Current Month" showing the wrong month. Lane 3 showed the headline itself can read ~0 against a
real ~11,975. **Fixed:** all three overridden to UTC in the card.

Sharp detail all three lanes noted: the existing test froze the clock at *exactly* the offending instant
(`2026-08-01 02:00 UTC`) to prove the data bucket was UTC — and never asserted the label. The test
demonstrated the bug and looked away from it.

### M2 — cache key with no window identity → a fabricated zero (lanes 1, 2; lane 3 as L1)
The key was `unique-lpr-case-claims:monthly-series:org=165` — **no month**. **Confirmed by rendering it.**
A series computed at 23:55 on the last day of a UTC month is still inside the 3600 s fresh window minutes
into the next one, the new month's bucket is absent, and `?? 0` fabricates a zero — indistinguishable from
a legitimate zero, which is precisely the collision this ticket's own AC forbids. **Fixed:** the UTC month
is now in the key; **verified it rotates** `…:2026-09:org=165` → `…:2026-10:org=165` across the boundary.

### M4 — the two central counting rules were unpinned (lanes 1, 2)
**Confirmed by mutation, and it was worse than reported.** Replacing
`uniqExactIf((forwarder_org_id, agent_org_id, vin), success AND vin != '')` with `countIf(vin != '')` —
removing **both** the dedup and the success filter — **passed all 12 tests**. **Fixed:** three regression
tests added (identical triples collapse to one; a failed claim counts in `all_claims` but not in
`successful`/`deduped`; same VIN + same forwarder + two agents = two claims). **Mutant now killed** (2
failures); suite 15/15.

## REJECTED — verified false

### lane 2's L1 — "newly breaks an untouched test under `CLICKHOUSE_DRIVER=pgsql`"
**Misattributed.** `StagingLiveHitLeaderTest::test_overview_last_month_…` does fail under the pgsql bridge —
but it fails **identically on `origin/main`**, at the same `ScanCount.php:51`. Verified by switching the
driver and running the same test on a detached `origin/main`. Not a regression.

The underlying observation (raw ClickHouse SQL with no driver branch, rethrowing under the bridge) is
true, but it is the pre-existing house shape — `Client/CohortDeclinedQuery.php` does the same — and the
bridge already does not work for this dashboard.

## OPEN — scoring, unactioned, needs a decision before this can ship

1. **The `today`/`last_7d` seam is outside the bound the prep claimed** (all three lanes). `prep-context.json`
   `allocation_sketch` says the cost is *"bounded by `Cache::flexible([3600, 86400])` to at most one fresh
   compute per (org, window) per hour."* True of `getMonthlyData()` only. `getModelQueryForDates()` routes
   through the base `currentPeriodTotal()` on `getCacheTTL()` = **10 min**, collapsing to **10 s** under
   `admin_impersonator` — so the same 39.9 M-row / 1.83 GB / 5.8–9.3 s query re-runs up to 6×/hour/org, and
   up to 360×/hour per impersonating session. At a 9.3 s p99 against a 10 s TTL, a re-render can start the
   next copy before the previous finishes. **My recorded envelope claim is wrong for half the card.**
2. **`Cache::flexible`'s cold path has no herd lock** (all three lanes). The lock guards only the
   stale-refresh branch; a total miss is a bare `remember`. A deploy or `cache:clear` — which the repo's own
   PG-009 prescribes as the fix for a stale widget — admits one uncached 38 M-row scan per entitled org and
   per concurrent tab.
3. **The documented failure pattern was half-adopted** (lane 3, M3 — the best-grounded new finding).
   `SharedHitStatsData.php:288-300` documents that bypassing `ClickhouseQuery`'s swallow is safe *because it
   is paired with a 60 s negative-cache marker*. This card took the bypass and not the marker, so during a
   ClickHouse incident every render re-issues the most expensive query on the dashboard, each pinning a PHP
   worker for up to the 60 s query timeout, with no cooldown. There is also a shipped
   `cache()->flexible` + ClickHouse + degrade-and-observe precedent — `Org::getHotlistClients()` — that this
   card reimplemented the caching half of and dropped the observability half.
4. **Impersonation freshness contract dropped.** `[3600, 86400]` bypasses `getCacheTTL()`'s 10 s
   impersonation collapse (okf PG-009, `clickhouse-query-patterns.md:42`). Siblings bypass it too, but at
   5–15 min, not 1–24 h.
5. **Stale `CompanyMetricsOverview` docblock counts.** Still reads "7 REMAINING GAPS" (now 6), "AT 9 CARDS",
   "This card is the 9th". That docblock declares itself the source of truth for the next sibling's slot
   arithmetic and shouts that the gaps are load-bearing.
6. **Mixed calendars.** `today`/`last_7d` are viewer-local days while `this_month`/`last_month` are UTC
   months, so "Today" can exceed "This Month".
7. Smaller: `billing_events` tombstones survive without `FINAL`; soft-deleted orgs escape the `insightlpr`
   `LEFT JOIN` (fails open); `report()` + rethrow double-reports; `zodDb()` duplicated from the base class.

## Corrections to my own records, both caught by the lanes

- **`Cache::flexible` does NOT have zero prior uses.** I claimed it did, in `prep-context.json`, `plan.json`,
  the PR body and the Jira mirror. My grep searched the facade form `Cache::flexible` and missed the helper
  form `cache()->flexible(` — there are **three** prior uses (`app/Models/Org.php:197`, `:233`,
  `app/Models/Concerns/HitAlertSettings.php:29`). This matters *in the change's favour*: `getHotlistClients()`
  is a hot production path, which empirically rules out a Redis-cluster `CROSSSLOT` failure on `flexible()`'s
  two-key read — and it is also the precedent that carries the failure handling open item 3 flags as missing.
- **The infra-carry SHA was loose.** Artifacts cited `cc34c05c` (that branch's HEAD); the hunks are from
  `47612ab1` on it. Lane 3 verified `git show 47612ab1 -- <the two files>` is **byte-identical** to what
  shipped, so the carry itself is clean.

## What all three lanes independently agreed was correct

The **port is faithful** — each lane re-derived it line-by-line against Backyard's `claimsCte()`/`deduped`
rather than trusting the artifacts: 3-column dedup key, `success AND vin != ''`, forwarder = api-key owner
org, `upper(trimBoth(vin))`, `case-claim` only, `titlemax` never filtered, `insightlpr` normalised and applied
on both axes (stricter than Backyard's forwarder-only filter), correct `position()` argument order, no SQL
injection surface. The **Pro gate is sound** and the guest-`true` arm is **not** a leak (all three traced it).
The **one-round-trip assertion is real, not vacuous** (a counting seam, correctly avoiding `DB::listen`, which
never fires for the ClickHouse driver). **PHP-side allocation is O(1)** — this card cannot reproduce the
DEV-2342 host-memory mode.
