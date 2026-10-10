# DEV-2325 Prep Candidate 0 (fast pass)

## synthesis
`SendEndOfShiftSummary` (dispatched after every shift close — the driver-confirmed path in `ClarityScoutShiftController::end()` and the auto/assumed-close path in `CloseInactiveShiftsCommand:91`) exhausts its 5 retries with a Postgres `57014` statement timeout while building the shift's summary. The timeout is on the **`platform` (write) connection**, because the entire `handle()` body runs inside `DB::connection('platform')->transaction()` (sticky). The one raw-SQL, `platform`-connection query in the summary path is the scans⋈zones spatial join in `BuildShiftSummary::buildZoneSummaries()` (app/Services/Scout/BuildShiftSummary.php:178-193). Same structural family as DEV-2309 (unbounded spatial predicate against the giant `public.scans` table) but a DIFFERENT call site and connection — DEV-2309 fixed Eloquent `Scan::query()` aggregates on `zod`; this is hand-written SQL on `platform`, untouched by that fix.

## scope
- Bound `buildZoneSummaries()`'s raw SQL (SELECT at ~178-193): pre-filter `public.scans` by kit_id + scanned_at into a `WITH shift_scans AS MATERIALIZED (...)` CTE, then join THAT against `platform.zones` via ST_Contains. Forces PG to compute the shift's small candidate scan set before the spatial predicate.
- AC-3: already substantially satisfied by pre-existing `failed()` (SendEndOfShiftSummary.php:125-141 — Log::error + summary_skipped_reason='mail_failed', shipped under DEV-1788). Whether to add proactive alert (Horizon Slack, commented out in HorizonServiceProvider:19-21) is an open human decision.

target_files: app/Services/Scout/BuildShiftSummary.php (primary), tests/Feature/Services/Scout/BuildShiftSummaryTest.php (new pinning test), app/Jobs/Scout/SendEndOfShiftSummary.php (only if AC-3 grows), tests/Feature/Jobs/Scout/SendEndOfShiftSummaryTest.php (only if AC-3 grows).

out_of_scope: ScanTimezoneScope/DEV-2309 call sites (already fixed); ClientSearchScansResource.php:92 (same-family latent risk, follow-up ticket); handle()'s lock-across-whole-build (secondary risk); statement_timeout GUC (infra); new index (none missing).

## acceptance_criteria
1. Completes without 57014 — needs the fix; final proof is post-deploy laravel.log (AC's own wording). decision=implement.
2. Timing-out query bounded — fix at BuildShiftSummary.php:178-193 (MATERIALIZED CTE). Existing indexes sufficient. decision=implement.
3. Retry-exhaustion no longer silently drops — substantially met by pre-existing failed() hook; proactive alert is open scope. decision=implement/confirm.

## questions
- Q1 (answered): Does DEV-2309's merged fix cover this? NO — DEV-2309 fixed Eloquent Scan::query() on 'zod'; DEV-2325 is raw SQL on 'platform' (buildZoneSummaries). Verified by reading DEV-2309's diff + current code. source=code.
- Q2 (needs-human): Should AC-3 add a NEW proactive alert (Slack/Notification) on retry-exhaustion, or is the existing failed() hook sufficient?
- Q3 (needs-human): Is the generic 'mail_failed' skip-reason label acceptable for a query-timeout cause, or relabel/more specific?
- Q4 (needs-human): Does the MATERIALIZED-CTE rewrite actually eliminate 57014 at prod scale, or is a covering/composite index also needed? (Local PG has 0 rows — no EXPLAIN possible.)

## blockers
none

## notes
- Cross-connection test isolation: scans (Scan model, 'zod') and the platform-connection zone read live in separate RefreshDatabase transactions. Pinning test must seed scans via DB::connection('platform')->table('scans')->insert(...). Zone::factory()->create() then $zone->rebuildGeometryFromDefinition() (Zone.php:251) or ST_Contains never matches.
- Follow DEV-2309 test style: enableQueryLog(), assert emitted SQL contains the CTE fence ('shift_scans'/'MATERIALIZED') + counts unchanged.
- Secondary risk: handle() wraps ClickHouse calls + PG join + Mail::send inside one platform transaction holding lockForUpdate on kit_shifts (62-117) — longer lock-hold than needed; optional follow-up.
- No app-level statement_timeout override anywhere (grepped).

## grounding
- app/Jobs/Scout/SendEndOfShiftSummary.php:52 ($tries=5), :60-118 (handle: DB::connection('platform')->transaction wraps lockForUpdate + forShift + mail), :125-141 (failed()).
- app/Console/Commands/CloseInactiveShiftsCommand.php:91 (dispatch), :110-128 (latestActivityFor — DEV-2309-fixed, has withoutGlobalScope).
- app/Http/Controllers/Api/ClarityScoutShiftController.php:143 (confirmed-end dispatch).
- app/Services/Scout/BuildShiftSummary.php:45-81 (forShift), :175-193 (buildZoneSummaries = THE query), :253-258 (Hit::query whereIn scan_id on 'zod'), :294-328 (loadZonePolygonsForMap — small id list).
- app/Models/Scan.php:108 (connection='zod'), :150 (addGlobalScope), :401-407 (safe scanCountByKitNameAggregate), :409-434 (scopeScanTimezone JOIN timezones ST_Within).
- app/Models/Zone.php:47 (connection='platform'), :251-292 (rebuildGeometryFromDefinition).
- config/database.php:58-121 (zod vs platform; platform sticky=true).
- vendor zod-schema.sql:3994-4009 (scans DDL PARTITION BY RANGE scanned_at), :29734-30315 (per-partition kit_id / kit_id+scanned_at btree + geo GiST).
- database/migrations/2026_05_12_000000_create_zones_table.php:16-41 (org_id + geom GiST).
- tests/Feature/Services/Scout/BuildShiftSummaryTest.php:17-25 (cross-connection gap docblock).

## exhaustiveness
Patterns: `grep -rniE "ST_Contains|ST_Within" app`, `grep -rn "Scan::query()" app`, `grep -rn "platform.zones|Zone::query|zones z" app`.
- BuildShiftSummary.php:178-193 — BUGGY (root cause; raw SQL, platform conn, no pre-filter fence).
- Scan.php:426 scopeScanTimezone — safe as definition.
- GeneratesPathData.php:112 — safe (different table, bounded).
- AggregateGeoHashScanDaily.php:39 — safe (batch ETL, date-bounded, timezones LATERAL LIMIT 1).
- Kit.php:132 — safe (withoutGlobalScopes).
- Scan.php:401 — safe (withoutGlobalScope).
- ListScans.php:327 — safe (withoutGlobalScope).
- EnsureOpenShift.php:132,152 + CloseInactiveShiftsCommand.php:112 — safe (DEV-2309-fixed).
- FlexMatch*/Disposition* controllers — safe (single-row PK firstOrFail).
- ClientSearchScansResource.php:92 — UNCONFIRMED/possibly-buggy, OUT OF SCOPE (no withoutGlobalScope, forOrg no date bound, Filament count() pagination). Follow-up ticket.
- loadZonePolygonsForMap:307 — safe (org_id + small id IN list).

## data_impact
pg_tables: public.scans (zod DB, partitioned by scanned_at, read via 'platform' conn here), platform.zones, platform.kit_shifts, platform.kit_settings, public.hits (read via 'zod'), public.kits/users/orgs.
ch_tables: clarity.sc_positions, scans_hot, scans_cold, hits_cold (CDC from PG; cannot raise 57014 — ruled out).
mv_or_cdc: none implicated (single raw PG query fix).
indexes_relied_on: scans_pYYYYMMDD_kit_id_idx, scans_pYYYYMMDD_kit_id_scanned_at_idx, scans_pYYYYMMDD_geo_idx (GiST), zones_geom_gist, zones org_id, ix_hits_scan_id.

## derivation_log
- DB::connection('platform') local: `SELECT to_regclass('public.scans'), to_regclass('platform.zones')` → both exist.
- `SELECT count(*) FROM public.scans; count(*) FROM platform.zones` → 0 rows each locally (cannot reproduce/EXPLAIN prod-scale plan).

## confidence
70

## escalate
recommend=true: root cause well-grounded but fix effectiveness at prod scale unverifiable locally (0 rows); AC-3 scope genuinely open (human decision); latent same-family risk found (ClientSearchScansResource:92); High-severity prod bug on write DB → prefer escalation.
