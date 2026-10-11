## DEV-2929 — LPR Repossessions CSV export: filename and tooltip show the exported window

Follow-up to [DEV-2904](https://insightlpr-team.atlassian.net/browse/DEV-2904). **Branch path:** main→main.

Scope (live Jira): the export filename and the export button tooltip are built from the export's existing window (`exportWindow()`, the same window the export query uses) instead of the export day. Month filters use the full UTC calendar month; Today and Last 7d use the viewer's timezone. Query, exported rows, CSV columns, window logic, notifications and chart/cards are unchanged.

<!-- ticket-status -->
**Ticket:** [DEV-2929](https://insightlpr-team.atlassian.net/browse/DEV-2929)
**Phase:** Provisioned **(2/7)** · Done Prep + Plan + Provision (test DB only) · Next **Implement** · Owner **Implement**
**Jira:** To Do · First Glance **43362** · Plan comment **43365**
**Gate:** CLEAN · Spec READY · `ch_touch: no` (no ClickHouse change) · #25 parity N/A (no query/path rewrite)
**Plan shape:** in `LprRepossessions::exportAction()`, the tooltip (L653) and filename (L656) become inline closures over the existing `exportWindow()` (same window as the export query). Months = full UTC calendar month; Today / Last 7d = viewer timezone; each date formatted in its own zone. No new helper/class/constant; query, window logic, exporter, chart and blade unchanged. Tests: update the old filename assert (L1261), add filename + tooltip cases (ticket examples, UTC month edge, cross-year Last 7d) and a tooltip-after-toggle case
**Provision:** keyed test database only (accepted deviation): Implement worktree has `vendor/` + `.env.testing` on keyed test DB `zod_testing_dev_2929`. Baseline `LprRepossessionsTest` **39 passed / 201 assertions / 0 failed** (pre-change). No site or ClickHouse environment for this key yet
**Agent QA environment:** a running site is stood up at QA Ready (tooltip per toggle, tooltip refresh after a toggle, downloaded filename)
**Branch path:** main→main · base `main` @ `0e5ae042`
**PR:** draft · base `main` · tip = PR head (docs only; no product code yet)
**Assignees:** Jira Dalton McCleery · GitHub DaltonMcCleery
**Open questions:** Q1 confirm against installed Filament how the tooltip renders in the heading HTML (Implement) · Q2 if the browser tooltip doesn't refresh on toggle at Agent QA, a heading view change needs a go-ahead first
**Decided:** tooltip copy word for word from the ticket ("as CSV" only on the month variant) · cross-year Last 7d uses the yearless form (e.g. "Dec 29–Jan 5")
**Open gates:** Agent QA UI evidence on the running site · soft: if the Filament 5.9 update (#489) lands first, re-run the tests
**Artifacts:** `docs/investigations/DEV-2929/` (spec mirror `DEV-2929.md`, `provision.md`, `provision.json`, `state.json`, `journal.md`, `harness/`)
<!-- /ticket-status -->
