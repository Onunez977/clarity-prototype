---
type: Pattern
title: Company Metrics Dashboard Card Family
description: How the /app Company Metrics grid is built — the cards() registry as the canonical key list (twelve cards after DEV-2651), the merged-card pattern where one card carries two figures, cards as data adapters (why mount() never runs and canViewCardData()/lockedStat() exist), Pro entitlement per viewer plus the canViewRatioFigure() partial gate, the DashboardWidget cache-TTL ladder including the 1-year sparkline TTL, the class_basename cache-key trap, the ::query()-swallows-vs-::handle()-propagates rule behind the shared blast radius of an unguarded getStats() loop, and the checklist for adding a card.
resource: app/Filament/Widgets/Dashboard/CompanyMetricsOverview.php
tags: [domain, filament, dashboard, clickhouse, caching, pro-gating, widgets, charts]
timestamp: 2026-09-22T00:00:00Z
---

# Company Metrics Dashboard Card Family

The **Company Metrics** grid on the `/app` dashboard. `CompanyMetricsOverview` (a Filament
`StatsOverviewWidget`) owns the grid; each card is a sibling widget class that owns its own query.
Read [Data Architecture](data-architecture.md) before touching any card's query — every card here
reads **ClickHouse**, and writes/truth live in PostgreSQL. Companions:
[ClickHouse Query Patterns](clickhouse-query-patterns.md),
[Filament Conventions](filament-conventions.md), [Feature Flags & Pro Tiers](feature-flags.md),
[Impersonation](impersonation.md), [Platform Gotchas](platform-gotchas.md).

## `cards()` is the canonical key registry

`CompanyMetricsOverview::cards()` maps each card key to its widget class, in **grid order**:

| # | key | class |
| --- | --- | --- |
| 0 | `unique-scan-counts` | `UniqueScanCounts` |
| 1 | `unique-lpr-case-claims` | `UniqueLprCaseClaims` |
| 2 | `lpr-repossessions` | `LprRepossessions` |
| 3 | `recovery-counts` | `RecoveryCounts` |
| 4 | `live-hits` | `LiveHits` |
| 5 | `historical-hits` | `HistoricalHits` |
| 6 | `staging-live-hits` | `StagingLiveHits` |
| 7 | `staging-historical-hits` | `StagingHistoricalHits` |
| 8 | `missed-opportunities` | `MissedOpportunities` |
| 9 | `unique-scans-leaderboard` | `UniqueScansLeaderboard` |
| 10 | `recovery-leader` | `RecoveryLeader` |
| 11 | `staging-live-hit-leader` | `StagingLiveHitLeader` |

**Twelve cards as of DEV-2651 (merged 2026-09-22).** The count moves; verify it against
`CompanyMetricsOverview::cards()` before quoting it. DEV-2651 both **removed four cards and
re-ordered every remaining one**, so any index quoted from an older doc or spec is wrong — the
grid is now four count cards, then the four hit cards, then missed opportunities plus the three
leaderboards.

**Four card classes were deleted outright** — `ScanCount`, `UniqueScansToRepo`,
`UniqueScansToCaseClaims`, `UniqueScansToLprRepossession` — each absorbed into a survivor (see
[Merged cards](#merged-cards-one-card-two-figures)). Their keys are gone from `cards()`, so a
reference to any of them in a spec, a test fixture or a cache key is stale.

Two rules that are easy to get wrong:

- **`cards()` is the key registry, not `Dashboard::cardWidgets()`.** Every `getCardKey()` in this
  namespace resolves against `cards()`. `cardWidgets()` returns the *entitlement-filtered*
  `expandableCards()`, which omits locked cards — so using it as the registry silently loses cards.
- **Slots are mockup-relative, not append-order.** A new card goes at its designed grid position,
  which usually means **inserting** into the middle of `cards()`. Do not "tidy" insertions back into
  append order; the ordering is the layout.

## Cards are data adapters, not mounted widgets

`getStats()` constructs each card class directly and calls `toStat()` (or `lockedStat()`), so the
card is used as a **data adapter** — Livewire never mounts it. The consequence is the whole reason
two extra seams exist:

- **`mount()` never runs on the compact path**, so a card's own `canView()` is *not* enforced there.
- Entitlement is therefore checked explicitly: `getStats()` calls `$class::canViewCardData()` and
  falls back to `lockedStat()` (`RendersDashboardStat::lockedStat()`), which renders a placeholder
  and **executes no query**.
- `expandableCards()` is `array_filter(cards(), fn ($class) => $class::canViewCardData())`. A locked
  card **keeps its grid slot** but is absent here, which is what makes it non-toggleable
  structurally rather than by a permission check at expand time.

Because cards are constructed rather than mounted, a new card must never rely on `mount()` for
setup, and its Pro gate must live in `canViewCardData()`.

## Merged cards: one card, two figures

DEV-2651 merged four pairs, following the shape `SharedHitStatsData` established for the hit cards
in DEV-2406: the **survivor keeps its headline**, the absorbed card's figure becomes a
`Stat::description()` line, and the expanded chart carries both as two series.

| survivor | absorbed (deleted) | headline | description line |
| --- | --- | --- | --- |
| `unique-scan-counts` | `scan-count` | distinct plates | `N total scans` |
| `unique-lpr-case-claims` | `unique-scans-to-case-claims` | deduped claims | `N scans per case claim` |
| `lpr-repossessions` | `unique-scans-to-lpr-repossession` | LPR repos | `N scans per LPR repo` |
| `recovery-counts` | `unique-scans-to-repo` | total repossessions | `N scans per repo` |

### The shared numerator carries no denominator, deliberately

`RatioAgainstUniqueScans` holds the "unique scans" numerator all three ratio cards share. It
deliberately supplies **no denominator**: each card's headline already builds a `Y-m`-keyed month
array, and the ratio must be zipped against **that same array**. Two arrays from two independently
bucketed queries can drift apart; one array zipped against itself cannot. If you add a fourth ratio
card, zip — do not run a second denominator query.

### Co-plotting two series exposes calendar mismatches that were invisible alone

The two series on `unique-scan-counts` come from different ClickHouse tables. `ScanStatsBatchQuery`
grouped its months in **UTC** while `ScanPlateStatsBatchQuery` grouped in the **viewer's zone**, so
for a non-UTC viewer the last hours of each local month fell into the next bar — and a bar could
show a raw total **below** a distinct-plate count, which is structurally impossible. Standing alone
as its own card the mismatch was invisible; juxtaposing the series is what surfaced it.

`ScanStatsBatchQuery` is now zone-aware, with **one** zone feeding both the `WHERE` bounds and the
`GROUP BY`. Splitting them lets the grouping emit a month key outside the caller's write set,
silently dropping the rows in the gap. The bounds are still converted to UTC so `org_id`/`scan_hour`
sort-key pruning is unaffected — only the SELECT-list grouping expression is zone-aware.

**This settles only that one card's two series agree with each other.** It does not settle which
calendar the dashboard should use overall — DEV-2652 owns that, including `RecoveryCounts`' month
axis, whose `repo_date` column carries no time-of-day and so cannot follow the viewer's clock
either way.

### Chart conventions for a merged card

- **Counts are bars; a ratio is a line.** Two bars of similar height on two different axes read as
  one unit — a viewer has to map bar to axis by colour to learn that 5 and 8 are a count and a rate.
  Different geometry carries that on its own.
- **A ratio rides a second right-hand axis** (`scales.y1`, `grid.drawOnChartArea: false`) so its much
  smaller scale is not flattened. A pair of **counts** does not: `unique-scan-counts` deliberately
  keeps **one** axis, and adding a second there for symmetry is a defect.
- **Two series need one stable colour each.** `makeDataset()` colours a series with a **per-month
  array** applying the current-month highlight, and Chart.js builds each legend swatch from **index 0**
  of that array — the leftmost month, which is never the highlighted one. On any charted series with
  a legend this renders a grey swatch beside accent-coloured bars. Use
  `DashboardWidget::staticColourDataset()`; keep `makeDataset()` only on the single-series path,
  where the highlight still earns its place and there is no legend to be wrong about.
- **Draw order is explicit, not incidental.** Chart.js sorts datasets by `(order, index)` ascending
  and then draws that list in **reverse**, so dataset 0 paints last and buries anything under it.
  A line over bars needs a **lower** `order` than the bars, or it disappears wherever it crosses one.
- **A failed figure renders `--`, never `0`.** `RatioAgainstUniqueScans::UNAVAILABLE_VALUE` is
  deliberately **not** `DashboardMetricCard::LOCKED_VALUE`: "could not be computed" and "you are not
  entitled to this" are different statements and the card says which it means. The ratio **dataset**
  is dropped entirely rather than plotting a fabricated flat line — and the axis and legend drop with
  it.

### The cache-namespace trap when absorbing a card

`buildWidgetCacheKey($dateType, $orgId)` is `{class}:{dateType}:org={id}`. After a merge **both**
legs run inside the surviving class, so the obvious port of the absorbed card's leg yields the
**identical key** the survivor's own series already uses — and one silently overwrites the other for
up to a year (see the TTL ladder below). `UniqueScanCounts` gives the ported leg its own
`scan-total:` sub-namespace for exactly this reason.

The opposite trap applies to keys that are **meant** to be shared: `UniqueScansLeaderboard`'s
sparkline is a passive reader of the month keys `UniqueScanCounts` fills, through the class-neutral
`ScanPlateStatsBatchQuery::sharedMonthCacheKey()`. Never reach for `buildWidgetCacheKey()` there —
it prefixes the calling widget's class basename and would hide the write from every other reader.
This is also why `unique-scan-counts` must precede `unique-scans-leaderboard` in `cards()`.

## Entitlement is per **viewer**, not per org

There are **two** shapes here and conflating them is a correctness bug, so take them separately.

`RendersDashboardStat::canViewCardData()` is the **trait default** — every card that does not
override it gets this, and its guest arm answers **`true`**:

```php
public static function canViewCardData(): bool
{
    if (auth()->guest()) {
        return true;                     // guest short-circuit — see below, it is not a hole
    }

    return static::canView();
}
```

The Pro-gated cards **override** it, and their guest arm answers **`false`**:

```php
public static function canViewCardData(): bool
{
    return auth()->check()
        && static::canView()
        && (Feature::active(FeatureEnum::COMPANY_METRICS->slug())
            || session()->has('admin_impersonator'));
}
```

Five cards ship that override as of DEV-2651: `MissedOpportunities`, `StagingLiveHitLeader`,
`UniqueLprCaseClaims`, `UniqueScansLeaderboard`, `LprRepossessions`. Two changes since the
DEV-2464 snapshot this page previously recorded:

- **`UniqueScansLeaderboard` joined** at DEV-2468 (PR #396).
- **`UniqueScanCounts` LEFT** at DEV-2651. It absorbed `ScanCount`, which was never gated, so
  keeping the gate would have *removed* a number every org could already see. The cost is real and
  was accepted explicitly: the per-viewer-timezone `ScanPlateStatsBatchQuery` scan (measured
  **60,738,996 rows / 2.44 GB** peak for a single copy) is no longer bounded to entitled orgs. Past
  months cache for a year, so it is a cold-start cost, not steady-state.

DEV-2651 also gave `RecoveryCounts` a *partial* gate — see below.

### The partial gate: `canViewRatioFigure()`

`RecoveryCounts` does **not** override `canViewCardData()`. Its Total Repossessions headline renders
for every org; only the absorbed "scans per repo" **ratio** is gated, through a separate seam on the
`RatioAgainstUniqueScans` concern:

```php
public static function canViewRatioFigure(): bool
{
    return auth()->check() && static::canViewCardData();
}
```

The default delegates, so a fully-gated card needs no override — `RecoveryCounts` is the only card
that overrides it, to gate the figure while leaving the card open. **`auth()->check()` first is not
redundant**: this is a *disclosure* gate, and the trait default `canViewCardData()` answers `true`
for a guest by design, so delegating bare would make the ratio open by default on any ungated card.

A card that gates a figure rather than the whole card must drop **everything derived from it** on the
withheld path — `getData()` drops the ratio dataset and `getOptions()` drops the right-hand axis and
the legend with it, or a withheld card renders a titled axis with nothing plotted against it. Review
caught exactly that on two of the three ratio cards.

The contract the two share is **totality**, not the return value: this method is reached from
`Dashboard::cardWidgets()` in contexts that never authenticated, so it must not dereference
`auth()->user()` unguarded and must not throw. The trait's `true` is safe because the only consumer
that renders a *value* (`CompanyMetricsOverview::getStats()`) is unreachable without auth — that
widget's `mount()` aborts first — while `cardWidgets()` needs only the array *shape*. The overrides
reach the same totality by guarding with `auth()->check()` first and then answering `false`.

**If you copy a Pro-gated card, copy the guard ordering, not the return value** — and do not copy
the trait's `return true` into a gate that decides whether a number is disclosed.

`COMPANY_METRICS` (`platform_repo_pro_company_metrics`) is defined in `PennantServiceProvider` as
`fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::COMPANY_METRICS)`.
Two things follow:

- The population that can render these cards is **larger than the set of Pro orgs** — InsightLPR
  staff render them inside non-Pro orgs, and `session('admin_impersonator')` is a further bypass.
- Only the `clarity_pro` and `clarity_pro_plus` tiers grant `COMPANY_METRICS`
  (`ProTierEnum::features()`); `basic` and `pro_lite` do not. Measured 2026-09-08: **29** orgs
  entitled out of 4,118 carrying any `platform_repo_pro*` key. See
  [Feature Flags & Pro Tiers](feature-flags.md) for how `isProEnabled()` resolves, including the
  backward-compat arm where an **absent** `platform_repo_pro_tier` resolves to `CLARITY_PRO`.

## The cache-TTL ladder (`DashboardWidget::getCacheTTL($index)`)

| condition | TTL |
| --- | --- |
| `$index !== 0`, not impersonating | **1 year** |
| `$index === 0`, not impersonating | **10 minutes** |
| impersonating (any index) | **10 seconds** |

`$index === 0` is the current-period headline value; non-zero indexes are the historical sparkline
buckets, which are treated as immutable and cached for a year. Two implications:

- A **stale monthly map is cached for a year**, so any cached monthly structure must carry the
  window in its key (see the next section) or a month rollover can serve a map whose newest month
  is missing.
- A card that caches through its own path (e.g. `Cache::flexible`) **bypasses this ladder entirely**,
  including the impersonation collapse to 10 s. That divergence is real and is why an impersonating
  admin can see a much staler value on one card than on its neighbours. Related: PG-009.

### The `class_basename` cache-key trap

`buildWidgetCacheKey($dateType, $orgId)` derives the key prefix from
`Str::of(class_basename(static::class))->snake('-')`. So **a subclass addresses a different cache
key than its parent** — in tests, warming a cache through `CountingFooWidget` and asserting through
`StubbingFooWidget` silently tests two different keys. Warm and assert through the *same* class.

## Shared blast radius — and the env gate that decides it

`getStats()` is a plain loop with **no per-card `try`/`catch`**:

```php
foreach (static::cards() as $class) {
    $widget = $this->makeMetricWidget($class);
    $stats[] = $class::canViewCardData() ? $widget->toStat() : $widget->lockedStat();
}
```

So a card that lets an exception escape returns a **500 for all twelve cards**. Whether a card escapes
is decided by an **environment gate** that is the house-wide convention here — both
`ClickhouseQuery::query()` and `SharedHitStatsData` are written as
`if (in_array(config('app.env'), ['production', 'testing'])) { report($e); /* degrade */ } throw $e;`
— i.e. **degrade in production/testing, throw loudly in local/dev.**

Behaviour on a ClickHouse failure, **in production**:

**The entry point decides the behaviour, and this is the single most load-bearing fact on this
page.** `ClickhouseQuery::query()` catches `Throwable` and returns `[]` in production/testing;
`ClickhouseQuery::handle()` does **not** go through that wrapper at all. So `::handle()` is the
bypass, and any "did the query fail?" guard written around `::query()` is **dead code** — the
failure was already swallowed into an empty array before the guard could see it. DEV-2651 review
found exactly that: a regression test stubbed the seam *above* the swallow and certified a guard
that could never fire.

Verified against merged `main` (2026-09-22):

| card(s) | entry point | result |
| --- | --- | --- |
| the four hit cards | `SharedHitStatsData` bypasses the swallow, then catches | `report()` + a **60 s negative-cache marker** + degrade |
| `UniqueScanCounts` | `ScanPlateStatsBatchQuery::handle` **and** `ScanStatsBatchQuery::handle` | **propagates → grid 500** |
| `RecoveryCounts` | `RecoveryTotalsBatchQuery::handle`, no catch | **propagates → grid 500** |
| `UniqueScansLeaderboard` | `ScanPlateStatsBatchQuery::handle`, no catch | **propagates → grid 500** |
| `MissedOpportunities` | Eloquent on `ClickhouseModel`, no catch anywhere | **propagates → grid 500** |
| `unique-lpr-case-claims`, `lpr-repossessions` | bespoke query, no swallow, by design | **propagates → grid 500** |
| `StagingLiveHitLeader`, `RecoveryLeader` | mixed — some legs `::query()` (swallowed → **`0`**), some `::handle()` | partly degrades, partly propagates |

**The 500-the-grid set is now larger than this page previously recorded** (it said "four of
sixteen"). Do not trust either number without re-checking: the deciding factor is per *leg*, not
per card, so grep the card for `::handle(` versus `::query(` rather than inferring from its class.
`UniqueScanCounts` in particular now carries **two** catch-free current-month legs where it
previously carried one, because it absorbed `ScanCount`'s raw-total leg — and that card is also
now ungated, so the population reaching those legs is every ADMIN/USER org. In **local/dev every**
card can 500 the grid, because both gates invert there — which is why a local grid 500 is *not*
evidence of production behaviour. (DEV-2463 QA hit exactly this: `HitStatsBatchQuery` threw under
`APP_ENV=local` and took the grid down, which in production would have been a `report()` plus a
marker.)

The design tension is real: `ClickhouseQuery`'s swallow turns a failure into a `0` that is
**indistinguishable from a legitimate zero**. For a card where zero is a normal reading, swallowing
is worse than failing — so such cards deliberately propagate, and inherit this blast radius.
`SharedHitStatsData` is the only place that resolves the tension properly: bypass the swallow, catch
it yourself, `report()`, and degrade behind a short marker.

**The propagate guarantee is synchronous-path only** (DEV-2464). A card that caches through
`Cache::flexible` keeps it on a total miss or a hard-expired key — which is exactly where a
fabricated `0` would otherwise be *written* — but **not** on the deferred stale refresh, because
Laravel runs deferred callbacks through `DeferredCallbackCollection::invokeWhen()`, which wraps each
in `rescue()`. A ClickHouse failure there is `report()`ed and swallowed, and the card serves the
**stale** value until the hard TTL. That is a better outcome than a fabricated zero, but it is a
different guarantee — do not let a class docblock claim the strong one unconditionally. Applying that shape uniformly (or a
per-card catch in `getStats()` that renders an explicit *unavailable* state, distinct from `0`) is
the open follow-up.

## Adding a card — checklist

1. Register the key in `cards()` at its **mockup slot** (insert, don't append).
2. Add an explicit arm to `DashboardWidget::getStatColor()`. The `default => 'gray'` arm **fails
   silently** — a missing arm looks like the locked state. Use a **registered** Filament colour:
   an unregistered name (e.g. `'purple'`) renders untinted with **no error at all**; assert
   `FilamentColor::getColor()` resolves it. If the card draws a sparkline, keep `getRgbColor()`
   paired with the chosen colour.
3. Put the Pro gate in `canViewCardData()`, never in `mount()`. To gate a *figure* rather than the
   whole card, override `canViewRatioFigure()` instead and drop every derived thing with it (the
   dataset, its axis, the legend).
4. Update the registry tests: the card-order/count assertion in
   `tests/Filament/Pages/DashboardCardExpansionTest.php`, the allowlist and count in
   `tests/Filament/Widgets/Dashboard/Concerns/DashboardCardVisibilityTest.php`, and keep
   `test_all_four_hit_cards_are_contiguous()` passing.
5. Fix **adjacency-based** e2e specs — inserting mid-grid shifts every later card's index
   (`tests/e2e/*.spec.ts` use nth-child style adjacency).
6. If the card overrides the month axis, override the **label axis with it**
   (`getGraphLabels()`, `getHighlightedMonthsFromFilter()`, `getDescription()`); mismatching the data
   axis and the label axis is a real defect class that `DashboardMonthLabelsTest` only partly guards.
7. If the card plots **two series**, give each one stable colour (`staticColourDataset()`), set an
   explicit draw `order` when one is a line, and check the two series are bucketed on the **same
   calendar** — see [Merged cards](#merged-cards-one-card-two-figures). A second series also turns
   the legend on, which is what exposes a per-month colour array as a wrong swatch.
8. If the card **absorbs** another, give the ported leg its own cache sub-namespace, and delete the
   absorbed class and its tests in a **separate** step from the `cards()` cutover — deleting a class
   still named in `cards()` fatals at load, and cutting the registry over first makes every
   intermediate commit a regression.

## See also

- [Billing Events](billing-events.md) — the table behind `unique-lpr-case-claims`.
- [ClickHouse Query Patterns](clickhouse-query-patterns.md) — `FINAL`, `_peerdb_is_deleted`, caching.
- [Feature Flags & Pro Tiers](feature-flags.md) · [Impersonation](impersonation.md)
- [Platform Gotchas](platform-gotchas.md) — PG-002, PG-009, PG-028.
