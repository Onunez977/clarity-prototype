# DEV-2414 — implementation plan

**Route:** feature · **Base:** `main` · **One node** (single-node fast path, no worktree fan-out)

## Why one node

The two query classes are bound by `ScoutCheckInMatchQueryParityTest`, which asserts they agree.
Splitting matcher and rollup into separate nodes would leave the parity test red between merges,
and their test files overlap. One node, serialized.

## t1 — Geo-dedup the matcher and rollup

### ScoutCheckInMatchQuery

Two-stage restructure. Stage 1 is today's aggregate, unchanged; stage 2 is the new geo election.

```sql
matches AS (            -- ADD addr_lng / addr_lat passthrough
    SELECT a.address_id, a.hotlist_id, a.is_staged,
           a.addr_lng, a.addr_lat,                        -- NEW
           p.timestamp_utc, p.hour_bucket, p.lng, p.lat
    FROM position_window p
    INNER JOIN addresses a ON geoDistance(...) <= 160.934
),
address_hour AS (       -- EXACTLY today's aggregate, now keyed by geo too
    SELECT address_id, addr_lng, addr_lat, hour_bucket,
           argMin(hotlist_id, (toUInt8(is_staged), hotlist_id)) AS hotlist_id,   -- UNCHANGED
           min(is_staged)                                       AS is_staged,    -- UNCHANGED
           tupleElement(argMin((lng, lat), timestamp_utc), 1)   AS out_lng,      -- UNCHANGED
           tupleElement(argMin((lng, lat), timestamp_utc), 2)   AS out_lat,      -- UNCHANGED
           min(timestamp_utc)                                   AS out_matched_at
    FROM matches
    GROUP BY address_id, addr_lng, addr_lat, hour_bucket
),
ranked AS (             -- NEW: elect one address per geo per hour
    SELECT *, ROW_NUMBER() OVER (
        PARTITION BY addr_lng, addr_lat, hour_bucket
        ORDER BY toUInt8(is_staged) ASC, address_id ASC
    ) AS rn
    FROM address_hour
)
SELECT ... FROM ranked WHERE rn = 1
```

**Stage 1 is not optional.** Today's `argMin(hotlist_id, (toUInt8(is_staged), hotlist_id))` resolves
one `address_id` sitting on multiple hotlists. Fold it into a single-pass rank and those rows tie on
both sort keys, so ClickHouse elects arbitrarily — regressing a documented semantic and
destabilising `test_duplicate_book_rows_for_one_address_produce_one_match`. Keeping it makes the
change a strict superset of current behaviour.

**Why the winner's own aggregate is faithful:** `geoDistance` is evaluated against the *address*
coordinates, so byte-identical `(addr_lng, addr_lat)` rows have identical qualifying position sets —
identical `argMin` coords, identical `min(timestamp_utc)`. Only `address_id`/`hotlist_id`/`is_staged`
differ. Nothing is lost by electing one.

The PHP return-mapping block and the `list<array{...}>` shape stay unchanged.

### ScoutCheckInRollupQuery

```sql
matches AS (   -- was: SELECT DISTINCT a.address_id, a.is_staged, s.hour_bucket
    SELECT a.address_id, a.is_staged, a.addr_lng, a.addr_lat, s.hour_bucket
    FROM scan_window s INNER JOIN addresses a ON geoDistance(...) <= 160.934
),
ranked AS (
    SELECT *, ROW_NUMBER() OVER (
        PARTITION BY is_staged, addr_lng, addr_lat, hour_bucket   -- is_staged IN the key
        ORDER BY address_id ASC
    ) AS rn
    FROM matches
)
SELECT toUInt64(countIf(is_staged = false AND rn = 1)) AS direct_bucket,
       toUInt64(countIf(is_staged = true  AND rn = 1)) AS staging_bucket
FROM ranked
```

`is_staged` sits **in** the partition key — that is what keeps the both-buckets semantic (AC6).
Direct and Staging each elect their own winner and never compete, so a shared location still counts
once in each bucket. No Direct-wins here; applying it would silently delete staging counts.

The `addresses` CTE is left alone — its `DISTINCT` dedups duplicate book-snapshot rows, a separate
pre-existing concern.

### Docblocks

Both classes: geo grain + the new priority rule, kept tight. The matcher additionally gets **one**
line recording the deploy-boundary grain discontinuity (pre-fix rows on the `address_id` grain,
post-fix on the geo grain, no backfill).

### Tests (TDD — red before green)

| test | change |
|---|---|
| `ScoutCheckInMatchQueryParityTest` | **ADD** distinct `address_id`s at identical coords → 1 match, lowest wins |
| `ScoutCheckInMatchQueryParityTest` | **ADD** priority: co-located Direct+Staging where **Staging holds the lower `address_id`** → Direct persists (isolates Direct-wins from `address_id` order) |
| `ScoutCheckInMatchQueryParityTest` | **ADD** co-located Directs seeded so `hotlist_id` order would elect the **wrong** winner → `address_id` decides |
| `ScoutCheckInMatchQueryParityTest` | **ADD** rollup-side: 2 Direct `address_id`s at one geo → `direct_bucket == 1` (nothing currently covers the rollup's within-bucket collapse) |
| `ScoutCheckInMatchQueryParityTest` | **UNCHANGED, must stay green:** `test_duplicate_book_rows_...`, `test_shared_address_is_direct_wins_...` |
| `ScoutRecordCheckInsCommandTest` | **ADD** volume: N≥3 co-located Directs → exactly 1 persisted row |
| `ScoutRecordCheckInsCommandTest` | **RENAME only** → `it_records_multiple_distinct_geo_addresses_in_one_cycle`; **assertion stays 3** (fixtures are ~14.7 km apart) |
| `ScoutRecordCheckInsCommandTest` | **UNCHANGED pins:** direct+staging (~1.47 km apart, 2 rows), hour-boundary (2 rows) |
| `ScoutRecordCheckInsMemoryTest` | **MUST RUN** — `assertSame(1, …)` + RSS budget; the resource-envelope guard |
| `ScoutRecordCheckInsOverrunSignalTest`, `ScoutRecordCheckInsScheduleTest` | **MUST RUN** — unaffected |

### Gates

- **Sizing:** PASS — 4 files, one concern.
- **Blast radius:** PASS — no sensitive-glob match.
- **Perf (WS6):** NOT REQUIRED — the scan-side access path is unchanged; the only new work is a
  `ROW_NUMBER` over the already bbox-and-radius-narrowed CTE, which can only shrink the result set.
- **AC8:** no migration, schedule stays commented — verify at the blast-radius re-check.

### Out of scope

`ScoutRecordCheckIns.php`, the controller, the `addresses` CTE, migrations, `routes/console.php`,
and all `okf/` pages (wiki WRITE lands post-merge). A chlocal follow-up ticket for the
fallback-centroid coordinates is recommended separately — see `cluster-investigation.md`.

---

> **SUPERSEDED (2026-08-25).** The election specified above — `PARTITION BY addr_lng, addr_lat,
> hour_bucket ORDER BY toUInt8(is_staged) ASC, address_id ASC` — is **not** what shipped. The
> DEV-1756 resolution changed it to `PARTITION BY addr_lng, addr_lat, hour_bucket, out_is_staged
> ORDER BY address_id ASC`, so both sides are kept at a shared geo. This document is retained as the
> record of the original plan; see `dev1756-conflict.md` for the change and its measured basis.
