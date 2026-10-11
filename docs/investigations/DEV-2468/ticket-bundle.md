# DEV-2468 — ticket bundle (intake snapshot, 2026-09-10)

- **Summary:** Company Metrics — Unique Scans Leaderboard card
- **Type:** Story · **Status:** To Do · **Priority:** Medium · **Sprint:** Sprint 40
- **Application:** Clarity · **Epic parent:** DEV-2409 (Company Metrics Dashboard — mockup build-out, 9 cards)
- **Blocked by:** DEV-2460 (foundation — QA Complete), DEV-2466 (spike — Done). Both merged to main.
- **Labels:** none · **Components:** none

---

## Description (verbatim)

### The metric

**Unique Scans Leaderboard** — a leaderboard ranked by unique scan count, where "unique" = distinct plate (`ocr`), not distinct scan id. Settled at the DEV-2409 gate on 2026-08-25.

**Grid slot: row 3, col 1.** One metric, one ticket.

### ✅ Spike cleared — DEV-2466 returned GO, and this card is nearly free

The feasibility spike is **done and the verdict is GO.**

**This ticket originally called itself "the riskiest of the four". It is not.** DEV-2466 measured that a single `GROUP BY kit_id WITH ROLLUP` pass returns **both** DEV-2467's org total (the `kit_id = 0` rollup row) **and** this leaderboard's per-kit ranking — from one scan of `scans_cold`. Build DEV-2467 first, then this card rides its query.

Still **depends on the foundation ticket** being merged first.

### ⚠️ Corrected by DEV-2466 (this ticket's original text was wrong)

#### 1. `org_id` IS the leading sort-key column — pruning works

This ticket previously said `scans_cold`'s sort key is `(kit_id, scanned_at, id)` with `org_id` outside it, giving "no primary-key pruning". **That was wrong.** Production, measured 2026-09-08:

| fact | value |
| --- | --- |
| `clarity.scans_cold` rows | 2,180,621,658 |
| On disk | 122.58 GiB |
| Sort key | `(org_id, scanned_at, kit_id, id)` — org_id LEADS |
| Partition | `toYYYYMM(scanned_at)` |

`EXPLAIN indexes = 1` on an org + window filter prunes **266,535 → 1,698 granules** by binary search. The committed ClickHouse schema dump misstates this key (same defect as DEV-2448) — trust `system.tables.sorting_key`, not the dump.

**Filter `org_id` directly.** Do NOT resolve the org to its kit ids and filter on `kit_id` — despite this being a per-kit leaderboard, `kit_id` is the _third_ sort-key column, so filtering on it degrades to generic exclusion search and prunes nothing (measured: 6.7x rows, 3.8x time, identical answer). Scope by `org_id` and let `GROUP BY kit_id` do the per-kit split.

#### 2. Omit `FINAL` — it is pure overhead for this aggregate

The original AC required `FINAL`. DEV-2466 measured it as **+97% server time and +149% bytes read for zero change in answer** (identical result across 3 runs each way). CDC duplicates of a scan carry the **same plate**, so they collapse inside `uniqExact` regardless — verified: of 1,635 duplicate-`id` groups, **0** carried more than one distinct `ocr`.

Keep `_peerdb_is_deleted = 0`. This is the opposite of `hits_cold`, where a mutable `hit_created_at` in the sort key makes `FINAL` load-bearing (DEV-2406).

#### 3. The cost is the same as DEV-2467's, not worse

The original text warned the `GROUP BY` "produces N rows rather than one number" and asked for a fresh measurement. DEV-2466 did it: the `WITH ROLLUP` form read **5,573,739 rows / 142.7 MB / 249 MB peak / 606 ms** (org 469, one month) — essentially the same scan as the scalar total, because the row count is bounded by the org's kit count (117 for the largest org), not by the data volume.

So the leaderboard is **not** a second envelope to justify. It is the same scan, reshaped.

### Proven query shape (measured by DEV-2466 — build against this)

One pass serving BOTH DEV-2467's org total and this leaderboard. `kit_id = 0` is the rollup row carrying the org total — verified to match the standalone scalar exactly (2,800,575 both ways):

```sql
SELECT kit_id, uniqExact(ocr) AS unique_plates
FROM {clarityDb}.scans_cold
WHERE org_id = :org_id
  AND scanned_at >= :start AND scanned_at <= :end
  AND _peerdb_is_deleted = 0
GROUP BY kit_id WITH ROLLUP
```

13-month series, same reshape:

```sql
SELECT toStartOfMonth(scanned_at) AS month, kit_id, uniqExact(ocr) AS unique_plates
FROM {clarityDb}.scans_cold
WHERE org_id = :org_id
  AND scanned_at >= :start_13mo AND scanned_at <= :end
  AND _peerdb_is_deleted = 0
GROUP BY month, kit_id WITH ROLLUP
ORDER BY month
```

### ⚠️ Definition wrinkle specific to a leaderboard

**Per-kit distinct plates do NOT sum to the org's distinct plates.** A plate seen by two kits counts once per kit, so the leaderboard column totals will exceed DEV-2467's headline number. That is arithmetically correct, not a bug — but the card must be unambiguous about which it shows.

The `WITH ROLLUP` shape above makes this safe by construction: the `kit_id = 0` row is the true org distinct count, and the per-kit rows are the ranking. Use the rollup row for any "total" the card displays — never a sum of the per-kit values.

### The approved envelope

Measured ceiling, worst-case org (469 — 13 months, 65.4M scans), no `FINAL`: **60,738,996 rows / 1.31 GB / 2.44 GB peak / 1,102 ms** for the 13-month series.

**Four unbatched cards, measured concurrently: 243,048,568 rows / 10.09 GB / ~10.03 GB concurrent peak / slowest query 3.818 s (2.6x the solo latency).** Sharing DEV-2467's scan via `WITH ROLLUP` is what keeps this card's marginal cost at roughly zero — which is why the batching requirement below is hard, not advisory.

### ClickHouse rules for this repo

* **Batch from the start.** The Staging Live Hit Leaderboard's measured lesson: 13 per-month calls → **one** batched call took it from **1.93 s / 13 queries → 0.38 s / 1 query**. Do not build the 13-call form and fix it later.
* Cross-card batching uses a cross-class cache **key** (`SharedHitStatsData::sharedUniqueRangeMemoKey()`), **never** a `static::$memo` array — trait statics are copied per using-class, and that mistake made 4 hit widgets do 4 separate ClickHouse scans in DEV-2406. Share the key with DEV-2467 rather than issuing a second scan. (Note: DEV-2469/2470 read a _different, far cheaper_ table and should NOT be forced onto this scan.)
* Use `static::clarityDb()` — **never** a hardcoded `clarity.` prefix. `LprVisitCountQuery`, `RdnHitHourCountQuery`, `RecoveryLeaderQuery`, `ScanStatsBatchQuery` and `RdnScanHourCountQuery` all hardcode it; copy the **fixed** pair `HitStatsBatchQuery` / `HitPlateStatsBatchQuery` (their docblocks record this as a real cross-database bug under per-worktree ClickHouse QA).
* Read `scans_cold` for every window — DEV-2466 measured `scans_hot` as **3.3x slower** on the same window (5x the parts, so `FINAL`'s merge work dominates).
* Always filter `_peerdb_is_deleted = 0`. ClickHouse timestamps are UTC.

### Card contract

* Register the key in `CompanyMetricsOverview::cards()` at its **exact mockup slot** (row 3 col 1). Mockup-relative, **not** append-only — do not "tidy" the gaps back into append order.
* Add an explicit `getStatColor()` arm in `DashboardWidget` (`default => 'gray'` fails silently).
* Update `tests/Filament/Pages/DashboardCardExpansionTest.php`'s card-order assertion; keep `test_all_four_hit_cards_are_contiguous()` passing.
* Respect the Today / Last 7d / This Month / Last Month page filter. Note: `last_7d` and `this_month` resolve to the **same range** on the 8th of any month — expected, not a bug.
* Pro gate: `makeMetricWidget()` never calls `mount()` — use the `lockedStat()` seam from the foundation ticket.
* Follow the existing collapsible-leaderboard pattern established by `RecoveryLeader` (DEV-2324).

### Acceptance criteria

1. Card present in its mockup slot, ranked by distinct `ocr` per kit, scoped by `org_id` directly and filtered by `_peerdb_is_deleted = 0`. `FINAL` omitted (DEV-2466 measured it as pure overhead).
2. Any org-level total the card shows comes from the `WITH ROLLUP` `kit_id = 0` row, **never** a sum of the per-kit values.
3. Respects the Today / Last 7d / This Month / Last Month filter.
4. The 13-month series is served by **one** batched call, sharing DEV-2467's cache key rather than issuing a second scan.
5. Measured cost recorded and within the DEV-2466 envelope; per-render ClickHouse round-trip count does not regress (DEV-2406).
6. Non-Pro orgs do not see the value. Existing metrics and their values are unaffected.

---

## Comment 39125 — Dalton McCleery, 2026-09-04 (verbatim)

> **CORRECTION — this is not "the riskiest of the four". A pre-aggregate already keyed by kit carries plate-uniqueness, and the perf premise is wrong.**
>
> Full evidence on DEV-2466 (comment 39123). What bears on this card:
>
> ### 1. There is a rollup shaped exactly like this leaderboard
>
> `clarity.affiliate_stats_hourly` (`okf/models/clickhouse/affiliate_stats_hourly.md`) is **3,692,355 rows / 29.74 MiB** — against the 2,172,538,831 rows / 122.10 GiB of `scans_cold` this ticket plans to `GROUP BY`. It is keyed `(org_id, kit_id, scan_hour)` and carries `unique_plates UInt64` (distinct plates per kit per hour), synced to within 9 minutes.
>
> Better still, `AffiliateStatsHourly::affiliateStatsQuery()` already exists: a raw sub-select taking `max(scan_count)` / `max(unique_plates)` per `(kit_id, scan_hour)`, rolled up per kit, **inner-joined to** `clarity.kits FINAL` for the kit name, `HAVING scan_total > 0`. That is this card's shape, already written and shipped.
>
> So the ticket's cost framing — "A leaderboard is a `GROUP BY` over the same 2.1-billion-row `clarity.scans_cold` […] Treat the spike's approved envelope as the ceiling and re-measure this shape specifically before shipping." — can be dropped if the rollup definition is acceptable. There is no billion-row `GROUP BY`.
>
> ### 2. And the fallback path prunes fine anyway
>
> If period-distinct is required instead (see §3), `scans_cold` is still cheap: resolve org → kit ids via `zod.public_kits` (5,611 rows) and filter `kit_id`, the **leading** sort-key column. Measured Jul 2026: org 1271 (largest, 81 kits) → 7,115,302 distinct `ocr` from 14,917,623 rows in **1.70s**; org 58069 → 3,086 from **4,350 rows** in **0.48s**. The spike's "cost independent of org size" trap comes from filtering a non-sort-key column and does not apply.
>
> ### 3. ⚠️ The two definitions differ by 1.56x — decide before building
>
> Summing hourly `unique_plates` is not distinct plates over the period; a plate seen in two hours counts twice. Org 1271, Jul 2026: **11,070,941** (hourly-summed) vs **7,115,302** (period-distinct) — a **1.556x** overcount.
>
> For a _leaderboard_ this matters less than for a headline count, since ranking is often preserved under either definition — but not guaranteed, because kits differ in how concentrated their scanning is across hours. If the rollup is used, verify the top-10 ordering matches the period-distinct ordering before shipping. That check is cheap and worth doing explicitly.
>
> ### 4. Confirmed: the leaderboard ranks kits
>
> Not stated anywhere in the ticket, so recording it. `RecoveryLeaderQuery` — the `RecoveryLeader` pattern this ticket says to follow — groups on `clarity.kits.name`, and `affiliate_stats_hourly` is keyed by `kit_id`. Rows are **kits**.
>
> ### 5. Sequencing
>
> The ticket says to build Unique Scan Counts first and share its query path and cache key. That still makes sense — but note DEV-2469 no longer belongs to this lane at all (it never needed `scans_cold`; see DEV-2469 comment 39118), so the "four unique-scan cards" sharing one `scans_cold` pass is now at most three, and possibly zero if the rollup is used.
>
> ### Unchanged, and still owed
>
> The batching lesson stands regardless — **one** call, not 13 (1.93s/13 queries → 0.38s/1). So do the cross-class cache **key**, never `static::$memo`; `static::clarityDb()`, never a hardcoded prefix; `_peerdb_is_deleted = 0`.
>
> Still owed: server-side `read_rows` / `read_bytes` / peak memory (`readonly_user` cannot read `query_log` across replicas — everything above is client wall clock), the §3 decision, and the top-10 ordering check if the rollup is chosen. Rollup coverage caveat: 262 orgs in the Jul 2026 window, 386 all-time, against 548 orgs owning kits — totals validated for 2 orgs, global completeness unverified.

**NOTE (intake, 2026-09-10):** the comment above predates the ticket-description rewrite. The description was updated 2026-09-08 with DEV-2466's *measured* results and now mandates the `scans_cold` + `WITH ROLLUP` shape, explicitly rejecting `FINAL` and the kit_id-filter path. The comment's `affiliate_stats_hourly` alternative (§1/§3) is **NOT** reflected in the current description or the ACs. Whether the comment's rollup proposal was considered and rejected at the 2026-09-08 rewrite, or simply not folded in, is an open question for the gate — the two paths give answers that differ by 1.56x.

---

## Intake findings that reshape scope (verified against the working tree at e469cf2d2)

1. **The card already exists as a STUB.** DEV-2409 PR #394 shipped
   `app/Filament/Widgets/Dashboard/UniqueScansLeaderboard.php` and registered
   `'unique-scans-leaderboard' => UniqueScansLeaderboard::class` at
   `CompanyMetricsOverview::cards()` index 9 (row 3 col 1 — the exact mockup slot the ticket
   asks for). `tests/Filament/Pages/DashboardCardExpansionTest.php:172` already carries it in
   the layout-order map. So the "register the key at its exact slot" and "update the card-order
   assertion" contract items are **already satisfied**; this ticket replaces the stub's data
   methods in place.
2. **The stub's own docblock states the base-class swap is this ticket's job:** *"A leaderboard
   needs an array per month, which DashboardWidget cannot model, so that ticket also swaps the
   base class to the RecoveryLeader shape inside this file."*
3. **DEV-2467 built the shared seam on purpose.**
   `ScanPlateStatsBatchQuery::handleRange()` returns `array<int kit_id, int count>` with key `0`
   as the `WITH ROLLUP` org total (identified by `GROUPING(kit_id)`, not row position), and
   `ScanPlateStatsBatchQuery::sharedRangeCacheKey($orgId, $start, $end, $timezone)` is a
   class-neutral key deliberately NOT routed through `DashboardWidget::buildWidgetCacheKey()`
   (which prefixes the widget class basename and would be unreadable from this card).
   `UniqueScanCounts::fetchRangeRollup()` already `cache()->remember()`s under that key.
4. **`ScanPlateStatsBatchQuery::handle()` (the 13-month series) has NO kit dimension.** Its
   docblock: *"No kit dimension: the combined 13-month x per-kit rollup was never measured and
   would take the group count from 13 to roughly 13 x kits against a 2.18B-row table (DEV-2466
   sec 4 vs sec 7)."* AC-4 asks for the 13-month series in one batched call sharing DEV-2467's
   cache key — but DEV-2467 deliberately did not build the shape AC-4 needs, and the ticket's
   own second SQL block (`GROUP BY month, kit_id WITH ROLLUP`) is the unmeasured combined shape.
   This is a direct conflict between AC-4 and the DEV-2467 invariant.
5. `ScanPlateStatsBatchQuery::safeTimezone()` exists and is public; DEV-2467 split raw vs
   allowlisted zones deliberately (raw converts bounds, allowlisted is the only thing
   interpolated into SQL) — see `UniqueScanCounts::bucketZone()` and `getRangeTotal()`.
6. DEV-2467 shipped **no `Cache::lock`** and records an unmitigated cold-start stampede as a
   known residual, deferred to its own ticket. Any new concurrent scan this card adds lands on
   top of that residual.
