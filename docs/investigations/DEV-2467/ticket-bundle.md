# DEV-2467 — Company Metrics — Unique Scan Counts card
Type: Story · App: Clarity · Status: To Do · Parent epic: DEV-2409 (Company Metrics Dashboard — mockup build-out, 9 cards, one per ticket)
Blocked by: DEV-2460 (foundation — QA Complete, MERGED to main via PR #383) and DEV-2466 (spike — GO, MERGED to main as ad29de3b). Both blockers are satisfied.
Sibling cards sharing this query path: DEV-2468 (Unique Scans Leaderboard). DEV-2469/DEV-2470 read a DIFFERENT, cheaper table and must NOT be forced onto this scan.

## DESCRIPTION (verbatim, updated 2026-09-08)

## The metric

**Unique Scan Counts** — deduplicated scan count, where "unique" = distinct plate (`ocr`), not distinct scan id. Settled at the DEV-2409 gate on 2026-08-25; consistent with DEV-2406's hit-side precedent.

**Grid slot: row 2, col 1.** One metric, one ticket.

## Spike cleared — DEV-2466 returned GO

The feasibility spike is **done and the verdict is GO.** Build this card. **Build it first of the four unique-scan cards** — it is the simplest, and DEV-2468 shares its query path.

Still **depends on the foundation ticket** being merged first.

DEV-2466 also **corrected two things this ticket originally got wrong** — read the next section before writing a query.

## Corrected by DEV-2466 (this ticket's original text was wrong)

### 1. `org_id` IS the leading sort-key column — pruning works

This ticket previously said the sort key is `(kit_id, scanned_at, id)` with `org_id` outside it, giving "no primary-key pruning". **That was wrong.** Production, measured 2026-09-08:

| fact | value |
| --- | --- |
| `clarity.scans_cold` rows | 2,180,621,658 |
| On disk | 122.58 GiB |
| Sort key | `(org_id, scanned_at, kit_id, id)` — org_id LEADS |
| Partition | `toYYYYMM(scanned_at)` |

`EXPLAIN indexes = 1` on an org + window filter prunes **266,535 -> 1,698 granules** by binary search. The committed ClickHouse schema dump misstates this key (same defect as DEV-2448) — trust `system.tables.sorting_key`, not the dump.

**So: filter** `org_id` directly. Do NOT resolve the org to its kit ids and filter on `kit_id` — `kit_id` is the _third_ sort-key column, so that route degrades to generic exclusion search and prunes nothing. Measured: 6.7x the rows, 3.8x the time, for the identical answer.

### 2. Omit `FINAL` — it is pure overhead for this aggregate

The original AC required `FINAL`. DEV-2466 measured it as **+97% server time and +149% bytes read for zero change in answer** (identical result across 3 runs each way).

Why: CDC duplicates of a scan carry the **same plate**, so they collapse inside `uniqExact` regardless. Verified — of 1,635 duplicate-`id` groups in the sample window, **0** carried more than one distinct `ocr` and **0** carried a delete flip.

Keep `_peerdb_is_deleted = 0` (nearly free, guards the delete case). This is the opposite of `hits_cold`, where a mutable `hit_created_at` in the sort key makes `FINAL` load-bearing (DEV-2406).

Carry this as a cheap regression check rather than assuming it holds forever:

```sql
SELECT countIf(distinct_ocr > 1) FROM (
  SELECT id, uniqExact(ocr) AS distinct_ocr, count() n
  FROM {clarityDb}.scans_cold
  WHERE org_id = :org AND scanned_at >= :s AND scanned_at <= :e
  GROUP BY id HAVING n > 1
)
```

### 3. `scans_hot` is not a win — read `scans_cold` for every window

Measured on the same This Month window: `scans_hot` was **3.3x slower** (454 ms vs 139 ms) despite reading fewer rows, because it carries 5x the parts. Read `scans_cold` deliberately.

(`ScanCount` already does this by accident — `Scan::hot()` returns an instance and `$scan::query()` is static, so it discards `setTable('scans_hot')`, per DEV-2324. Don't inherit that latent bug; just target `scans_cold` on purpose.)

## Proven query shapes (measured by DEV-2466 — build against these)

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

One pass serving BOTH this card's total and DEV-2468's leaderboard (`kit_id = 0` is the org total — verified to match the standalone scalar exactly):

```sql
SELECT kit_id, uniqExact(ocr) AS unique_plates
FROM {clarityDb}.scans_cold
WHERE org_id = :org_id
  AND scanned_at >= :start AND scanned_at <= :end
  AND _peerdb_is_deleted = 0
GROUP BY kit_id WITH ROLLUP
```

## The approved envelope — the 13-month series is the cost, not the total

`getMonthlyData()` needs a 13-month series, and **no pre-aggregate answers period-distinct plates** (`scan_report_hourly` is `uniqExact` over an Int64 scan id with no `ocr`; `affiliate_stats_hourly` has `unique_plates` but summing hourly buckets overcounts true distinct plates by 1.18x-1.63x). So the series must come from `scans_cold`.

Measured ceiling, worst-case org (469 — 13 months, 65.4M scans):

| shape | read_rows | read_bytes | peak mem | server ms |
| --- | --- | --- | --- | --- |
| single-window total | 7,218,864 | 300 MB | 447 MB | 190 |
| 13-month series, no `FINAL` | 60,738,996 | 1.31 GB | 2.44 GB | 1,102 |

**Four unbatched cards, measured concurrently: 243,048,568 rows / 10.09 GB / ~10.03 GB concurrent peak / slowest query 3.818 s (2.6x the solo latency).** That roughly doubles dashboard ClickHouse server time against the DEV-2409 baseline. **This is why batching is a hard requirement, not a nicety.**

## ClickHouse rules for this repo

* Use `static::clarityDb()` — **never** a hardcoded `clarity.` prefix. `LprVisitCountQuery`, `RdnHitHourCountQuery`, `RecoveryLeaderQuery`, `ScanStatsBatchQuery` and `RdnScanHourCountQuery` all hardcode it; copy the **fixed** pair `HitStatsBatchQuery` / `HitPlateStatsBatchQuery`, whose docblocks record this as a real cross-database bug under per-worktree ClickHouse QA.
* Always filter `_peerdb_is_deleted = 0`. ClickHouse timestamps are UTC.
* Cross-card batching uses a cross-class cache **key** (`SharedHitStatsData::sharedUniqueRangeMemoKey()`), **never** a `static::$memo` array — trait statics are copied per using-class, and that mistake made 4 hit widgets do 4 separate ClickHouse scans in DEV-2406. DEV-2468 reuses this query path, so get the sharing right here. (Note: DEV-2469/2470 read a _different, far cheaper_ table and should NOT be forced onto this scan — see those tickets.)
* Serve the 13-month sparkline series with **one** batched call, not 13 (the Staging Live Hit Leaderboard measured 1.93 s / 13 queries -> 0.38 s / 1 query).

## Decide deliberately: this card will disagree with TWO existing numbers

Both are pre-existing divergences, not bugs you introduce — but do not ship either unexamined.

1. **The existing Scan Count card** is un-deduped (no `->final()`), so a correctly plate-deduped Unique Scan Counts will read **lower** for the same period, sitting two slots away in row 2 col 1 vs row 1 col 1.
2. **The shipped affiliate report** already calls a different number "unique scans" — it sums hourly `unique_plates` from `clarity.affiliate_stats_hourly`, which overcounts true distinct plates by a measured **1.18x-1.63x** (org-dependent, so no constant corrects it).

Decide explicitly whether to reconcile or explain each in the UI, and record the decision in the Build Record.

## Card contract

* Register the key in `CompanyMetricsOverview::cards()` at its **exact mockup slot** (row 2 col 1). Mockup-relative, **not** append-only — do not "tidy" the gaps back into append order.
* Add an explicit `getStatColor()` arm in `DashboardWidget` (`default => 'gray'` fails silently).
* Update `tests/Filament/Pages/DashboardCardExpansionTest.php`'s card-order assertion; keep `test_all_four_hit_cards_are_contiguous()` passing.
* Respect the Today / Last 7d / This Month / Last Month page filter. Note: `last_7d` and `this_month` resolve to the **same range** on the 8th of any month (`subDays(7)->startOfDay()` == `startOfMonth()`) — expected, not a bug.
* Pro gate: `makeMetricWidget()` never calls `mount()` — use the `lockedStat()` seam from the foundation ticket.

## Acceptance criteria

* Card present in its mockup slot with a trend sparkline matching existing card styling, and an explicit `getStatColor()` mapping.
* Counts distinct `ocr` over the selected window, filtered by `org_id` directly and by `_peerdb_is_deleted = 0`. `FINAL` omitted (DEV-2466 measured it as pure overhead), with the dup-group probe above kept as a regression check.
* Respects the Today / Last 7d / This Month / Last Month filter.
* The 13-month series is served by **one** batched call, via a cross-class cache key DEV-2468 can share.
* Per-render ClickHouse round-trip count does not regress (measure before/after, per DEV-2406), and the measured cost is within the DEV-2466 envelope above.
* Both divergences (Scan Count card, affiliate report) resolved by an explicit decision, recorded in the Build Record.
* Non-Pro orgs do not see the value. Existing metrics and their values are unaffected.

## COMMENT 39124 (Dalton, 2026-09-04) — NOTE: PARTLY SUPERSEDED by the description above

This comment predates the DEV-2466 spike result. It argued (1) that org_id was NOT in the sort key so you should route via kit_id — **the description now states this was WRONG and org_id leads the sort key**; (2) that `FINAL` was correct on scans_cold — **the description now states DEV-2466 measured FINAL as pure overhead**; (3) that the "unique" definition was under-specified between hourly-sum and period-distinct — **the description now settles on period-distinct `uniqExact(ocr)` over the window**. Treat the DESCRIPTION as authoritative where they conflict; the comment is retained because its §4 divergence numbers are still live evidence:

- org 1271, Jul 2026: Scan Count (un-deduped) 14,917,623 vs period-distinct 7,115,302 -> the new card reads ~48% of the Scan Count card, a 2.1x gap.
- Under the hourly-sum definition instead it would read 11,070,941 (~74% of Scan Count).
- Summing hourly `unique_plates` overcounts period-distinct by 1.556x for org 1271 Jul 2026.
- `clarity.affiliate_stats_hourly`: 3,692,355 rows / 29.74 MiB, keyed (org_id, kit_id, scan_hour), carries `unique_plates UInt64`. `AffiliateStatsHourly::affiliateStatsQuery()` already computes a per-kit `unique_scans` from it.
- Rollup coverage caveat: 262 orgs in the Jul window, 386 all-time, vs 548 owning kits — global completeness unverified.
