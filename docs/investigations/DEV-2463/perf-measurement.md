# DEV-2463 — prod-scale measurement of the claims-only card

Read-only against production ClickHouse Cloud as `readonly_user`, via chlocal's
`tools/compare.py` `load_env()` + `clickhouse_connect`. **2026-09-08.** SQL committed
alongside as `measure-claims-card.sql`; the driver script is `measure-claims-card.py`.
Aggregates, timings and server-side counters only — **no raw rows**.

## 0. The headline: the server-side envelope IS obtainable

DEV-2464 recorded this as its one unresolvable item — *"`readonly_user` lacks `READ ON REMOTE`,
so `clusterAllReplicas(system.query_log)` is denied … all figures here are client wall clock."*
That is true of `system.query_log`, but it is **not** true of the envelope itself.

**The `X-ClickHouse-Summary` response header carries it, and needs no grant.** Via
`clickhouse_connect` it surfaces as `QueryResult.summary`, with keys:
`read_rows`, `read_bytes`, `result_rows`, `result_bytes`, `total_rows_to_read`,
`memory_usage`, `elapsed_ns`, `written_rows`, `written_bytes`, `query_id`.

So every figure below is **server-side measured**, not inferred from wall clock. This closes
DEV-2464's `unresolved[0]` and this ticket's own "no server-side resource numbers" gap.
(Technique precedent: DEV-2466 established it on `clarity.scans_cold`.)

## 1. The port is faithful — all six target numbers exact

Jul 2026 (UTC calendar month, epoch bounds derived **from ClickHouse** rather than by hand —
a first attempt at hand-arithmetic was silently 2 days off and produced 14,313/13,492), `ls-claim` off.

| stage | ticket target | measured | verdict |
| --- | --- | --- | --- |
| all claims, total | 15,128 | **15,128** | exact |
| successful, total | 14,288 | **14,288** | exact |
| deduped, total | 11,975 | **11,975** | exact |
| all claims, largest forwarder | 9,340 | **9,340** | exact |
| successful, largest forwarder | 9,262 | **9,262** | exact |
| deduped, largest forwarder | 7,230 | **7,230** | exact |

**The `insightlpr` exclusion rule reproduces its own self-check.** Without
`rejectInternalOrgs`, all-claims reads **15,160**; with it, **15,128** — exactly the 32-row
delta the ticket records. `successful` and `deduped` are *unchanged* by the exclusion, which
confirms the ticket's note that InsightLPR's 32 claims are all failures.

Unlike DEV-2464's DB-repos leg there is **no drift** here: `billing_events` rows are immutable
once written, so nothing moves between readings. The +18 that ticket had to explain does not
exist on this card.

## 2. Compute all three stages in ONE pass, not three CTE references

This is a real, measured finding the ticket does not anticipate.

| shape | read_rows | read_bytes | wall |
| --- | --- | --- | --- |
| three separate references to the `claims` CTE | **119,848,324** | 5.50 GB | 1.008s |
| one pass, `count()` / `countIf()` / `uniqExactIf()` | **39,949,441** | 1.83 GB | 0.448s |

`zod.public_billing_events` holds ~38.0M rows and is `ORDER BY id`, so **nothing prunes** —
every reference to the CTE re-scans the whole table. Three references = three scans = 3.0x the
read_rows and 3.0x the bytes. The single-pass form is the one to implement.

## 3. The sparkline span is FREE — 13 months costs nothing over 1 month

Two measurement sessions. Session A (quiet cluster), interleaved A/B n=6 each, org 165:

| span | min | median | p90 | max |
| --- | --- | --- | --- | --- |
| 1 month | 0.300s | 0.307s | 0.308s | 0.322s |
| 3 months | 0.345s | 0.351s | 0.356s | 0.378s |

Session B (cluster under contention), interleaved three-way n=5 each, org 165:

| span | min | median | max |
| --- | --- | --- | --- |
| 1 month | 0.721s | 3.616s | 8.706s |
| 3 months | 1.271s | 4.086s | 9.306s |
| **13 months** | **0.598s** | **3.580s** | **7.868s** |

**`read_rows` is byte-identical at 39,952,007 across 1, 3 AND 13 months.** Peak
`memory_usage` 437.5 MB at 13 months. The 13-month median came in at **-1.0% vs 1 month** and
**-12.4% vs 3 months** — i.e. the span is entirely inside the noise floor.

The mechanism is the one both sessions confirm: `zod.public_billing_events` is `ORDER BY id`,
so **nothing prunes** and the scan is the full table regardless of window width. Only the
surviving row count grows, and 13 buckets of aggregate is nothing.

**Consequence for the design decision.** DEV-2464 chose a 3-month span for its funnel. On this
card that choice buys nothing: 13 months is free and matches what the other eight cards on this
dashboard already render. Prefer **13 months**.

## 4. Server-side envelope

| metric | value |
| --- | --- |
| `read_rows` (1, 3 and 13 months alike) | 39,952,007 |
| `read_bytes` | 1.83 GB (1 month) → 2.03 GB (3 months) |
| peak `memory_usage` observed | **489 MB** |
| PHP-side allocation | **O(1)** — ≤13 bucket rows, no per-row loop |

The PHP side is the part that runs on our host, and it allocates one small result set. The
peak is ClickHouse Cloud's, not the app server's, so this card cannot reproduce the DEV-2342
host-memory failure mode.

## 5. Cheaper than the funnel — but I was wrong about the tail

| | DEV-2464 full funnel | this card (claims only) |
| --- | --- | --- |
| tables touched | 5, incl. hotlist 136M + status_log 389M | **3**, largest 38.0M |
| median | 5.04s | 0.31s (quiet) / 3.6s (contended) |
| worst observed | 16.5s / 33.7s | **9.3s** |

**Correction to this document's own earlier claim.** After session A I wrote *"No such tail
appeared here — 12 samples spanned 0.300-0.378s."* Session B disproves that. The same query, same
org, same window, ran a median of 3.6s and a max of **9.3s** — a 30x spread against session A,
with `read_rows` identical to the row. The difference is cluster contention, not query shape.

So the honest picture is: **this query costs somewhere between 0.3s and 9.3s depending on what
else is running on shared Cloud**, and the earlier "no fat tail" line was an artifact of
measuring in a quiet window. DEV-2464's own lesson — *"sequential batches on shared ClickHouse
Cloud cannot separate query shape from cluster load"* — applies to my session A as much as to its
own bad batch.

What survives unchanged: the tail here is **~3.6x smaller** than the funnel's (9.3s vs 33.7s),
and both sit under `CLICKHOUSE_TIMEOUT_QUERY` = 60s. And the ticket's
`Cache::flexible([3600, 86400])` rule is **load-bearing for the original reason after all** — a
cache miss in a contention window really can hold a dashboard render for most of ten seconds.

## 6. Honest limits on these numbers

- **All 12 A/B samples are WARM.** Earlier first-touch reads in the same session measured
  0.4–3.2s wall. Because nothing prunes, varying the window does not change which parts are
  read, so a genuinely cold read is not reproducible from here. Treat **~2-3s as the cold
  figure** and 0.31s as warm; the cold number is the one the caching rule protects against.
- **Concurrency is unmeasured.** Every figure is one query at a time. How N orgs
  cache-missing simultaneously behaves is unknown — same open item DEV-2464 carried.
- Single window (Jul 2026) plus a 3-month span to May 2026. **No snapshot was pinned**, so this
  is consistent-with rather than proven-identical to Brek's screenshot — but see §1: with
  immutable `billing_events` rows the drift argument that applied to DB repos does not apply here.
- The `insightlpr` needle match was implemented here as `position(stripped_name,'insightlpr')`
  on the org name. **That is the driver's reading of backyard's `rejectInternalOrgs()`, not a
  verified line-for-line port** — the implementation must check the real helper on
  `origin/deploy`. The fact that it reproduces the expected 32-row delta is strong evidence it
  is right, not proof.

## 7. WS6 perf gate

**Verdict: `go`.** The chosen approach (single-pass ClickHouse claims funnel, one
`Cache::flexible([3600, 86400])` query per resolved window, sparkline as a 13-month
`toStartOfMonth` grouping in that same query) is measured at production scale across two
sessions: 39.95M read_rows and 437-489 MB peak server memory regardless of span, 0.3s-9.3s
wall clock depending on cluster contention (well inside the 60s query timeout), and all six of
the ticket's target figures reproduced exactly.

The residual risk is the contention tail, and the ticket's own caching rule is the mitigation.

---

## 8. CORRECTION (review rounds 1-2): this document sized one query, not one render

All three review lanes, across both rounds, found the same gap in this file and in
`prep-context.json`'s `allocation_sketch`. Recorded here rather than quietly amended.

**What was wrong.** §4 and §7 size the card as *one* query per resolved window, and the
`allocation_sketch` claimed the cost was *"bounded by `Cache::flexible([3600, 86400])` to at most
one fresh compute per (org, window) per hour."*

Neither is true for two of the four filter states:

| filter | seams that fire on a cold render | scans | caching |
| --- | --- | --- | --- |
| `this_month` / `last_month` | sparkline only (`getMonthlyData`) | **1** | `heldFlexible`, `[3600, 86400]`, herd-guarded |
| `today` / `last_7d` | sparkline **and** window (`currentPeriodTotal`) | **2** | sparkline as above; window on the base class's `cache()->remember()` at **10 min** (60s floor while impersonating) |

So a cold `today` render is **~80M `read_rows` and ~3.7 GB**, not 40M / 1.83 GB, and the window
seam permits up to **6** fresh computes per hour per org rather than one.

**Why it was missed.** The one-cached-query AC is satisfied **per seam**, and the test that pins it
asserts one call per seam. That framing is defensible for the AC — the fan-out it targeted was the
base class's 13-queries-per-month loop, which is genuinely gone — but it is how the per-render
doubling escaped the record.

**Disposition** (operator decision, 2026-09-08): leave the window seam on the base class's
caching, shared with the nine sibling cards, rather than adding a fifth `DashboardWidget` override.
The 10-minute TTL bounds it to 6 computes/hour/org. The record is corrected; the behaviour is not
changed.

The per-query figures elsewhere in this document remain correct and re-verified — they were always
per-query measurements. Only the per-render extrapolation was wrong.
