# DEV-2736 Agent QA Report — Real Docker ClickHouse · RecoveryLeader selected-window series

**VERDICT: PASS**
**When:** 2026-09-25 12:34:46 CDT (America/Chicago)
**Runner:** Pippin executor on Galadriel fleet Linux box
**PR:** https://github.com/insight-lpr/platform/pull/468 (`cursor/dev-2736-recovery-leaderboard-2a57`) — left draft / not undrafted
**act-on:** NONE · no product code fixes · Pippin baton
**Gate:** PASS · act_on Implement NONE · tip-filter suite green

## Why PASS

Tip suite on **real** `CLICKHOUSE_DRIVER=clickhouse` @ `127.0.0.1:18127` after Review/docs stamp. Checkout stayed on **docs stamp** `6685cf3…` (docs-only); **product tip** `dd61b32…` is a verified ancestor. Ship: RecoveryLeader expanded chart → one selected-window series (kit labels, fractional values) + Scans chrome + Distinct strings; collapsed headline unchanged; RecoveryLeaderQuery stay; no Unique in new chrome. No product patches by QA.

| Check | Result |
|-------|--------|
| RecoveryLeaderTest | **24 OK / 138 assertions** |
| Pint `--test` tip PHP+tests | **PASS (2 files)** |
| `CLICKHOUSE_DRIVER` | **clickhouse** (not pgsql) |
| cloud_touched | **false** |

## Tips

| Role | SHA | Note |
|------|-----|------|
| **Product tip (verified ancestor)** | `dd61b327e3b15691e254b28cb9dd1367fb320fd3` | selected-window series + Scans/Distinct chrome — suites exercise this tree |
| Reviewed content tree | `1930a8c42699ae63c351f87da153f9886714c6f4` | docs journal/state after Implement |
| **Checkout HEAD (docs stamp)** | `6685cf3361b9ed2ea02085bef0cfc82ba90c5e2e` | docs-only stamp; product tip is ancestor — **OK** |

Checkout: `/workspace/dev-2736-wt` at stamp `6685cf3…`. Vendor **copied** (not symlink) from `dev-2738-wt/vendor` into worktree (symlink alone breaks composer `baseDir` under docker).

## Env (passwords redacted)

```
CLICKHOUSE_DRIVER=clickhouse
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=18127
CLICKHOUSE_DATABASE=clarity_testing_dev_2736
CLICKHOUSE_DATABASE_CLARITY=clarity_testing_dev_2736
CLICKHOUSE_DATABASE_ZOD=zod_testing_dev_2736
CLICKHOUSE_HTTPS=false
DB_HOST=127.0.0.1
DB_PORT=15436
DB_DATABASE=zod_testing_dev_2736
DB_USERNAME=clarity
DB_SEARCH_PATH=platform
CACHE_STORE=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
MAIL_MAILER=array
PENNANT_STORE=array
PGTZ=UTC
```

- CH container: `clarity-qa-clickhouse-dev-2736` (HTTP `18127→8123`)
- Keyed `clarity_dev_2736` table_count=**31** intact; suites used keyed testing DBs (no classic `clarity`/`zod` create needed)
- Never Cloud CH · Never refused ports (:18085/:15437/:18128, :18083/:15435/:18126, :18125, :18124)
- PHPUnit: `clarity-qa-phpunit:psql`, `--network host`, PHP 8.3.23, `memory_limit=1024M`
- mac_herd_refused=true · cloud_touched=false
- Fleet env fix (not product): `ALTER TABLE public.spatial_ref_sys OWNER TO clarity` on `zod_testing_dev_2736` so DatabaseTruncation can truncate PostGIS catalog

## Preflight prove

| Check | Result |
|-------|--------|
| `GET http://127.0.0.1:18084/app/login` | **200** |
| clarity_dev_2736 table_count | **31** |
| PG `127.0.0.1:15436` zod_testing_dev_2736 | connectable |
| `CLICKHOUSE_DRIVER` in PHPUnit process | **clickhouse** (PROCESS_CLICKHOUSE_DRIVER proven) |
| Refused sibling/torn ports | untouched |

## Pint

`vendor/bin/pint --test` on tip ship PHP + tip tests → **PASS (2 files)**

- `app/Filament/Widgets/Dashboard/RecoveryLeader.php`
- `tests/Filament/Widgets/Dashboard/RecoveryLeaderTest.php`

## PHPUnit (real CH) — tip filter

**PASS (24 tests, 138 assertions)** · Fail **0** · Error 0 · **Skip 0** · ~10.6s · Memory 85 MB

```
BOOT CLICKHOUSE_DRIVER=clickhouse HOST=127.0.0.1:18127 DB=clarity_testing_dev_2736
PG=127.0.0.1:15436/zod_testing_dev_2736
FILTER=tests/Filament/Widgets/Dashboard/RecoveryLeaderTest.php
```

Expected simulated `testing.ERROR` lines (invalid start date / stubbed `clarity.recovery_scans missing`) appear in the log — intentional negative-path coverage, not failures.

## Q1–Q3 (matched)

1. **Scans chrome / Distinct Recoveries** — heading `LPR Recovery Leaderboard`; dataset label `Distinct Recoveries`; options contain `Distinct recoveries`; Scans leader green on max bar
2. **Top 10 kits by recoveries** — asserted in chrome / options
3. **Distinct recoveries / chartSeries sum monthly top-10** — single selected-window series; fractional kit values preserved; collapsed headline unchanged

Also: `test_the_word_unique_never_appears_in_recovery_leader_chrome` — no Unique in new chrome.

## Soft OKs

- Live OTP/Pro / full Playwright: **NOT COVERED** (curl login smoke required + done)
- Query seams heavily stubbed in RecoveryLeaderTest — live CH not hit for most asserts; driver still proven `clickhouse` against Docker :18127
- SHOW CREATE VIEW gaps: N/A / soft OK

## Hard rules held

- No product patches by QA
- No Cloud CH writes (`cloud_touched=false`)
- No refused ports touched
- Fleet left **UP** · no undraft · no Board · no Jira · no git commit/push

## Artifacts

- `/workspace/dev-2736-wt/.context/qa-agent-report.md`
- `/workspace/dev-2736-wt/.context/qa-ac-results.json`
- `/workspace/dev-2736-wt/.context/qa-plan-agent.md`
- `/workspace/docs/investigations/DEV-2736/agent-qa-real-ch.md`
- `/workspace/docs/investigations/DEV-2736/phpunit-real-ch.log`
- `/workspace/docs/investigations/DEV-2736/pint-real-ch.log`
- Mirrored under `/workspace/first-glance/docs/investigations/DEV-2736/`
