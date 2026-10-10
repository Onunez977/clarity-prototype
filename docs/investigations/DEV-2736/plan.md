# DEV-2736 plan

**Phase:** plan. **Gate:** CLEAN. **Base:** `main` at `b999329615174f5e3c6bfe7f77e5c592f01c021a`. **Next:** Provision. Provision waits for the fleet ledger. This phase writes no product PHP and invents no DSN.

The expanded LPR Recovery chart becomes one series for the selected date window. Dalton's answers on 2026-09-25 supply the chrome, the labels, and the sparkline.

## How to read this

The steps below are the later implement pass. This commit does not run them. Check a step only when its diff exists.

## Decisions

Q1. `RecoveryLeader` copies `UniqueScansLeaderboard::getOptions` and `makeDataset`. Mode `nearest`. Subtitle and axis title are present. The max bar uses the Scans leader green. The other bars use the Scans zinc field.

Q2. Dataset label `Distinct Recoveries`. Subtitle `Top 10 kits by recoveries`. Axis title `Distinct recoveries`. New chrome strings use Distinct. The heading `LPR Recovery Leaderboard` and the existing tooltip stay as written.

Q3. `chartSeries` stays `BuildsDateFilterChartSeries::chartSeries`, the sum of each monthly top-10 map. No recovery org-total cache.

`RecoveryLeaderQuery::handleSql` stays. Fractional `kit_count / total_count` on `clarity.recovery_scans FINAL` and `clarity.recoveries FINAL`, `_peerdb_is_deleted = 0`, `repo_date` as `Y-m-d`, `GROUP BY k.id, k.name`, `ORDER BY total DESC LIMIT 10`. `handle` stays a name-keyed map. `okf/concepts/lpr-assist.md` is the stay-constraint. The kit-user table stays out. No third leaderboard class.

`getData` returns one dataset from `rankingForSelectedRange()` — the name→float map *before* `summarizeMonth`. Labels are kit names in query order. Values are those floats. Do **not** call `leaderboardForSelectedRange` for the chart: on this class it already collapses to `{leader, runner_up}` for `toStat()`. Extract `rankingForSelectedRange` as a move of the existing bucket body; `leaderboardForSelectedRange` becomes `summarizeMonth($this->rankingForSelectedRange())` so the headline stays unchanged. `this_month` stays on the last `getMonthlyData` bucket, the full calendar month the headline already uses.

## Preflight 25

Preflight 25 stays closed.

Q3 adds no second query. `handleSql` stays. The bars plot the selected-window map the headline already reads. Implement proof is a `RecoveryLeaderTest` that stubs that map and checks `getData` labels and floats, plus `test_repo_date_sql_compares_y_m_d_strings` and `test_collapsed_summary_preserves_fractional_attribution_shares`.

A Cloud ClickHouse old-versus-new count is required only if a later change edits `handleSql` or the tables it reads. This plan does not. This phase performs no Cloud read and no Cloud write.

## Preflight 18

Widget tests stub `RecoveryLeaderQuery`. They do not need a ClickHouse server.

If Agent QA or Verify later executes `RecoveryLeaderQuery::handle`, use the fleet Docker ClickHouse from the provision ledger. This plan does not invent a host, a port, or a database name. Measure there.

## Preflight 24

The data list matches `StagingLiveHitLeader::getData`. The options and the max-bar split match `UniqueScansLeaderboard`, with the Q2 strings. There is no third leaderboard class.

## Steps

These run after Provision. They do not run in this phase.

1. In `app/Filament/Widgets/Dashboard/RecoveryLeader.php`, extract `rankingForSelectedRange()` (name→float map) from the current `leaderboardForSelectedRange` bucket body. Rewrite `leaderboardForSelectedRange` to `return $this->summarizeMonth($this->rankingForSelectedRange());` so `toStat()` behavior is unchanged.
2. Change `getData` to one dataset from `rankingForSelectedRange`. Dataset label `Distinct Recoveries`. Labels = kit names; values = floats. Remove `generateKitColors` with the old per-kit loop. Keep `getGraphLabels` (other tests still call it).
3. Replace `getOptions` from `UniqueScansLeaderboard::getOptions`. Subtitle `Top 10 kits by recoveries`. Axis title `Distinct recoveries`. Mode `nearest`. Leave stacked unset.
4. Copy `UniqueScansLeaderboard::makeDataset` onto `RecoveryLeader`, including `LEADER_FILL`, `LEADER_BORDER`, `FIELD_FILL`, and `FIELD_BORDER`.
5. Leave `chartSeries` on `BuildsDateFilterChartSeries`. Add no org-total cache.
6. In `tests/Filament/Widgets/Dashboard/RecoveryLeaderTest.php`, remove `test_get_data_aligns_each_kits_monthly_values_positionally_with_graph_labels`. Assert one dataset, the Q2 strings, max-bar chrome colors, no Unique in new chrome, and that `today` uses the today map. Keep the fractional headline test and the `Y-m-d` SQL test.

## Files the implement pass may edit

- `app/Filament/Widgets/Dashboard/RecoveryLeader.php`
- `tests/Filament/Widgets/Dashboard/RecoveryLeaderTest.php`

## Leave unchanged

- `app/Clickhouse/Queries/RecoveryLeaderQuery.php`
- `app/Filament/Widgets/Dashboard/StagingLiveHitLeader.php`
- `app/Filament/Widgets/Dashboard/UniqueScansLeaderboard.php`
- `app/Filament/Widgets/KitUserRecoveries/LeaderboardTableWidget.php`
- `app/Filament/Exports/RecoveryLeaderboardExporter.php`
- `okf/`
- DEV-2660 and DEV-2738

## Needles

| Symbol | File | What it is now |
| --- | --- | --- |
| `RecoveryLeader` | `app/Filament/Widgets/Dashboard/RecoveryLeader.php:30` | Heading `LPR Recovery Leaderboard`. `getData` at line 264 is one dataset per kit across 13 months. |
| `RecoveryLeaderQuery` | `app/Clickhouse/Queries/RecoveryLeaderQuery.php:34` | `recovery_scans FINAL`, fractional credit, `LIMIT 10` at line 49. |
| `StagingLiveHitLeader` | `app/Filament/Widgets/Dashboard/StagingLiveHitLeader.php:229` | One dataset, kit-name labels. |
| `UniqueScansLeaderboard` | `app/Filament/Widgets/Dashboard/UniqueScansLeaderboard.php:100` | Scans chrome. `makeDataset` at line 456 paints the max bar. |
