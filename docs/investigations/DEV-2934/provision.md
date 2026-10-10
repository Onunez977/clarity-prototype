# DEV-2934 Provision: keyed test PG only (accepted #9 deviation)

**Phase:** Provision **Done** · **Next:** Implement
**Gate:** CLEAN
**At:** 2026-10-08 ~12:57pm CDT (America/Chicago)
**PR:** https://github.com/insight-lpr/platform/pull/512 (draft · base `main`)
**Tip at provision:** `03a45aef` (docs only; no product code)

## Accepted deviation (#9)

PM decision: no fleet Path A site and no ClickHouse twin for this key. The change has no query, UI, copy or ClickHouse surface (`ch_touch: no`). Plan, Implement and Review run on a **keyed test PG DB + `vendor/` + `.env.testing`** in the Implement worktree. Agent QA runs the suites only.

## Ledger

- Implement worktree (disk only, never commit): `<mac-run-dir>/platform-wt/.context/qa-prep-ledger.json`
- Control-plane mirror: `docs/investigations/DEV-2934/qa-prep-ledger.json` (box only). Box checkout copy: `/workspace/dev-2934-wt/.context/qa-prep-ledger.json`
- `provision_mode: keyed_test_pg_only` · `ch_touch: no` · `live: true` · `cloud_touched: false`

## Implement worktree (Mac mini)

| Item | Value |
| --- | --- |
| Worktree | `<mac-run-dir>/platform-wt` · branch `cursor/dev-2934-rdn-unauthorized-case-miss` @ `03a45aef` · no upstream |
| Test DB | Mac local PG `127.0.0.1:5432` · **`zod_testing_dev_2934`**. New DB, created with `createdb -T zod_testing` from the shared baseline, not from another key's DB. platform 41 / public 147 tables · PostGIS 3.4.2 |
| `.env.testing` | `DB_DATABASE=zod_testing_dev_2934`. `CLICKHOUSE_DRIVER=pgsql` with `CLICKHOUSE_DATABASE_*=zod_testing_dev_2934` is set only so the app boots. **Not ClickHouse evidence** (#18/#31); none is needed (`ch_touch: no`) |
| `vendor/` | APFS clone of `Herd/platform/vendor` (`composer.lock` identical) · `composer dump-autoload` OK |
| Runtime | PHP 8.4.25 locally (CI uses 8.3, so keep code 8.3-compatible) · Laravel 12.57.0 |
| Git hygiene | `vendor/`, `.env.testing`, `.ai/`, `.context/`, `<agent-config-dir>/` are all ignored · `git status` clean |

## Baseline (pre-change)

`php artisan test --env=testing tests/Feature/Jobs/Rdn/GetOrgHotlistCaseInfoTest.php tests/Feature/Console/Commands/RdnBackfillBranchesTest.php`
→ **58 passed (213 assertions), 0 failed, 0 skipped** · 10.7 s · `GetOrgHotlistCaseInfoTest` 47 · `RdnBackfillBranchesTest` 11. The spec also lists `RDNTest` and `GetOrgHotlistCasesTest` to run at Implement.

## Carry to Implement

- Checked spec: `.ai/specs/DEV-2934.md` in the worktree (READY · `ch_touch: no` · self-check CLEAN). Mirror: `docs/investigations/DEV-2934/DEV-2934.md`
- The Implement agent runs in this worktree, which has `vendor/` + `.env.testing` (#30).
- Smallest change (#24): one property + getter in `RDN.php`; `isCaseRefusal()` + a refusal branch in `handle()` + `seal` flag on `recordUnresolvedLookup()`; the `classify()` mirror; tests. Nothing else. `config/horizon.php` and `config/*.php` untouched.
- Narrow match only: `SoapFault` + case-insensitive `not authorized to access this case`.
- Never log or serialize `lastCallException()`. Use a fake key only in tests.

## Fences

Cloud CH not contacted · no Docker CH · no site · no Implement started · no Board / no messages.
