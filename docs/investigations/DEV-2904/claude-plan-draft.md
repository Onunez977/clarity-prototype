## Summary

DEV‑2904 adds a CSV export to the **LPR Repossessions** bar chart. The chart today resolves through `LprRepossessionsQuery::window()` / `monthlySeries()`, both of which return only `{deduped, db_repos}` integers — there is no row‑listing path anywhere in that class. The work is therefore: one new *listing* statement that reproduces the exact set `uniqExactIf((forwarder_org_id, agent_org_id, vin), …)` counts for the headline window, a house‑shaped `Exporter`, and an `ExportAction` on the **expanded** chart (which needs `HasActions` + a local chart blade, because `Filament\Widgets\ChartWidget` has no action hook). The chart's aggregate SELECT, the dedup triple, the backyard `- 7` predicate, and the two shared cache keys are not touched.

---

## AC → implementation mapping

| AC | Implementation | Proof read |
| --- | --- | --- |
| **AC‑1** Export button on the bar chart, CSV | `ExportAction` in a new `afterHeader` slot on a card‑local chart blade; `LprRepossessions implements HasActions` + `use InteractsWithActions` — exactly what `Filament\Widgets\TableWidget` adds over `Widget` | `vendor/filament/widgets/src/TableWidget.php:5-17`; `vendor/filament/widgets/resources/views/chart-widget.blade.php:19-67` |
| **AC‑2** One row per VIN making up the chart data | New `LprRepossessionsQuery::rows()` — one row per `(forwarder_org_id, agent_org_id, vin)` triple that satisfies the `db_repos` predicate for the headline window | `app/Clickhouse/Queries/LprRepossessionsQuery.php:178-179` |
| **AC‑3** VIN, Plate, RDN Case Number, Claimed By, Repo Agent Name | `LprRepossessionExporter::getColumns()`; VIN from `deduped.vin`, Plate from `zod.public_hotlist.plate`, case number from `zod.hotlist_json_denormalized.case_number`, Claimed By from `zod.public_orgs.name` for `agent_org_id`, Repo Agent Name **blank** | `database/schema/clickhouse-schema.sql:48,59,70,102,105` |
| **AC‑4** Respects current chart filters | The action closure reads `$this->date` and `auth()->user()->org_id` **on the widget at click time**; no client picker exists on this card and none is added | `app/Filament/Widgets/Dashboard/LprRepossessions.php:233,357-378` |
| **AC‑5** `lpr-repossession-export-YYYY-MM-DD.csv` | `->fileName(fn (Export $export): string => 'lpr-repossession-export-'.now($viewerZone)->format('Y-m-d'))`; the `.csv` suffix is appended by the format writer, which is why house call sites pass names without an extension | `app/Filament/Widgets/KitUserRecoveries/LeaderboardTableWidget.php:74` |

---

## File touch list

**Edit**

1. `app/Clickhouse/Queries/LprRepossessionsQuery.php`
   Add `rows(?int $orgId, CarbonInterface $start, CarbonInterface $end): array` + `rowsSql()`. Extract the shared `WITH claims … deduped … FROM deduped AS d LEFT JOIN … LEFT JOIN …` body into one private method so the listing and the aggregate cannot drift; the aggregate's `$select` / `$groupBy` strings (lines 177‑180) stay byte‑identical. Reuse `buildSql()`'s substitution map (`{db}`, `{clarity_db}`, `{org}`, `{f}`, `{t}`, `{d_from}`, `{d_to}`, `{event_type}`, `{floor}`) rather than re‑deriving placeholders.

2. `app/Filament/Widgets/Dashboard/LprRepossessions.php`
   `implements HasActions`, `use InteractsWithActions`, a `$view` pointing at the card‑local blade, and one `exportAction(): ExportAction` method. No change to `getData()`, `toStat()`, `getMonthlyData()`, `currentPeriodTotal()`, `getCardQueryStatements()`, or any guard constant.

3. `tests/Feature/Clickhouse/LprRepossessionsQueryTest.php` — add listing + parity cases (reuses the existing `seedOrg/seedClaim/seedHotlist/seedStatusLog/seedClarityHotlist` helpers, lines 541‑657).

4. `tests/Filament/Widgets/Dashboard/LprRepossessionsTest.php` — add action‑surface cases. **Watch `test_exactly_one_round_trip_per_seam_across_all_four_filter_states` (line 126):** it will fail if any new query path runs during render, which is the guard that keeps the export off the render path.

**Create**

5. `app/Filament/Exports/LprRepossessionExporter.php` — house shape (`ExportFormat::Csv` only, `getFileDisk(): 's3'`, `getJobQueue(): '{high}'`, `getJobBatchName()`, `getCompletedNotificationBody()`), mirroring `RecoveryLeaderboardExporter`.

6. `app/Clickhouse/Models/LprRepossessionExportRow.php` — a `ClickhouseModel` on `clarity_clickhouse` with no physical table; the FROM is supplied by `modifyQueryUsing()` as `fromRaw('('.LprRepossessionsQuery::rowsSql(…).') AS lpr_repo_export')`. Required because `CanExportRecords` does `$exporter::getModel()::query()` for a non‑`HasTable` Livewire component. Docblock must say the table is query‑supplied, so a reader does not go looking for it in the schema dump.

7. `resources/views/filament/widgets/dashboard/lpr-repossessions.blade.php` — a copy of the vendor chart‑widget view with the export action added to the `afterHeader` slot. Card‑local so no other dashboard chart changes.

**Create (optional, see Tests)**

8. `tests/Filament/Exports/LprRepossessionExporterTest.php`

---

## Query / export design

### Grain

`db_repos` is `uniqExactIf((d.forwarder_org_id, d.agent_org_id, d.vin), toDate(ch.first_repossessed_at,'UTC') >= d.claim_date - 7)`. Three properties follow, and all three are parity‑load‑bearing:

- **Distinct VIN is wrong.** The same VIN under a second agent org increments the chart; a VIN‑distinct listing would be shorter than the bar.
- **The LEFT JOINs fan out.** A triple can match several `h` rows (same `org_id` + `vin`, different hotlist ids). `uniqExactIf` collapses that; a flat `SELECT … WHERE <pred>` would emit duplicates. The listing must group.
- **The group key is the triple only — not the bucket.** `BillingClaimDedup::dedupedCteSql()` groups by `(bucket, forwarder, agent, vin)`, but the unbucketed aggregate's `uniqExact` key has no bucket. For `last_7d` spanning a month boundary, one triple produces two `deduped` rows and still counts once. Grouping the listing by `(bucket, …)` would over‑count exactly there.

Listing inner pass:

```
SELECT d.forwarder_org_id, d.agent_org_id, d.vin,
       argMinIf(h.id, ch.first_repossessed_at, <pred>) AS hotlist_id
FROM deduped AS d
LEFT JOIN (… public_hotlist FINAL, status = 3 …) AS h  ON …   -- unchanged
LEFT JOIN (… clarity.hotlist, no FINAL …)        AS ch ON …   -- unchanged
GROUP BY d.forwarder_org_id, d.agent_org_id, d.vin
HAVING countIf(<pred>) > 0
```

`HAVING countIf(<pred>) > 0` is the row‑level restatement of `uniqExactIf` and is where parity actually comes from. `argMinIf` picks the **earliest qualifying** repossession deterministically; `anyIf` would be cheaper and non‑deterministic, which would make two exports of the same window disagree on Plate.

Both join subqueries keep their current SELECT lists (`id, org_id, vin` and `id, first_repossessed_at`) — the inner pass needs no new column and no new table, so the set of triples that pass cannot move.

### Decoration columns — second round trip, not a third join

Plate, case number, and org name are resolved on the **small** result, not inside the heavy pass:

```
SELECT id, plate       FROM {db}.public_hotlist FINAL
  WHERE _peerdb_is_deleted = 0 AND id IN (<literal ids>)
SELECT hotlist_id, case_number FROM {db}.hotlist_json_denormalized FINAL
  WHERE _peerdb_is_deleted = 0 AND hotlist_id IN (<literal ids>)
SELECT id, name       FROM {db}.public_orgs FINAL
  WHERE _peerdb_is_deleted = 0 AND id IN (<literal agent org ids>)
```

Rationale: all three tables are `ORDER BY id` / `ORDER BY hotlist_id`, so joining them as uncorrelated subqueries inside the heavy statement adds three full‑table `FINAL` scans with no prune. Literal‑id injection on a bounded set is the house answer to that shape. `hotlist_json_denormalized` is `ReplacingMergeTree(_peerdb_version)` with an `Int8 _peerdb_is_deleted`, so `FINAL` + the tombstone predicate are both required; `case_number` is `Nullable(String)` fed by a `JSONExtractString` MV, i.e. **empty string when the key is absent** — render blank, do not substitute `remote_order_id`.

Alternative (single statement, three extra full‑table `FINAL` scans) is viable and simpler to read; it is the fallback if measurement shows the second round trip is not worth it.

### Window resolution — the parity trap

The card runs on **two calendars**, and the export must follow `getCardTotal()`, not `headlineWindow()`:

| Filter | Window the chart reads | Source |
| --- | --- | --- |
| `today`, `last_7d` | **viewer‑local** `resolveCardRange()`, re‑anchored in `viewerTimezone()` before UTC conversion | `LprRepossessions.php:386-395` |
| `this_month`, `last_month` | **UTC calendar month bucket** of the last / previous series point | `LprRepossessions.php:225-260`, `monthlySeriesSql():130-136` |

`DashboardWidget::headlineWindow()` and `resolveCardRange()` return **viewer‑local** month bounds for `this_month`/`last_month`. Using either for the export would hand a Chicago viewer a window offset 5–6 hours from the bar they are looking at, and parity would fail by however many claims land in that seam. The export must re‑derive `this_month`/`last_month` as `now('UTC')->startOfMonth()` (minus one month), matching `SERIES_ZONE`.

### Org scope

`auth()->user()->org_id`, passed straight through to `{org}`, which matches on **either** side (`org_id = {org} OR agent_org_id = {org}`). `null` → `0` → platform‑wide, same as the chart. No client filter: this card has none, and AC‑4's "client" clause has no control to respect here.

### Cache safety

Nothing is written to `lpr-repossessions-funnel:monthly-series-v1` or `…:window-v1`. Both carry explicit payload contracts (counts only) whose violation is documented as silently corrupting every other reader (`LprRepossectionsQuery.php:239-249, 259-273`). The export reads its own statement and caches nothing. If a listing cache is ever wanted, it takes a new key with its own `-v1` suffix.

### Async / execution budget

The card's measured envelope is ~584M read_rows and ~2.22 GB peak for a one‑month window, 6.00s median, 33.7s observed tail (`LprRepossessions.php:29-35, 86-99`). The listing reads the same sources, so each execution costs roughly the same. Filament's export pipeline is **key‑based**: `PrepareCsvExport` plucks keys and `ExportCsv` re‑runs `$query->find($chunk)` (`vendor/filament/actions/src/Exports/Jobs/ExportCsv.php:84`). Executions per export therefore scale with chunk count:

- `getCountForPagination()` — **1, synchronous, inside the Livewire request** (`CanExportRecords.php:212`)
- `PrepareCsvExport` key pass — 1 when `chunkSize * 10` exceeds the row count
- `ExportCsv` — one per chunk

**Recommendation:** keep the stock pipeline (prep: "runs on the existing exporter job"; preflight #24) and size `chunkSize` so a headline window is one chunk — e.g. `chunkSize(10000)` with `maxRows(10000)`, giving **3 executions** total, ~6.6 GB, against the 13.18 GB the card's own 13‑month series already costs on a cold render. The model needs a stable key for `find()`: `cityHash64(concat(toString(forwarder_org_id),'|',toString(agent_org_id),'|',vin))` as `id`, deterministic across executions.

**Named risks, to be settled by measurement in Agent QA, not now:**

- The synchronous count runs in the request against kamal‑proxy's 30s default (`COLD_BLOCK_BUDGET_SECONDS`'s own derivation, line 113‑123). A 33.7s‑tail count is a 504. It is click‑triggered, not render‑triggered, so it does not affect dashboard load — but it is the single largest hazard in this plan.
- If measurement rejects 3 executions or the sync count, the escape hatch is Filament's own `->job()` seam: a `PrepareCsvExport` subclass that runs the listing **once** (window/org carried in `->options()`, which is serialized into every job payload) plus an `ExportCsv` subclass that hydrates non‑persisted model instances from row payloads instead of calling `find()`. That is **1** execution, at the cost of restating ~25 lines of vendor `ExportCsv::handle()` progress bookkeeping. Do not reach for it before the measurement says so.

Either way the listing never runs inside `getData()`, `toStat()`, or `getCardTotal()`.

---

## Chart action placement (expanded only)

`ChartWidget implements HasSchemas` + `InteractsWithSchemas` but **not** `HasActions`; its view renders `afterHeader` only when `$filters || method_exists($this, 'getFiltersSchema')`. So:

1. `LprRepossections implements HasActions` + `use InteractsWithActions` — the same two lines `TableWidget` adds. The card is **not** converted to a `TableWidget`, and no other card is touched.
2. `protected string $view = 'filament.widgets.dashboard.lpr-repossessions';` — a plain class‑property default, resolved at render; unlike a conditional swap it needs no `booted()` hook.
3. The blade copies the vendor view and adds `{{ $this->exportAction }}` in `afterHeader`.
4. `->visible(fn (): bool => $this->expanded)` — `$expanded` is set to `true` only by `Dashboard::expandedCardComponents()` (`Dashboard.php:163-181`). The compact path never mounts Livewire at all (`CompanyMetricsOverview` constructs the class and calls `toStat()`), so the button cannot appear on a compact card.
5. Mirror the two house call‑site conventions: `->hiddenLabel()`, `heroicon-m-arrow-down-tray`, `->tooltip('Export as CSV')`, `->columnMapping(false)`, `->modal(false)`, and `Analytics::track('export', …)` in `->after()`.

---

## Tests

**`tests/Feature/Clickhouse/LprRepossessionsQueryTest.php`** (real ClickHouse, `#[Group('clickhouse')]`, `RequiresClickhouse`):

- **Parity, the headline gate (#25).** On a non‑trivial fixture, `count(rows(...)) === window(...)['db_repos']`, for both a scoped org and platform‑wide. Assert `db_repos > 0` first, or `0 === 0` passes and the gate stops gating — the same precondition the existing drift alarm learned to add (line 527).
- **One row per triple, not per VIN.** Same VIN, two agent orgs, both repossessed → 2 rows.
- **Fan‑out collapse.** Two status‑3 hotlists for the same `(org, vin)` → 1 row.
- **The backyard predicate survives in `HAVING`.** Reuse the `claim_date - 7` boundary fixture (line 272): on‑window counts, one day earlier does not.
- **Sentinel guards hold on the listing path.** Re‑point the `agent_org_id = 0` and `h.id = 0` fixtures (lines 187, 224) at `rows()` — both guards live in the shared join body, so a listing regression there is otherwise invisible.
- **Bucket‑straddling triple.** A `last_7d`‑style window across a month boundary with one triple claimed in each month → 1 row, matching `uniqExact`. This is the test that fails if the group key picks up `bucket`.
- **Decoration columns.** Plate from the joined hotlist; `case_number` blank when the MV wrote an empty string; Claimed By from `public_orgs.name`; `repo_agent_name` always blank.
- **Aggregate is unchanged.** A golden assertion that `windowSql()` / `monthlySeriesSql()` output is byte‑identical to the current text — the CTE extraction's only real risk.

**`tests/Filament/Widgets/Dashboard/LprRepossectionsTest.php`:**

- The export action exists when `expanded = true` and is absent when `false`.
- The action's resolved window is the **UTC** month for `this_month`/`last_month` and the **viewer‑local** range for `today`/`last_7d` — the two‑calendar trap, pinned by a recording subclass in the style of `WindowBoundsRecordingLprRepossessions` (line 1429).
- Org scope reaches the query as `auth()->user()->org_id`.
- Rendering the expanded card still performs **no** export query — the existing round‑trip test (line 126) covers the seams; extend rather than duplicate it.

**`tests/Filament/Exports/LprRepossectionExporterTest.php`** (new, optional): column names/labels/order, `ExportFormat::Csv` only, disk `s3`, queue `{high}`, filename shape, Repo Agent Name blank. Note the five existing exporters carry `@codeCoverageIgnore`; AC‑3 is a column contract, so pinning it is worth the small departure — if the reviewer prefers consistency, keep the annotation and assert only the static `getColumns()`.

No existing assertion about chart copy or behavior changes, so nothing in the two suites should need rewriting.

---

## Provision / QA notes

- **#18 — fleet Docker ClickHouse.** Agent QA and Verify need a real fleet CH twin: `clarity.billing_claim_events` (+ the `_v` view), `clarity.hotlist`, `zod.public_hotlist`, `zod.public_hotlist_status_log`, `zod.hotlist_json_denormalized`, `zod.public_orgs`. The query test seeds all of these directly through `DB::connection('clarity_clickhouse')->table(...)`, so the existing fixtures are the provisioning spec. Cloud CH stays **read‑only** and is only for a cost measure if the fleet twin cannot produce a realistic row count — prefer the twin.
- **#25 — parity.** The forecast gate is "export row count == chart `db_repos`, same filters". Verify in **both** directions: the automated test above, and a live check where the expanded card's headline number is compared against the CSV line count minus the header row, for at least `this_month` and `last_7d`.
- **Execution count is a QA deliverable, not a guess.** Capture `read_rows` / `peak_memory_usage` per export from `system.query_log` on the fleet twin and record how many times the listing actually executed. That number decides stock pipeline vs. the `->job()` fallback.
- **#27 — screenshots, later.** The expanded chart with the button, the started notification, and the opened CSV. Not this phase.
- Local CH QA: use the literal `clarity` / `zod` database names — several query classes in this area hardcode the prefix, and per‑worktree names break them.

---

## Hard stops / out of scope

- `uniqExactIf`, the dedup triple, `min(claim_date)`, the `- 7` backyard window, the `{floor}` prefilter, the either‑side org predicate, the `FINAL`/no‑`FINAL` asymmetry between `public_hotlist` and `clarity.hotlist`: **unchanged**.
- `deduped` vs `db_repos` funnel semantics: unchanged. The export is `db_repos`.
- Both shared cache keys and their payload contracts: untouched; no row payload written to either.
- Excel: not offered. All five house exporters are CSV‑only; First Glance 42732 prefers CSV.
- No client picker on this card.
- `remote_order_id` is **not** a substitute for RDN case number.
- `org_meta.properties.rdn_agents` is **not** joined; Repo Agent Name ships blank. It is an org‑level SOAP cache, not a per‑VIN name — AC‑3 says "when available", so blank satisfies it.
- `UniqueLprCaseClaims`, `UniqueLprCaseClaimsQuery`, and every other dashboard card: untouched.
- No Cloud ClickHouse writes; no fleet/Herd/local DB mutation; no scout branch; no `okf/` edits in this phase. Three wiki items are already queued in prep (`billing-events.md` still teaching `zod.public_billing_events`; `dto-and-enums.md` on `HotlistJsonData.case_number`; the `LprRepossessions` docblock's "every source is ORDER BY id") — land them after approval, with `php scripts/okf-lint.php`.

**Soft collisions:** DEV‑2660 (`first_repossessed_at` as the recoveries timestamp), DEV‑2659 (single‑pass `billing_claim_events_v`), DEV‑2737. The listing inherits all three; it does not re‑litigate any of them. `main → main`.

---

## Open questions

None. Prep's gate is CLEAN, every field source is cited or explicitly blanked, CSV is locked, and a blank Repo Agent Name is permitted by AC‑3's "when available". The one genuinely undecided item — stock 3‑execution pipeline vs. the `->job()` single‑execution fallback — is a measurement, not a question for the ticket.
