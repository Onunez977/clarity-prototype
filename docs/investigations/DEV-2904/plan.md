# DEV-2904 — Ultra-Plan (sealed)

**Gate:** CLEAN  
**First Glance:** comment [42732](https://insightlpr-team.atlassian.net/browse/DEV-2904?focusedCommentId=42732) Ready  
**Branch path:** main→main · draft PR [#501](https://github.com/insight-lpr/platform/pull/501)  
**Sources:** `prep-context.json` · `claude-plan-draft.md` · `cloud-plan-review.md` (REQUEST CHANGES) · `claude-plan-reconcile.md` (all four Changes + all Nits adopted)

## Summary

Add a CSV export to the expanded **LPR Repossessions** bar chart. One new *listing* statement reproduces the exact `(forwarder_org_id, agent_org_id, vin)` set that `uniqExactIf` counts as headline `db_repos`, projects AC-3 columns on that same statement (so `ExportCsv::find()` sees them), and drives a house-shaped Filament CSV exporter. Chart aggregate SELECT, shared cache keys, dedup triple, backyard `- 7`, and every other card stay untouched.

## AC map

| AC | Plan landing |
| --- | --- |
| AC-1 Export button, CSV, on the chart | `ExportAction` on card-local chart blade (`afterHeader`); `LprRepossessions` implements `HasActions` + `InteractsWithActions`; `public function exportAction(): ExportAction`; `->visible(fn () => $this->expanded)` |
| AC-2 One row per VIN that makes up the chart | One row per `(forwarder, agent, vin)` with `HAVING countIf(<pred>) > 0` — not distinct VIN, not sum of 13 bars |
| AC-3 VIN, Plate, RDN Case, Claimed By, Repo Agent Name | Projected on `rowsSql()`: VIN / `plate` / `case_number` / org name for `agent_org_id` / literal-empty Repo Agent Name. Joins **after** GROUP BY/HAVING |
| AC-4 Current chart filters | Click-time `$this->date` + `auth()->user()->org_id`. Month filters = UTC series bucket; today/last_7d = viewer-local `resolveCardRange()`. No client picker |
| AC-5 Filename `lpr-repossession-export-YYYY-MM-DD.csv` | `fileName` without extension; date via `$this->viewerTimezone()`; `CsvDownloader` appends `.csv` |

## Approach (smallest)

1. **`LprRepossessionsQuery::rowsSql()` / `rows()`** — Extract shared WITH/join body so aggregate `$select`/`$groupBy` stay byte-identical (golden SQL assert). Listing groups by the triple only; decoration joins after HAVING. `rows()` is a thin PHP wrapper over the same SQL (parity tests + export share one statement).
2. **String key** — `concat(toString(forwarder_org_id),'|',toString(agent_org_id),'|',vin) AS id` on query-supplied `LprRepossessionExportRow` (`$keyType = 'string'`, `$incrementing = false`). **Do not** use `cityHash64` (UInt64 JSON precision loss). Key round-trip test required.
3. **Exporter** — `LprRepossessionExporter` mirrors `RecoveryLeaderboardExporter`: `ExportFormat::Csv` only, `getFileDisk(): 's3'`, `getJobQueue(): '{high}'`. Omit `maxRows(10000)`; choose `chunkSize` independently. Keep query unordered or key-ordered so `orderedChunkById` applies.
4. **Chart action** — Class-property `$view` → card-local blade with `{{ $this->exportAction }}` in `afterHeader`. Mirror leaderboard call-site UX (`hiddenLabel`, tray icon, tooltip, `columnMapping(false)`, `modal(false)`, `Analytics::track`). Do **not** copy render-time `hidden(...->count() === 0)`.
5. **Window calendars** — `this_month`/`last_month`: UTC `startOfMonth()`→`endOfMonth()` matching `monthlySeriesSql()` / `SERIES_ZONE`. `today`/`last_7d`: viewer-local `resolveCardRange()` re-anchored in `viewerTimezone()`.
6. **`->job()` hatch** — Documented escape hatch only after fleet-twin timing rejects stock pipeline; hatch runs the **same** projecting statement.

## File / touch list

| File | Change |
| --- | --- |
| `app/Clickhouse/Queries/LprRepossessionsQuery.php` | Add `rows()` / `rowsSql()`; extract shared WITH body; aggregate SELECT unchanged |
| `app/Filament/Widgets/Dashboard/LprRepossessions.php` | `HasActions` + `InteractsWithActions`; `$view`; public `exportAction()` |
| `app/Filament/Exports/LprRepossessionExporter.php` | **Create** — house CSV/`s3`/`{high}` |
| `app/Clickhouse/Models/LprRepossessionExportRow.php` | **Create** — query-supplied ClickhouseModel; docblock says table is query-supplied |
| `resources/views/filament/widgets/dashboard/lpr-repossessions.blade.php` | **Create** — vendor chart blade + export in `afterHeader` |
| `tests/Feature/Clickhouse/LprRepossessionsQueryTest.php` | Listing + parity + key round-trip + grain guards |
| `tests/Filament/Widgets/Dashboard/LprRepossessionsTest.php` | **Edit** existing — action surface, two-calendar windows, no export on render |
| `tests/Filament/Exports/LprRepossessionExporterTest.php` | **Create** (required) — column contract, formats, disk, queue, filename, blank Repo Agent Name |

**Not touched:** other dashboard cards, `UniqueLprCaseClaims*`, aggregate funnel, shared cache keys, scout, `okf/` (wiki queue stays post-approval).

## Tests (plan; not run in this phase)

- **#25 parity:** `count(rows) === monthlySeries[bucket]['db_repos']` for month filters (bucket > 0 first); `count(rows) === window()['db_repos']` on identical bounds as listing-vs-unbucketed check.
- Triple-not-VIN, hotlist fan-out collapse, backyard `- 7` in HAVING, sentinel guards, bucket-straddle (no bucket in group key).
- Decoration present on listing; Repo Agent Name always blank.
- Aggregate SQL golden assert unchanged.
- Widget: expanded-only action; UTC vs viewer calendars; org scope; round-trip test still green (no export query on render).
- Exporter static contract + key round-trip.

## Provision sketch

- Fleet Docker CH twin (literal `clarity`/`zod`): claim events + view, hotlist, public_hotlist, status_log, **`zod.hotlist_json_denormalized`**, public_orgs. Prefer twin over Cloud measure.
- Cloud CH READ-ONLY if needed for cost sample only.
- QA deliverable: `system.query_log` execution count + read_rows decides stock vs `->job()` hatch.
- Live parity: expanded headline vs CSV line count − header for at least `this_month` and `last_7d`.
- Screenshots (#27) after control exists — not this phase.
- `provisioned: false` until real CLI ledger citing Galadriel LIVE fleet.

## Hard stops

1. No change to aggregate `uniqExactIf` / dedup triple / backyard / floor / either-side org / FINAL asymmetry.
2. No write to shared monthly-series or window cache keys.
3. CSV only; no Excel; no client picker.
4. `remote_order_id` is not RDN case number; `org_meta.rdn_agents` not joined.
5. No `UniqueLprCaseClaims` widen; no other card edits; no scout; no Cloud CH writes; no product PHP in this Plan phase.
6. Tie-break `argMinIf` on `(first_repossessed_at, h.id)` so two exports of one window agree on Plate.
7. First fleet-twin check: `fromRaw` CTE survives `EloquentSerializer` → `getCountForPagination()` → `orderedChunkById` → `find()`.

## Soft collisions

DEV-2660 / DEV-2659 / DEV-2737 — listing inherits; does not re-litigate. Branch path **main→main**.

## Open questions

None. Gate CLEAN. Stock vs `->job()` remains a fleet-twin measurement after Change 1 fixes which statement either path runs.
