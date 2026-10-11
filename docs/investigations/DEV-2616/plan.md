# DEV-2616 — Ultra-Plan

Input: `docs/investigations/DEV-2616/prep-context.json`. Gate **CLEAN**.
Machine contract: `plan.json`. Dual-plan #23.

First Glance **42081** (supersedes 42080). Branch path **main→main**. Base `main` @ `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`.

## What ships

**Nothing under `app/`.** `ship_surface = no product PHP`.

AC-1 is already true on `main`: `ScanStatsBatchQuery::handleSql` calls `static::clarityDb()` at `:47` and selects `FROM {$clarityDb}.scan_report_hourly` at `:53` (`93f9a249`, Company Metrics paired-count commit). The twelve-card Company Metrics query classes (nine Dashboard-imported query classes) already resolve `clarityDb()` / `zodDb()`. Preflight #24: the smallest change that clears AC is already on main.

This plan records that close, keeps the existing monthly-total tests, and tickets remaining off-path qualifiers for Galadriel/Jira. It does not add a helper, a view shim, or a second database resolver.

## AC decisions

| AC | Decision | Proof |
| --- | --- | --- |
| 1 ScanStatsBatchQuery uses clarityDb() | already_on_main | `:47` / `:53`; contract prefix test `:108-117` |
| 2 /app loads on worktree DB, no clarity shim | verify_at_agent_qa | CM queries resolve DB; per-card catch; no shim. QA reads header kit counts separately (Kit `$table=clarity.kits` degrades to 0). |
| 3 Scan Count monthly totals | keep_existing_tests | `UniqueScanCounts.php:760` → `handle()`; contract + UniqueScanCountsTest |
| 4 Grep CM path — fix or ticket | ticket_out | No remaining executable `FROM clarity.` / `FROM zod.` on Dashboard-imported query classes |

## Ticket-out (do not edit under this key)

Named in the ticket (off dashboard):

- `LprVisitCountQuery.php:19` → `LprVisitCountController`
- `RdnScanHourCountQuery.php:27` → `RdnMetricsPublisher`
- `RdnHitHourCountQuery.php:38` → `RdnMetricsPublisher` / `DispatchRdnMetricsHourly`

Also off-path (Galadriel follow-ups):

- Client `CohortClosedQuery` / `CohortDeclinedQuery` (`FROM zod.public_hotlist_status_log`)
- `ScanVehicleController.php:42` (`FROM zod.scans_metadata_extracted`)
- `NearestGivenAddressController.php:44` (`FROM clarity.recovery_address`)
- Eloquent `$table` / raw SQL: `Kit`, `Recovery`, `HitStatsDaily`, `RecoveryKitScanReport`, `LprAssist` (+ `:65` JOIN), `AffiliateStatsHourly` (+ `:47` JOIN)

Refuse expanding into Rdn/LprVisit/controller ports under this key. AC says fix **or** ticket; the CM path needs no fix.

## What does not ship

- Re-editing `ScanStatsBatchQuery` SQL or its SELECT list
- prior QA view-shim restore
- Ports of the ticket-out list
- New query abstraction
- Cloud ClickHouse writes; inventing a fleet/Mac Herd DSN
- Marking provisioned in this phase
- Any soft-twin fleet worktree / ledger / branch touch
- `okf/` edits before approval (queue the stale grid-500 sentence)

## Tests (run when asked)

```bash
php artisan test --compact tests/Unit/Clickhouse/ScanStatsBatchQueryContractTest.php
php artisan test --compact --filter=test_the_database_prefix_is_resolved_not_hardcoded
php artisan test --compact tests/Filament/Widgets/Dashboard/UniqueScanCountsTest.php
```

`CardQuerySqlFidelityTest` is delegation-only — not the prefix guard. This plan phase does not run tests and does not provision.

## Next

**Provision** on a **verify-only** path. Stand a worktree CH ledger later to prove `/app` loads without a `clarity` shim. Do not invent a DSN here. Do not mark `provisioned=true` in this run. AC-2 QA: HTTP 200 + CM grid, and separately note header kit counts may be silent 0 from pre-existing `clarity.kits`.
