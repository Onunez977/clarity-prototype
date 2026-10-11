# DEV-2463 — review round 3: findings and disposition

**Lanes:** 3, all `in-session-agent` (same `review.sh` auth failure as rounds 1–2).
**Scores:** 92 / 92 / 92 → **`REVIEW_GATE: pass min=92 lanes=3`.**

All three lanes independently confirmed the port is faithful, the Pro gate is sound, the
guest-`true` arm is not a leak, and PHP-side allocation is O(1). Two lanes re-derived the
Backyard port line-by-line rather than trusting the artifacts. Every lane verified the round-2
`_peerdb_is_deleted` deletion is fully reverted.

## What they found in the code I wrote *this round*

Three of the five findings below are defects in round 3's own work — including the guard I had
just described as "correctly this time".

### The guard was still wrong (lane 3 M2, lane 1 L3, lane 2 L1 — all three)
`heldFlexible()` tested `cache()->has($key)`. But `Cache::flexible`'s own miss test is
`in_array(null, [$value, $created], true)` over **two** keys — the value and a companion
`illuminate:cache:flexible:created:{$key}`. With the companion absent, `has($key)` is `true`,
so the guard took the **warm path, took no lock**, and `flexible()` then ran a full
unserialised ~40M-row scan. That is *precisely* the state the guard exists for.

Reachable in production, not hypothetical: the two keys carry no hash tag, so on the prod Redis
cluster they land in different slots, `RedisStore::putMany()` degrades to a non-atomic per-key
loop, and either key can be evicted alone.

**Fixed** — both keys are now tested, pinned by a torn-state test that fails without the fix.
This was the specific question the lanes were asked to check, and asking it was worth it.

### Finding 8's fix was deletable with the suite green (lane 3 M1, lane 2 L2)
The sizing test asserted `REFRESH_LOCK_SECONDS > 0` — a property of the **constant**, not of the
call. Dropping `Cache::flexible`'s 4th argument restored the no-expiry refresh lock and passed
all four guard tests. **Fixed** — the config now flows through a `flexibleRefreshLock()` seam a
recording subclass observes, so deleting it fails.

### The `today`/`last_7d` seam was pinned by nothing (lane 1 M1) — the most important finding
That seam produces the headline for **2 of the 4 filter states**, and every test double
overrode `fetchWindowTotal()` wholesale, so nothing tested it. **Two mutants survived all 35
tests:**

1. `['deduped']` → `['successful']` — the card would render the funnel stage the ticket's Q1
   **explicitly rejected**. At org 165, Jul 2026: 9,262 instead of 7,230, ~28% overstated.
   The *monthly* seam's stage selection was pinned; this one was not.
2. Dropping the viewer timezone from `Carbon::parse()` — `resolveCardRange()` hands naive
   strings in the viewer's timezone, so parsing them as UTC shifts a Chicago viewer's "Today"
   by 5–6 hours, dropping the last hours of real claims.

**Fixed** — a `runWindowQuery()` seam splits the query call from the stage selection, and
`UniqueLprCaseClaimsWindowSeamTest` kills both mutants.

### A false claim in my own docblock (lane 1 M2)
I had written that impersonators "still get the freshest value any sibling card offers within a
minute". Lane 1 checked and that is **wrong**: most siblings route through `getCacheTTL()` and do
collapse to 10s — six times fresher than my 60s floor. **Rewritten honestly**: the floor *is* a
divergence from `okf/concepts/impersonation.md`; it is not unprecedented
(`MissedOpportunityClientGraph` hardcodes 15 minutes with no collapse at all); the justification
is the measured 9.3s p99; and the monthly seam's `[3600, 86400]` bypasses `getCacheTTL()`
entirely and is staler still. Queued for an `okf` note post-merge.

### The citation was wrong twice (all three lanes)
My original comment credited the `FINAL` rule to **PG-027**, which says the reverse for
`zod.public_*` (*"Skip `FINAL` on `zod.public_*` … `FINAL` on a billion-row table is its own
problem"* — verified at `platform-gotchas.md:221`). My *first correction* then cited
`clickhouse-integration.md`. The canonical rule is **PG-002** plus
`clickhouse-query-patterns.md:79`. **Corrected** — and lane 3's stronger *structural*
justification is now recorded in place of the citation argument: `orgs` is a **JOIN source**, so
without `FINAL` a renamed org contributes two rows and fans out its claims ×2. PG-027's
"skip `FINAL`" guidance is about billion-row scan targets; `orgs` is a 726k-row hash-build side.

### Artifacts stale for three consecutive rounds (all lanes, rounds 2 and 3)
`test-baseline.md` still reported round-1 counts. **Refreshed** to 39 tests across 5 suites,
with a full mutation ledger.

## The mutation ledger — why it is the real result

| round | mutant | before | after |
| --- | --- | --- | --- |
| 1 | drop dedup **and** the success filter | passed all 12 | fails 2 |
| 2 | delete the three label-axis overrides | passed all 28 | fails 3 |
| 3 | delete `heldFlexible()` | passed all 3 guard tests | fails the lock probe |
| 3 | lock **before** the cache read (round 2's outage bug) | — | fails on a 13.8s block |
| 3 | `COLD_BLOCK_SECONDS` 12 → 5 | — | fails the sizing test |
| 3 | drop `Cache::flexible`'s `$lock` arg | **passed all 4** | fails via the seam |
| 3 | evict only the companion key | **passed everything** | fails |
| 3 | `['deduped']` → `['successful']` | **passed all 35** | fails |
| 3 | drop the viewer timezone | **passed all 35** | fails |

Four of those nine were guards that **existed in the code and were provably deletable without a
single test noticing**. Three were mine, found by the lanes. Two I found by mutating work I had
just written a test for.

## Open, accepted (unchanged from round 2's dispositions)

The cold-path herd on the three pre-existing `cache()->flexible` sites (`Org::getHotlistClients`
is worse — 156M rows); the `today`/`last_7d` seam staying on the base class's 10-min caching; no
failure cooldown (house-consistent — `RecoveryCounts` and `MissedOpportunities` already 500 the
grid the same way, which all three lanes verified); the mixed UTC-month/local-day calendars; the
tombstone limitation; and the `?? 0` fail-open platform-wide default. Each is documented at its
code site with the measurement behind it.

Post-merge queue: an `okf` page for the Company Metrics card family and `billing_events` (no page
exists for either — all three lanes confirmed), plus the impersonation-TTL note.
