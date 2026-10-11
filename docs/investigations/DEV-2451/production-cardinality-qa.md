# DEV-2451 — Production-cardinality QA

Answering the two human-judgment items (hp-3, hp-4) with measurement instead of opinion.
All queries **read-only** against production ClickHouse, **aggregates only** — no raw rows,
no plates, no scan ids. Run 2026-08-31.

Method: `zod.public_kits FINAL WHERE _peerdb_is_deleted = 0` bridges `kit_mapping` ↔ `kit_id`,
which is what lets `clarity.sc_positions` (keyed by `kit_mapping`, scanner casing → `lower()`)
be cross-tabbed against `clarity.kit_scan_paths` (keyed by `kit_id`). Both filtered to
`length(path_points) >= 2`, the same predicate `KitScanPathsQuery::filteredQuery()` applies.

---

## hp-3 — "how many kits actually render nothing?"

**Answer: zero at 7 and 15 days; exactly one at 30 days.**

| Window | Both sources (keep rendering) | **Goes dark** | Scan-only (unaffected) |
|---|---|---|---|
| 7 days | 489 | **0** | 457 |
| 15 days | 541 | **0** | 469 |
| 30 days | 611 | **1** | 487 |

30-day full population (5,570 kits with a non-empty `kit_mapping`):

| Population | Kits |
|---|---|
| GPS **and** scan paths → still render, with less detail | 611 |
| GPS but **zero** scan paths → **render nothing** | **1** |
| Scan only → unaffected by this change | 487 |
| Neither → already rendered nothing before this change | 4,471 |

**Orgs with any dark kit: 1.** Worst org: 1 dark kit.

The single dark kit is `kit_id 13075`, `org_id 445986`, `kit_type 1` — and it produced
**13 GPS rows across 1 active day** in the whole 30-day window. Under the old code its "route"
would have been 13 raw pings (≈4 rendered points after tier-1's 3 s decimation) on a single day.

### Correction this forces

Earlier artifacts in this run said *"~617 production kits will render nothing"* and framed hp-3
as the ticket's biggest risk. **That was wrong.** 617 was the count of kits that lose the
*continuous GPS* route — but **611 of them still render**, from scan data. Only 1 renders nothing,
and it is a barely-active kit. The claim conflated "loses the GPS source" with "loses its map".

---

## hp-4 — "how much fidelity is actually lost?"

**Answer: a typical kit-day keeps ~1,184 points spread across ~27 separate segments, retaining
roughly a third of the GPS route's rendered detail. Segments are substantive, not stubs.**

Scan-path shape per kit-day (7-day window):

| Measure | Value |
|---|---|
| Median points per kit-day | **1,183.5** |
| Median segments per kit-day | **27** |
| p90 segments per kit-day | 42 |
| Worst segments per kit-day | 93 |
| Average points per segment | **62.2** |

Segment quality — are the fragments degenerate? (7 days, 124,494 buckets):

| Points per segment | Buckets | Share |
|---|---|---|
| exactly 2 (a straight line — maximally fragmented) | 8,790 | **7.1%** |
| 3–5 | 15,751 | 12.7% |
| 6–20 | 34,410 | 27.6% |
| **more than 20** | 65,543 | **52.6%** |

Fidelity ratio on the 2,345 kit-days that have **both** sources (7 days):

| Measure | Value |
|---|---|
| Median raw `sc_positions` rows per kit-day | 11,339 |
| Median scan points per kit-day | 1,336 |
| Median scan detail as % of GPS **rendered** detail | **≈33.5%** |

⚠️ The 33.5% figure divides raw GPS rows by **3** to approximate tier-1's rendered points, using
the recorded ~3× decimation factor (3 s decimation + distance dedup). Treat it as an
order-of-magnitude answer — "about a third", not a precise 33.5%.

### What "fragmented" actually means here

Not a scatter of dots. The scan route becomes **~27 disconnected polylines per day instead of one
continuous track**, each averaging ~62 points. Only 7% are two-point stubs; over half carry more
than 20 points. So the loss is **continuity between segments**, not the segments themselves.

---

## Bearing on the render ceiling

Unchanged and still the real cost of this change: worst org **3,523,861** rendered points over
30 days vs the `MAX_RENDER_POINTS` ceiling of **1,500,000** — **2 of 263 orgs** over at 30 days
(4 more within 80%), 1 of 253 at 15 days, 0 of 248 at 7 days. Those orgs receive `HTTP 422`.
Owner-accepted: ship, no follow-up ticket.

Note the asymmetry worth remembering: the ceiling affects **2 orgs**, while total loss of the map
affects **1 kit**. The 422 is the bigger real-world consequence of this revert, not the dark kits.

---

## Verdict on the two items

| Item | Status |
|---|---|
| **hp-3** — does the empty state read as broken? | **Largely moot.** 0 kits at 7/15 days, 1 barely-active kit at 30 days. Worth a glance at the empty state for correctness, but it is not the risk it was framed as. |
| **hp-4** — is the fragmentation tolerable? | **Now an informed judgment**, not a blind one: ~27 segments/day, ~62 points each, ~⅓ of GPS detail, 7% degenerate. Still a human call on visual acceptability — but a narrow, well-bounded one. |
