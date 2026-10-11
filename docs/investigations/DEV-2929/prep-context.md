# DEV-2929 prep-context

**Gate:** CLEAN
**At:** 2026-10-07 6:22pm CDT
**Ticket:** [DEV-2929](https://insightlpr-team.atlassian.net/browse/DEV-2929) · Task · To Do · Sprint 42 (active) · Application Clarity · epic DEV-1580 · relates to DEV-2904 (Done) · assignee Dalton McCleery
**Repo:** insight-lpr/platform · **Branch path:** main→main (First Glance 43362) · base `origin/main` @ `0e5ae042`
**Branch:** `cursor/dev-2929-export-window-filename`

## Live AC (Jira wins)
1. For each date toggle option, the downloaded filename shows the start and end date of the exported window (single date when start and end are the same day).
2. For each date toggle option, the export button tooltip shows the selected period's start and end dates, and updates when the toggle changes.
3. Exported rows and the query are unchanged.
4. Tests cover the filename for a month filter, Last 7d, and Today.
5. Tests cover the tooltip text for a month filter, Last 7d, and Today.

Ticket examples (verbatim):
- Filename: `lpr-repossessions-claimed-2026-10-01-to-2026-10-31.csv` (month) · `lpr-repossessions-claimed-2026-09-30-to-2026-10-07.csv` (Last 7d) · `lpr-repossessions-claimed-2026-10-07.csv` (Today)
- Tooltip: "Export Oct '26 LPR repos (cases claimed Oct 1–31) as CSV" (month) · "Export last 7 days (Sep 30–Oct 7)" (Last 7d) · "Export today (Oct 7)" (Today)
- Out of scope: notifications, CSV columns, query or date-window logic, chart/card changes.

## Surface scout (main `0e5ae042`, verified)
| File | Lines | Role |
| --- | --- | --- |
| `app/Filament/Widgets/Dashboard/LprRepossessions.php` | `exportAction()` L648–662 | `->tooltip('Export as CSV')` L653 (static) → closure; `->fileName(...)` L656 (export day, viewer TZ) → from `exportWindow()` |
| same | `exportWindow()` L669–691 | today/last_7d: `resolveCardRange()` re-anchored in viewer TZ; this/last month: full UTC calendar month (`SERIES_ZONE` L91). **Unchanged.** |
| same | `exportRowsSql()` L693–698 | query uses the same `exportWindow()`. **Unchanged.** |
| same | `getDescription()` L337+, labels L317/L329 | existing UTC month label format `M 'y` (e.g. `Oct '26`) |
| same | `refreshData()` L512–520 | `refresh-dashboard` → heading re-render → tooltip closure re-evaluates |
| `app/Filament/Widgets/Dashboard/Concerns/BuildsDateFilterChartSeries.php` | `resolveCardRange()` L28–50 | last_7d = `subDays(7)->startOfDay()`..`endOfDay()` (8 calendar days) |
| `tests/Filament/Widgets/Dashboard/LprRepossessionsTest.php` | L1241 / **L1261** / L1284 | old filename assert at L1261 must change; export-window test L1284 stays as is |
| `tests/Filament/Actions/ResendToClearPlanActionTest.php` | L43 | `getTooltip()` assertion precedent |

## Rules carried to Plan
- Window = exactly what the query uses. Months: UTC calendar month (This Month = full month, Oct 1–31). Today / Last 7d: viewer TZ; Last 7d spans 8 calendar days. Format each bound in its own zone; never convert UTC month bounds to the viewer zone.
- Smallest change (#24): two closures in `exportAction()` + tests. No new class / helper / trait. Reuse `exportWindow()`.
- `ch_touch: no` (#31). #25 N/A (no query/path rewrite). #18 not required for the change itself.
- Soft collision: PR #489 (Filament 5.6 → 5.9). If it merges first, re-run the tests.
- No bot / tool names on Jira or GitHub (#17).

## OKF to read
`okf/concepts/company-metrics-dashboard.md` · `okf/concepts/filament-conventions.md` · `okf/concepts/testing-conventions.md` · `okf/concepts/platform-gotchas.md` · `okf/models/users.md` (viewer timezone) · `okf/models/timezones.md`.

## Material open questions blocking planning
None. Non-blocking: tooltip copy verbatim incl. "as CSV" on the month variant only; cross-year Last 7d form (no example).

## Provision note (PM-accepted #9 deviation)
No Path A site / ClickHouse twin for this key now (both slots held for human QA on DEV-2450 and DEV-2928). Plan / Implement / Review run on a keyed test PG DB + `vendor/` + `.env.testing` (existing tests stub the widget; zero ClickHouse calls). Running-site twin for Agent QA (#27 tooltip refresh on toggle) stood by PM at QA Ready time when a slot frees.

## Preflight cites
#9 (accepted deviation) · #17 · #23 · #24 · #26 · #30 · #31
