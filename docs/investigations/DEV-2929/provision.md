# DEV-2929 Provision — keyed test PG only (accepted #9 deviation)

**Phase:** Provision **Done** · **Next:** Implement
**Gate:** CLEAN
**At:** 2026-10-08 ~8:02am CDT (America/Chicago)
**PR:** https://github.com/insight-lpr/platform/pull/511 (draft · base `main`)
**Tip at provision:** `fb48a40e` (docs only; no product code)

## Accepted deviation (#9)

PM decision: no fleet Path A site / ClickHouse twin for this key now. Both Path A slots are held for human QA on DEV-2450 and DEV-2928. For Plan / Implement / Review, a **keyed test PG DB + `vendor/` + `.env.testing`** in the Implement worktree is enough: the widget tests stub the widget and make zero ClickHouse calls, and `ch_touch: no`.

**Agent QA environment:** a running-site twin for Agent QA (#27: tooltip per toggle, tooltip refresh after a toggle, downloaded filename) is **stood by PM at QA Ready** when a slot frees. The expanded card renders its chart from ClickHouse, so that site needs a reachable (even empty) per-key ClickHouse DB.

## Ledger

- Implement worktree (disk only, never commit): `<mac-run-dir>/platform-wt/.context/qa-prep-ledger.json`
- Control-plane mirror: `docs/investigations/DEV-2929/qa-prep-ledger.json` (box only) · box checkout copy `/workspace/dev-2929-wt/.context/qa-prep-ledger.json`
- `provision_mode: keyed_test_pg_only` · `ch_touch: no` · `live: true` · `cloud_touched: false`

## Implement worktree (Mac mini)

| Item | Value |
| --- | --- |
| Worktree | `<mac-run-dir>/platform-wt` · branch `cursor/dev-2929-export-window-filename` @ `fb48a40e` · no upstream |
| Test DB | Mac local PG `127.0.0.1:5432` · **`zod_testing_dev_2929`** (new; cloned from the shared `zod_testing` baseline template, not another key's DB) · platform 41 / public 147 tables · PostGIS 3.4.2 |
| `.env.testing` | `DB_DATABASE=zod_testing_dev_2929`; `CLICKHOUSE_DRIVER=pgsql` with `CLICKHOUSE_DATABASE_*=zod_testing_dev_2929` only so the app boots. **Not ClickHouse evidence** (#18/#31); no ClickHouse claim is made or needed (`ch_touch: no`) |
| `vendor/` | APFS clone of `Herd/platform/vendor` (`composer.lock` identical to `0e5ae042`) · `composer dump-autoload` OK |
| Runtime | PHP 8.4.25 locally (CI 8.3, keep code 8.3-compatible) · Laravel 12.57.0 · Filament v5.6.0 |
| Git hygiene | `vendor/`, `.env.testing`, `.ai/`, `.context/`, `<agent-config-dir>/` all ignored; `git status` clean |

## Baseline (pre-change)

`php artisan test --env=testing tests/Filament/Widgets/Dashboard/LprRepossessionsTest.php`
→ **39 passed (201 assertions), 0 failed, 0 skipped** · 9.7 s · includes `test_export_action_is_csv_only_and_does_not_cap_rows` (old filename assert L1261, which Implement changes) and `test_export_window_uses_utc_month_buckets_and_viewer_local_days` (stays unchanged).

## Carry to Implement

- Spec (checked): `.ai/specs/DEV-2929.md` in the worktree (READY · `ch_touch: no` · self-check CLEAN) · mirror `docs/investigations/DEV-2929/DEV-2929.md`
- Implement agent runs in this worktree: `vendor/` + `.env.testing` present (#30).
- Q1: confirm against installed Filament v5.6.0 that `cacheAction()` doesn't freeze the tooltip and how the tooltip attribute is encoded in heading HTML (decides spec §5 toggle-test step 5).
- If PR #489 (Filament 5.9) merges first, rebase and re-run the tests.
- Smallest change (#24): two inline closures at L653 / L656 + tests; nothing else.

## Fences

Cloud CH not contacted · no Docker CH · no site · #374 / #509 / #510 untouched · no Implement started.
