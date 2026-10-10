# DEV-2616 — Implement notes (verify-only)

**product_php=false · no app/ diff · route Agent QA**

No product PHP. `app/` and `tests/` were not edited. `ScanStatsBatchQuery` SQL and its SELECT list were not touched. Off-path qualifiers below are ticket-out only. This key does not open those ports.

Proof log: `docs/investigations/DEV-2616/artifacts/cm-path-grep.txt`.

## AC-1 — already on main

`ScanStatsBatchQuery::handleSql` calls `static::clarityDb()` at `app/Clickhouse/Queries/ScanStatsBatchQuery.php:47` and selects `FROM {$clarityDb}.scan_report_hourly` at `:53`.

Blame SHA `93f9a2497bd40ccb9956608099a501823563248d` (Company Metrics paired-count commit). Confirmed on branch tip `d27f3391568fd79c7a5274c861529ecda2e86890` before this verify pass.

## AC-4 — Company Metrics path

Nine Dashboard-imported query classes (`plan.json` `cm_path_resolved`). The twelve-card grid in `CompanyMetricsOverview` is the consumer surface (cards, not a twelfth query class).

| Class | Resolved prefix |
| --- | --- |
| `ScanStatsBatchQuery` | `static::clarityDb()` → `FROM {$clarityDb}.scan_report_hourly` |
| `ScanPlateStatsBatchQuery` | `static::clarityDb()` → `FROM {$clarityDb}.scans_cold` |
| `HitStatsBatchQuery` | `static::clarityDb()` → `FROM {$clarityDb}.hit_stats_daily` |
| `HitPlateStatsBatchQuery` | `static::clarityDb()` → `FROM {$clarityDb}.hits_cold` |
| `StagingLiveHitLeaderQuery` | `static::clarityDb()` → `FROM {$clarityDb}.hits_cold` |
| `RecoveryLeaderQuery` | `static::clarityDb()` → `FROM {$clarity}.recovery_scans` / `{$clarity}.recoveries` |
| `RecoveryTotalsBatchQuery` | `static::clarityDb()` → `FROM {$clarityDb}.recoveries` |
| `UniqueLprCaseClaimsQuery` | `self::clarityDb()` → `FROM {clarity_db}.billing_claim_events_v` / `{clarity_db}.hits_cold` |
| `LprRepossessionsQuery` | `self::clarityDb()` and `self::zodDb()` → `{clarity_db}` / `{db}` placeholders |

Grep of those nine files for executable `FROM clarity.` / `FROM zod.`: no matches. Grep of `app/Filament/Widgets/Dashboard` for the same: no matches. Docblocks still name production tables; that text is not SQL.

Dashboard `use App\Clickhouse\Queries\…` imports are only those nine classes.

Twelve-card grid (`CompanyMetricsOverview`): Unique Scan Counts, Unique LPR Case Claims, LPR Repossessions, Recovery Counts, Live Hits, Historical Hits, Staging Live Hits, Staging Historical Hits, Missed Opportunities, Unique Scans Leaderboard, Recovery Leader, Staging Live Hit Leader.

## AC-3

Existing pins only. `UniqueScanCounts::fetchTotalMonthlySeries` calls `ScanStatsBatchQuery::handle` at `UniqueScanCounts.php:760`. SELECT list unchanged.

## AC-2 — Agent QA, not this phase

`/app` loads against the fleet twin without a clarity view shim is Agent QA. Do not treat HTTP 200 as the full signal. Header kit counts must be read separately (expected silent 0 from `clarity.kits` via `HotlistCount` → `KitUsers`). No local Herd prove in this phase.

Provision cite (unchanged): site :18086 · PG :15438 · CH :18129 · cloud_touched=false · mac_herd_refused.

## Ticket-out

Nine `plan.json` `ticket_out` entries. Three are named in the ticket. Six are residuals. The last entry groups five model classes, so the expanded class-site count is 13. Do not port any of these under DEV-2616.

### Named (3)

| Class | Site | SQL | Consumer |
| --- | --- | --- | --- |
| `LprVisitCountQuery` | `app/Clickhouse/Queries/LprVisitCountQuery.php:19` | `FROM clarity.scans_cold` | `app/Http/Controllers/LprVisitCountController.php` |
| `RdnScanHourCountQuery` | `app/Clickhouse/Queries/RdnScanHourCountQuery.php:27` | `FROM clarity.scan_report_hourly` | `app/Services/Rdn/RdnMetricsPublisher.php` |
| `RdnHitHourCountQuery` | `app/Clickhouse/Queries/RdnHitHourCountQuery.php:38` | `FROM clarity.hits_hot FINAL` | `RdnMetricsPublisher` + `DispatchRdnMetricsHourly` |

### Residuals (6 entries)

| Class | Site | SQL | Consumer |
| --- | --- | --- | --- |
| `CohortClosedQuery` | `app/Clickhouse/Queries/Client/CohortClosedQuery.php` | `FROM zod.public_hotlist_status_log` | Client Analytics cohort |
| `CohortDeclinedQuery` | `app/Clickhouse/Queries/Client/CohortDeclinedQuery.php` (`:30`, `:84`, `:138`, `:179`) | `FROM zod.public_hotlist_status_log` | Client Analytics cohort |
| `ScanVehicleController` | `app/Http/Controllers/ScanVehicleController.php:42` | `FROM zod.scans_metadata_extracted` | scan-vehicle endpoint |
| `NearestGivenAddressController` | `app/Http/Controllers/NearestGivenAddressController.php:44` | `FROM clarity.recovery_address ra FINAL` | nearest-address endpoint |
| `Kit` | `app/Clickhouse/Models/Kit.php:14` | `$table = clarity.kits` | `HotlistCount` via `KitUsers`; degrades to 0 |
| Eloquent `$table` / raw SQL | `app/Clickhouse/Models/*` | see lines below | not CM card queries |

Grouped model sites still present:

- `Recovery` — `$table = clarity.recoveries` (`Recovery.php:16`)
- `HitStatsDaily` — `$table = clarity.hit_stats_daily` (`HitStatsDaily.php:12`)
- `RecoveryKitScanReport` — `$table = clarity.recovery_kit_scan_report` (`RecoveryKitScanReport.php:13`)
- `LprAssist` — `FROM clarity.lpr_assists` and `JOIN clarity.recoveries` (`LprAssist.php:64-65`)
- `AffiliateStatsHourly` — `FROM clarity.affiliate_stats_hourly` and `INNER JOIN clarity.kits` (`AffiliateStatsHourly.php:41`, `:47`)

## Pins

Run 2026-09-28T14:21:11Z against `.env.testing` (Postgres `zod_testing` @ 127.0.0.1:5432). `CLICKHOUSE_DRIVER=clickhouse` in that file points at the local Docker listener; these pins do not write ClickHouse. Cloud ClickHouse was not touched. Log: `docs/investigations/DEV-2616/artifacts/pins.log`.

| Command | Result |
| --- | --- |
| `php artisan test --compact tests/Unit/Clickhouse/ScanStatsBatchQueryContractTest.php` | 5 passed (14 assertions), 3.84s |
| `php artisan test --compact --filter=test_the_database_prefix_is_resolved_not_hardcoded` | 1 passed (2 assertions), 3.23s |
| `php artisan test --compact tests/Filament/Widgets/Dashboard/UniqueScanCountsTest.php` | 27 passed (109 assertions), 7.22s |
