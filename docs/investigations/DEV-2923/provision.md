# DEV-2923 Provision — Path A LIVE ledger cited

**Phase:** Provision **Done** · **Next:** Implement  
**Gate:** CLEAN  
**At:** 2026-10-05 8:40am CDT  
**PR:** https://github.com/insight-lpr/platform/pull/502 (draft · base `main`)  
**Tip at provision smoke:** `66a0aaad3414a8c67c5148696555aa4ddfb57c42`

## LIVE ledger (authoritative)

- `/workspace/docs/investigations/DEV-2923/qa-prep-ledger.json`
- `/workspace/first-glance/docs/investigations/DEV-2923/qa-prep-ledger.json`
- Checkout ledger copy: `/workspace/dev-2923-wt/.context/qa-prep-ledger.json` (disk only — never commit `.context/`)
- Prove: `/workspace/docs/investigations/DEV-2923/restand-prove.md` (`prove_passed=true`)

Confirmed from ledger JSON:

| Field | Value |
| --- | --- |
| `prove_passed` | `true` |
| `live` | `true` |
| `cloud_touched` | `false` |
| `mac_herd_refused` | `true` |
| `CLICKHOUSE_DRIVER` | `clickhouse` (not pgsql) |
| Soft #18 | Docker CH ready for forced-timeout prove |

## Fleet endpoints (Path A twin — own, not DEV-2904)

| Service | Bind | Database / path |
| --- | --- | --- |
| Site | `http://127.0.0.1:18091` | login `/app/login` · Soft #18 logging prove |
| Postgres | `127.0.0.1:15443` | `zod_dev_2923` (user `clarity`) · public 139 · platform 41 |
| ClickHouse (Docker) | `127.0.0.1:18134` | `clarity_dev_2923` / `zod_dev_2923` |
| Gateway | `172.19.0.1` | net `clarity-qa-dev-2923` |
| Checkout | `/workspace/dev-2923-wt` | tip `66a0aaad` · branch `cursor/dev-2923-ch-timeout-log-context-4838` · base `main` |
| QA user | — | `test@tester.com` (password via secrets ref) |

**Parallel:** DEV-2904 Path A LEFT UP untouched (`:18090` / `:15442` / `:18133`) — do not steal.

Secrets (refs only): `/workspace/first-glance/secrets/dev-2923-*`, `clarity-qa-dev-2923.env`.

## Smoke results (box, no Mac Herd)

| Check | Result |
| --- | --- |
| GET `/app/login` | **200** |
| PG public BASE TABLE | **139** |
| PG platform BASE TABLE | **41** |
| CH `SELECT 1` | **1** |
| Cloud CH | not queried · `cloud_touched=false` |

## Sealed plan (Implement)

- `docs/investigations/DEV-2923/plan.json` + `plan.md` (Ultra-Plan APPROVE-with-adopted-changes)
- Forced-159 recipe: test-local `timeout_query=2`, `timeout_connect=5`, `retries=0`; settings `timeout_before_checking_execution_speed=0` + `max_block_size=1`; `SELECT * FROM numbers(100000000)`; assert `ch_code===159`
- Platform-only: subclass HTTP client + Connection `createWithClient` + `AppServiceProvider::booted()` re-extend; truncation **4096**; omit `query_id`

## Hard stops (carry to Implement)

- Logging / observability only — do **not** change timeout budgets or rewrite product queries
- Do **not** retune dashboard constants (`LprRepossessions` / `HeldFlexibleCache`)
- Cloud CH **READ-ONLY**
- Platform-only — no `laravel-clickhouse` PR unless `query_id` becomes mandatory (escalate)
- Do **not** steal / reconfigure DEV-2904 Path A twin
- Not a fix for org 469 slow query

## Next

Implement sealed plan against this Path A ledger. Product PHP OK now. Cite this `provision.md` + `qa-prep-ledger.json`.
