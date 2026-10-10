# DEV-2904 cloud plan review

**Verdict: REQUEST CHANGES**

Reviewed the Ultra-Plan draft at `docs/investigations/DEV-2904/claude-plan-draft.md` against branch `cursor/dev-2904-lpr-repos-vin-export-24e1` tip `46becd0d0` (draft PR 501, base `main`). Read-only. No product PHP. ClickHouse was not queried.

The draft is the right shape for the card: a new listing of the headline `db_repos` triple, a house CSV exporter, and an expanded-chart action that does not touch the aggregate or the shared cache keys. Four items in the export-execution section have to be rewritten before implementation. They are listed under Changes requested. The rest of the draft can stand.

---

## 1. AC coverage

| AC | Draft | Review |
| --- | --- | --- |
| **AC-1** Export button, CSV, on the LPR Repossessions bar chart | `ExportAction` on a card-local chart blade; `HasActions` + `InteractsWithActions`; CSV only | Covered. `ChartWidget` implements `HasSchemas` and not `HasActions` (`vendor/filament/widgets/src/ChartWidget.php`). `TableWidget` is the widget that adds those two lines (`vendor/filament/widgets/src/TableWidget.php`). The vendor chart blade opens `afterHeader` only when `$filters` or `getFiltersSchema` exist (`vendor/filament/widgets/resources/views/chart-widget.blade.php`). This card's date toggle is page state, not a chart filter, so a local blade is the hook that exists. There is no chart render hook; `WidgetsRenderHook` is table-only. |
| **AC-2** One row per VIN that makes up the chart data | One row per `(forwarder_org_id, agent_org_id, vin)` that passes `db_repos`, not distinct VIN, not the sum of the 13 bars | Covered in the query design. `db_repos` is `uniqExactIf` of that triple (`app/Clickhouse/Queries/LprRepossessionsQuery.php`). `BillingClaimDedup::dedupedCteSql()` groups by bucket plus the triple, so a `last_7d` window can emit two `deduped` rows for one triple. Grouping the listing by the triple only, with `HAVING countIf(<pred>) > 0`, is the row-level restatement of `uniqExactIf`. The bucket-straddle test is the right guard. |
| **AC-3** VIN, Plate, RDN Case Number, Claimed By, Repo Agent Name | Sources match prep. Repo Agent Name stays blank | Sources are right. The path that writes the CSV does not select them. See change 1. |
| **AC-4** Current chart filters | Click-time `$this->date` and `auth()->user()->org_id`. No client picker | Covered. Either-side org predicate (`org_id = {org} OR agent_org_id = {org}`, null → 0) matches the chart. The two calendars match `getCardTotal()`, not `headlineWindow()`. See change 4 for the end bound and the test that has to lock it. |
| **AC-5** `lpr-repossession-export-YYYY-MM-DD.csv` | `fileName` without an extension, date at click time | Covered. `CsvDownloader` appends `.csv` (`vendor/filament/actions/src/Exports/Downloaders/CsvDownloader.php`). `getFileName()` runs in the Livewire action before the job is queued (`vendor/filament/actions/src/Concerns/CanExportRecords.php`). |

First Glance comment 42732 (Ready, main→main, CSV) matches the draft's hard stops. No new question gate. Blank Repo Agent Name is the acceptance criteria's "when available" rule.

## 2. Repo patterns

**Exporter / CSV / `{high}` / `s3`.** All five house exporters are `ExportFormat::Csv`, `getFileDisk(): 's3'`, `getJobQueue(): '{high}'`. `RecoveryLeaderboardExporter` is the right mirror, and the `LeaderboardTableWidget` call site is the right mirror for `hiddenLabel()`, the tray icon, the tooltip, `columnMapping(false)`, `modal(false)`, and `Analytics::track('export', …)` in `after()`. Follow the acceptance criteria for the filename. The leaderboard puts the export id in the name (`LeaderboardTableWidget.php`).

Do not copy that call site's `hidden(fn () => $this->getTable()->getRecords()->count() === 0)`. It runs a count while the widget renders. `test_exactly_one_round_trip_per_seam_across_all_four_filter_states` fails if any new query runs during render. `->visible(fn () => $this->expanded)` does not.

**ChartWidget action.** Sound. Subclass implements `HasActions` and uses `InteractsWithActions`. A class-property `$view` points at a copy of the vendor chart blade with `{{ $this->exportAction }}` in `afterHeader`. The card stays a chart. No other card changes. `Dashboard::expandedCardComponents()` is the only mount that sets `expanded` (`app/Filament/Pages/Dashboard.php`). The compact path builds a `Stat` through `toStat()` and never mounts the Livewire view, so the button cannot show on the grid.

`CanExportRecords` is why a model exists: a component that is not `HasTable` starts from `$exporter::getModel()::query()` (`CanExportRecords.php`). A `ClickhouseModel` whose FROM is `modifyQueryUsing(fromRaw(rowsSql))` is a new model with no physical table. That is justified by that branch, and the docblock should say the table is query-supplied. It is not justified as a second query API beside `rows()`.

**Listing versus aggregate.** The shared WITH/join body, with the aggregate `$select` / `$groupBy` left as they are and a golden assertion that `windowSql()` / `monthlySeriesSql()` stay byte-identical, is the right split. `normalizeRow()` still returns only `{deduped, db_repos}`. The listing is a new method.

**Cache key safety.** Sound. `sharedMonthlySeriesCacheKey` carries `array<'Y-m-01', {deduped, db_repos}>`. `sharedWindowCacheKey` carries an int `db_repos`. The draft writes neither. A later listing cache needs its own key and its own `-v1` suffix. The draft's cache paragraph cites `LprRepossectionsQuery.php`. The class is `LprRepossessionsQuery`. The line numbers match the real file.

## 3. Preflight #24 — smallest change

The file list is small: one query method, one exporter, one query-supplied model, one card blade, the widget action, tests. No new job hierarchy, no `TableWidget` conversion, no client picker, no second card.

Two recommendations in the execution section are the wrong size or the wrong mechanism:

- `maxRows(10000)` does not make one chunk. See change 2.
- A `cityHash64` primary key does not survive the ClickHouse HTTP client's JSON decode. See change 3.

The `->job()` escape hatch (one execution, a `PrepareCsvExport` subclass plus an `ExportCsv` subclass that skips `find()`) is a real Filament seam (`CanExportRecords::job()`). It restates vendor progress bookkeeping. Keep it as the hatch for after a fleet-twin measurement, once change 1 says which statement that hatch would run. Do not start there.

The synchronous `getCountForPagination()` inside the Livewire request is real (`CanExportRecords.php`, before the batch is dispatched). The card's own notes put a one-month read at the same ~584M rows as the series, 6.00s median, 33.7s observed tail, against kamal-proxy's 30s default (`LprRepossessions.php`, `COLD_BLOCK_BUDGET_SECONDS`). A click can 504. It does not run from `getData()` or `toStat()`. That hazard is correctly named and correctly left to measurement.

## 4. Preflight #25 — parity

`count(rows) === window()['db_repos']` on one fixture, with `db_repos > 0` first, is necessary and matches the drift-alarm lesson in `LprRepossessionsQueryTest` (the precondition is on the claims `deduped` stage; the lesson is the same). The triple-not-VIN case, the hotlist fan-out collapse, the `claim_date - 7` boundary, the `agent_org_id = 0` and `h.id = 0` sentinels, and the bucket-straddle case are the right membership tests. `HAVING countIf(<pred>) > 0` is the membership rule. `argMinIf` only chooses which hotlist decorates the row.

That suite does not yet prove the default filter. `getCardTotal()` reads `currentPeriodTotal()` → `window()` for `today` and `last_7d`, and the last or previous bucket of `getMonthlyData()` for `this_month` and `last_month` (`DashboardWidget::getCardTotal()`, `LprRepossessions::getMonthlyData()`). The series is UTC `toStartOfMonth` over `monthlySeriesSql()`'s span: `endOfMonth()` back 12 months (`LprRepossessionsQuery::monthlySeriesSql()`). `headlineWindow()` and `resolveCardRange()` use viewer-local month bounds, and `resolveCardRange()`'s `this_month` arm ends at viewer `endOfDay()`, not UTC `endOfMonth()`.

`event_date` on the billing view is `toDate(fromUnixTimestamp(ts), 'UTC')` (`database/schema/clickhouse-schema.sql`, `billing_claim_events_mv`). A tight UTC calendar month therefore matches that month's series bucket, provided the listing window ends at the same UTC `endOfMonth()` the series uses. The draft says `startOfMonth()` and does not spell the end. Change 4 asks for that bound and for an automated assert against the bucket, which is the number on the chart. The live check (headline versus CSV line count minus the header, for `this_month` and `last_7d`) stays.

## 5. Provisionability

Fleet Docker ClickHouse is the right twin. The listing reads `clarity.billing_claim_events_v`, `zod.public_hotlist`, `clarity.hotlist`, and, for decoration, `zod.hotlist_json_denormalized` and `zod.public_orgs`. Tests already seed `clarity.billing_claim_events` (the view is `FINAL` over that table), `zod.public_hotlist`, `zod.public_hotlist_status_log`, `clarity.hotlist`, and `zod.public_orgs`. Status log is a fixture input, not a column the listing reads (`first_repossessed_at` is the recoveries timestamp). Add `zod.hotlist_json_denormalized` to the twin because the case-number lookup is new.

No Mac-only step. Local and fleet QA use the literal `clarity` and `zod` database names. Cloud ClickHouse stays read-only and is only a cost measure if the twin cannot produce a realistic row count. This review did not query it.

`provisioned` in `state.json` is still false. That is correct for this phase. Agent QA is where the twin comes up. Screenshots stay later (preflight #27).

The first twin check, before debating three executions versus one, is whether a `fromRaw` CTE survives `EloquentSerializer`, `getCountForPagination()`, `orderedChunkById`, and `find()`. The house proof that Filament export runs on this driver is `RecoveryKitLeaderboard`: a real table, `kit_id as id`, grouped, from a `HasTable`. A query-supplied CTE is a step past that. `LaravelClickhouseEloquent\Builder::chunk()` is offset pagination and re-runs the statement per page. `PrepareCsvExport` uses that `chunk()` when the query has a non-key `ORDER BY`, and `orderedChunkById` (`chunkSize * 10`) otherwise. Leave the export query unordered, or ordered only by its key, or the "three executions" arithmetic does not apply.

On a sync queue the chain runs inside the request and the started notification is suppressed (`CanExportRecords.php`). Judge that notification on `{high}`, not on the local sync default.

## 6. Hard stops and risks

| Stop | Draft | Review |
| --- | --- | --- |
| Aggregate `uniqExactIf`, dedup triple, `min(claim_date)`, `- 7`, `{floor}`, either-side org, `FINAL` on `public_hotlist`, no `FINAL` on `clarity.hotlist` | Untouched; golden SQL test | Holds, as long as the shared-body extraction keeps `windowSql()` / `monthlySeriesSql()` byte-identical. Adding `plate` to the shared `h` subquery would fail that test. A later lookup can select plate without moving the triple set. |
| `deduped` versus `db_repos` | Export is `db_repos` | Holds. |
| Shared cache payload contracts | Untouched | Holds. |
| Excel | Not offered | Holds. Five house exporters are CSV-only. Comment 42732 prefers CSV. |
| `remote_order_id` as case number | Rejected | Holds. `hotlist_json_denormalized.case_number` is `Nullable(String)`, `ORDER BY hotlist_id`, engine `ReplacingMergeTree(_peerdb_version)` (`clickhouse-schema.sql`). The MV writes `JSONExtractString(json, 'case_number')`, so a missing key is `''`. Render blank for `''` and for NULL. `FINAL` plus `_peerdb_is_deleted = 0` are both required: this engine does not take the deleted-flag constructor argument that `public_hotlist` and `public_orgs` use. |
| `org_meta.properties.rdn_agents` | Not joined. Repo Agent Name column stays blank | Holds. It is an org-level SOAP cache, not a per-VIN name. |
| `UniqueLprCaseClaims` and every other card | Untouched | Holds. |
| Cloud writes, fleet/Herd/DB mutation, scout base, `okf/` during the plan | Out of scope | Holds. Wiki queue stays queued. |

`argMinIf(h.id, ch.first_repossessed_at, <pred>)` picks one qualifying hotlist. It is stable when that timestamp is unique. A tie can still change Plate between two exports of the same window. Tie-break on `(first_repossessed_at, h.id)` if the files must match. Membership does not depend on it.

Soft collisions (DEV-2660, DEV-2659, DEV-2737) are inherited. main→main. No scout branch.

The click-time count remains the largest operational risk. It is a measurement gate, not a reason to invent a new export stack before the fleet twin says the stock path misses the proxy budget.

## Changes requested

1. **Put the five columns on the statement `ExportCsv` re-runs.** `ExportCsv::handle()` writes `$query->find($ids)` (`vendor/filament/actions/src/Exports/Jobs/ExportCsv.php`). The draft's exporter sets that query to `fromRaw(rowsSql())`. The draft's decoration (plate, `case_number`, org name) is a second PHP round trip after a heavy pass, described on `rows()`, and the inner listing projects only the triple plus `hotlist_id`. `rows()` is what the parity tests call. The CSV writer never calls it. As written, the file can be triples with empty Plate, case number, and Claimed By while the query tests stay green.

   Pick one and make it the only design. Either `rowsSql()` is the statement `find()` runs and it projects VIN, plate, case number, Claimed By, and a blank Repo Agent Name (decoration joins after the `GROUP BY`, so they cannot change which triples `HAVING` keeps), or the single-execution `->job()` path is primary and the PHP lookup runs inside that job on the one result set. The "three extra FINAL scans" alternative is the stock-pipeline design, not a fallback, until a custom job is the one that writes the file. A second round trip whose ids exist only in a PHP array is invisible to `find()`.

2. **Drop `maxRows(10000)` as the way to get one chunk.** `getMaxRows()` below `totalRows` fails the action and does not start the export (`CanExportRecords.php`). `chunkSize` is what splits `ExportCsv` jobs. The query docblock records a measured platform month at `db_repos` 9149 (`LprRepossessionsQuery.php`). A cap of 10000 sits on top of that month and will reject a larger headline, which breaks AC-2 and the parity gate. Omit `maxRows`, or set a fail-closed ceiling that a real headline cannot reach, and choose `chunkSize` on its own.

3. **Do not use `cityHash64(...)` as the Eloquent key.** The driver requests `FORMAT JSON` and `json_decode`s it without `JSON_BIGINT_AS_STRING` (`vendor/insight-lpr/laravel-clickhouse-eloquent/src/ClickhouseHttpClient.php`). `cityHash64` is a UInt64. Values above `PHP_INT_MAX` become imprecise floats. `PrepareCsvExport` plucks those keys and `find()` looks them up. Rows disappear and the count still matches, so the started notification lies. `RecoveryKitLeaderboard` works because `kit_id` is a small integer. Use a string key of the triple (`forwarder_org_id`, `agent_org_id`, `vin`). It is unique, stable across the three executions, and it round-trips through JSON.

4. **Lock the month window to the series bucket.** For `this_month` and `last_month`, the listing bounds are UTC `startOfMonth()` through `endOfMonth()` (minus one month for `last_month`), the same end `monthlySeriesSql()` uses, in `SERIES_ZONE`. Not `headlineWindow()`, and not `resolveCardRange()`. The automated parity assert for those two filters is `count(rows(...)) === monthlySeries(...)[bucket]['db_repos']`, with the count greater than zero. `count(rows) === window()` on the same bounds stays as the listing-versus-unbucketed check. `today` and `last_7d` keep the viewer-local `resolveCardRange()` re-anchored in `viewerTimezone()` before UTC conversion (`LprRepossessions::getModelQueryForDates()`). The filename closure should call `$this->viewerTimezone()` (the draft's `$viewerZone` is not a property).

## Nits

- The tests section names `LprRepossectionsTest.php` and `LprRepossectionExporterTest.php`. The touch list names the real files: `tests/Filament/Widgets/Dashboard/LprRepossessionsTest.php` and `tests/Filament/Exports/LprRepossessionExporterTest.php`. Edit the existing widget test. Do not add a misspelled class.
- The same misspelling appears as `LprRepossections implements HasActions` and `LprRepossectionsQuery.php`. The file touch list is the one to follow.
- The optional exporter test is worth writing for the column names, order, CSV-only formats, disk `s3`, queue `{high}`, filename shape, and blank Repo Agent Name. `@codeCoverageIgnore` on the other five exporters is not a reason to skip a column contract.
- `public function exportAction(): ExportAction` so the blade can call it.

## Verdict

**REQUEST CHANGES.**

Grain, the two calendars, cache-key refusal, the expanded-only chart action, the house CSV/`s3`/`{high}` exporter, blank Repo Agent Name, and the fleet-twin provision note are sound. Implementation waits on a revised execution section that puts AC-3 on the query the exporter actually re-runs, stops treating `maxRows` as a chunk size, replaces the `cityHash64` key, and asserts month parity against the UTC series bucket.