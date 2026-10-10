# DEV-2546 — production measurement (RN5 pre-fix gate)

Run 2026-09-15 against production via `ubuntu@10.100.3.142`, app at
`/home/ubuntu/code/platform`. All statements read-only. The `zod` connection resolves to
the **read replica** `pg-read.c7ideqgxcm2t.us-east-2.rds.amazonaws.com`, not the primary.

Purpose: settle the four premises the RN4 review lanes could not verify from the source
tree, so the standing High is fixed against measured behaviour instead of a third guess.

---

## Headline: three of the four premises came back AGAINST the review lanes

| # | Lane premise | Measured | Verdict |
|---|---|---|---|
| 1 | "no `statement_timeout` on the platform connection, so the aggregate's ceiling is whatever the server allows" | `statement_timeout = 30s` on **both** `platform` and `zod` | **REFUTED** — a hard bound already exists |
| 2 | "sequential scan over a 318M-row table, no LIMIT" | **zero** seq scans on `hits`/`hotlist`/`scans_metadata` at every filter width; always driven by `kit_id_created_at_idx` | **REFUTED** — the mechanism is wrong |
| 3 | prod `CACHE_STORE` might be the `database` default, making RN4's cache a Postgres write per filter change | `config('cache.default') = redis` | **REFUTED** |
| 4 | the Postgres branch is "hot in production" | it is the **only** branch customers ever reach — see the flag bug below | **CONFIRMED, and worse than stated** |

## Real table sizes (`pg_class.reltuples` / `pg_total_relation_size`)

| table | est. rows | size |
|---|---|---|
| `scans_metadata` | 3,409,680,896 | 705 GB |
| `hits` | 228,575,936 | 89 GB |
| `hotlist` | 133,567,408 | 98 GB |
| `orgs` | 713,241 | 127 MB |
| `kits` | 5,631 | 1.3 MB |

Note `hits` is **228.6M**, not the 318M carried through prep and every review round — that
figure was `zod.public_hits`. `scans_metadata` at 3.4B rows / 705 GB was never mentioned by
any lane, and it is the largest table in the join.

## Indexes on `hits`

```
hits_pkey                (id) UNIQUE
hits_created_at_idx      (created_at)
hits_hotlist_id_idx      (hotlist_id) INCLUDE (id, hotlist_id, scan_id, hit_type, created_at)
hits_idx                 (hotlist_id, scan_id, hit_type) UNIQUE
ix_hits_scan_id          (scan_id)
kit_id_created_at_idx    (kit_id, created_at)      <- this is what drives the aggregate
```

There is **no index on `hit_type` alone**, which is why a naive
`GROUP BY hit_type` over the whole table must never be run (see "incident" below).

## Plan-only `EXPLAIN` of the compiled aggregate — org 1143 (274 kits), by filter width

Query is `applyHitMetricAggregate()` over `buildPostgresQuery()` with the default
dispositions predicate. `EXPLAIN` without `ANALYZE`, so nothing executed.

| Hit Date filter | total cost | rows driven off `hits` | seq scans on hits/hotlist/scans_metadata |
|---|---|---|---|
| today (**the default**) | 17,438 | 404 | 0 |
| last 30 days | 835,659 | 18,939 | 0 |
| last 1 year | 5,370,764 | 249,030 | 0 |
| no date filter at all | 6,174,173 | 356,492 | 0 |

Plan shape at every width: `Parallel Bitmap Heap Scan on hits` using
`kit_id_created_at_idx`, then single-row index lookups — `scans_metadata_pk`,
`hotlist_pkey`, `orgs_pkey` x2. `kits` is a 5,631-row seq scan (1.3 MB table). The 46
`Seq Scan on scans_pNNNNNN` nodes are **empty future partitions** (2027-2029) at
`cost=0.00..0.00`; populated `scans` data is reached by index.

**The load-bearing fact the lanes missed:** `hits.kit_id IN (...)` bounds the query to one
org's kit set. It is never an unbounded pass over 228M rows. The widest possible filter for
the busiest org reaches ~356K hit rows, not hundreds of millions. "Largest single `kit_id`
group = 7.7M" (carried from prep) is not a bound this query is ever exposed to.

## What IS real: cost scales ~350x with the window, and 30s is reachable

The aggregate cannot stop early; the table's own query can (`simplePaginate` stops at
`perPage + 1`). That structural asymmetry — the lanes' actual point — stands. And it is
reachable: while running the (much simpler, join-free) stage-2 count

```sql
SELECT hit_type, COUNT(*) FROM hits
WHERE kit_id IN (274 kits) AND created_at >= CURRENT_DATE - INTERVAL '1 year'
GROUP BY hit_type
```

production returned **`SQLSTATE[57014]` — canceling statement due to statement timeout**.
So a one-year window over a single org already exceeds 30s for a query strictly cheaper
than the aggregate. That is an unplanned but genuine measurement, and it is the strongest
evidence for H1 in the whole round. It also shows the existing 30s `statement_timeout` is
what stops it from being worse.

## Pre-existing production bug found while settling premise 4 — NOT introduced by DEV-2546

`ListHits::shouldUseClickhouse()` (`ListHits.php:123`) tests the literal string
**`'clickhouse:hits'`** (colon). Pennant's defined feature is
`FeatureEnum::CLICKHOUSE_HITS->slug()` = `Str::slug('Clickhouse Hits')` =
**`'clickhouse-hits'`** (hyphen).

Verified on production:

```
Pennant defined features (17): shared-kits, invites, scan-clustering, repo-heatmap,
  missed-opportunity, flex-search, alert-settings, client-analytics, clickhouse-scans,
  clickhouse-hits, ai-notifications, lpr-visits, resend-to-clearplan, distance-from-given,
  clarity-scout, clarity-scout-trips, hit-webhooks
                                              ^ 'clickhouse:hits' is NOT among them

Feature::for($user)->active('clickhouse:hits') = false
config('app.experimental.clickhouse.hits')     = true   <- the experimental gate IS on
```

So the flag check can never be true, and the ClickHouse branch of the Hits page is
reachable **only** through `session('admin_impersonator')`. Consequences:

- Every customer org is on the **Postgres** branch, 100%. H1 has maximum reach.
- Everything RN2/RN4 did on the ClickHouse branch — the `uniqExact(id)` fix, the
  driver-aware SQL, both CH test files — only ever executes for an impersonating admin.
- `config('app.experimental.clickhouse.hits') = true` means someone intended this to be
  live. It has been dead since `dd2213685` (2026-02-26, PR #109 "Clickhouse") — seven
  months.

`git diff origin/main...HEAD` confirms `shouldUseClickhouse()` is **not touched by this
ticket**. Out of scope for DEV-2546; recorded here because it rewrites the ticket's risk
picture and is worth its own ticket.

## Incident note — honest record

The stage-2 count above was issued expecting `kit_id_created_at_idx` to bound it. It did
not bound it enough and the statement was cancelled by the 30s timeout. Nothing was
written and the read replica absorbed it, but the query was heavier than intended and
should have been plan-checked first, as stages 3 and 4 were. Recorded rather than dropped.

## Not run

`EXPLAIN (ANALYZE, BUFFERS)` (stage 4) executes the query and was deliberately left for an
explicit go-ahead. Given the 57014 above, the broad-window case is already answered: it
exceeds 30s. Only the default-window case (cost 17,438, ~404 rows) would add anything.
