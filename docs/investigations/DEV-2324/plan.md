# DEV-2324 — Plan (Phase 3 ULTRA-PLAN)

Source of truth for scope: `docs/investigations/DEV-2324/prep-context.json` (gate CLEAN, 9/9 questions
answered by Dalton 2026-08-12). Machine-readable DAG: `plan.json`. Reference implementation read
read-only from `/Users/daltonmccleery/Herd/backyard` (InsightPulse).

## Sizing gate — exceeds 6 production files; NO split recommended

This plan touches **13 hand-written production files** (8 PHP, one of them a deletion; 5 new Blade
views) plus regenerated `public/build/**`, and **9 test files** (2 new, 1 deleted). Four more widget
files (`ScanCount`, `LiveHits`, `StagingLiveHits`, `StagingHistoricalHits`) are *claimed* by t2 for
ownership clarity but are expected to need **zero edits** — their card behaviour is inherited from
the base class.

That is over the 6-file reference line, and it is being called out deliberately rather than hidden.
**I am not recommending a split**, for four grounded reasons:

1. It is one surface and one coherent concern: a single Filament page's redesign. Every file is
   `app/Filament/{Pages,Widgets}/Dashboard/**` or its Blade/test companion.
2. The four enumerated sub-changes share one interaction contract ("only ONE detail region open at a
   time, page-wide"). That contract cannot be split across tickets — half-shipping it means either
   compounding panels or a page property with nothing driving it.
3. The file count is inflated by mechanical multiplicity, not by distinct concerns: 5 small Blade
   views, 2 one-line heading relabels, 2 file deletions.
4. Splitting would guarantee an intermediate state with a broken dashboard (e.g. the donut deleted
   before the replacement card exists, or cards rendered with no expansion target). The DAG below is
   sequenced specifically so that never happens.

If a split were forced anyway, the only defensible seam is **t1 (rename) as its own trivial ticket**.
Everything else is load-bearing on t2.

## Binding design (from `prep-context.decisions`) — treated as settled, not re-litigated

Rename via **both** `getTitle()` and a new static `$navigationLabel`; 8 cards in 2 rows of 4 with the
4 hit cards contiguous; compact card = name + headline total + % change + sparkline; sparkline reuses
each widget's existing cached monthly series; % change = current period vs the **same day-range** in
the prior period; card headline follows the page date filter (default `this_month` ⇒ MTD) with the
collapsed Recovery Leaderboard fixed at this+last month; Camera Kits = total kits and Kit Users =
the existing active-kit count (no new cross-connection query); the expanded Kit Users panel loses the
Active/Inactive filter (approved AC change); ONE shared detail region below the grid; the expanded
key is a property on the Dashboard **page**; `KitStatusGraph` + its test are deleted (approved);
`npm run build` + committed `public/build` is part of the work.

## The DAG

```
t1  rename page title + nav label            ─┐
t2  card contract: helpers + Blade kit       ─┼─ (no deps, mutually disjoint, run in parallel)
t3  relabel Repossessions / Direct Hist.     ─┘

t1,t2 ──► t4  page: expandedCard + 2x4 grid + shared detail region  ─┐
   t2 ──► t5  Recovery Leaderboard collapsed two-month summary      ─┼─ (mutually disjoint, parallel)
   t2 ──► t6  Kit Users card + filter removal                       ─┘

t4,t6 ──► t7  delete KitStatusGraph + its test

t3,t4,t5,t6,t7 ──► t8  npm run build + commit public/build
```

| id | title | depends on | parallel | test filter |
|----|-------|-----------|----------|-------------|
| t1 | Rename the dashboard page to "Company Metrics" (header + sidebar) | — | yes | `DashboardTest` |
| t2 | Compact-card contract for the six metric widgets (data helpers + shared Blade kit) | — | yes | `DashboardCardMetricsTest\|DashboardWidgetTest\|DashboardMonthLabelsTest` |
| t3 | Relabel Repossessions and Direct Historical Hits | — | yes | `RecoveryCountsTest\|HistoricalHitsTest` |
| t4 | Dashboard page: single expanded-card key, 2x4 grid, one shared detail region | t1, t2 | yes | `DashboardCardExpansionTest\|DashboardTest` |
| t5 | Recovery Leaderboard collapsed two-month summary card | t2 | yes | `RecoveryLeaderTest\|DashboardMonthLabelsTest` |
| t6 | Kit Users card (Camera Kits + Kit Users) and remove the Active/Inactive filter | t2 | yes | `KitUsersTest` |
| t7 | Delete the KitStatusGraph donut widget and its test | t4, t6 | no | `Dashboard` |
| t8 | Rebuild frontend assets and commit `public/build` | t3, t4, t5, t6, t7 | no | `Dashboard` |

No node carries a `blast_radius` tag. Nothing here touches `database/migrations/**`, billing,
Shield/Policies/auth config, or `Clickhouse*Purge|Cleanup|PruneScans`. `Dashboard.php` does contain
`canAccess()`, but t1/t4 explicitly do not touch it or `mount()`'s redirect logic — so no auth tag.

### Contention hot-spots, handled explicitly

* **`app/Filament/Pages/Dashboard.php`** is wanted by both the rename and the structural rewrite.
  Serialized: t1 changes only the two label lines; t4 (depends on t1) owns everything structural.
* **`Concerns/DashboardWidget.php`** is the shared base for all six metric widgets. Everything that
  touches it is inside t2 — the data helpers, the `expanded` flag and the view swap — precisely
  because splitting them would either leave the widgets pointing at a Blade view that does not exist
  yet or leave the page mounting a widget with an `expanded` prop it does not have. The two halves
  are circularly dependent, so they are one node rather than a fake parallel pair.
* **`Concerns/SharedHitStatsData.php`** also lives in t2 (its `getRangeTotal()` override is the whole
  reason the four hit cards keep sharing one batched ClickHouse query).

## What each node actually does (grounded)

### t1 — rename
`Dashboard.php:35` is the single hardcoded `'Dashboard'` literal in `app/`, `resources/`, `tests/`.
The sidebar is a *separate* path: `vendor/filament/filament/src/Pages/Dashboard.php:25-30` resolves
`static::$navigationLabel ?? static::$title ?? __('filament-panels::pages/dashboard.title')`, which
this subclass never overrides — so renaming `getTitle()` alone leaves "Dashboard" in the nav. Add the
static property, follow sibling convention (`Profile.php:24`, `ClientAnalytics.php:26`). Do not edit
`lang/vendor/filament-panels/en/pages/dashboard.php`. All 25 outside references go through
`Dashboard::getUrl()`, so redirects are unaffected.

### t2 — the card contract (the keystone node)
Builds **on top of** `getMonthlyData()`/`getGraphLabels()` (`DashboardWidget.php:161-238`) without
touching their month arithmetic; `DashboardMonthLabelsTest.php` must keep passing **unmodified**.

The current-period headline costs **zero new queries** because it is already cached under the keys the
widgets use today: `today` / `last_7d` (as `getDescription()` does), `this_month` = the last
`getMonthlyData()` bucket, `last_month` = the second-to-last bucket. Only the **prior-equivalent
period** is new — one bounded query per metric, as accepted — and for `date=last_month` even that is
free (the preceding month's `Y-m` bucket is already cached).

The prior-period read goes through a new `getRangeTotal()` seam defaulting to the existing abstract
`getModelQueryForDates()`. **`SharedHitStatsData` must override it**: the four hit widgets stub
`getModelQueryForDates()` to `return 0` (`LiveHits.php:31-34`) and their real numbers come from the
single batched `HitStatsBatchQuery` (FINAL, org-scoped, bounded). Because no new SQL is written at
all, every FINAL / `_peerdb_is_deleted = 0` hygiene property is preserved by construction.

Month-end correctness matters here: "same day-range last month" on Mar 31 must clamp to Feb 28, and
every month expression must normalize with `startOfMonth()` **before** `subMonth()` (DEV-1927).
`getCardPercentChange()` returns `null` when the prior total is 0 so the card shows an em dash rather
than a fabricated +100%.

Blade kit ports exactly the three things prep said port from Backyard: the single-open state shape,
the toggle idiom, and the server-computed inline SVG `<polyline>` sparkline
(`partials/pulse-tile.blade.php:23-27`). It deliberately does **not** port Backyard's eager
`<livewire:...>` detail mounting or its hand-rolled Chart.js.

### t4 — the page
Implements Q8 option (b). One scalar `?string $expandedCard` makes "opening a new one replaces the
old" structural. The eight cards move from `getFooterWidgets()` into `getWidgets()` and `content()` is
composed as *filters → card grid → detail region*, because footer widgets render **after** `content()`
and would otherwise put the shared detail region above the grid. The detail region's schema must be a
**closure** (`->schema(fn () => ...)`) — Filament caches page schemas, and a static array would freeze
the region at first render. `filtersForm()`/`updatedFilters()` and the `refresh-dashboard` dispatch
(`Dashboard.php:107`) are untouched: that event is shared with `ClientAnalytics.php:166`,
`IndividualClientAnalytics.php:55,191` and `Client/ClientTable.php:46`, and it is already what makes
the cards follow the date filter. If `$parent.toggleCard()` does not resolve through Filament's
schema-rendered nested widget, the only sanctioned fallback is a **new** event name.

### t5 — leaderboard
Collapsed = this-month and last-month leader + runner-up, **fixed** regardless of the date filter
(the Q4 exception). Zero new queries: `getMonthlyData(formatted: false)` already caches one
`RecoveryLeaderQuery` result per month and that query is `ORDER BY total DESC LIMIT 10`, so leader and
runner-up are the first two entries. `getData()`/`getOptions()` stay byte-identical so the expanded
stacked chart does not move. Adds the missing regression test for commit `b2f0f7ea2`'s positional
alignment, which prep flagged as untested.

### t6 — Kit Users
Camera Kits = total org kit count; Kit Users = the **existing** active count. Take it from
`count(cachedActiveKitIds())` (`KitUsers.php:35-54`) rather than porting `KitStatusGraph::active()`:
identical population (`DISTINCT kit_id` over the same 3-day window), one fewer ClickHouse round trip,
and it guarantees the headline equals the number of rows in the expanded list. No `Kit`(zod) ⋈
`KitSettings`(platform) query, so that hazard is avoided entirely.

**Trap to get right:** the removed `SelectFilter('type')` default is what restricted the table to
active kits. Removing the filter without moving `whereIn('id', cachedActiveKitIds())` into the base
query would silently start listing *every* kit under the heading "Active Kit Users". The prior
"flip to inactive when there are no active kits" fallback correctly disappears; the empty state is
now `No Kit Users`.

**Test-coverage note (needs to be visible in review):** `KitUsersTest::test_can_filter_by_active_and_inactive`
cannot survive the approved AC change. It is **repurposed**, not deleted, into a test asserting the
filter is absent and that only active kits are listed. No test file is removed in this node.

### t7 — delete the donut
Approved in Q9. Sequenced after t4 (registration already removed → never registered-but-missing) and
after t6 (replacement card already ships → no coverage gap). Verify with
`grep -rn KitStatusGraph app tests resources` returning nothing.

### t8 — build
One terminal node, not smeared. `public/build` is tracked and Tailwind v4 `@source`-scans
`app/Filament` + `resources/views/filament` (`resources/css/filament/app/theme.css:5-6`), so the new
utility classes do not exist in the compiled CSS until this runs. No new Vite entrypoint and no new JS
dependency (`x-collapse` already ships in `vendor/filament/filament/dist/index.js`). `npm run build`
also rewrites `CLAUDE.md`, `AGENTS.md`, `.junie/guidelines.md` and **deletes `./boost.json`** — revert
all four, commit only `public/build/**`.

## TDD contract per node

| node | test that pins it |
|------|-------------------|
| t1 | `DashboardTest` — new assertion on the header **and** on `Dashboard::getNavigationLabel()` (the two separate code paths) |
| t2 | new `Concerns/DashboardCardMetricsTest` — MTD total, %-change vs same-day-range last month, month-end clamp, null-on-zero-prior, `last_month` issues no new query, sparkline point string; `DashboardWidgetTest` extended; `DashboardMonthLabelsTest` unchanged and green |
| t3 | `RecoveryCountsTest` / `HistoricalHitsTest` heading assertions updated (and tightened — `'Historical Hits'` is a substring of both headings today) |
| t4 | new `DashboardCardExpansionTest` — open A, open B, assert only B is set (the no-compounding pin), toggle B again → null; plus a guard that card keys match `getCardKey()` and the four hit keys are contiguous |
| t5 | `RecoveryLeaderTest` — two-month summary with a *different* leader per month (catches a range mix-up), fixed-under-filter-change, and the new `getData()` alignment regression |
| t6 | `KitUsersTest` — repurposed no-filter/active-only test + new card-count test where Camera Kits ≠ Kit Users |
| t7 | `Dashboard` filter sweep + grep for zero references |
| t8 | `Dashboard` filter sweep (no new tests; asset-only node) |

Every test follows the existing in-repo pattern: `Tests\TestCase` (`RefreshDatabase`, `createUser()`),
cache pre-seeding to keep ClickHouse out of CI (`KitUsersTest.php:27`,
`DashboardMonthLabelsTest.php:85-91`), and direct instantiation + reflection for helper-level
assertions. PHPUnit only — there is no JS test runner, so the collapse animation and the 2x4
responsive layout are human/agent-browser QA, not unit tests.

## Exhaustiveness

Every `buggy` site in `prep-context.exhaustiveness` is covered; `excluded_sites` is empty.

| site | covered by |
|------|-----------|
| `Dashboard.php:35` hardcoded 'Dashboard' | t1 |
| `Dashboard.php` missing static `$navigationLabel` | t1 |
| the six metric widgets (always-visible bar charts) | t2 (base-class card path; `ScanCount`/`LiveHits`/`StagingLiveHits`/`StagingHistoricalHits` need no per-file edit) + t3 (relabels) |
| `RecoveryLeader.php:161-236` | t5 |
| `KitUsers.php:35-90` | t6 |
| `KitStatusGraph.php:1-121` | t4 (unregister) + t7 (delete) |
| `tests/.../KitStatusGraphTest.php` | t7 |

## Judgment calls flagged for the human

1. **Heading relabels (t3).** The ticket names the cards "Repossessions" and "Direct Historical
   Hits" while the widgets are headed "Recoveries" and "Historical Hits", and Q10 calls these
   relabels. I planned the headings to actually change. If Dalton wants the widget headings left
   alone and only the *card* labels changed, t3 shrinks to nothing and the card label becomes a
   separate `getCardLabel()` override.
2. **Kit Users expanded query (t6).** Removing the filter forces a decision about what the panel
   lists. I chose active-only, matching the "Active Kit Users" heading and Q5/Q6's framing. The
   alternative reading — "no filter" literally meaning "all kits" — would contradict the heading.
3. **`KitUsersTest::test_can_filter_by_active_and_inactive`** must change; it is repurposed rather
   than deleted. Q9's approval covered the `KitStatusGraphTest` file, not this method.
4. **Alpine highlight vs. server state (t2/t4).** The authoritative expanded key is the page
   property; the chevron/ring is Alpine-local so a click does not force eight widget round trips.
   That means the visual state and the server state are seeded together but updated independently —
   intentional, and the reason the card shell reads `open` from an ancestor `x-data` that t4 must
   provide.

## Nothing in the binding design looks like a mistake

Each contested decision holds up against the code: option (b) is the only shape that gets true
click-triggered lazy loading given that Filament widgets are lazy and `DashboardWidget::mount()`
deliberately skips `ChartWidget::mount()`'s data checksum; the same-day-range %-change is affordable
because the current-period value is already cached and only the prior value is new; and reusing the
existing `active()` population for "Kit Users" sidesteps the zod/platform cross-connection trap
entirely. The one thing worth naming, not as an error but as an accepted cost: on the 1st of the
month every card's %-change compares a single day against a single day, so early-month percentages
will be noisy. That is inherent to the chosen definition, not a defect in it.
