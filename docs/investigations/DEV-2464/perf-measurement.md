# DEV-2464 — prod-scale measurement of the chosen approach (WS6 perf gate)

Read-only against production ClickHouse Cloud as `readonly_user`, via chlocal
`tools/compare.py` (`load_env` + `run_ch_query`). 2026-09-08. SQL committed alongside:
`measure-funnel-total.sql`, `measure-org-window.sql`, `measure-org-monthly.sql`.
Aggregates and timings only — no raw rows.

## 1. The port is faithful

Backyard's `claimsCte()` + `deduped` + `recoveriesCte()` + `orgDbReposSql()` ported to
ClickHouse, applying `FINAL` + `_peerdb_is_deleted = 0` on the hotlist leg and
`_peerdb_is_deleted = 0` (no `FINAL`) on the status log.

| stage | ticket target | this port | verdict |
| --- | --- | --- | --- |
| all claims (Jul 2026) | 15,128 | **15,128** | exact |
| successful | 14,288 | **14,288** | exact |
| deduped (total) | 11,975 | **11,975** | exact |
| DB repos (total) | 9,116-9,117 | 9,135 | +18, drift |
| deduped, largest forwarder | 7,230 | **7,230** | exact |
| DB repos, largest forwarder | 5,355-5,356 | 5,369 | +13, drift |

Per-forwarder deduped counts reproduce comment 39129's table exactly across the top five
orgs (165: 7,230 · 170: 2,696 · 81: 812 · 160: 809 · 717: 306). `case-claim` and `ls-claim`
confirmed as the real `event_type` values, with `ls-claim` at 6,893 rows = 1.85% of
`case-claim`'s 365,309 — matching the ticket's "1.8% of claim volume".

**This closes the FINAL doubt for good.** The +18 persists *with* `FINAL` applied, so it is
not a deduplication artefact. The drift rate also reconciles: comment 39117 read 9,130 on
2026-09-04, this reads 9,135 on 2026-09-08 (+5 in 4 days); the Postgres screenshot of
9,116-9,117 is from 2026-08-27 (+13 in 8 days). Both ≈ 1.3-1.6/day, in the single direction
port rule 4 predicts. Live-`hotlist` drift is the correct explanation.

## 2. Sparkline span costs essentially nothing

Interleaved A/B, n=8 each, org 165 (the largest forwarder — worst case). Interleaving
matters: a non-interleaved batch earlier produced a badly misleading result (below).

| span | min | median | p90 | max |
| --- | --- | --- | --- | --- |
| 1 month | 2.70s | **5.04s** | 5.54s | 16.52s |
| 3 months, bucketed by month in one query | 2.83s | **5.40s** | 6.22s | 33.65s |

**A 3-month grouped sparkline costs +7% at the median and +12% at p90 over a single window.**
The span is not the cost.

The mechanism is clear from the DDL: nothing prunes. `zod.public_billing_events` (38.0M) and
`zod.public_hotlist_status_log` (389.3M) are both `ORDER BY id`, so neither the `ts` range nor
`created_at` can use the primary index — the scan is the same size whatever the window, and only
the surviving row count changes, which is small relative to the scan. Widening the window
therefore adds almost nothing.

### A false result I have to flag
A first, **non-interleaved** batch measured the 3-month query at median 23.47s / max 42.03s and
the 1-month at a tight 2.70-3.05s, and I reported that the 3-month span was unstable. That was
wrong. Interleaving the two shows the spikes land on whichever query happens to run during a
contention window — the 1-month query produced a 9.0s and a 16.5s sample of its own. **Sequential
batches on shared ClickHouse Cloud cannot separate query shape from cluster load.** Interleave, or
do not compare.

## 3. The real operational finding: this card is ~6x cheaper than the ticket assumes

The ticket calls this "the expensive card" and records ~87s for the Postgres recoveries leg;
comment 39117 measured ~32s for the ClickHouse funnel. **This port runs at ~5s median.**

The difference is query shape, not luck. Three things:
- filter `hotlist` to `status = 3` inside the joined subquery, not after the join;
- filter `hotlist_status_log` to `new_status = 3` **and** the `min(claim_date) - 7` date floor
  before joining, so the 389M-row side is reduced first;
- reduce `deduped` to `DISTINCT (vin, agent_org_id)` before the hotlist join, exactly as
  backyard's own SQL does.

That is backyard's documented discipline — `recoveriesCte()`'s docblock says *"never scan all
status=3 hotlists for the agent orgs — that is tens of millions of rows"* — and honouring it is
what produces the 5s figure.

## 4. What still argues for the caching rule

A fat tail. Median ~5s, p90 ~6s, but observed maxima of **16.5s and 33.7s** across 16 samples on
shared Cloud. `CLICKHOUSE_TIMEOUT_QUERY` is 60s, so a cache miss landing in a contention window
could hold a dashboard render for tens of seconds and, in a worse moment, time out.

So the ticket's `Cache::flexible([3600, 86400])` rule stays load-bearing — but the reason has
moved. It is not "the query costs 32s"; it is "the query usually costs 5s and occasionally costs
34s, and a render must never wait on the bad case."

## 5. Still not measured

- **Server-side `read_rows` / `read_bytes` / peak memory.** `readonly_user` lacks
  `READ ON REMOTE`, so `clusterAllReplicas(system.query_log)` is denied. Confirmed still true;
  all figures here are client wall clock. This is open question 7.
- **Concurrency.** Every number here is one query at a time. How N orgs cache-missing
  simultaneously behaves is unmeasured, and the fat tail suggests contention is the thing that
  actually bites.
- The measurement used `public_clickhouse`-style raw SQL on the `zod` database. It did **not**
  compare against a `clarity.hotlist` variant (open question 4), and note
  `okf/concepts/clickhouse-integration.md:29` records `max_bytes_before_external_group_by = 10 GB`
  on the `clarity` connection, which `public_clickhouse` may not share.
