<!-- ticket-status -->
**Ticket:** [DEV-2616](https://insightlpr-team.atlassian.net/browse/DEV-2616)
**Phase:** Implement (verify-only) done
**Gate:** CLEAN
**Done:** Ultra-Prep · Ultra-Plan · Provision · Implement (verify-only)
**Next:** Agent QA
**Open questions:** none
**Ship:** verify-only / no product PHP. AC-1 already on main (`clarityDb()`, `93f9a249`, Company Metrics paired-count commit). Ticket-out: 9 plan entries (3 named, 6 residual; 13 class sites).
**product_php=false · no app/ diff · route Agent QA**
**Branch path:** main→main (First Glance **42081**)
**Base:** `main` @ `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`
**Provisioned:** true — site :18086 · PG :15438 · CH :18129 · cloud_touched=false · mac_herd_refused
**PR:** https://github.com/insight-lpr/platform/pull/471 (draft)
**Assignee:** DaltonMcCleery
**Jira assignee:** Dalton McCleery (unchanged)
<!-- /ticket-status -->

# DEV-2616 journal

## Intake (2026-09-28T13:45:00Z)

Live Jira re-GET. Bug, To Do, Sprint 42, Clarity, repo platform, assignee Dalton McCleery. Parent epic (In Code Review). Relates the prior QA view-shim work (QA Complete) and the Company Metrics paired-count commit (QA Complete, `93f9a249`).

First Glance comment **42081** (2026-09-28 ~8:44 CT) supersedes **42080**. Branch path **main→main**. Verdict Ready → Ultra-Prep, Path A parallel with the soft-twin fleet. Hardness Low–Med. Hard stop: none for Prep. Forecast gates: port to `clarityDb()` / `zodDb()`; grep remaining hardcoded qualifiers on the Company Metrics path; `/app` loads against a worktree database without a `clarity` view shim; Scan Count monthly totals stay correct.

`git fetch origin main` left `origin/main` at `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`. This run stops at prep. No product PHP. No provision. No shared branch or ledger with the soft-twin fleet.

## Ultra-Prep (2026-09-28T13:49:23Z)

Read `okf/index.md`, `okf/concepts/data-architecture.md`, `okf/concepts/clickhouse-integration.md`, and `okf/concepts/company-metrics-dashboard.md`. ClickHouse stays read-only. Wiki edits are queued, not made.

### Offender and consumer

`ScanStatsBatchQuery` does not hardcode `clarity.` on this SHA. `handleSql` calls `static::clarityDb()` and selects `FROM {$clarityDb}.scan_report_hourly`. Blame is `93f9a2497bd40ccb9956608099a501823563248d` (Company Metrics paired-count commit, 2026-09-22). The ticket's line 19 is the pre-port location. The in-code note says DEV-2616 stayed open for the other classes.

The Scan Count card was absorbed into `unique-scan-counts`. `UniqueScanCounts::fetchTotalMonthlySeries` calls `ScanStatsBatchQuery::handle`. The current-month total uses `Scan::hot()` on unqualified `scans_hot`.

Pattern: `HitStatsBatchQuery` (docblock plus `clarityDb()`), helper `ClickhouseQuery::clarityDb()` / `zodDb()`.

Guard already present: `ScanStatsBatchQueryContractTest::test_the_database_prefix_is_resolved_not_hardcoded`, plus the timezone-aware month-grouping tests.

### Prior QA view-shim (context only)

That earlier agent QA created a temporary `clarity` / `zod` view shim so `/app` could read per-worktree databases, then tore it down. Status remains QA Complete. This key does not reopen that ticket and does not restore the shim.

### Inventory

Company Metrics query classes on the twelve-card grid already resolve the database. No executable `FROM clarity.` or `FROM zod.` remains in those classes.

Ticket-out (named, off the dashboard):

- `LprVisitCountQuery` line 19 — `LprVisitCountController`
- `RdnScanHourCountQuery` line 27 — `RdnMetricsPublisher`
- `RdnHitHourCountQuery` line 38 — `RdnMetricsPublisher` and `DispatchRdnMetricsHourly`

Also off-path, not edited here: Client `CohortClosedQuery` / `CohortDeclinedQuery`; Eloquent `$table` prefixes on `Kit`, `Recovery`, `HitStatsDaily`, `RecoveryKitScanReport`; raw SQL in `LprAssist` and `AffiliateStatsHourly`. `Kit` is reached from the `/app` header via `HotlistCount` → `KitUsers::cameraKitsCount`, and that failure degrades to 0, so it is not the 500 this ticket names.

`CompanyMetricsOverview::getStats` catches a thrown `toStat()` per card. The wiki line that says this handle() 500s the grid is stale. Code wins. Wiki correction waits until after approval.

## Question gate (2026-09-28T13:49:23Z)

CLEAN. No open questions. AC-1 is already true. The straggler classification follows the code and the "Company Metrics path" forecast gate. Preflight 18 does not fire: this run does not provision and invents no DSN.

## Ultra-Plan outline

No product PHP. Keep `ScanStatsBatchQueryContractTest` and the Unique Scan Counts handle() assertion green. Do not change the SELECT list. Record the ticket-out list. Do not add a helper or a view shim.

Next is Ultra-Plan. This phase does not mark provisioned.

Draft PR: https://github.com/insight-lpr/platform/pull/471. The GitHub integration cannot add assignees (HTTP 403). The required assignee remains DaltonMcCleery.

## Ultra-Plan (2026-09-28T13:55:00Z)

Dual-plan #23. Tip re-read confirmed prep: `ScanStatsBatchQuery` already uses `clarityDb()`; no CM-path hardcoded `FROM clarity.` / `FROM zod.`. Claude headless draft (`claude -p --permission-prompts none --tools Read,Grep,Glob`) agreed on verify-only / no product PHP. Adopted whole-app ticket-out additions (ScanVehicleController, NearestGivenAddressController, JOIN line nits) and the AC-2 header-zero QA note. Rejected any product PHP or ports under this key.

`plan.json` / `plan.md` authoritative. `ship_surface = no product PHP`. `provisioned` stays false. Next is Provision on a verify-only path. Boost-regen dirty files reverted; not in this commit. No soft-twin fleet touch. No Cloud CH writes. No DSN invent.

2026-09-28T14:10:00Z · provisioned · citing Path A :18086/:15438/:18129 (verify-only).

## Implement (verify-only) (2026-09-28T14:20:00Z)

No product PHP. `app/` and `tests/` untouched. `ScanStatsBatchQuery` SQL / SELECT list untouched.

AC-1 confirmed on this tip: `clarityDb()` at `ScanStatsBatchQuery.php:47`, `FROM {$clarityDb}.scan_report_hourly` at `:53`, blame `93f9a2497bd40ccb9956608099a501823563248d` (Company Metrics paired-count commit).

AC-4: the nine Dashboard-imported query classes in `plan.json` `cm_path_resolved` have no executable `FROM clarity.` / `FROM zod.`. The twelve-card grid is the consumer surface. Proof: `docs/investigations/DEV-2616/artifacts/cm-path-grep.txt`.

Ticket-out recorded in `docs/investigations/DEV-2616/implement-notes.md`: 9 `plan.json` entries (3 named, 6 residual). Expanded class sites: 13. Ports not opened.

AC-2 stays Agent QA against the fleet twin (site :18086 · PG :15438 · CH :18129 · cloud_touched=false · mac_herd_refused). HTTP 200 is not the full signal; header kit counts must be read separately.

**product_php=false · no app/ diff · route Agent QA**

Pins (`.env.testing`, Postgres `zod_testing` @ 127.0.0.1:5432). No Cloud ClickHouse writes. Log: `docs/investigations/DEV-2616/artifacts/pins.log`.

- `php artisan test --compact tests/Unit/Clickhouse/ScanStatsBatchQueryContractTest.php` — 5 passed (14 assertions), 3.84s
- `php artisan test --compact --filter=test_the_database_prefix_is_resolved_not_hardcoded` — 1 passed (2 assertions), 3.23s
- `php artisan test --compact tests/Filament/Widgets/Dashboard/UniqueScanCountsTest.php` — 27 passed (109 assertions), 7.22s

AC-2 remains Agent QA. HTTP 200 on the fleet twin is not the full signal; read header kit counts separately.

Verify record `b90611248e30a7440dd5ef148506aeac843254ba` (`state.json` `tip_sha`).

## Verify / Build Record — 2026-09-28 ~09:45 CDT

Verdict **PASS**. Agent QA PASS cited. Build Record Jira comment **42108** (`Insight AI — Build Record`). Review skipped (verify-only / NO_PRODUCT_PHP; mechanical score placeholder only — not fabricated Sam lanes). Preflight #18 PASS cite real Docker CH `:18129` / clickhouse driver / cloud_touched=false. #25 N/A. #16 Pint N/A. AC-1..4 + soft flags walked into `record.ac`. PR #471 remains **DRAFT**. Fleet LEFT UP. Next: Undraft / CI / QA Ready (PM).
