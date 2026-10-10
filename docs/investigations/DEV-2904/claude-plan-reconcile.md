# DEV-2904 — Ultra-Plan reconcile

## Verdict

**APPROVE-with-adopted-changes.** All four Changes requested are **adopted** — each is backed by a live cite I re-read in this session, and none of them is contradicted by anything in the draft. Change 1 is the material one: it moves AC-3 from a PHP decoration pass that `ExportCsv` never calls onto the statement it does re-run. Changes 2–4 are corrections to one paragraph (`maxRows`, the `cityHash64` key, the month end bound) and a filename accessor. All four Nits are adopted. The draft's grain, calendars, cache-key refusal, chart-action placement, hard stops, and provision note stand unchanged. No park.

## Changes requested

### Change 1 — Put the five columns on the statement `ExportCsv` re-runs
- **Decision: adopt**
- **Why:** `ExportCsv::handle()` unserializes the stored query and writes `foreach ($query->find($this->records) as $record)` (`vendor/filament/actions/src/Exports/Jobs/ExportCsv.php:77,84`). The exporter's query is `fromRaw(rowsSql())`, so whatever `rowsSql()` does **not** project does not exist on the record the CSV writer receives. The draft's inner listing projects only `forwarder_org_id, agent_org_id, vin, hotlist_id` (draft lines 60–67) and resolves Plate / case number / Claimed By in three follow-up statements described on `rows()` (lines 77–86). `rows()` is what the parity tests call; the CSV writer never does. The failure mode is exactly as the review states — green query tests over a file with three empty AC-3 columns. The draft itself already named the single-statement join form as "viable and simpler to read" (line 88); the review is right that it is the *design*, not a fallback, as long as the stock pipeline writes the file. Per preflight #24 this is also the smaller change: projecting columns beats subclassing two vendor jobs.
- **Plan delta:** `rowsSql()` becomes the one export statement and projects `vin`, `plate`, `case_number`, `claimed_by`, and a literal-empty `repo_agent_name`, with the three decoration joins applied **after** the `GROUP BY ... HAVING countIf(<pred>) > 0` so the triple set is provably unmoved. `rows()` stays as the PHP-side wrapper the parity tests call, over that same SQL — one statement, two callers, no second API. Drop the "second round trip, not a third join" section and its literal-id injection; keep its reasoning as a one-line note on why the joins sit outside the heavy pass. Keep `->job()` only as a measured escape hatch (see Change 2 delta), and record that the hatch would now run this same projecting statement.

### Change 2 — Drop `maxRows(10000)` as the way to get one chunk
- **Decision: adopt**
- **Why:** `CanExportRecords` evaluates `$maxRows = $action->getMaxRows() ?? $totalRows;` and, when `$maxRows < $totalRows`, raises a danger `failureNotification` and never dispatches the batch (`CanExportRecords.php:218–228`). It is a refusal, not a chunker; `chunkSize` is the separate knob (`CanExportRecords.php:51,481–483`). The query's own docblock records a measured platform month at `db_repos` 9149 (`LprRepossessionsQuery.php:57–58`), so a 10000 cap sits ~9% above a *measured* headline — one busier month fails the action outright and takes AC-2 and the parity gate with it. The draft conflated the two knobs; nothing in it defends the cap on its own merits.
- **Plan delta:** Omit `maxRows` entirely (or, if a fail-closed ceiling is wanted, set it far above any real headline — e.g. 250k — and say in the plan that it is a runaway guard, not a chunk size). Choose `chunkSize` independently on the "how many `ExportCsv` executions" axis. Restate the execution budget as: 1 synchronous `getCountForPagination()` + 1 key pass + ⌈rows ÷ chunkSize⌉ `ExportCsv` runs, and note the review's constraint that the export query must be unordered or ordered only by its key, or `PrepareCsvExport` falls to offset `chunk()` and the arithmetic changes.

### Change 3 — Do not use `cityHash64(...)` as the Eloquent key
- **Decision: adopt**
- **Why:** The driver appends `FORMAT JSON` and calls `json_decode($response, true)` with no `JSON_BIGINT_AS_STRING` (`vendor/insight-lpr/laravel-clickhouse-eloquent/src/ClickhouseHttpClient.php:44–45`). `cityHash64` is UInt64; values above `PHP_INT_MAX` decode as floats and lose the low bits. `PrepareCsvExport` plucks those keys and `ExportCsv` feeds them to `find()` (`ExportCsv.php:84`), so a mangled key silently matches nothing — rows vanish from the file while `total_rows` (set from the count, `CanExportRecords.php:212,276`) still reports the full number. That is a silent-wrong-output bug, the worst class for an export whose whole gate is parity. `RecoveryKitLeaderboard` is not a counter-example: its key is a small int.
- **Plan delta:** Key is a stable **string** `concat(toString(forwarder_org_id),'|',toString(agent_org_id),'|',vin) AS id`, with `$keyType = 'string'` and `$incrementing = false` on `LprRepossessionExportRow`. Add a test that the key round-trips: pluck ids from the listing, `find()` them back, assert the same row count. Note in the plan that this is a general trap for any UInt64 projected as a key on this driver.

### Change 4 — Lock the month window to the series bucket
- **Decision: adopt**
- **Why:** The draft got the calendar split right but left the end bound unspecified, saying only `now('UTC')->startOfMonth()` (line 99). The bar the user is looking at for `this_month`/`last_month` comes from `getCardTotal()` → `getMonthlyData()` buckets (`DashboardWidget.php:362–366`), and the series spans `$through->clone()->utc()->endOfMonth()` back twelve months (`LprRepossessionsQuery.php:132–133`) with `SERIES_ZONE = 'UTC'` (`LprRepossessions.php:84`). `resolveCardRange()`/`headlineWindow()` are viewer-local throughout (`DashboardWidget.php:161,173,240,278,386`), and the `this_month` arm ends at a viewer day boundary, not a UTC month end. An unpinned end bound is the same seam failure the draft was trying to avoid, one layer down. The review's parity assert is also strictly stronger: comparing against the bucket compares against *the number on the chart*.
- **Plan delta:** For `this_month`/`last_month`, bounds are UTC `startOfMonth()` → `endOfMonth()` (minus one month for `last_month`), matching `monthlySeriesSql()`. `today`/`last_7d` keep viewer-local `resolveCardRange()` re-anchored in `viewerTimezone()` before UTC conversion. Parity tests become two asserts: `count(rows(...)) === monthlySeries(...)[bucket]['db_repos']` for the month filters (with the bucket > 0 precondition), and `count(rows(...)) === window(...)['db_repos']` on identical bounds as the listing-vs-unbucketed check. Filename closure uses `$this->viewerTimezone()` — a method on the widget, not the draft's `$viewerZone`.

## Nits

### Nit 1 — Misspellings (`LprRepossections*`)
- **Decision: adopt**
- **Why:** Draft lines 107, 132, 153, 160 carry `LprRepossections`; the file touch list (lines 23–43) has the real names. A plan that names a non-existent test class invites a *new* misspelled file beside the existing one.
- **Plan delta:** Normalize every reference to `LprRepossessionsQuery`, `LprRepossessions`, `tests/Filament/Widgets/Dashboard/LprRepossessionsTest.php`, `tests/Filament/Exports/LprRepossessionExporterTest.php`. State explicitly that the widget test is **edited**, not created.

### Nit 2 — Write the optional exporter column-contract test
- **Decision: adopt**
- **Why:** AC-3 *is* a column contract — names, order, and the deliberately blank Repo Agent Name. The draft already argued for it and only hedged on the five existing exporters' `@codeCoverageIgnore` (line 160); coverage annotations are not an argument against asserting an acceptance criterion. Cheap, static, no ClickHouse.
- **Plan delta:** Promote `tests/Filament/Exports/LprRepossessionExporterTest.php` from optional to required: column names/labels/order, `ExportFormat::Csv` only, disk `s3`, queue `{high}`, filename shape, blank Repo Agent Name.

### Nit 3 — `public function exportAction(): ExportAction`
- **Decision: adopt**
- **Why:** `{{ $this->exportAction }}` in the blade resolves through the component's public surface; a protected method would not be reachable. Free correctness.
- **Plan delta:** Declare it public with an `ExportAction` return type.

## Adopted summary

- `rowsSql()` is the single export statement and projects VIN, Plate, RDN case number, Claimed By, blank Repo Agent Name — decoration joins **after** `GROUP BY`/`HAVING`; delete the PHP second-round-trip design; `rows()` becomes a wrapper over the same SQL.
- `->job()` demoted to a documented escape hatch, taken only on fleet-twin timing evidence; note it would run the same projecting statement.
- Remove `maxRows(10000)`; pick `chunkSize` on its own; keep the export query unordered or key-ordered so `orderedChunkById` applies.
- Key is `concat(forwarder_org_id,'|',agent_org_id,'|',vin) AS id`, string, non-incrementing; add a key round-trip test.
- `this_month`/`last_month` = UTC `startOfMonth()`→`endOfMonth()` matching `monthlySeriesSql()`; `today`/`last_7d` = viewer-local `resolveCardRange()`; parity asserts against the **series bucket** plus the window check; filename uses `$this->viewerTimezone()`.
- Fix all `LprRepossections*` misspellings; edit the existing widget test.
- Exporter column-contract test required, not optional.
- `public function exportAction(): ExportAction`.
- Carry forward the review's two extras: tie-break `argMinIf` on `(first_repossessed_at, h.id)` so two exports of one window agree on Plate; first fleet-twin check is whether a `fromRaw` CTE survives `EloquentSerializer` → `getCountForPagination()` → `orderedChunkById` → `find()`, before any three-vs-one execution debate.

## Rejected summary

(none)

## What stands from the draft

- **Grain.** Triple-not-VIN, fan-out collapse via `GROUP BY`, group key excludes bucket, `HAVING countIf(<pred>) > 0` as the row-level restatement of `uniqExactIf` — and the bucket-straddle test as its guard.
- **Listing vs aggregate split.** Shared WITH/join body extracted; aggregate `$select`/`$groupBy` byte-identical, pinned by a golden SQL assertion. Do not add `plate` to the shared `h` subquery.
- **Cache-key refusal.** Neither shared key written; a future listing cache takes its own `-v1`.
- **Expanded-only chart action.** `HasActions` + `InteractsWithActions`, class-property `$view`, card-local blade, `->visible(fn () => $this->expanded)`. Do **not** copy the leaderboard's render-time `hidden(... ->count() === 0)`.
- **House exporter shape.** CSV only, `s3`, `{high}`, `RecoveryLeaderboardExporter` as mirror; `hiddenLabel()`, tray icon, tooltip, `columnMapping(false)`, `modal(false)`, `Analytics::track('export', …)`.
- **Blank Repo Agent Name**; `remote_order_id` rejected; `org_meta.rdn_agents` not joined.
- **Query-supplied model** justified solely by the `CanExportRecords` non-`HasTable` branch, with the docblock saying so.
- **Fleet Docker CH twin** as the provision target (add `zod.hotlist_json_denormalized`), literal `clarity`/`zod` names, Cloud CH read-only, `provisioned: false` correct for this phase, screenshots later.
- **Sync `getCountForPagination()` 504 hazard** named and left to measurement; judge the started notification on `{high}`, not the local sync default.
- Hard stops, soft collisions (DEV-2660 / DEV-2659 / DEV-2737), `main → main`, wiki items stay queued.

## Open questions

None. The one undecided item — stock pipeline vs. `->job()` — remains a fleet-twin measurement, now with Change 1 fixing which statement either path runs.

## Gate

CLEAN
