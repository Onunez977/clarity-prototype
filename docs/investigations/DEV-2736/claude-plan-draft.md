Read-only pass done. Here is the independent implement plan.

---

# DEV-2736 — independent implement plan

**Branch:** `cursor/dev-2736-recovery-leaderboard-2a57` · **Base:** `b999329` · **Gate:** CLEAN
**Verdict on the parked plan:** approve the shape, with **one correction** (below) that `plan.md` step 1 and `plan.json` t1 both get wrong. Everything else stands.

## ⚠ Correction the implement pass must apply

`plan.md:45` and `plan.json` t1 say *"`getData` reads `leaderboardForSelectedRange`"*. That method does **not** return a list on this class.

| Class | `leaderboardForSelectedRange()` returns |
|---|---|
| `UniqueScansLeaderboard` / `StagingLiveHitLeader` (`StagingLiveHitLeader.php:268-290`) | `list<{id,name,total}>` — pluckable |
| **`RecoveryLeader` (`RecoveryLeader.php:188-209`)** | `{leader:{name,count}, runner_up:{name,count}}` — **already collapsed by `summarizeMonth()`** |

Plucking it yields a 2-bar chart of leader + runner-up, not a top-10. **Do not change its return shape** — `toStat()` (`:248`) and four tests (`RecoveryLeaderTest:240-256`, `:285`, `:601`, `:649`) depend on it.

**Fix (no new type, no new class):** extract the pre-`summarizeMonth` bucket into a sibling and have both callers use it.

```php
/** @return array<string, float> kit name => fractional share, query order (SQL ORDER BY total DESC LIMIT 10). */
protected function rankingForSelectedRange(): array
{
    $orgId = auth()->user()->org_id;

    if (\in_array($this->date, ['today', 'last_7d', 'last_month'], true)) {
        [$start, $end] = $this->resolveCardRange();

        return $this->queryClickhouse(
            $orgId,
            ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            $this->date === 'last_month' ? 1 : 0,
            $this->date === 'last_month' ? $end : null,
        );
    }

    $monthlyData = $this->getMonthlyData(formatted: false);
    $bucket = $monthlyData[\count($monthlyData) - 1] ?? [];

    return \is_array($bucket) ? $bucket : [];
}
```

`leaderboardForSelectedRange()` then becomes `return $this->summarizeMonth($this->rankingForSelectedRange());` — same output, zero behavior change. This is lifted verbatim from lines 189-208, so it is a move, not a rewrite.

## Edits — `app/Filament/Widgets/Dashboard/RecoveryLeader.php` (the only product file)

| # | Lines | Action |
|---|---|---|
| 1 | after `:30` | Add the four `private const` chrome colors (copy `UniqueScansLeaderboard.php:48-54` verbatim, values below). |
| 2 | `58-122` | **Replace** `getOptions()` body with `UniqueScansLeaderboard.php:100-158`, swapping two strings (map below). |
| 3 | `188-209` | Split per the correction above; add `rankingForSelectedRange()`. |
| 4 | `264-296` | **Replace** `getData()` (7 lines, below). |
| 5 | `468-480` | **Replace** `makeDataset()` with `UniqueScansLeaderboard.php:456-474` verbatim (2-arg signature). Sole caller is `:289`, which #4 rewrites — no other call site exists. |
| 6 | `482-506` | **Delete** `generateKitColors()` — dead once #4 lands. |

New `getData()`:

```php
public function getData(): array
{
    $ranking = $this->rankingForSelectedRange();

    return [
        'datasets' => [
            $this->makeDataset('Distinct Recoveries', \array_values($ranking)),
        ],
        'labels' => \array_keys($ranking),
    ];
}
```

No `stack`. No sort — the SQL already ships `ORDER BY total DESC LIMIT 10` (`RecoveryLeaderQuery.php:48-49`) and `handle()` (`:56-62`) preserves insertion order. Values stay `float` (`(float) $row['total']` at `:59`); do not cast.

## Chrome copy map (exact literals)

Copy `UniqueScansLeaderboard::getOptions` verbatim **except** these two lines:

| Slot | Scans source | RecoveryLeader value |
|---|---|---|
| `plugins.subtitle.text` | `'Top 10 kits by distinct plates'` (`:108`) | `'Top 10 kits by recoveries'` |
| `scales.y.title.text` | `'Distinct plates'` (`:139`) | `'Distinct recoveries'` |

Dataset label (in `getData`, not options): `'Distinct Recoveries'` (title case — Q2).

Constants, copied character-for-character:

```php
private const LEADER_FILL = 'rgba(34, 197, 94, 0.6)';
private const LEADER_BORDER = 'rgba(34, 197, 94, 1)';
private const FIELD_FILL = 'oklch(0.552 0.016 285.938)';
private const FIELD_BORDER = 'oklch(0.705 0.015 286.067)';
```

**Copying means these current `getOptions` elements go away** — call it out in the PR rather than half-keeping them:

- `y.stacked: true` / `x.stacked: true` (`:102`, `:111`) — gone; a single series must not stack.
- `interaction.mode: 'index'` → `'nearest'`, `intersect: false` → `true` (`:88-89`).
- the whole `hover: { mode: 'index', ... }` block (`:91-95`) — Scans has none.
- tooltip `displayColors: true` → `false`; drop `boxWidth`/`boxHeight`/`usePointStyle`/`pointStyle`, `borderColor: 'rgba(127, 172, 255, 1)'`, `filter:`, `itemSort:` — all multi-series idioms.
- Gains: `plugins.subtitle` block and `scales.y.title`.

`legend.display: false`, `animation.duration: 750`, `responsive`, `maintainAspectRatio`, `aspectRatio: 3`, `beginAtZero`, `grid`, `maxTicksLimit: 8` are already identical — no diff there.

**Unchanged strings (Q2):** `$heading = 'LPR Recovery Leaderboard'` (`:30`); `getTooltipDescription()` (`:34`); `toStat()`'s hardcoded `'LPR Recovery Leaderboard'` (`:251`) — `RecoveryLeaderTest:202` asserts it exactly; every `getDescription()` string (`:298-357`).

## Do not touch

- `app/Clickhouse/Queries/RecoveryLeaderQuery.php` — no SQL edit ⇒ preflight 25 stays closed and no Cloud CH old-vs-new count is owed.
- `getGraphLabels()` (`:455-466`) — **keep it even though `getData` stops calling it.** `DashboardMonthLabelsTest.php:48` iterates `[RecoveryLeader, UniqueScanCounts]` through it, and `RecoveryLeaderTest:683` asserts index 12. Deleting it breaks a different file.
- `formatMonthlyData()` (`:508-515`) — already dead (this class's `getMonthlyData` at `:359` never calls it, unlike `DashboardWidget.php:256`). Pre-existing drift; leave it.
- `resolveCardRange()` (`:431-453`) — a verbatim duplicate of `BuildsDateFilterChartSeries::resolveCardRange`. Pre-existing; leave.
- `chartSeries` (Q3 A) — stays on the trait. No override, no org-total cache.
- `getRgbColor()` (`:236`), `getCardKey()`, `getCardSummary()`, `summarizeMonth()`, `queryClickhouse()`, `getCardQueryStatements()`.
- `StagingLiveHitLeader`, `UniqueScansLeaderboard`, `LeaderboardTableWidget`, `RecoveryLeaderboardExporter`, `okf/`, `public/build`.
- **Do not add `declare(strict_types=1);`** to this file (Scans has it, Recovery does not). It is unrelated drift and it changes float/int coercion on a card whose whole point is fractional shares.
- **Do not memoize** `rankingForSelectedRange()`/`queryClickhouse()`. `RecoveryLeaderTest:609-652` invokes `queryClickhouse` twice on one instance and requires two *different* results (throw, then success). If you ever do add a memo, it must be reset in `refreshData()` (`:136-141`) alongside `$monthlyDataMemo`.

## Query-count note (for the PR, not a code change)

`handle()` (`RecoveryLeaderQuery.php:53`) hits ClickHouse directly — no `remember`, and `queryClickhouse`'s `cache()->put` (`:417`) is write-only by design (`:391-396`). So on `today`/`last_7d`/`last_month`, the expanded chart drops from 13 windows (`getMonthlyData`) to 1. On `this_month` it still reads the 13-bucket memo, because Q2 pins `this_month` to the last `getMonthlyData` bucket and Q3 A keeps the sparkline on the same series — expected, not a regression. `getDescription()` still issues its own window read; leave that alone.

## Tests — `tests/Filament/Widgets/Dashboard/RecoveryLeaderTest.php`

**Delete:** `test_get_data_aligns_each_kits_monthly_values_positionally_with_graph_labels` (`:320-376`, docblock included). It pins the month-zip this ticket removes.

**Keep, must stay green untouched:** `test_collapsed_summary_preserves_fractional_attribution_shares` (`:414`), `test_repo_date_sql_compares_y_m_d_strings` (`:480`), `test_repo_date_predicates_stay_calendar_dates` (`:467`), both cache-TTL tests (`:495`, `:532`), both throw/recover tests (`:570`, `:609`), `test_invalid_viewer_timezone_renders_utc_counts` (`:657`), and the five render/stat tests (`:38`-`:318`).

**Add four,** all on the existing `stubRecoveryLeaderQuery()` harness (`:706`) — no ClickHouse server, so no `RequiresClickhouse` and preflight 18 stays unfired:

1. `test_get_data_is_one_distinct_recoveries_series_in_query_order` — `Carbon::setTestNow('2026-08-12 12:00:00')`, stub the today window with `['Kit Alpha' => 9.5, 'Kit Bravo' => 4.0, 'Kit Charlie' => 1.25]`, `$widget->date = 'today'`. Assert `\count($data['datasets']) === 1`; label `'Distinct Recoveries'`; `data === [9.5, 4.0, 1.25]` (floats, `assertSame`); `labels === ['Kit Alpha','Kit Bravo','Kit Charlie']`; `assertArrayNotHasKey('stack', $data['datasets'][0])`.
2. `test_get_data_follows_the_page_date_filter` — stub distinct today and this-month maps, assert `labels` flips with `$widget->date`. This is the AC-1 proof that the bars read the selected window.
3. `test_the_max_bar_uses_the_leader_fill_and_the_field_stays_zinc` — from the #1 fixture assert `backgroundColor[0] === 'rgba(34, 197, 94, 0.6)'`, `[1] === 'oklch(0.552 0.016 285.938)'`, `borderColor[0] === 'rgba(34, 197, 94, 1)'`, `[1] === 'oklch(0.705 0.015 286.067)'`. Add a tied-max case (`['A' => 5.0, 'B' => 5.0]`) — both green, mirroring the `>= $max` contract in `UniqueScansLeaderboard.php:451,460`.
4. `test_the_word_unique_never_appears_in_recovery_leader_chrome` — mirror `UniqueScansLeaderboardTest.php:456-480` exactly: `$options = (string) (new ReflectionMethod($widget, 'getOptions'))->invoke($widget);` (`RawJs` → `Illuminate\Support\Js`, `__toString` returns the raw JS — `vendor/laravel/framework/src/Illuminate/Support/Js.php:145-158`). Assert the options string contains `'Top 10 kits by recoveries'`, `'Distinct recoveries'` and `"mode: 'nearest'"`; `assertStringNotContainsString('stacked', $options)`; `assertStringNotContainsStringIgnoringCase('unique', $options)` and the same on the dataset label; and `assertSame('recovery-leader', $widget->getCardKey())`.

**Also add an empty-ranking case** (stub `[]`): `datasets[0]['data'] === []`, `labels === []`, no exception — `collect([])->max()` is `null` and the `$isLeader` closure short-circuits (`UniqueScansLeaderboard.php:458-460`), so this is a one-assert guard, not new code.

## Collateral check (read, confirmed unaffected)

`DashboardCardExpansionTest.php:171` (card-key map), `DashboardCardMetricsTest.php:453` (asserts the `filament-widgets::chart-widget` view), `DashboardWidgetTest.php:289` (`isLazy`), `CardQuerySqlFidelityTest.php:110-143` (SQL only), `DashboardMonthLabelsTest.php:48` (`getGraphLabels`), `tests/e2e/{staging-live-hit-leader,missed-opportunities}.spec.ts` + `helpers/company-metrics.ts:9,25` (heading string + grid order only). None reads `getData`/`getOptions` for this class. No Blade or JS builds this chart — the options are the `RawJs` (`prep-context.json` driver_verified; `theme.css` has no `recovery-leader` rule).

## Close-out

`composer format` (Pint) before finishing. Run only `--filter RecoveryLeaderTest` against `.env.testing`, and only if Dalton asks — CI runs the suite. No `okf/` edit until after approval; queue the `company-metrics-dashboard.md` chrome note for the post-merge pass. No provision, no DSN, no Cloud ClickHouse read or write in this phase.
