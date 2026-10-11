# DEV-2381 — Plan (single-pass; design locked)

**App:** clarity · **Route:** bug (W1 of DEV-2342) · **Scope:** platform follow-through only
**Out of scope:** Cloud `ALTER`/`MATERIALIZE` (chlocal), PHP matcher rewrite, GPS decimation, uncommenting `scout:record-check-ins` (W4), okf writes before Cloud apply, platform ClickHouse *migrations*.
**UAT needed:** no. **perf_validation:** not-required (this run does not change a query; WS6 not triggered by docs). CI schema + schema-assertion test do not prove Cloud prune.

Platform `/ticket` concludes: **there is no platform application-code change until Cloud apply.** That is the valid outcome. This plan ships the operator handoff the chlocal owner needs, plus queued schema-dump/wiki instructions. Follow-up (Dalton): CI dump + PHPUnit pin `proj_kit_mapping_lc` so unit tests see the projection before Cloud apply.

## Ordered subtasks

| id | title | complexity | depends_on | target_files |
|----|-------|-----------|-----------|--------------|
| t1 | chlocal operator handoff: exact ALTER, off-peak MATERIALIZE, success EXPLAIN, schema-dump + queued wiki | simple | — | `docs/investigations/DEV-2381/*` |
| t2 | CI ClickHouse dump + unit test: `proj_kit_mapping_lc` on `clarity.sc_positions` before Cloud apply | simple | t1 | `database/schema/clickhouse-schema.sql`, `tests/Unit/Clickhouse/Queries/ScPositionsLowercaseProjectionTest.php` |

**DAG:** t1 → t2.

## AC coverage map (this repo)

- **Projection exists on Cloud + MATERIALIZE done** → chlocal operator ([operator-handoff.md](operator-handoff.md)). Not executable from platform.
- **EXPLAIN tens of granules, not ~3355** → chlocal operator ([explain-runbook.md](explain-runbook.md)). Baseline already in DEV-2342 derivation_log.
- **Platform PHP unchanged** → satisfied by not touching `app/`, `database/migrations/`, `routes/console.php`.
- **Wiki + Cloud schema dump** → queued ([wiki-follow-through.md](wiki-follow-through.md)); `/chlocal` re-export after Cloud success replaces the CI hand edit.
- **Schedule stays commented** → confirmed `routes/console.php` lines 58–69.
- **CI dump has `proj_kit_mapping_lc`** → t2 (`clickhouse-schema.sql` + `ScPositionsLowercaseProjectionTest`). Workflow YAML already loads that file.

## Excluded / deliberately-not-covered sites

- `database/migrations/**` — no ClickHouse DDL in this repo for `clarity.sc_positions`.
- `app/Clickhouse/Queries/ScoutCheckInMatchQuery.php` and every other `lower(kit_mapping)` reader — PHP keeps emitting `lower()`; the projection is what prunes.
- `routes/console.php` ScoutRecordCheckIns schedule — W4.
- `okf/**` — WRITE-after-approval; projection not shipped on Cloud.
- Cloud `ALTER`/`MATERIALIZE` — chlocal; CI dump is not the CREATE to ALTER.

## Sizing gate

Operator handoff + CI dump/test. **Verdict: ship as ONE ticket** (this is already the split-out W1). No Cloud ALTER from platform. No PHP matcher rewrite.

## Blast radius

None in platform runtime or Cloud. CI Docker ClickHouse CREATE for `clarity.sc_positions` now includes `proj_kit_mapping_lc` (GHA already loads this dump). The load-bearing production change remains Cloud DDL owned by chlocal (~6.5 GiB MATERIALIZE).
