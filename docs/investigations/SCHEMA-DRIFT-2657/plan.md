# SCHEMA-DRIFT-2657 — hotlist schema mirror

Phase: plan. Gate: CLEAN. Next: Provision.

Base: `origin/main` `c811e34e482805df5e7795c118489d1e55c379f4`.

DDL source: insight-lpr/chlocal PR 37 head `a9e366bb21fee0702fd827a9d7c57488be57fc69` (`a9e366bb`), file `migrations/116_dev_2657_hotlist_status_timestamps.sql` (blob `235ac8e9eafca3e499e29c607dfe499657ec5c98`).

File to edit: `database/schema/clickhouse-schema.sql`.

## Current vs target

`CREATE TABLE clarity.hotlist` on this base (line 24) has `prj_id_lookup` and does not have the seven new fields or `prj_id_lookup_v2`. `clarity.hotlist_incremental_mv` is absent (0 matches).

| Column | Target type | Placement |
| --- | --- | --- |
| `_peerdb_hsl_synced_at` | `DateTime64(9) DEFAULT now64()` | immediately after `_peerdb_hm_synced_at` |
| `first_open_at` | `Nullable(DateTime64(6))` | immediately after `close_date` |
| `first_hold_at` | `Nullable(DateTime64(6))` | immediately after `first_open_at` |
| `first_repossessed_at` | `Nullable(DateTime64(6))` | immediately after `first_hold_at` |
| `first_declined_at` | `Nullable(DateTime64(6))` | immediately after `first_repossessed_at` |
| `first_closed_at` | `Nullable(DateTime64(6))` | immediately after `first_declined_at` |
| `first_invalid_at` | `Nullable(DateTime64(6))` | immediately after `first_closed_at` |

Keep `PROJECTION prj_id_lookup` unchanged. Add `PROJECTION prj_id_lookup_v2` with that same select list plus `first_repossessed_at`. Do not drop `prj_id_lookup`. Section 5 of the migration is a commented `DROP PROJECTION`.

Existing column types, `ENGINE = ReplacingMergeTree(_version, _peerdb_is_deleted)`, `PARTITION BY toYYYYMM(created_at)`, `ORDER BY`, TTL, and settings stay.

## Edits

### 1. Header

`-- MATERIALIZED VIEWS (5)` becomes `-- MATERIALIZED VIEWS (6)`.

Leave `-- TABLES (64)`, `-- VIEWS (13)`, and `-- Generated: 2026-09-01`.

### 2. Replace the `CREATE TABLE clarity.hotlist` line

sha256 `ba93b813d584a900f4076a92550d26936a5242a025d637caaf7535250024e875`

```sql
CREATE TABLE clarity.hotlist (`id` Int64, `org_id` Int32, `integration_id` Nullable(Int32), `remote_order_id` Nullable(String), `client_org_id` Nullable(Int32), `client_org_name` Nullable(String), `lienholder_org_id` Nullable(Int32), `lienholder_org_name` Nullable(String), `status` Nullable(Int32), `order_type` Nullable(Int32), `is_staged` Bool, `plate` Nullable(String), `us_state` Nullable(String), `vin` Nullable(String), `vehicle_year` Nullable(String), `vehicle_make` Nullable(String), `vehicle_model` Nullable(String), `lookback_days` Nullable(Int32), `created_at` DateTime, `updated_at` DateTime, `branch_id` Nullable(Int64), `_peerdb_synced_at` DateTime, `_peerdb_hj_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_hm_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_hsl_synced_at` DateTime64(9) DEFAULT now64(), `repo_date` Nullable(Date), `order_date` Nullable(Date), `_version` UInt64, `_peerdb_is_deleted` UInt8, `close_date` Nullable(Date), `first_open_at` Nullable(DateTime64(6)), `first_hold_at` Nullable(DateTime64(6)), `first_repossessed_at` Nullable(DateTime64(6)), `first_declined_at` Nullable(DateTime64(6)), `first_closed_at` Nullable(DateTime64(6)), `first_invalid_at` Nullable(DateTime64(6)), INDEX idx_status status TYPE set(10) GRANULARITY 4, INDEX idx_plate plate TYPE bloom_filter(0.01) GRANULARITY 4, INDEX idx_order_type order_type TYPE set(10) GRANULARITY 4, INDEX idx_peerdb_synced_at _peerdb_synced_at TYPE minmax GRANULARITY 1, INDEX idx_id id TYPE minmax GRANULARITY 1, INDEX idx_client_org_id client_org_id TYPE set(1000) GRANULARITY 4, INDEX idx_branch_id branch_id TYPE set(1000) GRANULARITY 4, PROJECTION prj_id_lookup (SELECT id, us_state, plate, status, vin, org_id, remote_order_id, integration_id, client_org_id, branch_id, lienholder_org_id, order_date, repo_date, close_date, client_org_name, lienholder_org_name, vehicle_year, vehicle_make, vehicle_model, _peerdb_synced_at, _version, _peerdb_is_deleted ORDER BY id), PROJECTION prj_id_lookup_v2 (SELECT id, us_state, plate, status, vin, org_id, remote_order_id, integration_id, client_org_id, branch_id, lienholder_org_id, order_date, repo_date, close_date, client_org_name, lienholder_org_name, vehicle_year, vehicle_make, vehicle_model, _peerdb_synced_at, _version, _peerdb_is_deleted, first_repossessed_at ORDER BY id)) ENGINE = ReplacingMergeTree(_version, _peerdb_is_deleted) PARTITION BY toYYYYMM(created_at) ORDER BY (toYYYYMM(created_at), org_id, created_at, id) TTL updated_at + toIntervalDay(30) WHERE _peerdb_is_deleted = 1 SETTINGS index_granularity = 8192, deduplicate_merge_projection_mode = 'rebuild';
```

### 3. Insert `clarity.hotlist_incremental_mv`

Place it after the line that starts with `CREATE MATERIALIZED VIEW clarity.billing_claim_events_mv` and before the line that starts with `CREATE MATERIALIZED VIEW zod.eric_test`.

sha256 `383185efb27168c87bd49ac7bdbad0338f456ed8fb598bdf47e1b32e895511b8`

```sql
CREATE MATERIALIZED VIEW clarity.hotlist_incremental_mv REFRESH EVERY 1 MINUTE APPEND TO clarity.hotlist (`id` Int64, `org_id` Int32, `integration_id` Int32, `remote_order_id` Nullable(String), `client_org_id` Int32, `client_org_name` Nullable(String), `lienholder_org_id` Nullable(Int32), `lienholder_org_name` Nullable(String), `status` Int32, `order_type` Int32, `is_staged` Bool, `plate` String, `us_state` String, `vin` String, `vehicle_year` String, `vehicle_make` String, `vehicle_model` String, `lookback_days` Int32, `created_at` DateTime64(6), `updated_at` DateTime64(6), `branch_id` Nullable(Int64), `_peerdb_synced_at` DateTime64(9), `_peerdb_hj_synced_at` DateTime64(9), `_peerdb_hm_synced_at` DateTime64(9), `repo_date` Nullable(Date), `order_date` Nullable(Date), `close_date` Nullable(Date), `_version` UInt64, `_peerdb_is_deleted` UInt8, `first_open_at` Nullable(DateTime64(6)), `first_hold_at` Nullable(DateTime64(6)), `first_repossessed_at` Nullable(DateTime64(6)), `first_declined_at` Nullable(DateTime64(6)), `first_closed_at` Nullable(DateTime64(6)), `first_invalid_at` Nullable(DateTime64(6)), `_peerdb_hsl_synced_at` DateTime64(9)) DEFINER = default SQL SECURITY DEFINER AS WITH hotlist_watermark AS (SELECT coalesce(max(_peerdb_synced_at), toDateTime64('1970-01-01', 9)) - toIntervalSecond(30) AS ts FROM clarity.hotlist), hj_watermark AS (SELECT greatest(coalesce(max(_peerdb_hj_synced_at), toDateTime64('1970-01-01', 9)) - toIntervalSecond(30), (SELECT ts FROM hotlist_watermark) - toIntervalHour(1)) AS ts FROM clarity.hotlist), hm_watermark AS (SELECT greatest(coalesce(max(_peerdb_hm_synced_at), toDateTime64('1970-01-01', 9)) - toIntervalSecond(30), (SELECT ts FROM hotlist_watermark) - toIntervalHour(1)) AS ts FROM clarity.hotlist), hsl_watermark AS (SELECT greatest(coalesce(max(_peerdb_hsl_synced_at), toDateTime64('1970-01-01', 9)) - toIntervalSecond(30), (SELECT ts FROM hotlist_watermark) - toIntervalHour(1)) AS ts FROM clarity.hotlist), changed_hotlist_ids AS (SELECT id FROM zod.public_hotlist WHERE _peerdb_synced_at >= (SELECT ts FROM hotlist_watermark) UNION DISTINCT SELECT hotlist_id AS id FROM zod.hotlist_json_denormalized WHERE _peerdb_synced_at >= (SELECT ts FROM hj_watermark) UNION DISTINCT SELECT assumeNotNull(hotlist_id) AS id FROM zod.public_hotlist_meta WHERE _peerdb_synced_at >= (SELECT ts FROM hm_watermark) UNION DISTINCT SELECT hotlist_id AS id FROM zod.public_hotlist_status_log WHERE _peerdb_synced_at >= (SELECT ts FROM hsl_watermark)), h_deduped AS (SELECT id, argMax(org_id, _peerdb_version) AS org_id, argMax(integration_id, _peerdb_version) AS integration_id, argMax(remote_order_id, _peerdb_version) AS remote_order_id, argMax(client_org_id, _peerdb_version) AS client_org_id, argMax(lienholder_org_id, _peerdb_version) AS lienholder_org_id, argMax(status, _peerdb_version) AS status, argMax(order_type, _peerdb_version) AS order_type, argMax(is_staged, _peerdb_version) AS is_staged, argMax(plate, _peerdb_version) AS plate, argMax(us_state, _peerdb_version) AS us_state, argMax(vin, _peerdb_version) AS vin, argMax(vehicle_year, _peerdb_version) AS vehicle_year, argMax(vehicle_make, _peerdb_version) AS vehicle_make, argMax(vehicle_model, _peerdb_version) AS vehicle_model, argMax(lookback_days, _peerdb_version) AS lookback_days, argMax(created_at, _peerdb_version) AS created_at, argMax(updated_at, _peerdb_version) AS updated_at, argMax(_peerdb_synced_at, _peerdb_version) AS _peerdb_synced_at, max(_peerdb_version) AS max_version, argMax(_peerdb_is_deleted, _peerdb_version) AS _peerdb_is_deleted FROM zod.public_hotlist WHERE id IN (SELECT id FROM changed_hotlist_ids) GROUP BY id), hj_deduped AS (SELECT hotlist_id, argMax(branch_id, _peerdb_version) AS branch_id, argMax(_peerdb_synced_at, _peerdb_version) AS _peerdb_synced_at, max(_peerdb_version) AS max_version, argMax(_peerdb_is_deleted, _peerdb_version) AS _peerdb_is_deleted FROM zod.hotlist_json_denormalized WHERE hotlist_id IN (SELECT id FROM changed_hotlist_ids) GROUP BY hotlist_id), hm_deduped AS (SELECT assumeNotNull(hotlist_id) AS hotlist_id, argMax(repo_date, _peerdb_version) AS repo_date, argMax(order_date, _peerdb_version) AS order_date, argMax(close_date, _peerdb_version) AS close_date, argMax(_peerdb_synced_at, _peerdb_version) AS _peerdb_synced_at, max(_peerdb_version) AS max_version, argMax(_peerdb_is_deleted, _peerdb_version) AS _peerdb_is_deleted FROM zod.public_hotlist_meta WHERE hotlist_id IN (SELECT id FROM changed_hotlist_ids) GROUP BY hotlist_id), hsl_deduped AS (SELECT hotlist_id, minIf(toNullable(created_at), new_status = 1) AS first_open_at, minIf(toNullable(created_at), new_status = 2) AS first_hold_at, minIf(toNullable(created_at), new_status = 3) AS first_repossessed_at, minIf(toNullable(created_at), new_status = 4) AS first_declined_at, minIf(toNullable(created_at), new_status = 5) AS first_closed_at, minIf(toNullable(created_at), new_status = 99) AS first_invalid_at, max(_peerdb_synced_at) AS _peerdb_synced_at, max(_peerdb_version) AS max_version FROM zod.public_hotlist_status_log WHERE hotlist_id IN (SELECT id FROM changed_hotlist_ids) AND _peerdb_is_deleted = 0 GROUP BY hotlist_id) SELECT h.id AS id, h.org_id AS org_id, h.integration_id AS integration_id, h.remote_order_id AS remote_order_id, h.client_org_id AS client_org_id, co.name AS client_org_name, h.lienholder_org_id AS lienholder_org_id, lo.name AS lienholder_org_name, h.status AS status, h.order_type AS order_type, h.is_staged AS is_staged, h.plate AS plate, h.us_state AS us_state, h.vin AS vin, h.vehicle_year AS vehicle_year, h.vehicle_make AS vehicle_make, h.vehicle_model AS vehicle_model, h.lookback_days AS lookback_days, h.created_at AS created_at, h.updated_at AS updated_at, hj.branch_id AS branch_id, h._peerdb_synced_at AS _peerdb_synced_at, coalesce(hj._peerdb_synced_at, h._peerdb_synced_at) AS _peerdb_hj_synced_at, coalesce(hm._peerdb_synced_at, h._peerdb_synced_at) AS _peerdb_hm_synced_at, hm.repo_date AS repo_date, hm.order_date AS order_date, hm.close_date AS close_date, greatest(toUInt64(h.max_version), toUInt64(ifNull(hj.max_version, 0)), toUInt64(ifNull(hm.max_version, 0)), toUInt64(ifNull(hsl.max_version, 0))) AS _version, h._peerdb_is_deleted AS _peerdb_is_deleted, hsl.first_open_at AS first_open_at, hsl.first_hold_at AS first_hold_at, hsl.first_repossessed_at AS first_repossessed_at, hsl.first_declined_at AS first_declined_at, hsl.first_closed_at AS first_closed_at, hsl.first_invalid_at AS first_invalid_at, ifNull(hsl._peerdb_synced_at, toDateTime64('1970-01-01', 9)) AS _peerdb_hsl_synced_at FROM h_deduped AS h LEFT JOIN hj_deduped AS hj ON hj.hotlist_id = h.id AND hj._peerdb_is_deleted = 0 LEFT JOIN hm_deduped AS hm ON hm.hotlist_id = h.id AND hm._peerdb_is_deleted = 0 LEFT JOIN hsl_deduped AS hsl ON hsl.hotlist_id = h.id LEFT JOIN zod.public_orgs_latest AS co ON co.id = h.client_org_id LEFT JOIN zod.public_orgs_latest AS lo ON lo.id = h.lienholder_org_id WHERE h.org_id IN (SELECT id FROM clarity.orgs FINAL) AND h._peerdb_is_deleted = 0;
```

If the OSS 26.2 load rejects the definer, delete only `DEFINER = default SQL SECURITY DEFINER` and reload. Do not edit the `SELECT`.

## Do not copy from the pin

`database/schemas/clickhouse/clarity/tables/hotlist.sql` and `database/schemas/clickhouse/clarity/materialized_views/hotlist_incremental_mv.sql` at `a9e366bb21fee0702fd827a9d7c57488be57fc69` are the pre-migration export. The table file lacks the new columns. The view file uses a personal definer and omits the status-log arm.

## Excluded from the dump

- `ALTER TABLE ... MATERIALIZE COLUMN` (section 2)
- `MATERIALIZE PROJECTION` (section 3)
- `DROP VIEW IF EXISTS clarity.hotlist_incremental_mv` (section 4)
- commented `DROP PROJECTION prj_id_lookup` (section 5)
- `scripts/backfill/backfill_hotlist_status_timestamps.py`

## Verify after fleet Docker schema apply

This repo has no `up.sh`. Apply is `clickhouse-client --multiquery` of `database/schema/clickhouse-schema.sql`. CI does that on `clickhouse/clickhouse-server:26.2`. `.cursor/start.sh` loads the file only when `EXISTS TABLE clarity.scans_cold` is not 1, so a warm container will not show the new objects until the file is applied to an empty server.

After that apply, on fleet Docker ClickHouse:

```sql
SHOW CREATE TABLE clarity.hotlist;
SHOW CREATE VIEW clarity.hotlist_incremental_mv;
SHOW CREATE TABLE clarity.hotlist_incremental_mv;
```

Expect the seven columns, both projections, engine unchanged, and the materialized view `REFRESH EVERY 1 MINUTE APPEND TO clarity.hotlist` with `DEFINER = default SQL SECURITY DEFINER`.

A `SHOW CREATE` drift check on ClickHouse Cloud is allowed. No `INSERT`, `ALTER`, `DELETE`, `OPTIMIZE`, or `DROP` on Cloud.

## Non-goals

- No application PHP.
- No backfill and no `INSERT` into `clarity.hotlist`.
- No ClickHouse Cloud writes.
- No DEV-2660.
- No Zod or PostgreSQL DDL.
- No full dump regeneration.
- No `okf/` edit while this track is in flight.
- No provision in this run. Do not mark provisioned.
- Do not reuse the DEV-2654 stand. It is torn down (~13:02 CT). Ports 18082, 15434, and 18124 are free.
- Do not reuse the DEV-2555 SCHEMA-GATE twins on ports 18081, 15433, and 18123.

## Soft collision

Path A soft-OK versus the DEV-2555 SCHEMA-GATE twins still on `:18081` / `:15433` / `:18123`.

DEV-2654 is torn down (~13:02 CT). Ports 18082, 15434, and 18124 are free. That stand is not a live second ledger.

This track is not provisioned. The next stand is a fresh SCHEMA-DRIFT-2657 keyed Docker environment. Its ledger lands under `docs/investigations/SCHEMA-DRIFT-2657/` and is not written in this run.

## Ticket status

Ticket: SCHEMA-DRIFT-2657. Progress ticket DEV-2657 stays In Code Review. Assignee stays Dalton McCleery.

Phase: plan.

Done: intake, prep, gate CLEAN, plan.

Next: Provision against a fresh SCHEMA-DRIFT-2657 keyed Docker stand. This plan does not mark the track provisioned.

Soft collision: Path A soft-OK vs DEV-2555 SCHEMA-GATE on :18081 / :15433 / :18123. DEV-2654 is torn_down (~13:02 CT); :18082 / :15434 / :18124 are free and are not a live ledger.

Open questions: none.

Artifacts: `docs/investigations/SCHEMA-DRIFT-2657/`.
