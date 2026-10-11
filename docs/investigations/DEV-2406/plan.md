# DEV-2406 — Implementation Plan

Deduplicated plate count as the primary number on the four Company Metrics hit tiles,
with the raw total demoted to a secondary, a two-series grouped monthly chart, and a
legend in the expanded view only.

**Sizing:** 6 files (2 new, 4 modified), one concern. Under the split threshold — build it
as one ticket.

---

## The decisions this plan is built on (settled, do not re-litigate)

| # | Decision | Evidence |
| --- | --- | --- |
| 1 | Dedup key = `uniqExact(ocr)` | F11 — `ocr` and `hotlist_plate` differ on 0.85% of rows; `hotlist_id` counts hotlist entries (~24% higher), not plates |
| 2 | **Option B** — total stays on `clarity.hit_stats_daily` (existing path UNCHANGED); unique comes from `clarity.hits_cold` | Q2 — hit_stats_daily is reliable, faster and has the fuller population; deduped divergence is only −0.1% to −0.8%/month |
| 3 | Org scoping = `kit_org_id` | F3 / C4 — `hit_stats_daily.org_id` is the kit org; org 165, the largest `hotlist_org_id`, has ZERO hit_stats_daily rows |
| 4 | **Never bare `count()` on hits_cold** | F15 — `FINAL` cannot collapse rows whose sort key differs, and the key contains the mutable `hit_created_at`; raw counts run up to ~30% high |
| 5 | `FINAL` alone drops soft-deletes | F7 — `_peerdb_is_deleted` is the native engine is-deleted argument |
| 6 | No disposition filter | F9 — the chlocal MV applies none; adding `validDisposition()` would break parity |
| 7 | AC-2 uses `Stat::description()` | stat.blade.php:75-78 + precedent KitUsers.php:59 — no vendor-blade change |
| 8 | AC-4 legend via a trait-level `getOptions()` override, expanded-only | F6 — `DashboardWidget` is a base class for six widgets |
| 9 | Strict cache-key separation, no try/catch inside `remember()` | DashboardWidget.php:330-338 (DEV-2324) |
| 10 | Perf is cleared | 0.17-0.27s server-side for the full 13-month grouped query |

Two further constraints derived while reading the code, which the nodes below encode:

- **Cold-only.** `hits_hot` is a 90-day TTL copy of the same schema (clickhouse-schema.sql:129,131);
  `hits_cold` is the full table and already carries current-month rows. One cold query covers
  all 13 months. The prep sketch's `Hit::forWindow()` hot/cold split is **deliberately dropped** —
  a hot source for `today` and a cold source for `this_month` is precisely the two-sources-of-truth
  shape DEV-2324 fixed, and the measurement removes any reason to take that risk.
- **Do not "fix" the timezone smear.** The unique query must take the *same* `$batchStart`/`$batchEnd`
  strings the total path builds (SharedHitStatsData.php:77-80) and group by
  `toStartOfMonth(hit_created_at)` with no `convertToUtc()`. Both series must smear the month
  boundary identically. Making the new query more timezone-correct than its partner produces a
  visible total-vs-unique mismatch at month edges and drags DEV-1927 into scope.

---

## Order of work

```
t1  HitPlateStatsBatchQuery  (+ its CH test)
 └─ t2  SharedHitStatsData — data layer  (+ DashboardCardMetricsTest)
     └─ t3  SharedHitStatsData — presentation  (+ ExpansionTest, MonthLabelsTest)
```

Strictly serial. t2 and t3 both edit `SharedHitStatsData.php`, so they cannot be parallelized;
the split is kept because the two halves have different risk profiles and different test filters
— t2 is a data-correctness/cache-discipline node, t3 is a blast-radius node.

---

### t1 — `app/Clickhouse/Queries/HitPlateStatsBatchQuery.php` (NEW)

A sibling to `HitStatsBatchQuery` with the same public shape (`handle()` for the 13-month batch,
`handleRange()` for today/last_7d) returning the **same four array keys**
(`live_hit_count`, `historical_hit_count`, `staging_live_hit_count`, `staging_historical_hit_count`)
so `getHitStatsColumn()` indexes both maps without branching.

```sql
SELECT toStartOfMonth(hit_created_at) AS month,
       uniqExactIf(ocr, hit_type = 'L') AS live_hit_count,
       uniqExactIf(ocr, hit_type = 'H') AS historical_hit_count,
       uniqExactIf(ocr, hit_type = 'S') AS staging_live_hit_count,
       uniqExactIf(ocr, hit_type = 'Z') AS staging_historical_hit_count
FROM   <clarityDb>.hits_cold FINAL
WHERE  kit_org_id = {orgId}
   AND hit_created_at >= toDateTime('{start}')
   AND hit_created_at <= toDateTime('{end}')
GROUP BY month ORDER BY month
```

- Validate `start`/`end` against a strict `DATETIME_PATTERN` and throw `InvalidArgumentException`
  (copy the guard + comment from `RdnHitHourCountQuery.php:11,25-30`; `addslashes()` is not a SQL escape).
- Table prefix via `static::clarityDb()` — a hardcoded `clarity.` breaks the per-worktree local-CH QA
  databases.
- Month key via `date('Y-m', strtotime($row['month']))`, identical to `HitStatsBatchQuery.php:34`,
  so both series land on the same keys.
- Enumerating `L/H/S/Z` structurally excludes the undocumented `'F'` type (34 rows) and `M`/`P`.

**Must not trip**

- Bare `count()`/`countIf()` anywhere in this class. Only `uniqExact*` aggregates are safe on
  `hits_cold` (F15). `uniqExact(ocr)` is immune to the duplicate-row defect because the duplicated
  rows carry the same `ocr`.
- `scopeForOrg()` / `hotlist_org_id`. The tiles are `kit_org_id`.
- `validDisposition()`.
- A `WHERE _peerdb_is_deleted = 0` alongside `FINAL` — redundant, and its presence invites the
  belief that `FINAL` alone is insufficient elsewhere.

**Test** `tests/Feature/Clickhouse/HitPlateStatsBatchQueryTest.php` — PHPUnit, `#[Group('clickhouse')]`,
`RequiresClickhouse`, modelled on `HitStatsBatchQueryTest`. Pins: dedup collapses repeats *within*
a month; the same plate in two months counts once in **each** (months do not collapse); `kit_org_id`
scoping wins over a decoy `hotlist_org_id` row; `FINAL` handles duplicate `_version` and
`_peerdb_is_deleted = 1`; `F`/`M`/`P` rows land in none of the four buckets. `hits_cold` has no TTL
(only `hits_hot` does), so fixtures need not be anchored to `now()` — freeze time anyway for
determinism.

---

### t2 — `SharedHitStatsData`, data layer

Mirrors the existing total path exactly, under its own namespace.

- New statics `$sharedUniqueMonthlyStats` / `$sharedUniqueRangeStats`, and
  `const UNIQUE_NAMESPACE = 'unique:'` → every key is `{widget-prefix}:unique:{dateType}:org={id}`.
  It can never alias a total key.
- `buildMonthlyDataFromCache()` gains a trailing `string $namespace = ''` parameter. **Refactor, do
  not duplicate** — the `for ($i = 12; $i >= 0; $i--)` loop stays as-is and becomes the single
  bucketing loop for both series. A parallel copy of that loop is exactly what DEV-1927 /
  `DashboardMonthLabelsTest` exists to catch.
- `getUniqueMonthlyData()`, `resolvePastUniqueMonths()`, `resolveCurrentUniqueMonth()`,
  `getSharedUniqueHitStatsForRange()`, `currentPeriodUnique()` — one-for-one mirrors of the total
  path, same `getCacheTTL()` policy (`(1)` past months, `(0)` current month, `()` today/last_7d).
- `getCardTotal()` override → the deduped figure (**AC-1**). Because `DashboardWidget::toStat()`
  already renders `getCardTotal()`, AC-1 lands here with no presentation change.
- `getTotalHitCount()` → the previous `getCardTotal()` behaviour verbatim, for t3's AC-2 secondary.
- `getDescription()` switches to the unique series in all four branches (**AC-5**). The duplicate
  copy on `DashboardWidget` stays untouched — it serves ScanCount/RecoveryCounts.

**Must not trip**

- **No try/catch in `getSharedUniqueHitStatsForRange()`,** and none inside `currentPeriodUnique()`'s
  `cache()->remember()`. `ClickhouseQuery` already swallows `Throwable` and returns `[]`
  (ClickhouseQuery.php:29-37), so a catch is dead code whose only effect is persisting a fabricated 0
  under a key the description also reads — the thing `DashboardWidget::getRangeTotal()`'s docblock
  (:351-362) explicitly forbids. Do **not** copy the catch from the total sibling at :179-198, and do
  **not** remove that pre-existing one either; it is not this ticket's drift.
- Writing a unique value into a total key or vice-versa. The two maps share key *names* on purpose
  (so `getHitStatsColumn()` works for both) — the cache namespace is the only thing keeping them
  apart, so it is load-bearing.
- Re-deriving the batch window. Reuse the strings the total path already computes.

**Test** `DashboardCardMetricsTest` — the seam test at :287-313 flips premise. Seed **different**
sentinels into the two memos (total 555 / unique 222) and assert `getCardTotal() === 222` *and*
`getTotalHitCount() === 555`; with one shared value a source mix-up passes silently. Add a cache-key
isolation test (each series returns its own sentinel for the same month, neither overwrites the
other) and an AC-5 assertion on `getDescription()`.

> **Trap for both t2 and t3 tests:** the resolvers only skip ClickHouse when *every* key is warm. A
> test that must stay CH-free has to seed all four `hitWidgetMap` prefixes for months 12..1 **plus**
> the `:unique:this_month:` key. Otherwise the widget quietly issues a real query, gets `[]` swallowed
> in the testing env, and asserts against zeros.

---

### t3 — `SharedHitStatsData`, presentation

- `toStat()` override (**AC-2**): the base method plus one line —
  `->description(number_format($this->getTotalHitCount()).' total hits')`. Headline stays
  `getCardTotal()`, already deduped by t2.
- `chartSeries()` override: the compact sparkline follows the headline onto the unique series. The
  headline and the sparkline immediately beside it must describe the same metric — leaving the
  sparkline on totals is the same incoherence DEV-2324 fixed. Override in the trait only;
  `BuildsDateFilterChartSeries` itself is shared with RecoveryLeader (:235).
  `getSparklinePoints()` is left alone (no app-code caller).
- `getData()` override (**AC-3**): two `makeDataset()` entries over the same `getGraphLabels()` —
  `'<label> (Total)'` from `getMonthlyData()`, `'<label> (Unique Plates)'` from
  `getUniqueMonthlyData()` with a solid `getRgbColor()` background so the two are visually distinct.
- `getOptions()` override (**AC-4**): copy `$this->options` **by value**, set
  `plugins.legend.display = $this->expanded` (+ `position: 'top'`, `labels.usePointStyle`), return it.
  Signature must match `ChartWidget::getOptions(): array|RawJs|null`.

**Must not trip**

- **Passing a `$stack` argument to `makeDataset()`.** It only emits a `'stack'` key when given one,
  and the base `$options` sets no `scales.*.stacked` — that omission is the entire mechanism by which
  Chart.js groups the bars side by side. Copying RecoveryLeader's `stack: 'recoveries'` silently
  stacks them and fails AC-3.
- **Touching the base-class `$options`, `getData()` or `toStat()`.** `ScanCount` and `RecoveryCounts`
  extend `DashboardWidget`; a base-level legend flip puts a legend on their charts too. Everything
  stays in the trait, which only the four hit tiles use.
- **Mutating `$this->options` in place.** `mount()` writes `tooltip.borderColor` into it
  (DashboardWidget.php:98); build a local copy.
- Editing the vendor `stat.blade.php`.

**Tests** — `DashboardCardExpansionTest` gains (1) two datasets, distinct labels, **no `stack` key on
either**, 13 points matching 13 labels, for all four hit widgets; and (2) legend `display` true only
when `expanded`, plus the regression half: `ScanCount` and `RecoveryCounts` keep
`legend.display === false` in **both** states (via `ReflectionMethod` — `getOptions()` is protected).
`DashboardMonthLabelsTest` gains a hit-tile case at the `2026-05-31` month-overflow anchor proving
the unique series buckets through the same loop (February's sentinel under `"Feb '26"`).

---

## Verification

Per repo policy tests run only when asked; when they are:

```
php artisan test --compact --filter=HitPlateStatsBatchQueryTest          # t1 (needs CLICKHOUSE_DRIVER=clickhouse)
php artisan test --compact --filter=DashboardCardMetricsTest             # t2
php artisan test --compact --filter='DashboardCardExpansionTest|DashboardMonthLabelsTest'   # t3
php artisan test --compact --filter=HitStatsBatchQueryTest               # regression: totals path unchanged
composer format
```

`zod_testing` via `.env.testing`; never `--parallel`.

## Known caveats to carry into the Build Record

- DEV-2324's recorded constraint *"never re-scan hits_hot/hits_cold"* is **deliberately overridden**
  here on the strength of the 0.17-0.27s measurement (Q4, accepted). Record it as a decision, not drift.
- `hits_cold.kit_org_id` derives from the **scan's** org while `hit_stats_daily.org_id` derives from
  the **kit's** org — a permanent 0.6-1.2% definitional difference between the pair (F14/C8).
- NICB hits (org 149515) are excluded from `hits_cold` by design, so staging-live uniques run slightly
  short of the total's population.
- **March 2026** is the one month where the pair visibly disagrees (~6-9% short on Z) until DEV-2189's
  never-executed historical backfill runs. Not a blocker.
- okf/ updates (kit_org_id scoping, the uniqExact-not-`count()` constraint, the two-source pairing)
  are queued for **after** merge, per CLAUDE.md.
