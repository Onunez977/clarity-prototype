# DEV-2406 — driver-verified findings (Phase 1)

All ClickHouse figures below were obtained by the DRIVER via read-only queries against
production ClickHouse Cloud on 2026-08-24. Aggregates only; no raw rows were retrieved
or recorded (non-negotiable 3).

## F1 — Table sizes (settles a candidate disagreement)

| table | rows |
| --- | --- |
| `clarity.hits_cold` | 24,961,523 |
| `clarity.hits_hot` | 2,795,122 |
| `clarity.hit_stats_daily` | 218,538 |
| `zod.public_hits` | 308,664,520 |

The fast pass characterised the dedup path as scanning ~308M rows. That figure is
`zod.public_hits` — the Postgres CDC mirror — **not** the table a dedup query would read.
The operative table is `clarity.hits_cold` at ~25M rows.

## F2 — Measured cost of the dedup query (perf gate: GO)

Query shape:
`SELECT toStartOfMonth(hit_created_at), hit_type, count(), uniqExact(ocr)
 FROM clarity.hits_cold FINAL
 WHERE _peerdb_is_deleted = 0 [AND <org_col> = <id>]
   AND hit_created_at >= <13-month window> GROUP BY month, hit_type`

| variant | wall |
| --- | --- |
| scoped by `hotlist_org_id` to the largest org (14.1M rows) | 0.27s |
| scoped by `kit_org_id` (column NOT in the sort key) | 0.33s |
| **unscoped, all orgs** | 0.37s |

Sub-second even unscoped. The "reintroduces the 30s-timeout query shape" concern does not
hold for this shape at current volume. **No chlocal schema change is required on
performance grounds.**

## F3 — `hit_stats_daily.org_id` is the KIT org, not the hotlist org

`hit_stats_daily` top orgs by total hits: 1143, 469, 14 — exactly matching the top
`kit_org_id` values in `hits_cold` (1143 = 2,876,335; 469 = 2,234,878; 14 = 1,638,136).
Org 165, the largest `hotlist_org_id` (14.1M rows), returns ZERO rows from
`hit_stats_daily`.

**Implication:** any new dedup query must scope by `kit_org_id` to match what the tiles
show today. Scoping L/H by `hotlist_org_id` (as one candidate proposed) would produce
numbers that do not correspond to the existing totals.

## F4 — The two sources DISAGREE by ~0.5–9% (the real correctness hazard)

Same org (`kit_org_id` = 1143), same months, `hit_stats_daily` vs `hits_cold`:

| month | source | L | H | S | Z |
| --- | --- | --- | --- | --- | --- |
| 2026-05 | hit_stats_daily | 1339 | 16314 | 6439 | 37128 |
| 2026-05 | hits_cold | 1339 | 16470 | 5860 | 37294 |
| 2026-06 | hit_stats_daily | 1691 | 19757 | 4116 | 35321 |
| 2026-06 | hits_cold | 1691 | 19865 | 3861 | 35303 |
| 2026-08 | hit_stats_daily | 1255 | 14193 | 1469 | 14743 |
| 2026-08 | hits_cold | 1257 | 14249 | 1459 | 14763 |

Largest observed divergence: staging-live (S) for 2026-05, 6439 vs 5860 (~9%).

**Implication:** a tile CANNOT take `total` from `hit_stats_daily` and `unique` from
`hits_cold` — they are different populations, so the pair would be mutually inconsistent
and `unique` could in principle exceed `total`. Both numbers must come from ONE source.
Sourcing both from the raw hits tables is the coherent option, but it **shifts totals that
users already see today** — a product decision, not an implementation detail.

## F5 — `hit_type` distribution (whole table, aggregate)

`Z` 11,638,903 · `H` 4,227,434 · `S` 2,346,595 · `L` 741,232 · `F` 34

An undocumented `F` hit_type exists (34 rows). Not one of the four tiles; confirm it is
correctly excluded rather than silently swept into a bucket.

## F6 — `DashboardWidget` is a shared BASE CLASS (blast radius, verified from code)

`getData()` (:123), `getDescription()` (:139), `makeDataset()` (:189), `toStat()` (:287)
and `protected ?array $options` carrying `plugins.legend.display => false` (:41-45) all
live on `app/Filament/Widgets/Dashboard/Concerns/DashboardWidget.php`.

Six widgets extend it: the four hit tiles **plus `ScanCount` and `RecoveryCounts`**.

**Implication:** flipping the legend on for AC-4, or changing `getData()` to emit two
datasets, lands on ScanCount and RecoveryCounts too unless guarded per-subclass. An
in-repo precedent for a per-widget `$options` override already exists at
`app/Filament/Widgets/Dashboard/MissedOpportunityClientGraph.php:36`. This question is
ANSWERED FROM CODE — it is a plan constraint, not a human question.

## F7 — Engine args (verified from `system.tables`)

- `clarity.hits_cold` — `SharedReplacingMergeTree(…, _version, _peerdb_is_deleted)`,
  `PARTITION BY toYYYYMM(hit_created_at)`, `ORDER BY (hotlist_org_id, toYYYYMM(hit_created_at), hit_created_at, id)`
- `clarity.hits_hot` — same engine + a TTL on `hit_created_at` (the hot window)
- `clarity.hit_stats_daily` — `SharedReplacingMergeTree(…, _peerdb_synced_at)`, `ORDER BY (org_id, date)`

`_peerdb_is_deleted` is the NATIVE is-deleted engine argument on the hits tables, so `FINAL`
alone drops soft-deleted rows — no separate `WHERE _peerdb_is_deleted = 0` is required
(the driver's probes included one redundantly; harmless).

Note the tables are ordered by `hotlist_org_id`, but F3 shows the tiles are scoped by
`kit_org_id` — so the org filter the tiles need is NOT primary-key-assisted. Measured cost
of that path is nonetheless 0.33s (F2), so this is a note, not an obstacle.

## F8 — The repo already documented this exact hazard (DEV-2324)

`app/Filament/Widgets/Dashboard/Concerns/DashboardWidget.php:330-338`:

> "This is deliberately not a second query path… the card computing it separately is what
> let a swallowed failure in one path write a fabricated value into the other's key, and
> what let ScanCount's two paths read different tables. One method, one cache key, two
> consumers."

Combined with F4 (the two sources measurably disagree by up to ~9%), this is decisive:
taking `total` from `hit_stats_daily` and `unique` from `hits_cold` would recreate, in the
same file, the precise defect DEV-2324 fixed three days ago.

## F9 — The chlocal MV applies NO disposition filter (answers Q3 from code)

`chlocal/database/schemas/clickhouse/clarity/materialized_views/hit_stats_daily_mv.sql`:

- `REFRESH EVERY 1 HOUR APPEND`, watermark-driven affected-days recompute
- `org_id` = `toUInt32(kits.org_id)` → **third independent confirmation of F3 (kit org)**
- Filters, in full: `hits._peerdb_is_deleted = 0`; day IN affected_days;
  `kits.org_id IN (SELECT id FROM clarity.orgs WHERE type = 1)`
- `INNER JOIN zod.public_kits ON hits.kit_id = kits.id` — hits with no matching kit are dropped
- Buckets on **`hits.created_at`**

There is **no disposition/validity filter**. So a new query seeking parity should NOT apply
`validDisposition()`.

## F10 — Three sources, three answers (org 1143, 2026-05)

| source | L | H | S | Z |
| --- | --- | --- | --- | --- |
| MV logic replayed on `zod.public_hits`, bucketed by `created_at` | 1339 | 16060 | 6439 | 36642 |
| `clarity.hit_stats_daily` (what the tiles show today) | 1339 | 16314 | 6439 | 37128 |
| `clarity.hits_cold`, bucketed by `hit_created_at` | 1339 | 16470 | 5860 | 37294 |

`zod.public_hits` has only `created_at`; `clarity.hits_cold` has only `hit_created_at`.
The latter is a chlocal-derived field, and it does not bucket identically — H and Z shift
across month boundaries, and S differs by 579 for this month.

**Implication for Q2:** the divergence is NOT a disposition filter (F9). It is a bucketing
+ pipeline difference. Option A (source both numbers from the hits tables) is still the only
self-consistent choice, but it will visibly change H/S/Z totals for closed months — this is
now a quantified, explainable change rather than an unknown, which is exactly what a product
decision needs.

## F11 — The three candidate dedup keys, measured (org 1143, 2026-05)

| hit_type | total | uniqExact(ocr) | uniqExact(hotlist_plate) | uniqExact(hotlist_id) |
| --- | --- | --- | --- | --- |
| H | 16,470 | 2,488 | 2,487 | 2,528 |
| L | 1,339 | 622 | 622 | 623 |
| S | 5,860 | 2,623 | 2,623 | 3,065 |
| Z | 37,294 | 6,996 | 6,998 | 8,664 |

`ocr` and `hotlist_plate` are effectively the same number — only **0.85%** of rows in this
org/month have `ocr != hotlist_plate` — because a hit only exists where a scan MATCHED a
hotlist plate. The "OCR misreads will inflate the count" objection is therefore negligible
here, unlike for raw scans.

`hotlist_id` is up to **~24% higher** (Z: 8,664 vs 6,996) because one plate is commonly
carried on several hotlist entries. It answers "how many hotlist entries were hit", which is
NOT what a tile labelled "unique plates" claims.

**Conclusion:** the `countDistinct(hotlist_id)` precedent in `MissedOpportunity` answers a
different question and should not govern this metric.

## F12 — `clarity.hits_cold` diverges from the true hit population in BOTH directions

`hits_cold_mv.sql:159` maps `hits.created_at AS hit_created_at` — the two are the SAME field,
so bucketing was never the cause. Lines 197-198 are the cause:

```
INNER JOIN filtered_scans   AS s ON s.id = hits.scan_id      -- supplies ocr, from scans_cold
INNER JOIN filtered_hotlist AS h ON h.id = hits.hotlist_id   -- and org_id != 0
```

Any hit whose scan or hotlist row is absent is dropped. Loss attribution for org 1143
(all-time): 112,936 of 4,648,797 hits (2.4%) lack a hotlist row, so the SCANS join dominates
— the defect DEV-2189 describes ("drops hits with late-syncing scans, S/staging most affected").

Per-month completeness, org 1143, `hits_cold` vs source (positive = missing, negative = excess):

| month | S true | S cold | S missing | Z true | Z cold | Z missing |
| --- | --- | --- | --- | --- | --- | --- |
| 2025-10 | 6,239 | 5,697 | 8.7% | 29,990 | 31,930 | -6.5% |
| 2026-01 | 4,910 | 4,494 | 8.5% | 31,384 | 34,238 | -9.1% |
| 2026-02 | 6,050 | 5,733 | 5.2% | 49,109 | 56,264 | -14.6% |
| 2026-03 | 6,426 | 5,825 | 9.4% | 41,363 | 52,423 | -26.7% |
| 2026-05 | 6,439 | 5,860 | 9.0% | 36,642 | 37,294 | -1.8% |
| 2026-07 | 2,395 | 2,372 | 1.0% | 22,493 | 22,384 | 0.5% |
| 2026-08 | 1,475 | 1,463 | 0.8% | 14,820 | 14,770 | 0.3% |

So `hits_cold` UNDERCOUNTS staging-live by 5-9% AND OVERCOUNTS staging-historical by up to
27%. Recent months (Jul/Aug 2026) are clean — consistent with DEV-2189's fix being
forward-only with no historical backfill, plus soft-deleted source rows the APPEND-mode MV
never removes.

**DEV-2189 is marked Done, but the historical data it describes is still wrong in production.**

## F13 — There is no fast AND accurate source for a unique-plate count

A plate is not on the hit row; it lives on the scan or the hotlist entry. So a unique-plate
count over the TRUE hit population requires a join, and that is the expensive part:

| route | correctness | measured cost |
| --- | --- | --- |
| `clarity.hits_cold` (pre-joined) | drifts -9% to +27% by month/type (F12) | **0.37s** |
| `zod.public_hits` LEFT JOIN `public_hotlist` (13mo, one org) | true population, 2.4% null plate | **6.6s** |
| same, hotlist subquery pre-scoped by `org_id`, ONE hit type | same | **2.9s** |

The fast source is not trustworthy; the trustworthy source is not fast. This is the crux of
the remaining decision and it is a data-quality problem upstream of DEV-2406, not a design
choice inside it.

## F14 — CORRECTION to F12, and the real status of DEV-2189

Two errors in F12, corrected here:

1. **Org attribution.** `hits_cold_mv:167` sets `s.org_id AS kit_org_id` — the org comes from
   the SCAN, whereas `hit_stats_daily.org_id` comes from `kits.org_id`. Measured, these differ
   for **1.02%** of scans (2,013,791 of 198,022,059 over 60 days). F12 compared the two using
   the kit-org definition on one side and the scan-org definition on the other, which inflated
   the apparent divergence.

2. **Migration 110 IS live.** The DEV-2189 §1 probe against the live MV returns
   `scan_watermark=1, changed_scan_ids=1, changed_hotlist_ids=1, toIntervalDay(8)=1,
   LEFT JOIN=0, INNER JOIN=1` — exactly the variant-C shape DEV-2189 chose. The local
   `chlocal` checkout's `hits_cold_mv.sql` does NOT contain 107 or 110, so **the committed
   schema dump is stale relative to Cloud** (separate housekeeping issue).

Re-running the comparison with the org definition `hits_cold` actually uses (scan org):

| month | Z src | Z cold | Z diff | S src | S cold | S diff |
| --- | --- | --- | --- | --- | --- | --- |
| 2026-02 | 52,225 | 56,264 | **-4,039** | 6,050 | 5,733 | 317 |
| 2026-03 | 42,825 | 52,423 | **-9,598** | 6,309 | 5,825 | 484 |
| 2026-04 | 38,657 | 38,631 | 26 | 6,987 | 6,384 | 603 |
| 2026-05 | 37,317 | 37,294 | 23 | 6,439 | 5,860 | 579 |
| 2026-06 | 35,511 | 35,303 | 208 | 4,116 | 3,861 | 255 |
| 2026-07 | 22,432 | 22,384 | 48 | 2,395 | 2,372 | 23 |
| 2026-08 | 14,788 | 14,777 | 11 | 1,479 | 1,467 | 12 |

**Revised conclusion — DEV-2189's MV fix WORKS.** Migration 110 landed 2026-07-23 and from
July onward both types reconcile to within ~0.1-1%. Z reconciles from April onward.

**What is still broken is the half of DEV-2189 that was never executed: the one-time
historical backfill.** The ticket's own "Deploy + backfill" step specified
`ALTER TABLE ... MODIFY QUERY` **then** `scripts/backfill/backfill_hits_cold_watermark_gap.py`.
The closing comment records only "Migration 110 has been applied to production. PR #30 has
been merged" — the migration, not the backfill. So pre-July months keep the gap permanently.

**A second, distinct defect:** Feb-Mar 2026 show `hits_cold` with MORE Z rows than source
(+9,598 in March, ~22%). That is not a late-arrival drop and is NOT explained by DEV-2189 —
most likely source rows soft-deleted after materialization whose deletes the APPEND MV never
propagated, or a prior partial backfill double-inserting. Needs its own diagnosis.

## F15 — CORRECTIONS from independent adversarial verification (Fable 5, 2026-08-24)

An independent verifier re-derived F1-F14 against production and found two real errors.

### ERROR 1 (F12/F14): the Feb-Mar "over-count" was a measurement artifact

The driver used bare `count()` / `countIf()` on `hits_cold FINAL`. **FINAL cannot collapse rows
whose sort key differs**, and the sort key `(hotlist_org_id, toYYYYMM(hit_created_at),
hit_created_at, id)` contains the mutable `hit_created_at`. Verified: March 2026 / org 1143 / Z
holds 52,423 ROWS but only **40,046 distinct hit ids**. Deduped, March is **-6.5% short**, not
+22% over.

Mechanism, measured by the verifier: 4,057 hit ids carry rows in TWO month partitions with
`hit_created_at` 24-50 days apart (avg 42.8) — a source-side reprocessing event appended
new-keyed rows that ReplacingMergeTree can never merge (different partition AND key). Raw
non-FINAL counts run 1.5-2.6x distinct ids in spring 2026.

**THE IMPLEMENTATION CONSTRAINT THIS CREATES — the single most important finding for DEV-2406:**
never use bare `count()` on `hits_cold`. Use `uniqExact(id)` for the total and `uniqExact(ocr)`
for the unique-plate figure. Using `count()` would have shipped a "total hits" number inflated by
up to ~30% in the worst months.

### ERROR 2 (F12/F14): the staging-live gap is intentional, not the watermark bug

Essentially 100% of org 1143's missing S hits, month after month (206/206, 832/832, 707/707),
belong to hotlist org 149515 (NICB) — the **intentional migration-63 curation exclusion**
documented in DEV-2189 itself. The apparent mid-June "convergence" is NOT the fix landing
(migration 110 shipped 2026-07-23); it is NICB's own S volume collapsing from ~600/month to
23 (Jul) and 12 (Aug). Nothing was repaired in June.

Deduped, Z divergence is only **-0.1% to -0.8% in every month across 13 months and 3 orgs**,
except the March 2026 incident. The claim of broad "material divergence" in earlier months is
**false**.

### METHODOLOGICAL WARNING (applies to any future work here)

Any comparison joining `scans_cold` without deduping versions is poisoned: the verifier's own
first pass showed a phantom permanent -13% Z deficit that vanished to 0.0% under `uniqExact(id)`.

### WHAT SURVIVES VERIFICATION

- C1 hit_stats_daily has no plate column — CONFIRMED
- C2 hits_cold ~24.9M vs zod.public_hits ~308.7M — CONFIRMED
- C3 perf — CONFIRMED and slightly FASTER: 0.169-0.204s (largest hotlist org), 0.224-0.255s
  (kit org), 0.264-0.272s unscoped, server-side elapsed
- C4 hit_stats_daily.org_id is the KIT org — CONFIRMED (org 165 has zero kit_org_id rows)
- C5 migration 110 IS live, INNER joins are correct (110 deliberately rejected the LEFT variant)
- C6 the backfill was never run — CONFIRMED, but the recoverable gap is **March 2026**, not a
  13-month hole. 97% of one org's missing March hits have their hotlist in `clarity.hotlist`
  today, so the script would still recover them.
- C8 the org definitions DO differ (scan-org vs kit-org); the rate is 0.62-1.238% depending on
  population, not a fixed 1.02%.

### REVISED VERDICT

`clarity.hits_cold` **is usable for DEV-2406 today**, with three guards:
1. `uniqExact(id)` for totals, `uniqExact(ocr)` for unique — never bare `count()`.
2. Choose the org dimension deliberately: `kit_org_id` (scan's org) vs `hotlist_org_id` (order's
   org) vs hit_stats_daily's kit-org are three different things, and whole large orgs (e.g. 165)
   exist only on the hotlist axis.
3. Accept documented caveats: NICB hits excluded by design; March 2026 ~6-9% short on Z until
   the backfill is run; ~0.1-0.8% residual from the known scans_cold-absent follow-up.
