# DEV-2469 — corrected spec (the Jira ACs are obsolete)

The ticket's Acceptance Criteria still describe the **pre-Oscar** metric: "distinct `ocr`
among scans attributed to an LPR repossession via `clarity.recovery_scans`". That spec is
dead. It was written before Oscar redefined the metric on 2026-09-10. Build against THIS
file, not the ticket body. (The correction was deliberately not posted to Jira.)

## Oscar's definition

> "I thought we talked about this. this metric should be count of unique scans / count of
> LPR repossessions = this metric"

Structurally identical to the formula he gave two days earlier for the sibling card
(DEV-2409 comment 39276): "total number of unique scans / by unique case claims". He
treats it as the pattern for the whole `Unique Scans to X` family.

So the card is a **ratio**, not a count.

## Why the old spec cannot work

`clarity.recovery_scans` is the LPR-*assisted* population -- repos our LPR contributed to.
Oscar's "LPR repossessions" is the **case-claim funnel** (DEV-2409 comment 39277, shipped
as DEV-2464). Measured on DEV-2469 comment 39119: **zero** of 3,894 case-claimed recoveries
carry any `recovery_scans` row, all-time, because `recovery_scans_mv` requires
`recoveries.geo != (0,0)` and no staged recovery is geocoded.

The old numerator over the new denominator is therefore structurally 0. The card would read
0 forever.

Consequence: **DEV-2466's "GO, cheapest card" verdict does not apply.** That envelope
(1,195,761 rows / 22.2 MB / 27 ms) was measured on the `recovery_scans` path, which this
card no longer touches.

## The four decisions

1. **Literal ratio, not a percentage.** scans per repo (e.g. `420.3`), not a `%`.
   A per-vehicle percentage would land ~96-100% here and near-duplicate DEV-2465: an LPR
   repossession under Oscar's definition arrived *through* a case claim, and 96.6% of
   claimed vehicles were camera-seen.

   Note DEV-2465 did NOT implement "unique scans" literally either -- its numerator
   `pre_claim` is `uniqExactIf(trip, pre_claim)`, a count of *claims whose vehicle had a
   prior scan*. Both of its halves are vehicles. This card diverges from that reading on
   purpose.

2. **Numerator = org-wide unique scans**, `ScanPlateStatsBatchQuery` (`uniqExact(ocr)`),
   NOT scans linked to the repossessed vehicles. Oscar is composing two numbers that are
   already cards on this grid (Unique Scan Counts row 2 col 1; LPR Repossessions row 1
   col 4). The linked reading would read ~3.5 and need a new plate/hotlist join.

3. **Denominator = `LprRepossessionsQuery`'s `db_repos`** (NOT `deduped`). `db_repos` is
   the funnel stage that means "case claim that went on to a recovered transition" --
   Oscar's definition exactly.

4. **Both halves bucket in UTC.** This is forced, and it is the one real trap here.

## The timezone trap (read before touching the monthly series)

The two landed query classes bucket on deliberately OPPOSITE clocks:

| half | bucket expression | clock |
| --- | --- | --- |
| `LprRepossessionsQuery::sql()` | `toStartOfMonth(toDate(fromUnixTimestamp(be.ts), 'UTC'))` | hard UTC |
| `ScanPlateStatsBatchQuery::handle()` | `toStartOfMonth(toTimeZone(scanned_at, $timezone))` | viewer's tz |

Each is correct for its own card. Dividing them per month mixes axes -- for a Chicago
viewer the numerator's "September" starts 5-6 hours after the denominator's, so every bar
is subtly wrong and worst at month boundaries.

Fix: call the numerator with `timezone: 'UTC'` and adopt the UTC label axis
(`getGraphLabels()` / `getHighlightedMonthsFromFilter()`), exactly as
`UniqueScansToCaseClaims` and `UniqueLprCaseClaims` already do.

### Accepted cost

`ScanPlateStatsBatchQuery::sharedMonthCacheKey()` is keyed BY timezone, so asking for UTC
yields a different key from the one DEV-2467's card warms for a non-UTC viewer. This card
therefore does NOT ride DEV-2467's cache -- it triggers its own `scans_cold` pass,
measured on that path at **60.7M rows / 1.31 GB read / 2.44 GB peak / ~1.1s** per 13-month
series.

Accepted deliberately (operator decision). It is comparable to what DEV-2465 already ships
(106.8M rows / 3.8 GiB), so it sits inside the envelope this grid already carries -- but it
is an ADDITIONAL heavy pass, not free reuse, and the ticket's round-trip-regression AC
(DEV-2406) must be read with that in mind.

## Verification status

| surface | state |
| --- | --- |
| `UniqueScansToLprRepossessionTest` (13 tests) | pass |
| new + `UniqueScansToCaseClaims` + `LprRepossessions` + `DashboardCardVisibility` (69) | pass |
| Pint, PHPStan | clean |
| **Either ClickHouse query actually executing** | **NOT verified locally** |

Local tests stub every ClickHouse seam and this worktree runs `CLICKHOUSE_DRIVER=pgsql`, so
neither `ScanPlateStatsBatchQuery` nor `LprRepossessionsQuery` has run. The card's SHAPE is
pinned; its NUMBERS are not.

`DashboardCardExpansionTest` reports 7 failures locally. Measured baseline against
`origin/main`'s stub card: **7 failed / 9 passed** -- identical, trace at `ScanCount.php:51`,
an unrelated card. They are a pgsql-bridge artifact, not a regression. Net new failures from
this change: **zero**. CI (`lint_check_and_test.yml`) runs a real ClickHouse 26.2 container
and loads the schema dump, so they are expected to PASS there -- if they do not, that
baseline reading was wrong and should be re-examined before merge.

---

## Review round 2 — gate FAILED (72 / 70 / 72 vs 90). Fixes applied.

### A correction to the "Accepted cost" section above

That section claimed the UTC requirement FORCED a private cache key. **That was wrong.**
`ScanPlateStatsBatchQuery::sharedMonthCacheKey(int $orgId, string $monthKey, string $timezone)`
takes the zone as a PARAMETER -- passing `'UTC'` gives per-month shared entries. The premise
the operator approved the extra pass on did not hold.

What actually shipped in RN2 was one 13-month entry at `[3600, 86400]`, so twelve IMMUTABLE
months were re-scanned every hour (~900 GB/day of ClickHouse reads across 29 entitled orgs,
~92% of it recomputing closed history).

### What changed

| finding | fix |
| --- | --- |
| **H1** denominator duplicated `LprRepossessions`' identical query (584M rows / 2.79 GB / 33.7s tail) under a `class_basename`-prefixed key | added `LprRepossessionsQuery::sharedMonthlySeriesCacheKey()` + `::sharedWindowCacheKey()`; **both cards now read them**, so the query runs once between them |
| **H2** 12 immutable months re-scanned hourly | numerator now reads per-month `sharedMonthCacheKey(..., 'UTC')`; closed months written at `getCacheTTL(1)` (1 year), only the current month carries a short TTL; uncached months fetched in ONE batched call |
| **H3** two-query guarded closure, 5th synchronous guarded leg | the outer class-prefixed cache is GONE. Each guarded closure now wraps exactly ONE query, and the ratio is 13 divisions over cache hits |
| **M** window numerator bypassed `sharedRangeCacheKey()` | now routed through it with the identical org/start/end/timezone |
| **M** lock constants copied, not derived; 2-query closure vs 90s lock | constants now match `LprRepossessions` **because the keys are shared**, with its derivation restated; a sizing test pins the match |
| **M** tests: round-trip unpinned, today/last_7d uncovered, zip only run empty | added per-month caching + batch-count test, shared-key read test, null-org test, real-data zip test, lock-sizing test |
| **L** `$orgId ?? 0` meant "no org" to one leg and "platform-wide" to the other | both legs now return zeros on a null org rather than querying |
| **L** `getDescription()` docblock named the wrong date | corrected: the window is the CASE-CLAIM date, unbounded forward |
| **L** visibility-test prose said SEVEN gated cards | updated to EIGHT |

### Residual, NOT fixed

`H3`'s underlying point stands: this is still an additional synchronously-rendered card on a
non-lazy grid. The fixes cut its marginal cost substantially (no duplicate 584M-row leg, no
hourly history rescan, shared window rollup) but out-of-band warming -- `LprRepossessions`'
"plan node dc1" -- remains the real remedy and is not in this ticket.

### Test results after the fix

| suite | result |
| --- | --- |
| `UniqueScansToLprRepossessionTest` | **18 passed** |
| + `LprRepossessions`, `UniqueScanCounts`, `UniqueScansLeaderboard`, `UniqueScansToCaseClaims`, `DashboardCardVisibility` | **103 passed** |
| `DashboardCardExpansionTest` | 7 failed / 9 passed -- **identical to the origin/main baseline**, unchanged |
| `LprRepossessionsQueryTest`, `ScanPlateStatsBatchQueryTest` | 34 skipped locally (`RequiresClickhouse`); CI runs them against real CH |

---

## Review round 3 — gate FAILED AGAIN (62 / 65 / 62). The RN2 fix introduced a REGRESSION.

All three lanes independently found it, and it was verified directly.

### What I broke

RN2 shared `sharedWindowCacheKey` between two cards that store **different payload shapes**:

| writer | payload |
| --- | --- |
| `LprRepossessions::currentPeriodTotal()` | `int` (its selected `db_repos` stage) |
| `UniqueScansToLprRepossession::fetchWindowTotal()` | `array{deduped, db_repos}` |

Byte-identical keys, and BOTH mis-reads are silent:

```
$int['db_repos'] ?? 0          -> 0   (?? uses isset; no warning)
(int) ['deduped'=>5,'db_repos'=>7] -> 1
```

`LprRepossessions` renders 4th and this card 8th in one `getStats()` loop, so in normal order
this card read **0.0 for Today / Last 7 Days forever**; on an expanded-card re-render the order
inverts and the SHIPPED `LprRepossessions` card rendered **"1"**. That is worse than the
duplicate query RN2 set out to fix.

Root cause: I copied `ScanPlateStatsBatchQuery`'s key-sharing idiom and dropped the PAYLOAD
clause that makes it safe. That class versions its key `-v2` precisely because a shape change
"is a live production break, not a test failure". The sibling `UniqueScansToCaseClaims`
deliberately keeps its window key class-prefixed for this exact reason -- the precedent existed
and was walked past.

### The fix (deliberately narrower than "make both read one array shape")

Making both cards read one array shape requires restructuring `LprRepossessions`' window cache
path, whose suite carries FIVE doubles overriding `fetchWindowTotal(): int` plus key/lock
assertions at three more sites. That is not surgical, and over-reaching is what caused this.

So: **the SERIES key stays shared** (both writers store the identical array; all three lanes
confirm it safe, and it is the expensive leg -- 584M rows / 2.79 GB / 33.7s tail). **The WINDOW
key is no longer shared** -- each card keeps its own. `sharedWindowCacheKey()` is deleted rather
than left as a loaded gun.

KNOWN COST, accepted: the window query now runs once per card again.

Also fixed: a PAYLOAD CONTRACT clause added to the surviving shared key; closed-month writes now
honour `UniqueScanCounts::IMPERSONATED_PAST_MONTH_TTL_MINUTES` (10 min) instead of this card's
60s floor on a namespace it does not own (that constant is now `public` -- it is a cross-card
contract); and the docblock claim that the UTC numerator "shares entries with UniqueScanCounts"
is corrected -- it does not, for any non-UTC viewer, and the default is `America/Chicago`.

### The test that catches it -- and why the first attempt did not

A cross-card test was added: warm through the REAL sibling, assert through this card. The FIRST
version was **vacuous** -- verified by re-introducing the bug and watching it pass. Cause: a test
double gets its own `class_basename`, so `buildWidgetCacheKey()` gave each double a private key
namespace and the collision could not occur. This is the okf `class_basename` cache-key trap,
reproduced inside the test that existed to catch it.

Both doubles now override `buildWidgetCacheKey()` to delegate to the PARENT's basename, so they
write the production keys. Re-verified in both directions: **fails with the bug re-introduced,
passes with it fixed.**

### Test results

| suite | result |
| --- | --- |
| `UniqueScansToLprRepossessionTest` | **21 passed** |
| + 5 sibling suites incl. `LprRepossessions`, `UniqueScanCounts`, `UniqueScansLeaderboard` | **106 passed** |
| `DashboardCardExpansionTest` | 7 failed / 9 passed -- unchanged from the origin/main baseline |

### Residual, NOT fixed -- carried to handoff

1. **The numerator's UTC `scans_cold` pass does not share with `UniqueScanCounts`** for any
   non-UTC viewer (the default). RN3 lane 3 measures this as roughly DOUBLING the unguarded
   `scans_cold` axis (~2.44 GB -> ~4.88 GB peak per concurrent render copy). Real, disclosed,
   and not addressed here.
2. **The numerator leg is unguarded** (bare `cache()->get`/`put`, no cold mutex), so its
   concurrency is per-tab rather than per-org.
3. **No failure marker** -- a ClickHouse outage makes this a fifth card that 500s the whole grid,
   and it does not read `UniqueScanCounts`' backoff marker.
4. **Deploy-time note**: `LprRepossessions`' monthly-series key moves namespace, orphaning warm
   entries fleet-wide (~81 GB cold across 29 orgs on first render). Recorded here; belongs in
   the deploy notes.
5. The window query runs once per card (the accepted cost above).

---

## Review round 4 — 92 / 76 / 72. Two findings fixed; the rest recorded, not papered over.

### Fixed

**1. The window key is SHARED again, carrying an int.** RN3 un-shared it on the stated grounds
that this card "needs the whole `{deduped, db_repos}` array". **That was false** —
`fetchWindowTotal()` reads `db_repos` and nothing else; `deduped` was fetched and discarded. The
un-share therefore bought no safety and ran the grid's heaviest query (584M rows / 2.79 GB /
33.7s tail) **twice** per cold render on `today`/`last_7d`.

`LprRepossessionsQuery::sharedWindowCacheKey()` is restored with an explicit **PAYLOAD CONTRACT:
this key carries an `int`** — the already-selected `db_repos` stage. That clause is the fix for
the RN2 defect (two shapes on one key), and it is what makes sharing safe rather than a repeat.

Measured: both cards together now cost **4** ClickHouse round trips on a cold `today` render.
Before the re-share, this card **alone** cost 4.

**2. The numerator honours the shared ClickHouse backoff.** It now reads the marker before
querying and, on failure, reports + writes the marker + writes NO bucket — the full four moves
`UniqueScanCounts` makes, including its env gate, instead of only "don't write a fabricated 0".
Previously a `scans_cold` failure escaped through `getStats()` (no per-card try/catch) and 500'd
all sixteen cards, *and* re-issued the query `UniqueScanCounts` had just backed off from.

The marker helper and its TTL moved to **`ScanPlateStatsBatchQuery`** — the class that owns the
`scan-plate-stats:` namespace — and `UniqueScanCounts` delegates to it. A backoff only works if
every writer of a namespace observes it, so the contract cannot live privately on one card.

**3. The `boost:install` docs drift** that commit `25bf951f9` swept in is reverted. It had
`okf-lint` exiting 1, deleted the anti-inline guard lines from `AGENTS.md`, and reversed PG-021
(`.junie` asserted php 8.4.23; production runs **8.3**).

### Tests

23 on the card (108 across all affected suites). The re-share test was verified in both
directions: it **fails** when the key is un-shared (`repoWindowCalls` becomes 1) and passes when
shared. Two new tests cover the failure path — degrade-not-propagate, and marker-suppresses-query.

Live re-verification after the change: `today 6.0 · last_7d 40.0 · this_month 5.0 ·
last_month 0.0`, no cross-card corruption, and the browser renders 5.0 with Scan Count 132 /
Unique Scan Counts 40 / LPR Repossessions 8 alongside.

### NOT fixed — recorded, with the correction they deserve

- **The numerator leg is still unguarded** (bare `cache()->get`/`put`, no cold mutex), so its
  concurrency is per-tab. At 2.44 GB server peak per copy on a server with `max_memory_usage = 0`
  this is the largest open risk. The wiki's prescribed mitigations — a `Cache::add` semaphore, or
  a per-query `max_memory_usage` — are a follow-up ticket, not a fourth in-flight fix.
- **The UTC numerator does not share with `UniqueScanCounts`** for any non-UTC viewer (default is
  `America/Chicago`), so it is a genuinely additional `scans_cold` pass rather than recovered
  sharing.
- **`COLD_BLOCK_BUDGET_SECONDS` is a per-REQUEST budget** and this card's docblock derived its
  divisor per-card. The docblock is corrected in place; the grid-wide overrun is pre-existing
  across this card family and is not this ticket's to fix.
- **`sharedRangeCacheKey` is still written with this card's 60s TTL floor** instead of the
  owner's ladder — the RN3 repair was applied to the month key only.
- **The surviving shared SERIES key has no cross-card round-trip test** (the window key now does).
- **Deploy note owed:** `LprRepossessions`' series key changes namespace, orphaning warm entries
  fleet-wide (~81 GB cold across 29 orgs) and phase-locking their refresh boundary.

### Corrections to this document's own QA section

- "cold render = exactly 2" held only for `this_month`/`last_month`. The `today` arm was 4
  (now 4 for *both* cards combined).
- The "deliberately non-UTC viewer" framing oversold the seed: all seeded data is mid-month, so
  a viewer-tz and a UTC numerator land in the same bucket. The seed does not discriminate the
  UTC axis; the unit tests do.
- The in-process and browser sections described different entitlement states (not entitled →
  `lockedStat()`; then Pro Plus rendering values). The grant was flipped between passes and the
  report did not say so.
