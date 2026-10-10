# DEV-2339 Plan

## Outcome
Stop `ScoutOpenOrderAddressesQuery` from hitting ClickHouse execution timeouts by adopting **approach B (prune-first)**: keep the load-bearing `id IN (address_links)` primary-key prune on `zod.public_address`, then join metadata from `address_links`.

## Why not A
Measured on Cloud (org 123): deleting the `IN` causes `public_address` PrimaryKey `Condition: true` (full scan) and times out at the 20s client ceiling. Ticket lead rejected.

## Subtasks
### t1 — prune-first rewrite (simple)
- Rewrite SQL in `ScoutOpenOrderAddressesQuery`
- Docblock: IN = PK prune, JOIN = metadata; keep BOTH
- Run `ScoutRecordCheckInsCommandTest`
- Out of scope: Rollup sibling, DEV-2342, timeout bumps, C/E

## Acceptance
- Query completes under ceiling for large orgs (B measured 13.4s vs CURRENT 15.4s)
- Semantics unchanged (Direct/Staging, direct-prefer, latest CDC coords, geo guards)
- No max_execution_time increase
