# Dual-plan co-pilot · DEV-2616 (read-only)

Tools: Read, Grep, Glob only. Do not mutate env, commit, push, provision, or write Cloud ClickHouse.

## Ticket
https://insightlpr-team.atlassian.net/browse/DEV-2616
Bug · Sprint 42 · insight-lpr/platform · parent epic.
Branch path **main→main** (First Glance comment **42081**, supersedes 42080).
PR draft: https://github.com/insight-lpr/platform/pull/471
Worktree tip: Ultra-Prep at `b61044c350359c452e91168096eaaf8f0f88eb19` on `cursor/dev-2616-clarity-db-prep-625a`.
Base: `main` @ `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`.

## Ask (AC)
1. `ScanStatsBatchQuery` uses `clarityDb()` (no hardcoded `clarity.` qualifier).
2. Company Metrics `/app` loads against a local-CH QA worktree DB without a `clarity` view shim.
3. Scan Count card monthly totals still correct in unit/feature coverage.
4. Grep remaining hardcoded `FROM clarity.` / `FROM zod.` on the Company Metrics path — fix **or** ticket remaining stragglers (`LprVisitCountQuery`, `RdnScanHourCountQuery`, `RdnHitHourCountQuery` were noted).

## Prep finding (verify on tip; do not invent)
Prep says AC-1 is already true on `main` via `93f9a249` (Company Metrics paired-count commit): `ScanStatsBatchQuery::handleSql` calls `static::clarityDb()` and interpolates `FROM {$clarityDb}.scan_report_hourly`. Twelve-card CM query classes already resolve `clarityDb()` / `zodDb()`. The soft-related prior QA view-shim work is QA Complete (view shim residual — do not reopen). Gate CLEAN. Preflight #24: smallest change — if AC already met, plan is **verify-only / no product PHP**; do not invent ports of ticket-out classes into this key.

Artifacts: `docs/investigations/DEV-2616/{prep-context,state,journal,precedents}.*`

## Your job
Independently re-read:
- `app/Clickhouse/Queries/ScanStatsBatchQuery.php`
- `app/Clickhouse/Queries/HitStatsBatchQuery.php` (pattern)
- `app/Clickhouse/Queries/ClickhouseQuery.php` (`clarityDb` / `zodDb`)
- `tests/Unit/Clickhouse/ScanStatsBatchQueryContractTest.php`
- Grep under `app/Clickhouse/Queries` for executable `FROM clarity.` / `FROM zod.`
- Confirm which of those hits are imported by `app/Filament/Widgets/Dashboard`

Then write an **independent** Ultra-Plan in markdown to stdout. Prefer the smallest ship that clears AC (#24). If tip confirms no remaining CM-path hardcoded qualifier, the plan must be **verify-only / no product PHP**, with a ticket-out list for Galadriel/Jira follow-ups. Do **not** expand into Rdn/LprVisit ports under this key unless live AC requires a CM-path fix (it should not). Do not rubber-stamp prep blindly — cite file:line proof. Do not mark provisioned. Do not invent a fleet DSN. Do not touch the soft-twin fleet.

## Output shape
Cover: ship_surface (product PHP yes/no), AC decisions with proof, verification commands (existing tests only), ticket-out list with consumers, explicit non-goals, next phase (Provision / verify-only provision path). End with any disagreement vs `docs/investigations/DEV-2616/prep-context.json`.
