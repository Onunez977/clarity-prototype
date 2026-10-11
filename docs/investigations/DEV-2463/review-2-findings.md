# DEV-2463 — review round 2: findings and disposition

**Lanes:** 3, all `in-session-agent` (same `review.sh` auth failure as round 1).
**Scores:** claude1 **89**, claude2 **92**, claude3 **94** → **MIN 89, gate threshold 90.**

Two lanes independently noted the worktree HEAD moved under them mid-review (a concurrent
process) and correctly re-anchored on the branch object `8c327822`. Their anchors are sound;
that commit is the round-2 tip they graded.

## The headline: round 2's own fixes were net-harmful, and have been WITHDRAWN

Round 1 left three open envelope findings. My round-2 attempt to close them introduced a worse
defect than the ones it addressed, and all three lanes caught it independently.

### What was wrong with my fix

`guardedFlexible()` checked the negative-cache marker **before** the cache read, and **threw**.
`CompanyMetricsOverview::getStats()` has no per-card try/catch, so:

> one transient ClickHouse blip → the marker is set for 60s → **every render for that org 500s
> the entire ten-card dashboard for the next 60 seconds**, even after ClickHouse recovers and a
> fresh value is sitting in Redis.

Before my fix, that same second request would simply have retried and succeeded. I verified the
ordering by reading both my code and the house pattern it cited: `SharedHitStatsData` checks
memo → **cache** → marker, and *degrades* (`return null`) rather than throwing.

Lane 1 also dismantled my stated justification. My docblock argued degrading "would render 0 and
break the AC" — but `DashboardMetricCard::LOCKED_VALUE = '—'` already exists as a non-numeric,
non-zero render that is distinguishable from a legitimate zero. It was a **false dichotomy**.

Two further defects in the same fix:
- **The herd lock did not bind.** `->block(5, …)` against a measured 5.8–9.3s p99 means every
  waiter times out and computes anyway — no herd reduction in the contended band it was added
  for, **plus up to 5s of added latency per waiter**. The 30s lock TTL also sits below the 60s
  query timeout, so a slow holder loses the mutex mid-query.
- **It guarded the wrong seam.** The marker/lock went on the monthly seam (1 miss/hour) and not
  the `today`/`last_7d` seam (up to 6/hour, same 40M-row query) — and lane 3 showed the marker
  never covers `Cache::flexible`'s **deferred** stale-refresh path at all, because that closure
  runs in `terminate`, outside my `try`.

### Disposition: withdrawn, not patched

Adding a third layer to a guard that was already net-negative would have been the wrong instinct.
`guardedFlexible()`, the herd lock and both markers are **removed**. The monthly seam is back to
plain `Cache::flexible` with the month-stamped key from round 1 (which was a genuine fix and is
kept).

Round 1's open items 1–3 therefore **remain open**, which is the honest state. They are also
house-wide, not specific to this card: `Org::getHotlistClients()` has the identical unguarded
`cache()->flexible` cold path over a **156M-row** table.

## Also withdrawn: the `orgs` CTE change

Round 1's finding was that a soft-deleted internal org escapes the `insightlpr` exclusion. I
"fixed" it by dropping `_peerdb_is_deleted = 0` from the `orgs` CTE. **Lane 3 proved that inert
under both possible readings of `FINAL`:**

- if `FINAL` drops tombstones → the org is absent → `LEFT JOIN` → `coalesce('')` → needle misses → claim kept;
- if `FINAL` keeps them → with Postgres `REPLICA IDENTITY DEFAULT` the tombstone carries only the
  PK, so non-nullable `name` lands as `''` → needle misses → claim kept.

The hole stays open either way. Worse, my change **deleted a guard `okf/concepts/clickhouse-query-patterns.md`
mandates verbatim** for raw queries, and my justifying comment ("`FINAL` still collapses … deleted
or not") is the direct negation of `HitPlateStatsBatchQuery.php:25-28`'s claim about the same
engine. I had asserted a mechanism rather than measuring it.

**Reverted** to `WHERE _peerdb_is_deleted = 0`. The tombstone hole is recorded as open; it is
unreachable today (only one org has ever forwarded a case claim, with 0 successful, and InsightLPR
never appears as an agent, so `deduped` — the only stage this card renders — is unaffected).

## FIXED this round

### The UTC label axis was never pinned (lane 1 M1)
Round 1 *fixed* the label axis but tested nothing: deleting all three overrides left every test
green and fully restored the defect. `DashboardMonthLabelsTest` exists to guard exactly this class
of bug but iterates only `[ScanCount, RecoveryLeader]`.

Added `UniqueLprCaseClaimsLabelAxisTest` (3 tests) pinning labels, the highlighted month, and the
description against UTC at the adversarial instant. **Mutation-verified:** all three fail on the
deleted-overrides mutant, all three pass on the fix.

### Six sibling e2e assertions the index-2 insert invalidated (lane 2 M2)
`missed-opportunities.spec.ts` and `staging-live-hit-leader.spec.ts` both assert
`staging-live-hit-leader === recovery-counts + 1`. This card sits between them, so the truth is
`+ 2`. Six sites corrected across both files, each with a comment naming the cause.

### My own e2e scenario D could not pass (all three lanes)
It asserted `Current Month:` / `Last Month:` — literals my round-1 `getDescription()` override
removed in favour of UTC month names. The round-1 fix changed a format string duplicated across
files and updated only one consumer. Both the positive assertion and the leak-detection negative
assertion are re-aligned to `Mmm 'yy:`.

### `composer analyse` regression (lane 1 L3, verified with PHPStan)
`$ttl->lessThan()` on a value typed `\DateTimeInterface` — Carbon-only. Now `getTimestamp()`
comparison. PHPStan level 5 on both changed files: **no errors.**

### Stale artifacts (all three lanes)
`test-baseline.md` still reported 12/12 and `shipped-class-verification.md` certified a
pre-round-2 `sql()` body. Both refreshed; the shipped SQL was **re-run against production after
the revert** and all six figures are still exact.

## Kept from round 2

The **60s TTL floor** on `getCacheTTL()`. The base collapses to 10s under impersonation, shorter
than the query's own p99. Lane 3 fairly notes this is the repo's first override of that collapse
and that `okf/concepts/impersonation.md` records the 3–10s drop as deliberate — so it is a
documented divergence to raise post-merge, not a silent one. It is also, as lane 1 notes, only the
*smaller* half of the problem: the monthly seam's `[3600, 86400]` bypasses `getCacheTTL()` entirely.

## Open, unactioned — needs a decision

1. **Cold-path herd exposure** on both seams (house-wide; `Org::getHotlistClients()` is worse).
2. **The `today`/`last_7d` seam is outside the caching bound the prep claims** — 10-min TTL on a
   40M-row query, and `prep-context.json`'s `allocation_sketch` is still wrong about this.
3. **A cold `today`/`last_7d` render costs TWO 40M-row scans**, not one (window + sparkline). No
   artifact states the doubling; the one-query AC was met per *seam*.
4. **No negative-cache / cooldown at all** now that mine was withdrawn — during a ClickHouse
   incident every render retries. The right shape is the house one: degrade to `LOCKED_VALUE`,
   don't throw.
5. **Mixed calendars** — UTC months vs viewer-local days, so "Today" can exceed "This Month".
6. **The soft-deleted-org exclusion hole** (see above) — needs the `argMax` idiom or
   `zod.public_orgs_latest`, plus a `_peerdb_is_deleted = 1` fixture.
7. **`?? 0` means platform-wide** in a tenant-scoped query — latent, currently unreachable only
   because `buildWidgetCacheKey(string, int)` TypeErrors on a null org first.
8. `Cache::flexible`'s internal refresh lock has **no expiry**; a killed worker wedges the key
   until the 86400s TTL.

## What all three lanes agreed is correct

The **port is faithful** — each re-derived it line-by-line against Backyard's `claimsCte()`/
`deduped`/`isInternalTestOrg()` rather than trusting the artifacts, including the deliberate
both-axes exclusion and the measured-equal `LEFT JOIN` divergence. **No SQL-injection surface.**
The **Pro gate is sound** and the guest-`true` arm is **not** a leak (all three traced it
independently). **PHP-side allocation is O(1)** — this card cannot reproduce the DEV-2342
host-memory mode. Round 1's **dedup/success regression tests genuinely kill their mutants**, each
of the three key columns and the success filter independently. The **docblock arithmetic** I
corrected in round 1 was re-derived by two lanes and is right: 10 cards, 6 gaps, 6 preceding,
re-aligns at 12.
