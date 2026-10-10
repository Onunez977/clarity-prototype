# SCHEMA-DRIFT-2657 journal

Track: **SCHEMA-DRIFT-2657**. Thin platform mirror. Progress ticket [DEV-2657](https://insightlpr-team.atlassian.net/browse/DEV-2657).

**Phase:** implemented
**Done:** intake · Ultra-Prep · Question Gate (CLEAN) · Ultra-Plan · ticket provision (fleet Docker CH cite · slim) · Implement
**Next:** Review
**Status:** Next Review
**Open questions:** none

## Intake (2026-09-24T18:48:59Z)

Repo is insight-lpr/platform. Base is `origin/main` `c811e34e482805df5e7795c118489d1e55c379f4`. Artifact directory is `docs/investigations/SCHEMA-DRIFT-2657/`. Ship surface is `database/schema/clickhouse-schema.sql` only.

Frozen source is insight-lpr/chlocal PR 37, open, head `a9e366bb21fee0702fd827a9d7c57488be57fc69` (`a9e366bb`). Subject: `Merge branch 'main' into claude/dev-2657-e32bd2`. That SHA is the pin. It was not moved forward.

DEV-2657 was read as status **In Code Review**, assignee **Dalton McCleery**. Status and assignee stay as read.

DEV-2660, Cloud apply, the backfill script, and product PHP are out of scope. ClickHouse Cloud stays read-only.

## Prep (2026-09-24T18:48:59Z)

`migrations/116_dev_2657_hotlist_status_timestamps.sql` at `a9e366bb21fee0702fd827a9d7c57488be57fc69` (blob `235ac8e9eafca3e499e29c607dfe499657ec5c98`, 29039 bytes) is the DDL source.

On `c811e34e482805df5e7795c118489d1e55c379f4`, `database/schema/clickhouse-schema.sql` counts:

- `first_open_at` 0
- `hotlist_incremental` 0
- `prj_id_lookup_v2` 0
- `_peerdb_hsl_synced_at` 0
- `prj_id_lookup` 1 (inside `CREATE TABLE clarity.hotlist`, line 24)

Header: `-- TABLES (64)`, `-- VIEWS (13)`, `-- MATERIALIZED VIEWS (5)`, `-- Generated: 2026-09-01`.

The dump's hotlist column list and `prj_id_lookup` select list match chlocal `database/schemas/clickhouse/clarity/tables/hotlist.sql` at the pin. The engine in the dump is already `ReplacingMergeTree(_version, _peerdb_is_deleted)`.

Those chlocal schema files are the pre-migration export. `hotlist.sql` has no `first_*` columns and no `prj_id_lookup_v2`. `hotlist_incremental_mv.sql` has no status-log arm and uses a personal `DEFINER`. They are not the shape to copy.

## Question gate (2026-09-24T18:48:59Z)

**CLEAN.** No open questions.

The migration was fetched at the pin. The splice uses the existing dump line plus the migration's `ADD COLUMN` order, `ADD PROJECTION` list, and section-4 `CREATE MATERIALIZED VIEW`. Comments are stripped, including the semicolon inside `-- replace;`. Whitespace is collapsed to the dump's one-line style. `DEFINER = default SQL SECURITY DEFINER` is kept.

## Plan (2026-09-24T18:48:59Z)

`plan.json` and `plan.md` hold the two statements.

- `clarity.hotlist` sha256 `ba93b813d584a900f4076a92550d26936a5242a025d637caaf7535250024e875`
- `clarity.hotlist_incremental_mv` sha256 `383185efb27168c87bd49ac7bdbad0338f456ed8fb598bdf47e1b32e895511b8`

Next is Provision. This run does not mark provisioned and does not apply the schema.

## PR (2026-09-24T18:50:04Z)

Draft PR https://github.com/insight-lpr/platform/pull/461. Assignee DaltonMcCleery. DEV-2657 progress comment 41704. Status left In Code Review. Phase stays plan. Next stays Provision.

## Path A correction (2026-09-24T18:55:00Z)

DEV-2654 fleet is torn_down (~13:02 CT). Ports 18082, 15434, and 18124 are free. It is not a live second ledger.

Soft Path A is versus the DEV-2555 SCHEMA-GATE twins still on :18081 / :15433 / :18123. This track does not reuse those ports.

## Provision (2026-09-24T19:00:12Z)

Provision-only. Fleet Docker ClickHouse cite, slim. No site. No Postgres. Mac Herd refused. Cloud ClickHouse not written.

Ledger `docs/investigations/SCHEMA-DRIFT-2657/qa-prep-ledger.json` (stood `2026-09-24T13:57:39-05:00`, status `live`, `slim` true). Handoff copy `.context/qa-prep-ledger.json` stays untracked. The ledger file is not committed.

- ClickHouse strategy `docker`. Container `clarity-qa-clickhouse-schema-drift-2657`. HTTP `:18125`. Local `http://127.0.0.1:18125`. Fleet `http://172.30.0.2:18125`. Database `clarity_schema_drift_2657`.
- DSN local `http://default@127.0.0.1:18125/clarity_schema_drift_2657`. DSN fleet `http://default@172.30.0.2:18125/clarity_schema_drift_2657`.
- Prove: ping Ok, table count 31, `hotlist` present. `first_*_at` and `_peerdb_hsl_synced_at` are absent. That absence is the drift until the schema mirror of chlocal `a9e366bb` / migration 116 lands.
- `cloud_ch_writes`: false. `mac_herd_refused`: true. `cloud_touched`: false. `site`: null. `postgres`: null.

`state.json` phase is `provisioned`. Gate stays CLEAN. Next is **Implement**. DEV-2657 progress comment 41717. Status left In Code Review. Assignee Dalton McCleery.

## Implement (2026-09-24T19:06:37Z)

Schema mirror only. `database/schema/clickhouse-schema.sql` at `1f5d8491`.

- `CREATE TABLE clarity.hotlist` sha256 `ba93b813d584a900f4076a92550d26936a5242a025d637caaf7535250024e875`. Six `Nullable(DateTime64(6))` columns after `close_date`. `_peerdb_hsl_synced_at` `DateTime64(9) DEFAULT now64()` after `_peerdb_hm_synced_at`. `prj_id_lookup` kept. `prj_id_lookup_v2` added with `first_repossessed_at`.
- `CREATE MATERIALIZED VIEW clarity.hotlist_incremental_mv` sha256 `383185efb27168c87bd49ac7bdbad0338f456ed8fb598bdf47e1b32e895511b8`. Placed after `clarity.billing_claim_events_mv` and before `zod.eric_test`. `DEFINER = default SQL SECURITY DEFINER` kept. The collapsed statement matches section 4 of chlocal `migrations/116_dev_2657_hotlist_status_timestamps.sql` at `a9e366bb21fee0702fd827a9d7c57488be57fc69` (blob `235ac8e9eafca3e499e29c607dfe499657ec5c98`).
- Header: `-- TABLES (64)`, `-- VIEWS (13)`, `-- MATERIALIZED VIEWS (6)`, `-- Generated: 2026-09-01`.

Excluded, as planned: `MATERIALIZE COLUMN`, `MATERIALIZE PROJECTION`, `DROP VIEW`, the commented `DROP PROJECTION prj_id_lookup`, and the backfill script.

Prove on ClickHouse `26.2.19.43` at `127.0.0.1:8123` (the agent server already running). Scratch database `clarity_schema_drift_2657_prove` accepted the table and the view. `SHOW CREATE` and `system.projections` showed the seven columns, both projections, `ReplacingMergeTree`, and `REFRESH EVERY 1 MINUTE` with `DEFINER = default SQL SECURITY DEFINER`. The scratch database was dropped. Fleet HTTP `:18125` (`127.0.0.1` and `172.30.0.2`) did not connect. ClickHouse Cloud was not written.

`state.json` phase is `implemented`. Gate stays CLEAN. Status is **Next Review**. Next is **Review**. DEV-2657 stays In Code Review. Assignee stays Dalton McCleery. No QA Ready. No board post. Draft PR stays draft.

## Review rn=1 — PASS min 90 (2026-09-24T19:10:35Z)

- Tip `1787e0462e96af3e12dbb8743006fda2f271556f` PR #461 (draft).
- Lanes: correctness 92 / conventions 90 / blast-radius 91. Gate exit 0.
- Act-on Implement: NONE.
- Next: Verify (Jeff-skip; undraft after Build Record; green CI = merge gate; no QA Ready).
- Soft notes: fleet :18125 unreachable at prove; OKF lag on first_* columns; chlocal PR 37 open.

## 2026-09-24 ~14:15 CDT — Verify / Build Record

- Preflight #18: fleet Docker CH `127.0.0.1:18125` ping Ok, version 25.8.11.66, DB `clarity_schema_drift_2657`.
- Tip statement hashes MATCH plan (`ba93b813…` / `383185ef…`).
- Applied remapped tip CREATE TABLE hotlist; DESCRIBE proves seven new fields; `system.projections` proves `prj_id_lookup` + `prj_id_lookup_v2`. Engine ReplacingMergeTree.
- MV CREATE soft-fail: missing `zod_schema_drift_2657.public_orgs_latest` on slim stand — hard bar (table+projections) cleared.
- Build Record posted DEV-2657 comment **41731**.
- state.phase=done; next=Undraft/CI (Jeff-skip; no QA Ready). Leave env UP.
- Gates: BUILD_RECORD_GATE pass id=41731; REWORK_GATE pass (docs-only since reviewed_sha).
- PR 461 ticket-status rewritten; stays DRAFT.
