ClickHouse follow-up plan — `clarity.sc_positions` lowercase projection

Investigation of this ticket’s \~104-minute cycles found the missing \~97% of wall-clock is **not** the Query A 30s timeouts (DEV-2339). It is per-kit `ScoutCheckInMatchQuery` chunk storms, made worse by `lower(kit_mapping)` disabling the `sc_positions` primary key.

**This ALTER does not ship on DEV-2342** (observability only). Implementation belongs in **chlocal** (schema/ingest owner) as a sibling/blocker of DEV-2349. Platform stays SELECT-only except wiki + schema dump after Cloud apply.

---

## Workload

* **Shape:** scanner GPS telemetry (`clarity.sc_positions`, \~268M rows, 6.5 GiB, TTL 120d).
* **Query pattern:** `lower(kit_mapping) = ? AND timestamp_utc BETWEEN ? AND ?`, plus org-wide `lower(kit_mapping) IN (…)` + 24h cutoff.
* **Why it is slow:** ORDER BY is `(kit_mapping, timestamp_utc)` (case-sensitive). Readers wrap the prefix in `lower()` (DEV-2012). Measured: **3056/32732 granules** vs **13/32732** for exact-case equality. \~40k matcher queries/hour, p50 \~400ms, \~0.5–1.3M rows read each.

An ALIAS/virtual column does **not** fix this — ClickHouse inlines `lower(kit_mapping)` and the primary index still cannot use the prefix. ORDER BY is immutable.

Flow: scanner ingest (mixed-case `kit_mapping`) → base table `ORDER BY (kit_mapping, timestamp_utc)` → projection `ORDER BY (lower(kit_mapping), timestamp_utc)` → Scout PHP keeps emitting `lower(kit_mapping)` and the optimizer reads the projection.

---

## Decision (locked)

**Ship a projection, not a virtual column, not a table rebuild.**

```sql
ALTER TABLE clarity.sc_positions
ADD PROJECTION proj_kit_mapping_lc
(
    SELECT *
    ORDER BY (lower(kit_mapping), timestamp_utc)
);

ALTER TABLE clarity.sc_positions
MATERIALIZE PROJECTION proj_kit_mapping_lc;
```

* **official:** projections are extra data ordered for a second query shape; `MATERIALIZE` backfills existing parts. [Projections docs](https://clickhouse.com/docs/engines/table-engines/mergetree-family/mergetree#projections).
* **derived:** `WHERE lower(kit_mapping) = ? AND timestamp_utc BETWEEN …` can use that projection with **no PHP rewrite**.
* **field:** `SELECT *` is acceptable — the table is narrow. Confirm Cloud columns first (prod has `id`, `gl`, `timestamp_local`, `ingested_at`; platform `database/schema/clickhouse-schema.sql` is a stale local subset).

**Rejected**

* ALIAS `kit_mapping_lc` — convenience only, same 3056-granule plan.
* MATERIALIZED column without changing ORDER BY — still not a PK prefix; needs a skip index **and** a query rewrite of every reader.
* Rebuild ORDER BY `(lower(kit_mapping), timestamp_utc)` — full copy; ingest/CDC owned elsewhere.
* Bloom skip index as the **shipped** fix — cheaper canary only. Granule skip, not `(key, time)` range prune.

**Out of scope (do not mix into this change)**

* `ScoutOpenOrderAddressesQuery` / `zod.public_hotlist_address` ORDER BY `id` (DEV-2339 leftover, Query A).
* 2000-row `values()` chunking / org 123’s \~255k open addresses (fewer granules per chunk ≠ fewer chunks).
* Raising `withoutOverlapping(10)` (this ticket).

This is a **duration cut**, not a chunk-count cut. After prune, 128 chunks × \~20ms can replace 128 × \~600ms; the 104-minute cycle can drop even if chunk count stays.

---

## Ownership split

Precedent: DEV-2177 — **chlocal owns** `clarity.sc_positions` DDL/ingest; platform only SELECTs.

* **chlocal:** `ADD PROJECTION` + `MATERIALIZE`; ingest keeps writing the base table (projection maintained on insert).
* **platform:** No matcher SQL change. After Cloud apply: refresh schema dump, wiki, EXPLAIN/`query_log` verification.

---

## Platform readers that benefit with zero rewrite

All already filter `lower(kit_mapping)`:

* `ScoutCheckInMatchQuery` (`position_window` CTE) — load-bearing for recorder duration
* `KitHistoricPositionsQuery`
* `KitRangePathsQuery`
* `OrgLivePositionsQuery` (`IN` + 24h cutoff)
* `OrgLivePathsQuery`
* `CloseInactiveShiftsCommand`
* `ClarityScoutMapController` (inline live-path / kit position)

---

## Rollout

1. **Canary EXPLAIN on Cloud (read-only)** — replay the 15-minute matcher predicate; capture granules/ranges. Optional: bloom skip index on `lower(kit_mapping)` as a short experiment; drop it if the projection ships.
2. **chlocal DDL** — `ADD PROJECTION` only (empty until materialize). New inserts start populating it.
3. **Off-peak** `MATERIALIZE PROJECTION` — \~6.5 GiB extra; mutation must finish before calling it done. Watch `system.mutations` / part counts. Avoid the recorder’s \~40k q/h peak if possible.
4. **Prove the plan flipped**

    * `EXPLAIN indexes = 1` (and projection section): granules near the **13/32732** exact-case baseline for a single mapping + 15 minutes.
    * `system.query_log` for `position_window` + `sc_positions`: p50 duration and `read_rows` vs the Aug 12–13 baseline (p50 \~360–450ms, avg read \~0.25–1.3M).
    
5. **platform follow-through** — re-export `database/schema/clickhouse-schema.sql`; update `okf/concepts/scout-clickhouse-queries.md`, `scout-real-time-tracking.md` (DEV-2012), `scout-check-in-recorder.md`, `platform-gotchas.md`. Record: filter `lower(kit_mapping)` is correct; prune comes from `proj_kit_mapping_lc`.
6. **No PHPUnit change required** unless local Docker schema is updated. Do not add a Cloud EXPLAIN assertion to CI.

---

## Success criteria

* Single-kit 15-minute `EXPLAIN`: granule count within a small factor of the exact-case plan (tens, not thousands).
* Matcher `query_log` p50 well below 400ms and `read_rows` well below \~500k under similar concurrency.
* Recorder cycle duration re-measured (DEV-2349 AC): if CH wait was the bulk, wall-clock should fall from \~36–148 min toward the 5–10 min cadence; if it does not, the leftover is Query A / chunk count / PHP — not this index.

## Risks

* **Storage and insert amplification** — second physical copy. Ingest is modest (\~1.2M rows / 6h); still budget \~6.5 GiB and extra write CPU.
* **MATERIALIZE load** — can compete with the overlapping recorder. Run off-peak; do not cancel mid-mutation casually.
* **Optimizer may ignore the projection** on some shapes (`IN` lists, `GROUP BY lower(kit_mapping)`). If EXPLAIN shows no projection, rewrite those readers only after adding a stored column — do not rewrite first.
* **Local vs Cloud schema drift** — Docker tests will not prove Cloud prune until the dump/export is refreshed.

## Todos

1. Open a chlocal schema ticket (sibling/blocker of DEV-2349); keep platform PHP out of the ALTER.
2. Capture Cloud EXPLAIN + `query_log` baseline for `lower(kit_mapping)` 15-minute matcher before DDL.
3. `ADD PROJECTION proj_kit_mapping_lc …`; `MATERIALIZE` off-peak.
4. Re-EXPLAIN and compare matcher `query_log` p50/`read_rows` to the Aug 12–13 baseline.
5. Re-export schema dump; update Scout CH wiki pages.


