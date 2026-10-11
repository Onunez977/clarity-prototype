# DEV-2381 — EXPLAIN runbook (chlocal operator)

Read-only against **ClickHouse Cloud**. Query **shapes**, parameter **classes**, and **aggregate** results only. Do not record kit mappings, plates, GL device ids, or user identifiers (same disclosure policy as `docs/investigations/DEV-2342/derivation_log.md`).

Baseline already captured 2026-08-13 on `clarity_clickhouse` / `readonly_user`: [DEV-2342 derivation_log](../DEV-2342/derivation_log.md). Re-run before DDL (canary) and after MATERIALIZE (prove the plan flipped).

## 0. Confirm Cloud schema (before ALTER)

```sql
SHOW CREATE TABLE clarity.sc_positions;
SELECT name, type FROM system.columns
WHERE database = 'clarity' AND table = 'sc_positions'
ORDER BY position;
SELECT total_rows, formatReadableSize(total_bytes) AS bytes, sorting_key
FROM system.tables
WHERE database = 'clarity' AND name = 'sc_positions';
```

Expected Cloud columns (2026-08-13): `id Int32`, `kit_mapping String`, `gl String`, `timestamp_utc DateTime`, `timestamp_local DateTime`, `geo Point`, `speed Float32`, `heading Float32`, `ingested_at DateTime64(3)`. Sorting key `(kit_mapping, timestamp_utc)`. Row count then **270,825,495**.

Platform `database/schema/clickhouse-schema.sql` is still a **column-stale** local subset (`kit_mapping`, `geo`, `timestamp_utc`, `speed`, `heading` only) plus a **hand-added** `proj_kit_mapping_lc` for CI Docker. Do not ALTER Cloud from that dump.

## 1. Canary EXPLAIN (read-only, before DDL)

Hold a real mixed-case mapping in-memory. Use a 15-minute window that matches the recorder lookback (`config/scout.php` `lookback_minutes` = 15).

```sql
EXPLAIN indexes = 1
SELECT timestamp_utc, geo
FROM clarity.sc_positions
WHERE lower(kit_mapping) = {mapping:String}
  AND timestamp_utc BETWEEN now() - INTERVAL 15 MINUTE AND now();
```

Control (exact-case, same mapping / same window):

```sql
EXPLAIN indexes = 1
SELECT timestamp_utc, geo
FROM clarity.sc_positions
WHERE kit_mapping = {mappingExact:String}
  AND timestamp_utc BETWEEN now() - INTERVAL 15 MINUTE AND now();
```

Record: granules selected / total, parts, whether a Projection section appears.

**2026-08-13 baseline (pre-projection):**

| predicate | granules | parts |
| --- | --- | --- |
| `lower(kit_mapping) = ?` | **3355 / 33096** | 19/19 |
| `kit_mapping = ?` (exact-case) | **20 / 33096** | 16/19 |
| `lower(kit_mapping) IN (40 kits)` | **3355 / 33096** | 19/19 |

`query_log` matcher baseline (Aug 12–13): p50 **~360–450 ms**, avg `read_rows` **~0.25–1.3M**.

## 2. After ADD PROJECTION (before MATERIALIZE)

New inserts populate the projection; historical parts do not. EXPLAIN on a 15-minute window of **already-written** rows may still show ~3355 granules. That is expected. Do not call the ticket done here.

```sql
SELECT name, type FROM system.projection_parts
WHERE database = 'clarity' AND table = 'sc_positions';
```

## 3. After MATERIALIZE finishes

Wait until `system.mutations` for this table shows `is_done = 1`. Then replay **the same** `lower(kit_mapping) = ?` EXPLAIN.

**Pass:**

- Granules are **tens**, not ~3355 (within a small factor of the exact-case **20 / 33096** plan).
- EXPLAIN names projection `proj_kit_mapping_lc`.
- Matcher `query_log` (`position_window` / `sc_positions`, similar concurrency): p50 **well below 400 ms**, `read_rows` **well below ~500k**.

**Fail / optimizer ignored the projection:**

- Do **not** rewrite PHP off of `lower(kit_mapping)` first.
- `IN` lists and `GROUP BY lower(kit_mapping)` (`OrgLivePositionsQuery`, `KitRangePathsQuery`) may ignore the projection even when equality uses it. That is a known risk on comment 38238. Equality (`ScoutCheckInMatchQuery.position_window`) is the load-bearing shape for recorder duration.
- Only after a stored column + reader rewrite would be considered — that is a **new** ticket, not a silent pivot on this one.

## 4. Optional bloom canary (not the shipped fix)

A bloom skip index on `lower(kit_mapping)` may be used as a **short experiment** and **dropped** if the projection ships. Granule skip is not `(key, time)` range prune. Do not leave it as the production fix.

## 5. What platform will not do

- No Cloud EXPLAIN assertion in PHPUnit / CI.
- No PHPUnit change unless the local Docker schema dump is refreshed after Cloud apply.
- No `scout:record-check-ins` schedule uncomment (DEV-2342 W4).
