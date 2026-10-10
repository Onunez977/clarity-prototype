<!-- ticket-status -->
**Ticket:** [DEV-2381](https://insightlpr-team.atlassian.net/browse/DEV-2381)
**Parent:** [DEV-2342](https://insightlpr-team.atlassian.net/browse/DEV-2342) W1
**Phase:** handoff · **Route:** bug · **Mode:** normal
**Done:** intake · prep · gate CLEAN (design locked, comment 38238) · plan · implement (docs + CI dump) · no Cloud DDL · no PHP matcher rewrite
**Open questions:** none

chlocal owns Cloud `clarity.sc_positions` DDL (DEV-2177). This PR is still **not** a Cloud ALTER: exact `ADD PROJECTION proj_kit_mapping_lc`, off-peak `MATERIALIZE` (~6.5 GiB), success EXPLAIN (tens of granules, not ~3355). Until that Cloud apply, the **CI** dump (`database/schema/clickhouse-schema.sql`) includes the same projection so Docker PHPUnit sees it; `ScPositionsLowercaseProjectionTest` pins that.

**Not in this PR (and must not be):** `ALTER TABLE clarity.sc_positions ADD PROJECTION` in a platform migration/PHP/deploy hook; uncommenting `scout:record-check-ins` (DEV-2342 W4); PHP rewrite off of `lower(kit_mapping)`; ALIAS / virtual column / table rebuild / bloom skip index as the shipped fix.

**Artifacts:** `docs/investigations/DEV-2381/`
<!-- /ticket-status -->

## Why platform still cannot ship the Cloud ALTER

Jeffrey Cobb locked the design on DEV-2342 comment 38238. After DEV-2342 W2, the recorder is one ClickHouse statement per kit; duration is still the `lower(kit_mapping)` PK bypass on `clarity.sc_positions` (3355/33096 granules vs 20/33096 exact-case). The projection is extra data ordered for that filter so Scout PHP can keep emitting `lower(kit_mapping)`.

Platform cannot legally ship the Cloud ALTER. The remaining production work is a Cloud mutation in **chlocal**.

## CI (this follow-up)

GitHub Actions already loads `database/schema/clickhouse-schema.sql` into `clickhouse/clickhouse-server:26.2` (`lint_check_and_test.yml`, `deploy.yml`). This PR adds `proj_kit_mapping_lc` to that CREATE (plus `deduplicate_merge_projection_mode = 'drop'`, required for ReplacingMergeTree + projection on 24.8+/26.2; same setting as `zod.public_hotlist_status_log` in the dump). `ScPositionsLowercaseProjectionTest` asserts the projection exists. That does **not** prove Cloud granule prune.

After Cloud apply: re-export the dump via `/chlocal` so it picks up real Cloud columns and replaces this hand edit.

## Operator packet

- [operator-handoff.md](docs/investigations/DEV-2381/operator-handoff.md) — exact SQL
- [explain-runbook.md](docs/investigations/DEV-2381/explain-runbook.md) — canary + pass/fail
- [wiki-follow-through.md](docs/investigations/DEV-2381/wiki-follow-through.md) — after Cloud apply only
