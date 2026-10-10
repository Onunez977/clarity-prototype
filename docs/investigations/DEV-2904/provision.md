# DEV-2904 Provision — Path A LIVE ledger cited

**Phase:** Provision **Done** · **Next:** Implement  
**Gate:** CLEAN  
**At:** 2026-10-02 3:18pm CDT  
**PR:** https://github.com/insight-lpr/platform/pull/501 (draft · base `main`)  
**Tip at provision smoke:** `b6a99e6e45a9856818bcb0d61a2de10b59b1954b`

## LIVE ledger (authoritative)

- `/workspace/docs/investigations/DEV-2904/qa-prep-ledger.json`
- `/workspace/first-glance/docs/investigations/DEV-2904/qa-prep-ledger.json`
- Checkout ledger copy: `/workspace/dev-2904-wt/.context/qa-prep-ledger.json`
- Prove: `/workspace/docs/investigations/DEV-2904/restand-prove.md` (`prove_passed=true`)

Confirmed from ledger JSON:

| Field | Value |
| --- | --- |
| `prove_passed` | `true` |
| `live` | `true` |
| `cloud_touched` | `false` |
| `mac_herd_refused` | `true` |
| `CLICKHOUSE_DRIVER` | `clickhouse` (not pgsql) |
| Plan tables | billing_claim_events(+_v/_mv), hotlist, public_hotlist, public_hotlist_status_log, hotlist_json_denormalized, public_orgs |

## Fleet endpoints (Path A twin)

| Service | Bind | Database / path |
| --- | --- | --- |
| Site | `http://127.0.0.1:18090` | login `/app/login` · UI twin Company Metrics / LPR Repossessions |
| Postgres | `127.0.0.1:15442` | `zod_dev_2904` (user `clarity`) |
| ClickHouse (Docker) | `127.0.0.1:18133` | `clarity_dev_2904` / `zod_dev_2904` |
| Gateway | `172.18.0.1` | app → DB/CH east-west |
| Checkout | `/workspace/dev-2904-wt` | tip `b6a99e6e` · branch `cursor/dev-2904-lpr-repos-vin-export-24e1` · base `main` |
| QA user | — | `test@tester.com` (password via secrets ref) |

Secrets (refs only): `/workspace/first-glance/secrets/dev-2904-*`, `clarity-qa-dev-2904.env`.

## Smoke results (box, no Mac Herd)

| Probe | Result |
| --- | --- |
| `GET http://127.0.0.1:18090/app/login` | **200** |
| `GET http://127.0.0.1:18090/app` (unauth) | **302** (expected) |
| PG public BASE TABLE count | **139** |
| CH `SELECT 1` | **1** (RO only; no writes) |
| CH plan tables (zod + clarity) | **all present** (incl. `hotlist_json_denormalized`) |
| Cloud CH | **untouched** |

## Scope of this phase

Provision cites the Galadriel Path A LIVE twin for Implement. **No product PHP** in this revision.

Implement (next) follows sealed `plan.md` / `plan.json`: listing `rowsSql()` projecting AC-3 columns, house CSV exporter, expanded-chart `ExportAction`. Aggregate + shared cache keys untouched.

## Hard stops

- Aggregate `uniqExactIf` / dedup / backyard / shared caches: **unchanged**
- CSV only; no client picker; Repo Agent Name blank; no `remote_order_id` as case
- **No Cloud ClickHouse writes.** Docker CH for Implement/Agent QA only
- **Do not tear down** Path A fleet in this phase
- **Do not undraft** PR #501 in this phase
- Branch path **main→main**
- Mac Herd is **not** the live-QA host

## Artifacts

- `docs/investigations/DEV-2904/provision.md` (this file)
- `docs/investigations/DEV-2904/provision.json`
- LIVE ledger paths above
