# DEV-2929 journal

## 2026-10-07 ~6:21pm CDT — Prep intake

- Live re-GET DEV-2929 (Atlassian connector): To Do · Task · Sprint 42 (active) · Application Clarity · epic DEV-1580 · assignee Dalton McCleery · relates to DEV-2904 (Done) · labels none · created 6:02pm CDT.
- First Glance comment **43362** live (6:19pm CDT) and identical to the local copy `/workspace/first-glance/DEV-2929-fg-comment.md`: Verdict Ready · Hardness Low · **Branch path main→main** · hard stop / forecast gates / Next present. Only comment on the ticket.
- Live description + AC match the PM card. No drift on scope. Notes (from live Jira, not new scope):
  - Filename prefix changes to `lpr-repossessions-claimed-` (ticket examples), not just the date part.
  - This Month = full UTC calendar month (Oct 1–31), per the ticket example and `exportWindow()`.
  - Tooltip copy is uneven across examples ("as CSV" only on the month one); Plan follows the examples verbatim.
- `origin/main` = `0e5ae042` (GHA deploy, 2026-10-07 1:42pm CDT) — same SHA First Glance read.
- Scratch clone `/workspace/dev-2929-fg` left untouched (read-only).
- Box checkout `/workspace/dev-2929-wt` (`worktree add --no-track -b cursor/dev-2929-export-window-filename origin/main`); no upstream set.
- Scout confirmed line refs: `exportAction()` L648–662 (tooltip L653, fileName L656), `exportWindow()` L669–691, `exportRowsSql()` L693–698, `resolveCardRange()` L28–50, test L1241 / L1261 / L1284.
- Gate: **CLEAN**. Next: draft PR, then Plan.
- Mac mini (`Daltons-Mac-mini.local`) reported **not connected** at intake (local-exec offline); Planner launch waits for it.

## 2026-10-07 6:23pm CDT — Draft PR

- Prep tip `ab002e99` pushed with explicit refspec `HEAD:refs/heads/cursor/dev-2929-export-window-filename` (no upstream configured).
- Draft PR **#511** opened, base `main`, assignee DaltonMcCleery set, living ticket-status (Prep 1/7, Next Plan).

## 2026-10-07 6:20–6:38pm CDT — Plan BLOCKED (Mac mini offline)

- Mac mini (`Daltons-Mac-mini.local`) local-exec reported **not connected** on every check (6:20, 6:25, 6:29, 6:33, 6:38pm CDT); a direct Shell probe failed to spawn ("local machine isn't connected").
- Planner runs only on the Mac mini (#23/#30); no Plan CLI on the box. Not routed around.
- Planner prompt ready: `harness/prompt.txt` (live AC, First Glance 43362, window/TZ rules, smallest-change fence, required sections incl. `ch_touch: no` and the accepted #9 provision note).
- Not done: Mac worktree, harness-local agent/guard, Planner run, self-check, Jira plan comment, Mac test-DB provision. PR #511 ticket-status stays Prep (1/7) / Next Plan (accurate).
- Resume: Mac online → `git -C ~/Herd/platform fetch` → `worktree add --no-track -b cursor/dev-2929-export-window-filename <wt> origin/main` → ff to `origin/cursor/dev-2929-export-window-filename` (`ab002e99`+) → harness-local agent + guard → launch → self-check → post → light provision.

## 2026-10-08 7:27–7:36am CDT — Resume (Mac mini back)

- Mac mini connected 7:27am CDT. `origin/main` still `0e5ae042`; #489 not merged.
- Mac worktree `<mac-run-dir>/platform-wt`: `worktree add --no-track -b cursor/dev-2929-export-window-filename <wt> origin/main` → `merge --ff-only origin/cursor/dev-2929-export-window-filename` → `fb48a40e`. No upstream.
- Harness-local planner agent + guard (allowedPaths `<wt>/.ai/specs/**`, `fs_write` added to allowedTools, hooks + Serena → wt). Guard smoke 2/0/2. Global `global agent config` untouched.
- PM rulings folded into the prompt: tooltip copy word for word ("as CSV" only on the month variant); yearless cross-year Last 7d OK.
- Two aborted starts, no output kept: 7:29am (prompt copy raced the PM-rulings edit, stale prompt; killed at once) and 7:30am (Planner died when its parent shell session was killed). Relaunched in its own session at 7:36am.

## 2026-10-08 7:36–7:59am CDT — Plan CLEAN

- Planner claude-opus-5.5 / high · trust `fs_read,fs_write,grep,glob,code,thinking` · exit 0 · wall **972 s (~16m 12s)**.
- Spec `.ai/specs/DEV-2929.md` Status **READY** · `ch_touch: no`. Two inline closures at L653 / L656 over `exportWindow()`; tests: L1261 edit + 9-row data provider (ticket examples at 2026-10-07 15:00 UTC, UTC month edge 2026-08-01 02:00 UTC, cross-year Dec 29–Jan 5) + toggle test via real `refreshData()`.
- Self-check **CLEAN** (`harness/self-check.md`); expected strings hand-verified; citations spot-checked. Harness edits for #17 only: spec internal role tags → `@Implement` / `@PM`, byline → "Plan"; comment gained a Branch path line and a neutral footer.
- Plan comment posted to Jira **43365** at 7:59am CDT. Checked spec mirrored to Mac wt + box checkout `.ai/specs/`.
- Open questions 2 (non-blocking): Q1 Filament `cacheAction()` / tooltip HTML encoding check at Implement; Q2 browser tooltip refresh is Agent QA's proof, blade change would need a go-ahead.

## 2026-10-08 ~7:31–8:02am CDT — Provision Done (keyed test PG only, accepted #9 deviation)

- Mac local PG: `createdb -T zod_testing zod_testing_dev_2929` (new keyed DB; template is the shared baseline). platform 41 / public 147 tables, PostGIS 3.4.2.
- `vendor/` APFS-cloned from Herd (`composer.lock` identical), `composer dump-autoload` OK. `.env.testing` from Herd with DB + `CLICKHOUSE_DATABASE_*` → `zod_testing_dev_2929` (pgsql ClickHouse mirror only to boot; not ClickHouse evidence; `ch_touch: no`).
- Baseline: `LprRepossessionsTest.php` **39 passed / 201 assertions / 0 failed** (9.7 s), pre-change.
- Ledger `.context/qa-prep-ledger.json` (Mac wt, disk only): `provision_mode: keyed_test_pg_only`, `ch_touch: no`, deviation note. Mirrored to box control plane + box checkout `.context/`.
- state → `phase=provisioned`, gate CLEAN, Next Implement. PR #511 ticket-status → Provisioned (2/7). No Implement handoff from this step.

## 2026-10-08 8:04–8:10am CDT — Implement

- Ran on the Mac mini worktree at `1613a296`. Implement agent under the harness (worktree-local agent, writes limited by a guard to `LprRepossessions.php` and `LprRepossessionsTest.php`; trusted tools fs_read/fs_write/grep/glob/code/thinking only; exit 0, ~63s, result pass).
- Product: `exportAction()` tooltip and fileName become inline closures over `exportWindow()`. Prefix `lpr-repossessions-claimed-`. Tooltip matches ticket examples (U+2013; "as CSV" only on month). No change to `exportWindow()`, query, exporter, blade, chart.
- Q1: Filament v5.6.0 `cacheAction()` caches the Action instance only; tooltip Closure re-evaluates. HTML uses `Js::from` so the en dash is `\u2013` in the attribute — toggle test keeps the ASCII prefix assert. K9 confirmed (`CsvDownloader` appends `.csv`). PR #489 still OPEN.
- Tests on Mac (PHP 8.4.25 and 8.3.33): `LprRepossessionsTest` 49 passed / 245 assertions (was 39 / 201). Export filter 12 / 81. Exporter guard 1 / 14. Pint clean on touched files. PHPStan L529 match.unhandled is pre-existing and untouched.
- Next: Review.
