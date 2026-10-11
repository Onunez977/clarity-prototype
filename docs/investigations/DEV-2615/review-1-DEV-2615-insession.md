# Review RN1 — DEV-2615 (in-session, no_spawn)

Score: 93/100

## Summary
Restores sort-key-leading `org_id` prune on Scans ClickHouse using historical orgs from `zod.kit_last_scan`, unioned with current Postgres kit owners. Authorization remains `Kit::getKits`. Matches the ticket's measured fix.

## Scoring findings
None.

## Non-scoring notes
- N1 (low): `catch (\Throwable)` falls back to current-owner prune if `kit_last_scan` is down — correct vs full-table scan; document in Build Record.
- N2 (info): 30s timeout is server `max_execution_time` on `clarity_clickhouse` (no settings.max_execution_time); `CLICKHOUSE_TIMEOUT_QUERY` is client/HTTP. Confirmed; no config change in scope.
- N3 (info): Date floor deferred by human Q1.

## AC coverage
- org prune from kit_last_scan: SATISFIED (code + shape test)
- EXPLAIN tens of millions: SATISFIED by ticket measurement (perf=go); re-check post-deploy
- moved-kit parity: SATISFIED (ClickhouseScanQueryParityTest)
- p50 / error 159: PENDING prod (human QA / post-deploy)
- cache + forgetCachedKits: SATISFIED (shape cache test + Kit.php)
- 30s timeout source: SATISFIED (documented)

## Residual risk
Prod p50 must be watched after deploy; Hits still unpruned (DEV-2614).
