# DEV-2466 — Spike: plate-level unique-scan feasibility on `clarity.scans_cold`

**Verdict: GO**, with four conditions. All measurements read-only against ClickHouse Cloud
(`readonly_user`), aggregates only — no raw rows, plates, or VINs recorded.

Measured 2026-09-08 (UTC). ClickHouse 26.4.1.2212.

---

## 1. Both stated premises are wrong — and so is the existing correction

### 1a. The sort key

| source | claimed sort key |
| --- | --- |
| ticket body | `(kit_id, scanned_at, id)` |
| comment 39123 (2026-09-04) | `(kit_id, scanned_at, id)` |
| **production, measured** | **`(org_id, scanned_at, kit_id, id)`** |

```
SELECT name, total_rows, formatReadableSize(total_bytes), sorting_key
FROM system.tables WHERE database='clarity' AND name='scans_cold'
```

| name | total_rows | disk | sorting_key |
| --- | --- | --- | --- |
| `scans_cold` | 2,180,621,658 | 122.58 GiB | `org_id, scanned_at, kit_id, id` |
| `scans_hot` | 298,222,481 | 17.94 GiB | `org_id, scanned_at, kit_id, id` |
| `scan_report_hourly` | 5,901,295 | 8.13 GiB | `org_id, kit_id, scan_hour` |
| `affiliate_stats_hourly` | 3,775,529 | 30.02 MiB | `org_id, kit_id, scan_hour` |

`org_id` is the **leading** primary-key column. The committed schema dump misstates this
(same defect recorded under DEV-2448). `PARTITION BY toYYYYMM(scanned_at)` and
`ENGINE = SharedReplacingMergeTree(..., _version, _peerdb_is_deleted)`.

### 1b. Pruning does occur — `EXPLAIN indexes = 1`, org 1271, last month

```
PrimaryKey
  Keys: org_id, scanned_at
  Condition: and((scanned_at in (-Inf,'1788220799']), and((scanned_at in ['1785542400',+Inf)), (org_id in [1271,1271])))
  Parts: 14/15
  Granules: 1698/12526
  Search Algorithm: binary search
```

Partition min-max first cuts 268 parts / 266,535 granules → 15 / 12,526; the primary key
then cuts to **14 parts / 1,698 granules**. A 157x granule prune by binary search. The
"no primary-key pruning on a 2.1-billion-row table" premise does not hold.

### 1c. The `kit_id`-routing fix in comment 39123 is **6.7x worse**

Comment 39123 recommends resolving the org to its kit ids and filtering on `kit_id`,
"the leading sort-key column". `kit_id` is the **third** column. Filtering it without
`org_id` degrades to generic exclusion search and prunes essentially nothing.

Org 1271, Aug 2026, both returning the identical **6,705,174**:

| approach | granules | read_rows | read_bytes | peak mem | server ms |
| --- | --- | --- | --- | --- | --- |
| `org_id = 1271` | **1,698 / 12,526** | **15,550,080** | **650 MB** | 1.17 GB | **374** |
| `kit_id IN (117 ids)` | 12,525 / 12,526 | 104,152,388 | 4.32 GB | 2.31 GB | 1,433 |
| kit-routing penalty | — | **6.7x** | **6.6x** | 2.0x | **3.8x** |

`EXPLAIN` on the kit-routed form: `Search Algorithm: generic exclusion search`,
`Granules: 12525/12526`. Do **not** route through `kit_id`. Filter `org_id` directly.

---

## 2. The trap this spike was written to detect does not apply

Cost scales with org size by ~**1,900x** across the endpoints. `uniqExact(ocr)` over
`scans_cold FINAL` with `_peerdb_is_deleted = 0`, Aug 2026:

| org | kits | read_rows | read_bytes | peak mem | server ms | unique plates |
| --- | --- | --- | --- | --- | --- | --- |
| 1271 (largest) | 117 | 15,550,080 | 650 MB | 1,168 MB | 374 | 6,705,174 |
| 58069 (small) | 1 | 98,304 | 4.06 MB | 31 MB | 18 | 25,297 |
| 48575 (tiny) | 1 | **8,192** | 0.17 MB | 3.8 MB | 8 | 138 |

Org 48575 reads a **single granule**. The DEV-2409 finding ("an org with 2 in-window rows
read 1,635,868 rows") is a symptom of filtering a column absent from the sort key — it
does not reproduce here, because `org_id` *is* in the sort key. "Most orgs are small" is
a valid argument for `scans_cold`.

---

## 3. Full matrix — 2 orgs x 4 dashboard windows (AC 2)

Windows taken verbatim from `BuildsDateFilterChartSeries::resolveCardRange()`. Anchored
2026-09-08 UTC. Query shape:

```sql
SELECT uniqExact(ocr) FROM clarity.scans_cold FINAL
WHERE org_id = ? AND scanned_at >= ? AND scanned_at <= ? AND _peerdb_is_deleted = 0
```

| org | window | read_rows | read_bytes | peak mem | server ms | client s | plates |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1271 | Today | 247,276 | 10.3 MB | 71 MB | 25 | 0.143 | 70,339 |
| 1271 | Last 7d | 4,086,448 | 170.7 MB | 380 MB | 139 | 0.263 | 1,733,214 |
| 1271 | This Month | 4,086,448 | 170.7 MB | 295 MB | 128 | 0.253 | 1,733,214 |
| 1271 | Last Month | 15,550,080 | 649.7 MB | 1,168 MB | 374 | 0.486 | 6,705,174 |
| 58069 | Today | 31,015 | 1.28 MB | 10 MB | 11 | 0.132 | 177 |
| 58069 | Last 7d | 63,783 | 2.63 MB | 14 MB | 15 | 0.152 | 13,241 |
| 58069 | This Month | 63,783 | 2.63 MB | 14 MB | 15 | 0.152 | 13,241 |
| 58069 | Last Month | 98,304 | 4.06 MB | 31 MB | 18 | 0.158 | 25,297 |

Peak memory is server-side (`memory_usage`); where `system.query_log` resolved it agreed
within 25% (e.g. Last 7d: summary 307 MB, query_log 380 MB — the query_log figure is
tabulated above where available).

**`Last 7d` and `This Month` are the same window on the 8th of a month** —
`subDays(7)->startOfDay()` = 2026-09-01 = `startOfMonth()`. Not a measurement error; the
two filters genuinely coincide for the first 8 days of every month.

### The server-side envelope was obtainable all along

Comment 39123 recorded these as "not yet obtained: `readonly_user` lacks `READ ON REMOTE`
so `clusterAllReplicas(system.query_log)` is denied". That path is not needed. The
`X-ClickHouse-Summary` **HTTP response header** carries `read_rows`, `read_bytes`,
`memory_usage` and `elapsed_ns` for every query, with no extra grant:

```
X-ClickHouse-Summary: {"read_rows":"15550080","read_bytes":"649667508",
  "memory_usage":"1168419595","elapsed_ns":"373575287", ...}
```

`system.query_log` on the local replica is also readable (37,213,245 rows) and resolved
for ~30% of queries — Cloud routes the log read to an arbitrary replica, so it is a
best-effort supplement, not the primary source.

---

## 4. The real cost centre is the 13-month series, not the card total

`DashboardWidget::getMonthlyData()` needs a **13-month** series for every card's
sparkline and expanded chart. For scan *counts* that is free from `scan_report_hourly`
(`ScanStatsBatchQuery`). **No pre-aggregate answers period-distinct plates**, so a
plate-distinct card must compute the series from `scans_cold`.

Worst case = org 469 (13 months present, 65,427,573 scans):

| shape | read_rows | read_bytes | peak mem | server ms | client s |
| --- | --- | --- | --- | --- | --- |
| 13-month series, `FINAL` | 60,757,669 | 2.52 GB | **2.51 GB** | 1,351 | 1.491 |
| 13-month series, no `FINAL` | 60,738,996 | 1.31 GB | 2.44 GB | 1,102 | 1.227 |
| single-window card total | 7,218,864 | 300 MB | 447 MB | 190 | 0.322 |

One card's cold series costs **~8x** its own headline total. Org 1271 (5 months of
history) cost 47,195,910 rows / 1.97 GB / 1,025 ms — the 13-month figure is the ceiling
for a long-tenured high-volume org.

### 4-card concurrent envelope (measured, not extrapolated)

Four concurrent 13-month plate-distinct queries, org 469:

| | per query | aggregate |
| --- | --- | --- |
| read_rows | 60,762,142 | **243,048,568** |
| read_bytes | 2.52 GB | **10.09 GB** |
| peak memory | 2.50–2.52 GB | **~10.03 GB concurrent** |
| client wall | 1.528 / 2.555 / 2.624 / 3.818 s | slowest **3.818 s** |

Solo the same query took 1.491 s, so 4-way concurrency degrades latency **2.6x**. Against
the DEV-2409 cold-render baseline (52 queries / 25.95 s client / >=6,024 ms CH server),
four *unbatched* direct-read cards would add ~3.8 s client and ~5.4 s CH server — roughly
**doubling** the dashboard's ClickHouse server time — and ~10 GB of concurrent peak memory
per viewing user.

That is the number that decides the verdict, and it is why the conditions below are
conditions and not suggestions.

---

## 5. `FINAL` is pure overhead for `uniqExact(ocr)` — and skipping it is verified safe

Org 469, Aug 2026, 3 runs each, **identical answer 2,800,575** every run:

| | read_rows | read_bytes | peak mem | server ms |
| --- | --- | --- | --- | --- |
| `FINAL` | 7,218,864 | 299.9 MB | 445–450 MB | 190 / 190 / 209 |
| no `FINAL` | 5,573,739 | 120.4 MB | 410–419 MB | 96 / 102 / 106 |
| `FINAL` cost | +29.5% | **+149%** | +8% | **+97%** |

Why the answer cannot differ — measured on the same window:

| probe | result |
| --- | --- |
| duplicate-`id` groups | 1,635 |
| ...carrying **more than one distinct `ocr`** | **0** |
| ...carrying a `_peerdb_is_deleted` flip | **0** |
| soft-deleted rows in window | **0** |
| row-level dup rate | 0.03% (5,462,685 raw vs 5,461,050 FINAL) |

`ocr` is immutable across a scan's CDC versions, so CDC duplicates collapse inside
`uniqExact` without `FINAL`. Keep `_peerdb_is_deleted = 0` — it is nearly free and guards
the delete case. This differs from `hits_cold`, where a mutable sort-key column
(`hit_created_at`) makes `FINAL` load-bearing (DEV-2406).

Scope caveat: verified on one org / one month (5.46M rows, 1,635 dup groups). Strong, but
the card tickets should keep the probe above as a cheap regression check rather than treat
"no FINAL" as unconditional.

## 6. `scans_hot` is not a win

Org 1271, This Month:

| table | parts | read_rows | read_bytes | peak mem | server ms |
| --- | --- | --- | --- | --- | --- |
| `scans_cold` | 10 | 4,086,448 | 170.7 MB | 380 MB | 139 |
| `scans_hot` | 53 | 3,470,485 | 144.9 MB | 440 MB | **454** |

Fewer rows but **3.3x slower** — `scans_hot` carries 5x the parts, so `FINAL`'s merge
work dominates. `ScanCount` already reads `scans_cold` for every window regardless, because
`Scan::hot()` returns an instance and `$scan::query()` is static — it discards the
`setTable('scans_hot')` (DEV-2324). The four new cards should read `scans_cold`
deliberately rather than inherit that latent routing bug.

---

## 7. One query can serve both the count card and the leaderboard

`GROUP BY kit_id WITH ROLLUP` returns the per-kit leaderboard **and** the org total
(`kit_id = 0`) in a single pass. Org 469, Aug 2026, no `FINAL`:

| shape | read_rows | read_bytes | peak mem | server ms |
| --- | --- | --- | --- | --- |
| org total alone | 5,573,739 | 120.4 MB | 410 MB | 96 |
| per-kit leaderboard alone (`FINAL`) | 15,550,080* | 650 MB* | 865 MB* | 359* |
| **both, `WITH ROLLUP`** | **5,573,739** | 142.7 MB | 249 MB | 606 |

\* org 1271 figures; org 469's standalone leaderboard was not measured separately.

The `WITH ROLLUP` total was **2,800,575** — exactly the standalone scalar. This is the
batching the ticket mandates, expressed as one query: DEV-2467's headline and DEV-2468's
leaderboard from one scan.

---

## 8. `affiliate_stats_hourly` answers a *different* question, 260x cheaper

Comment 39123 is right that `clarity.affiliate_stats_hourly` exists and carries
`unique_plates UInt64`. Confirmed: 3,775,529 rows / 30.02 MiB, `ORDER BY (org_id, kit_id,
scan_hour)`, synced to within **1 minute** when checked.

13-month series, org 469, both paths:

| path | read_rows | read_bytes | peak mem | server ms | client s |
| --- | --- | --- | --- | --- | --- |
| `scans_cold` (period-distinct) | 60,757,669 | 2.52 GB | 2.51 GB | 1,351 | 1.491 |
| `affiliate_stats_hourly` (hourly-sum) | **234,054** | **6.55 MB** | **51 MB** | **17** | 0.126 |
| rollup advantage | **260x** | **385x** | **49x** | **79x** | 12x |

Four concurrent rollup queries: 936,216 rows / 26.2 MB / ~60 MB total peak / 0.154–0.591 s
— against the direct path's 243M rows / 10.09 GB / ~10 GB.

### But the two definitions are 18–63% apart

Summing hourly `unique_plates` is **not** period-distinct: a plate seen in two hours is
counted twice. Aug 2026:

| org | hourly-sum | period-distinct | overcount |
| --- | --- | --- | --- |
| 1271 | 10,441,330 | 6,705,174 | **1.557x** |
| 469 | 4,567,090 | 2,800,575 | **1.631x** |
| 58069 | 29,951 | 25,297 | 1.184x |

The gap is **org-dependent** (larger orgs recur more plates per hour), so it cannot be
corrected by a constant factor.

A third definition lurks in the leaderboard: **per-kit** distinct plates do not sum to
**org** distinct plates (a plate seen by two kits counts once per kit). The
`WITH ROLLUP` shape above gives both without ambiguity — the card must state which it
shows.

## 9. Rollup coverage is complete — closes open item 4 of comment 39123

Comment 39123 left this "unverified" on a 2-org sample. Verified across every org:

| probe | value |
| --- | --- |
| orgs owning kits (`zod.public_kits`) | 548 |
| orgs with scans in Aug 2026 (`scans_cold`) | **264** |
| orgs in Aug 2026 rollup | **264** |
| orgs in rollup all-time | 386 |

Per-org `scan_count` vs `scans_cold count()`, all 264 Aug orgs:

| probe | value |
| --- | --- |
| orgs compared | 264 |
| exact match | 262 |
| within 1% | **264 / 264** |
| more than 5% off | **0** |
| worst relative delta | 0.0000 |
| global ratio (rollup / scans_cold) | **1.000000** |

The 548 vs 386 gap is orgs that own kits but have never scanned — not a coverage hole.
The rollup is complete and exact on totals.

### Data-hygiene note confirmed

`affiliate_stats_hourly` carries junk `scan_hour` values (max **2051-05-26**). A naive
`max(scan_hour)` freshness probe is fooled. Use `max(_peerdb_synced_at)` — which read a
**1 minute** lag. Windowed queries are unaffected.

---

## 10. Verdict — GO

**GO.** Plate-level unique-scan cards can read `clarity.scans_cold` directly. No new
chlocal DDL is required, and the no-go branch in the ticket
(`AggregateFunction(uniqExact, String)` over `ocr`) can be dropped.

The single-window card read is cheap for every org: 8 KB–650 MB, 8–374 ms. The reason is
`org_id` leading the sort key, which the ticket and its correction both had wrong.

Conditions — each one is measured above, not asserted:

1. **Filter `org_id` directly.** Never route through `kit_id` (§1c: 6.7x rows, 3.8x time,
   no pruning).
2. **Batch the 13-month series across cards** with a cross-class cache **key**
   (`SharedHitStatsData::sharedUniqueRangeMemoKey()` pattern), never a `static::$memo`
   array (DEV-2406: trait statics are copied per using-class). Unbatched, four cards cost
   ~10 GB concurrent peak and ~3.8 s wall on a cold render (§4). Use
   `GROUP BY kit_id WITH ROLLUP` so the count card and the leaderboard share one scan (§7).
3. **Omit `FINAL`, keep `_peerdb_is_deleted = 0`** (§5: 2x server time and 2.5x bytes for
   zero answer change; `ocr` verified immutable across CDC versions). Carry the dup-group
   probe as a regression check.
4. **Use `static::clarityDb()`, never a hardcoded `clarity.` prefix** — as the ticket
   requires. `LprVisitCountQuery`, `RdnHitHourCountQuery`, `RecoveryLeaderQuery`,
   `ScanStatsBatchQuery` and `RdnScanHourCountQuery` all hardcode it; copy
   `HitStatsBatchQuery` / `HitPlateStatsBatchQuery`.

Per-card viability:

| ticket | card | viable | note |
| --- | --- | --- | --- |
| DEV-2467 | Unique Scan Counts | **yes** | 13-month series is the cost; batch it |
| DEV-2468 | Unique Scans Leaderboard | **yes** | free alongside 2467 via `WITH ROLLUP` |
| DEV-2469 | Unique Scans to LPR Repossession | **yes** | cheapest of the four — reads `recovery_scans` (which already carries `ocr`), never `scans_cold`: 1,195,761 rows / 27 ms |
| DEV-2470 | Unique Scans to Repo | **yes, technically** | same cheap path; still blocked on the product question of whether it duplicates 2469 |

**The definitional question is answered** — period-distinct plates — and it is answered by
the ticket set and shipped code rather than by preference. See §11. The remaining decision
is a UI one, not a blocker: whether to reconcile or explain the divergence against the
existing Scan Count card and the affiliate report.

---

## Proven query shapes (record for the card tickets)

Single window, org total:

```sql
SELECT uniqExact(ocr) AS unique_plates
FROM {clarityDb}.scans_cold
WHERE org_id = :org_id
  AND scanned_at >= :start AND scanned_at <= :end
  AND _peerdb_is_deleted = 0
```

13-month series (the batched, cached shape):

```sql
SELECT toStartOfMonth(scanned_at) AS month, uniqExact(ocr) AS unique_plates
FROM {clarityDb}.scans_cold
WHERE org_id = :org_id
  AND scanned_at >= :start_13mo AND scanned_at <= :end
  AND _peerdb_is_deleted = 0
GROUP BY month ORDER BY month
```

Count card + leaderboard in one pass (`kit_id = 0` is the org total):

```sql
SELECT kit_id, uniqExact(ocr) AS unique_plates
FROM {clarityDb}.scans_cold
WHERE org_id = :org_id
  AND scanned_at >= :start AND scanned_at <= :end
  AND _peerdb_is_deleted = 0
GROUP BY kit_id WITH ROLLUP
```

Rollup alternative, **if** the hourly-sum definition is chosen (260x cheaper, different
number):

```sql
SELECT toStartOfMonth(scan_hour) AS month, sum(unique_plates) AS unique_plates
FROM {clarityDb}.affiliate_stats_hourly FINAL
WHERE org_id = :org_id
  AND scan_hour >= :start AND scan_hour <= :end
GROUP BY month ORDER BY month
```

---

## The definitional question is ANSWERED — by the ticket set and shipped code

It looked like a product coin-flip. It is not. Three independent lines eliminate the
hourly-sum option by construction.

### 1. DEV-2467's own acceptance criterion

> "Counts **distinct** `ocr`, with `FINAL` and the soft-delete filter."

`sum(unique_plates)` over hourly buckets is not a distinct count of anything. The AC
already specifies period-distinct.

### 2. The DEV-2406 hit-side precedent DEV-2467 is required to match

DEV-2467 says the definition is "consistent with DEV-2406's hit-side precedent". That
precedent is shipped code — `app/Clickhouse/Queries/HitPlateStatsBatchQuery.php`:

- `uniqExactIf(ocr, hit_type = 'L')` etc., computed **over the window** —
  `GROUP BY toStartOfMonth(hit_created_at)` for the series, and a bare aggregate over the
  range in `handleRange()` for today/last_7d.
- It **explicitly rejects the rollup** in its own docblock: *"the `hit_stats_daily` rollup
  has no plate column"*, so it reads `hits_cold` directly.

Period-distinct over the raw table is the pattern already in production on the hit side.

### 3. DEV-2469 and DEV-2470 structurally cannot use the rollup — and must share the definition

`affiliate_stats_hourly` has exactly seven columns:

| name | type |
| --- | --- |
| `org_id` | Int32 |
| `kit_id` | Int32 |
| `scan_hour` | DateTime |
| `scan_count` | UInt64 |
| `unique_plates` | UInt64 |
| `_peerdb_synced_at` | DateTime64(9) |
| `_version` | UInt64 |

**No `ocr`, no scan id.** DEV-2469 counts distinct plates *among scans attributed to an LPR
repossession via `clarity.recovery_scans`* — per-scan attribution the rollup cannot express
at any cost. And DEV-2469's AC requires "**sharing the sibling unique-scan cache key**", so
all four cards must share one definition and one query path.

The only definition all four cards can produce is **period-distinct**. Choosing hourly-sum
would make DEV-2467 and DEV-2468 unbuildable *as a set* with DEV-2469 and DEV-2470.

### Therefore: period-distinct plates. `uniqExact(ocr)` over the window.

The affiliate report's looser "unique scans" number does not change this — it is a
different, coarser metric, and the divergence is handled the way DEV-2467 already
anticipates for the Scan Count divergence: *"Decide explicitly whether to reconcile the two
cards or explain the difference in the UI — do not ship the discrepancy unexamined."*
Record the affiliate-report divergence the same way, with the measured factor
(**1.18x-1.63x**, org-dependent) in the Build Record.

The third definition still needs a UI decision, but it is not a blocker: **per-kit** distinct
plates do not sum to **org** distinct plates. The `WITH ROLLUP` shape returns both, so the
leaderboard and the count card can be internally consistent by construction — the card just
has to say which one it shows.

---

## Bonus finding — DEV-2469 and DEV-2470 never need `scans_cold` at all

DEV-2469's stated data path says `scans_cold` is *"needed for the plate (`ocr`)"* and to
"resolve plates from `scans_cold`". That is unnecessary: **`clarity.recovery_scans` already
carries `ocr` directly.**

```
CREATE TABLE clarity.recovery_scans (
    recovery_id Int64, hit_id Int64, scan_id Int64, hit_type String,
    kit_id Int32, ocr String, geo Point, scanned_at DateTime64(6), ...
) ENGINE = SharedReplacingMergeTree(..., _peerdb_version)
ORDER BY (recovery_id, scan_id)
```

921,038 rows / 28.38 MiB. `recovery_scans` has **no `org_id`**, so the org filter must come
through `clarity.recoveries` (17,045,278 rows, `ORDER BY (org_id, repo_date, id)` — org_id
leading, so it prunes well).

Measured, org 1271 (the largest by junction volume — 6,243 junction rows in Aug 2026):

| shape | read_rows | read_bytes | peak mem | server ms | result |
| --- | --- | --- | --- | --- | --- |
| single window (Aug 2026) | 953,806 | 17.0 MB | 50 MB | 493 | 1,255 plates |
| 13-month series | 1,195,761 | 22.2 MB | 36 MB | **27** | 5 months populated |

Compared with DEV-2467's `scans_cold` 13-month path (60,757,669 rows / 2.52 GB / 2.51 GB /
1,351 ms) that is **51x fewer rows, 113x less bytes, 70x less memory**. DEV-2469 and
DEV-2470 are the *cheapest* of the four cards, not the most expensive — and they are viable
today. Proven shape:

```sql
SELECT toStartOfMonth(r.repo_date) AS month, uniqExact(rs.ocr) AS unique_scans_to_repo
FROM {clarityDb}.recovery_scans AS rs
INNER JOIN (
    SELECT id, repo_date FROM {clarityDb}.recoveries FINAL
    WHERE org_id = :org_id AND repo_date >= :start AND repo_date <= :end
) AS r ON rs.recovery_id = r.id
WHERE rs._peerdb_is_deleted = 0
GROUP BY month ORDER BY month
```

This also means the "sibling unique-scan cache key" DEV-2469 wants to share is only truly
shared between DEV-2467 and DEV-2468 (both `scans_cold`); DEV-2469/2470 read a different,
much cheaper table and should not be forced onto the `scans_cold` pass.
