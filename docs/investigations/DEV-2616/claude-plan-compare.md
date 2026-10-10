# DEV-2616 — Claude dual-plan compare (preflight #23)

Date: 2026-09-28 ~8:55 CT
Claude draft: `claude-plan-draft.md` (headless `claude -p --permission-prompts none --tools Read,Grep,Glob`)
Authority: this run's `plan.json` / `plan.md` (Grok Ultra-Plan). Draft is never a done-signal.

## Convergences

- **ship_surface = no product PHP.** AC-1 already on main via `clarityDb()` at `ScanStatsBatchQuery.php:47,:53` (`93f9a249`).
- Contract prefix guard + HitStatsBatchQuery pattern + ClickhouseQuery helpers confirmed.
- No remaining executable `FROM clarity.` / `FROM zod.` on Dashboard-imported CM query classes → AC-4 tickets out, does not fix under this key.
- Named stragglers stay ticket-out: `LprVisitCountQuery`, `RdnScanHourCountQuery`, `RdnHitHourCountQuery` (+ Client Cohort*).
- Keep existing ScanStatsBatchQueryContractTest / UniqueScanCountsTest; do not change SELECT.
- No shim, no fleet DSN invent, no provisioned mark, no soft-twin fleet share, no okf edit this run.
- Next = Provision on a verify-only path.

## Divergences

- Claude expands ticket-out with whole-`app/` hits: `ScanVehicleController.php:42`, `NearestGivenAddressController.php:44`, plus JOIN line nits on `AffiliateStatsHourly:47` / `LprAssist:65`. Prep scoped Queries/; AC-4's CM-path fix branch still does not fire.
- Claude reframes AC-2 QA: header kit counts silently 0 via `HotlistCount::degradesToZero` around `Kit::$table = clarity.kits`. Prep called this fail-soft; Claude insists QA must read numbers, not only HTTP 200.
- Claude notes prep "twelve query classes" vs nine enumerated — cards vs query-class count.
- Claude flags boost-regen dirty files (AGENTS.md / CLAUDE.md / …). Reverted before this plan commit; not ship surface.

## Adopted from Claude

- Add controller / JOIN residuals to the **ticket-out** list (still off-path; still not edited here).
- AC-2 provision/QA note: read header kit counts separately from HTTP 200 + CM grid.
- Clarify nine CM query classes vs twelve-card grid.
- Working-tree hygiene: leave boost regen out of the PR.

## Rejected from Claude

- Any product PHP, test edits, or ports of ticket-out rows under this key (#24).
- Treating inventory incompleteness as a gate reopen — Gate stays CLEAN; AC-4 "ticket" branch covers it.
- Expanding ship_surface beyond artifacts.

## Judgment

Final plan is verify-only / no product PHP. Dual-plan #23 satisfied. Next remains Provision (verify-only path). Do not mark provisioned. Do not implement.
