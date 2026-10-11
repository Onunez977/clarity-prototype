Insight AI — Build Record
<!-- build-record: DEV-2381 -->

**Route:** bug (DEV-2342 W1) · **Base:** main · **PR:** https://github.com/insight-lpr/platform/pull/338 · **Branch:** `cursor/dev-2381-sc-positions-projection-95f7`

## Verdict

**There is no platform Cloud/application DDL until Cloud apply.** That is the correct `/ticket` outcome, not a skip.

chlocal owns Cloud `clarity.sc_positions` DDL/ingest (precedent DEV-2177). This run did **not** apply DDL to ClickHouse Cloud, did **not** add `ALTER TABLE … ADD PROJECTION` (or MATERIALIZE) to a platform migration/PHP/deploy hook, and did **not** uncomment `scout:record-check-ins`.

Follow-up (Dalton): the **CI** dump now includes `proj_kit_mapping_lc` so Docker PHPUnit sees the projection before Cloud apply. Workflow YAML is unchanged.

## What this run produced

| Artifact | Why |
| --- | --- |
| `docs/investigations/DEV-2381/operator-handoff.md` | Exact locked ALTER + off-peak MATERIALIZE (~6.5 GiB) for the chlocal operator |
| `docs/investigations/DEV-2381/explain-runbook.md` | Canary + post-MATERIALIZE EXPLAIN; pass = tens of granules, not ~3355 |
| `docs/investigations/DEV-2381/wiki-follow-through.md` | Queued okf + `/chlocal` schema-dump steps — **not applied** (WRITE-after-approval; projection not on Cloud) |
| `database/schema/clickhouse-schema.sql` | Hand-edit: `proj_kit_mapping_lc` + `deduplicate_merge_projection_mode=drop` on CI `clarity.sc_positions` |
| `tests/Unit/Clickhouse/Queries/ScPositionsLowercaseProjectionTest.php` | Pins the CI dump; does not assert Cloud granule counts |
| Jira link | DEV-2381 **blocks** DEV-2342 (W4 stays gated) |

## Locked design (not re-litigated)

Jeffrey Cobb, [DEV-2342 comment 38238](https://insightlpr-team.atlassian.net/browse/DEV-2342?focusedCommentId=38238):

```sql
ALTER TABLE clarity.sc_positions
ADD PROJECTION proj_kit_mapping_lc
(
    SELECT *
    ORDER BY (lower(kit_mapping), timestamp_utc)
);

ALTER TABLE clarity.sc_positions
MATERIALIZE PROJECTION proj_kit_mapping_lc;
```

Rejected (do not implement): ALIAS, virtual column, table rebuild, bloom skip index as the shipped fix, PHP rewrite off of `lower(kit_mapping)`, GPS decimation.

## Confirmed in this repo (HEAD main)

- `routes/console.php` ScoutRecordCheckIns schedule **remains commented** (W4).
- Local `database/schema/clickhouse-schema.sql` `clarity.sc_positions` is still the column-stale subset the ticket names (no `id`/`gl`/`timestamp_local`/`ingested_at`) **plus** hand-added `proj_kit_mapping_lc` for CI. Do not ALTER Cloud from it. Refresh via `.claude/commands/chlocal.md` **after** Cloud apply.
- Readers already filter `lower(kit_mapping)`: `ScoutCheckInMatchQuery`, `KitHistoricPositionsQuery`, `KitRangePathsQuery`, `OrgLivePositionsQuery`, `OrgLivePathsQuery`, `CloseInactiveShiftsCommand`, `ClarityScoutMapController`. Zero rewrite this run.
- Pre-DDL EXPLAIN baseline (DEV-2342 t1, 2026-08-13): `lower()=` **3355/33096** vs exact-case **20/33096**. ClickHouse MCP was unauthenticated this run; Cloud was not queried; DDL was not applied.

## Acceptance criteria

- [ ] **Cloud** `proj_kit_mapping_lc` added + materialized — **chlocal operator** (handoff written)
- [ ] **EXPLAIN** single-kit 15-minute `lower(kit_mapping)=?`: tens of granules, not ~3355 — **chlocal operator** (runbook written)
- [ ] **query_log** matcher p50 well below 400ms, `read_rows` well below ~500k — **chlocal operator**
- [x] **Platform PHP** unchanged; `lower(kit_mapping)` stays
- [x] **Schedule** stays commented (W4)
- [x] **CI dump** includes `proj_kit_mapping_lc` (`database/schema/clickhouse-schema.sql` + `ScPositionsLowercaseProjectionTest`)
- [ ] **Wiki + Cloud schema dump** — after Cloud apply ([wiki-follow-through.md](wiki-follow-through.md)); `/chlocal` re-export replaces the CI hand edit
- [ ] **Recorder** kits no longer skip with CH 159; manual full cycle under 5 minutes — DEV-2342 W4, after this lands

## Tests / review / QA

`ScPositionsLowercaseProjectionTest` is `RequiresClickhouse`-gated and will run on GHA (`CLICKHOUSE_DRIVER=clickhouse`). Not run in this agent (no PHP in the environment; CLAUDE.md: never run the full suite automatically). No Cloud EXPLAIN in PHPUnit. No QA env. Adversarial review of application code: N/A (no matcher rewrite).

## How numbers were derived

Granule counts and Cloud column list: `docs/investigations/DEV-2342/derivation_log.md` (shapes + aggregates only). Table size ~6.5 GiB / ~268–271M rows / TTL 120d: comment 38238 + that log. No new Cloud queries this run.

## Next owner

**chlocal operator** — execute [operator-handoff.md](operator-handoff.md), then [explain-runbook.md](explain-runbook.md). Then platform: `/chlocal` dump + wiki notes. Then DEV-2342 W4: manual `php artisan scout:record-check-ins`; do not uncomment the schedule until that cycle is under 5 minutes and `system.mem.pct_usable` stays flat.
