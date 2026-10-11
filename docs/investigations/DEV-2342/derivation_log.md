# DEV-2342 — Derivation Log (t1 EXPLAIN-first)

Read-only measurements against **ClickHouse Cloud** (`clarity_clickhouse`, `readonly_user`), 2026-08-13.
Query SHAPES + parameter classes + aggregate results only — no raw rows, plates, or kit identifiers
(non-negotiable 3). Kit mappings used as query parameters were held in-memory and never recorded.

## Baseline

- `SELECT total_rows FROM system.tables WHERE database='clarity' AND name='sc_positions'` → **270,825,495 rows**.
- Sorting key: `(kit_mapping, timestamp_utc)` (case-sensitive) — confirmed via `system.tables`.
- Columns (Cloud, authoritative — the local `database/schema/clickhouse-schema.sql` is a stale subset per evidence-38238): `id Int32, kit_mapping String, gl String, timestamp_utc DateTime, timestamp_local DateTime, geo Point, speed Float32, heading Float32, ingested_at DateTime64(3)`. `geo` is a ClickHouse `Point` (Tuple(Float64,Float64)) → `tupleElement(geo,1)=lng`, `tupleElement(geo,2)=lat` as the matcher assumes.

## Granule prune (the PK-bypass, root cause 1) — 15-min window, single kit

`EXPLAIN indexes = 1 SELECT timestamp_utc, geo FROM clarity.sc_positions WHERE <pred> AND timestamp_utc BETWEEN ? AND ?`

| predicate shape | granules scanned | parts |
| --- | --- | --- |
| `lower(kit_mapping) = ?` (per-kit, current table) | **3355 / 33096** | 19/19 |
| `kit_mapping = ?` (exact-case, the prune baseline) | **20 / 33096** | 16/19 |
| `lower(kit_mapping) IN (40 kits)` (per-org) | **3355 / 33096** | 19/19 |

Reconfirms evidence-38238 (3056/32732 vs 13/32732 then; table has grown). **`lower()` defeats the PK
either way** → per-org `IN` scans the SAME 3355 granules as a single per-kit `lower()=` on the current
UNPROJECTED table (so there is no per-query granule penalty to IN pre-W1; the only difference pre-W1 is
query COUNT). The ~20-granule exact-case plan is what W1's `proj_kit_mapping_lc` projection unlocks for
`lower(kit_mapping)=?`. evidence-38238 flags that the optimizer **may ignore the projection for `IN`
lists** → the post-W1 prune is guaranteed for equality but uncertain for IN.

## GPS density (design risk 2) — position_window size

`SELECT count() FROM clarity.sc_positions WHERE timestamp_utc >= now()-INTERVAL 15 MINUTE GROUP BY lower(kit_mapping) ORDER BY count() DESC`

- Global positions / 15 min: **45,480**.
- Densest kit `position_window` / 15 min: **4,542 rows** (one outlier ≈5/s); typical dense kit **~850–900 rows** (top-5 15m: 4526, 897, 896, 855, 843).
- Densest kit / 60 min: **41,497** (same outlier); 2nd–5th: 3254/3234/2896/2891 (≈0.9/s, the normal ~3s cadence).

**position_window is at most a few thousand rows per kit per 15-min window → no memory concern and no
decimation needed.** The rollup's bbox-prefilter profile transfers; GPS density does not force GPS
decimation. (If a future outlier kit emits at multi-Hz, revisit with a `toStartOfInterval` decimation
inside `position_window` — not needed now.)

## Decisions grounded by t1

- **W2 query shape = per-kit equality** (`lower(kit_mapping) = ?`, one statement per kit). W2 fixes
  memory (`O(matches)`) regardless of shape; the granule prune only matters post-W1, where equality is
  guaranteed to prune (~20 granules) but IN may not. We optimize for the post-W1 state (schedule stays
  disabled until W1 lands, Q9). ~43 small pruned queries/cycle > one possibly-unpruned 3355-granule org scan.
- **Batching granularity = per-kit** (follows from the shape).
- **GPS decimation = not needed.**
- **W1 remains the duration fix** (closes 3355→~20 granules); **W2 is the memory fix**. Both required
  before re-enable (W4); this reaffirms the schedule stays disabled until W1 ships.
