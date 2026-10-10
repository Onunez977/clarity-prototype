# DEV-2659 Path A restand prove

- **When:** 2026-09-24 19:03:54 CDT (America/Chicago)
- **Result:** LIVE
- **cloud_touched:** false
- **mac_herd_refused:** true
- **Refuse reuse:** clarity-qa-*-dev-2654 / SCHEMA-DRIFT :18125 leftovers (ports are 18083/15435/18126)

## Containers / ports

| Role | Container | Publish |
|------|-----------|---------|
| App | clarity-qa-app-dev-2659 | 0.0.0.0:18083→80 |
| Postgres | clarity-qa-postgres-dev-2659 | 0.0.0.0:15435→5432 |
| ClickHouse | clarity-qa-clickhouse-dev-2659 | 0.0.0.0:18126→8123 |

Network: `clarity-qa-dev-2652` gateway `172.18.0.1` (DB_HOST / CLICKHOUSE_HOST).

## HTTP probes

| URL | Code |
|-----|------|
| GET http://127.0.0.1:18083/app/login | **200** |
| GET http://127.0.0.1:18083/ | 302 |
| GET http://127.0.0.1:18083/app | 302 |

## Postgres

- DBs: `zod_dev_2659` (fixture restored: ~139 public BASE TABLE, 41 platform), `zod_testing_dev_2659` (empty + postgis)
- User: clarity @ :15435
- QA user id=1 email=test@tester.com present

## ClickHouse (Docker :18126 only — not Cloud)

| Database | table_count |
|----------|-------------|
| clarity_dev_2659 | **31** |
| clarity_testing_dev_2659 | 28 |
| zod_dev_2659 | 36 |
| zod_testing_dev_2659 | 36 |

billing_claim on `clarity_dev_2659`:
- billing_claim_events
- billing_claim_events_v
- billing_claim_events_mv

CLICKHOUSE_DRIVER=clickhouse · CLICKHOUSE_HOST=172.18.0.1 · CLICKHOUSE_PORT=18126

## Ledgers written (status=live, phase=env_ready / provisioned_fleet)

- /workspace/docs/investigations/DEV-2659/qa-prep-ledger.json
- /workspace/first-glance/docs/investigations/DEV-2659/qa-prep-ledger.json
- /workspace/DEV-2659/qa-prep-ledger.json
