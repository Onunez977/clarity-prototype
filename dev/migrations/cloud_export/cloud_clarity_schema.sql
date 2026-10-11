-- ============================================================
-- Schema export from ClickHouse Cloud
-- Database: clarity
-- Generated: 2025-12-19T14:10:28.135360
-- ============================================================

CREATE DATABASE IF NOT EXISTS clarity;

-- ------------------------------------------------------------
-- Tables
-- ------------------------------------------------------------

-- Table: aggregate_recovery_kit_scans (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS clarity.aggregate_recovery_kit_scans;
CREATE TABLE clarity.aggregate_recovery_kit_scans (`recovery_id` Int64, `kit_id` Int32, `integration_id` Nullable(Int32), `remote_order_id` Nullable(String), `status` Nullable(Int32), `org_id` Int32, `client_org_id` Nullable(Int32), `lienholder_org_id` Nullable(Int32), `order_type` Nullable(Int32), `is_staged` Nullable(UInt8), `plate` Nullable(String), `us_state` Nullable(String), `vin` Nullable(String), `vehicle_year` Nullable(String), `vehicle_make` Nullable(String), `vehicle_model` Nullable(String), `lookback_days` Nullable(Int32), `created_at` Nullable(DateTime64(6)), `updated_at` Nullable(DateTime64(6)), `address_id` Nullable(Int64), `house_number` Nullable(String), `road` Nullable(String), `city` Nullable(String), `state` Nullable(String), `postcode` Nullable(String), `repo_date` Nullable(Date), `count` Int64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY (org_id, recovery_id, kit_id) SETTINGS index_granularity = 8192;

-- Table: client_assignment_stats_hourly (Engine: SharedSummingMergeTree)
DROP TABLE IF EXISTS clarity.client_assignment_stats_hourly;
CREATE TABLE clarity.client_assignment_stats_hourly (`hour_timestamp` DateTime, `org_id` Int32, `client_org_id` Int32, `branch_id` Nullable(Int32), `total_assignments` Int64, `accepted_assignments` Int64, `declined_assignments` Int64, `updated_at` DateTime) ENGINE = SharedSummingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') PARTITION BY toYYYYMM(hour_timestamp) ORDER BY (org_id, client_org_id, hour_timestamp) SETTINGS index_granularity = 8192;

-- Table: recovery_address (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS clarity.recovery_address;
CREATE TABLE clarity.recovery_address (`id` Int64, `address_id` Int64, `geo` String, `road` Nullable(String), `house_number` Nullable(String), `state` Nullable(String), `city` Nullable(String), `postcode` Nullable(String)) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY id SETTINGS index_granularity = 8192;

-- ------------------------------------------------------------
-- Views
-- ------------------------------------------------------------

-- View: aggregate_recovery_kit_scans_with_totals
DROP VIEW IF EXISTS clarity.aggregate_recovery_kit_scans_with_totals;
CREATE VIEW clarity.aggregate_recovery_kit_scans_with_totals (`recovery_id` Int64, `kit_id` Int32, `integration_id` Nullable(Int32), `remote_order_id` Nullable(String), `status` Nullable(Int32), `org_id` Int32, `client_org_id` Nullable(Int32), `lienholder_org_id` Nullable(Int32), `order_type` Nullable(Int32), `is_staged` Nullable(UInt8), `plate` Nullable(String), `us_state` Nullable(String), `vin` Nullable(String), `vehicle_year` Nullable(String), `vehicle_make` Nullable(String), `vehicle_model` Nullable(String), `lookback_days` Nullable(Int32), `created_at` Nullable(DateTime64(6)), `updated_at` Nullable(DateTime64(6)), `address_id` Nullable(Int64), `house_number` Nullable(String), `road` Nullable(String), `city` Nullable(String), `state` Nullable(String), `postcode` Nullable(String), `repo_date` Nullable(Date), `count` Int64, `hotlist_scan_count` Int64, `hotlist_scan_percentage` Nullable(Float64)) AS SELECT recovery_id, kit_id, integration_id, remote_order_id, status, org_id, client_org_id, lienholder_org_id, order_type, is_staged, plate, us_state, vin, vehicle_year, vehicle_make, vehicle_model, lookback_days, created_at, updated_at, address_id, house_number, road, city, state, postcode, repo_date, count, sum(count) OVER (PARTITION BY recovery_id) AS hotlist_scan_count, (count * 100.) / nullIf(sum(count) OVER (PARTITION BY recovery_id), 0) AS hotlist_scan_percentage FROM clarity.aggregate_recovery_kit_scans;

-- View: client_assignment_stats_1
DROP VIEW IF EXISTS clarity.client_assignment_stats_1;
CREATE VIEW clarity.client_assignment_stats_1 (`hour_timestamp` DateTime, `org_id` UInt32, `client_org_id` UInt32, `branch_id` Nullable(UInt32), `total_assignments` UInt64, `accepted_assignments` UInt64, `declined_assignments` UInt64, `updated_at` DateTime) AS SELECT toStartOfHour(h.created_at) AS hour_timestamp, toUInt32(h.org_id) AS org_id, if(h.client_org_id = 0, 0, toUInt32(h.client_org_id)) AS client_org_id, toUInt32(h.branch_id) AS branch_id, count(*) AS total_assignments, sumIf(1, h.order_type = 2) AS accepted_assignments, sumIf(1, h.order_type = 4) AS declined_assignments, now() AS updated_at FROM clarity.hotlist AS h WHERE h.org_id IN (SELECT id FROM clarity.orgs WHERE enable_genetech_hotlist = true) GROUP BY org_id, client_org_id, hour_timestamp, branch_id;

-- View: client_lpr_assists_hourly
DROP VIEW IF EXISTS clarity.client_lpr_assists_hourly;
CREATE VIEW clarity.client_lpr_assists_hourly (`hour_timestamp` Nullable(DateTime), `org_id` UInt32, `client_org_id` Nullable(UInt32), `branch_id` Nullable(UInt32), `lpr_assist_count` UInt64, `updated_at` DateTime) AS SELECT toStartOfHour(toDateTime(arr.repo_date)) AS hour_timestamp, toUInt32(arr.org_id) AS org_id, if(arr.client_org_id = 0, 0, toUInt32(arr.client_org_id)) AS client_org_id, toUInt32(h.branch_id) AS branch_id, countDistinct(arr.remote_order_id) AS lpr_assist_count, now() AS updated_at FROM clarity.aggregate_recovery_kit_scans AS arr LEFT JOIN clarity.hotlist AS h ON h.id = arr.recovery_id WHERE arr.repo_date IS NOT NULL GROUP BY org_id, client_org_id, hour_timestamp, branch_id;

-- View: client_missed_opportunities_hourly
DROP VIEW IF EXISTS clarity.client_missed_opportunities_hourly;
CREATE VIEW clarity.client_missed_opportunities_hourly (`hour_timestamp` DateTime, `org_id` UInt32, `client_org_id` UInt32, `branch_id` Nullable(UInt32), `missed_opportunities_count` UInt64, `updated_at` DateTime) AS SELECT toStartOfHour(hits.created_at) AS hour_timestamp, toUInt32(k.org_id) AS org_id, if(h.client_org_id = 0, 0, toUInt32(h.client_org_id)) AS client_org_id, toUInt32(h.branch_id) AS branch_id, count(*) AS missed_opportunities_count, now() AS updated_at FROM zod.public_hits AS hits INNER JOIN clarity.hotlist AS h ON h.id = hits.hotlist_id INNER JOIN zod.public_kits AS k ON k.id = hits.kit_id WHERE hits.hit_type = 'M' GROUP BY org_id, client_org_id, hour_timestamp, branch_id;

-- View: client_repossession_stats_hourly
DROP VIEW IF EXISTS clarity.client_repossession_stats_hourly;
CREATE VIEW clarity.client_repossession_stats_hourly (`hour_timestamp` Nullable(DateTime), `org_id` UInt32, `client_org_id` UInt32, `branch_id` Nullable(UInt32), `total_repossessions` UInt64, `updated_at` DateTime) AS SELECT toStartOfHour(toDateTime(h.repo_date)) AS hour_timestamp, toUInt32(h.org_id) AS org_id, if(h.client_org_id = 0, 0, toUInt32(h.client_org_id)) AS client_org_id, CAST(h.branch_id, 'Nullable(UInt32)') AS branch_id, count(*) AS total_repossessions, now() AS updated_at FROM clarity.hotlist AS h WHERE (h.status = 3) AND (h.repo_date IS NOT NULL) GROUP BY org_id, client_org_id, hour_timestamp, branch_id;

-- View: hotlist
DROP VIEW IF EXISTS clarity.hotlist;
CREATE VIEW clarity.hotlist (`id` Int64, `public_hotlist.id` Int64, `integration_id` Int32, `remote_order_id` Nullable(String), `status` Int32, `org_id` Int32, `client_org_id` Int32, `lienholder_org_id` Nullable(Int32), `order_type` Int32, `is_staged` Bool, `plate` String, `us_state` String, `vin` String, `vehicle_year` String, `vehicle_make` String, `vehicle_model` String, `lookback_days` Int32, `public_hotlist.created_at` DateTime64(6), `public_hotlist.updated_at` DateTime64(6), `public_hotlist._peerdb_synced_at` DateTime64(9), `public_hotlist._peerdb_is_deleted` UInt8, `public_hotlist._peerdb_version` UInt64, `branch_id` Nullable(Int64), `repo_date` Nullable(Date32)) AS SELECT zod.public_hotlist.id AS id, zod.public_hotlist.*, JSONExtract(hj.json, 'branch_id', 'Nullable(Int64)') AS branch_id, hm.repo_date FROM zod.public_hotlist FINAL LEFT JOIN zod.public_hotlist_json AS hj FINAL ON hj.hotlist_id = zod.public_hotlist.id LEFT JOIN zod.public_hotlist_meta AS hm FINAL ON hm.hotlist_id = zod.public_hotlist.id WHERE org_id IN (SELECT id FROM clarity.orgs WHERE enable_genetech_hotlist = true);

-- View: hotlist_branch
DROP VIEW IF EXISTS clarity.hotlist_branch;
CREATE VIEW clarity.hotlist_branch (`id` Int64, `branch_id` Nullable(Int64)) AS SELECT h.id AS id, JSONExtract(hj.json, 'branch_id', 'Nullable(Int64)') AS branch_id FROM zod.public_hotlist AS h FINAL LEFT JOIN zod.public_hotlist_json AS hj FINAL ON hj.hotlist_id = h.id WHERE h.org_id IN (SELECT id FROM clarity.orgs WHERE enable_genetech_hotlist = true);

-- View: integrations
DROP VIEW IF EXISTS clarity.integrations;
CREATE VIEW clarity.integrations (`id` Int32, `name` String, `status` String, `batch_id` Nullable(Int64), `mapping_id` Int32, `mapping_type` Nullable(String), `_peerdb_synced_at` DateTime64(9), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) AS SELECT * FROM zod.public_integrations FINAL WHERE id IN (1, 2, 6, 8, 9, 10, 44, 1482);

-- View: kits
DROP VIEW IF EXISTS clarity.kits;
CREATE VIEW clarity.kits (`id` Int32, `org_id` Int32, `kit_mapping` String, `kit_type` Int32, `name` String, `text_phone` Nullable(String), `fixed_latitude` Nullable(Float64), `fixed_longitude` Nullable(Float64), `_peerdb_synced_at` DateTime64(9), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) AS SELECT * FROM zod.public_kits WHERE org_id IN (SELECT id FROM clarity.orgs);

-- View: orgs
DROP VIEW IF EXISTS clarity.orgs;
CREATE VIEW clarity.orgs (`id` Int32, `name` String, `address_id` Nullable(Int64), `type` Int32, `rdn_id` Nullable(String), `rdn_api_key` Nullable(String), `irepo_id` Nullable(String), `enable_genetech_hotlist` Bool, `enable_matrix_hotlist` Bool, `silent_hit_generation` Bool, `ls_id` Nullable(String), `_peerdb_synced_at` DateTime64(9), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) AS SELECT * FROM zod.public_orgs WHERE type IN (1, 2);

-- View: recoveries
DROP VIEW IF EXISTS clarity.recoveries;
CREATE VIEW clarity.recoveries (`id` Int64, `public_hotlist.id` Int64, `integration_id` Int32, `remote_order_id` Nullable(String), `status` Int32, `org_id` Int32, `client_org_id` Int32, `lienholder_org_id` Nullable(Int32), `order_type` Int32, `is_staged` Bool, `plate` String, `us_state` String, `vin` String, `vehicle_year` String, `vehicle_make` String, `vehicle_model` String, `lookback_days` Int32, `public_hotlist.created_at` DateTime64(6), `public_hotlist.updated_at` DateTime64(6), `public_hotlist._peerdb_synced_at` DateTime64(9), `public_hotlist._peerdb_is_deleted` UInt8, `public_hotlist._peerdb_version` UInt64, `branch_id` Nullable(Int64), `repo_date` Nullable(Date32), `address_id` Int64, `geo` String, `road` Nullable(String), `house_number` Nullable(String), `state` Nullable(String), `city` Nullable(String), `postcode` Nullable(String)) AS SELECT hotlist.*, recovery_address.* EXCEPT id FROM clarity.hotlist AS hotlist LEFT JOIN clarity.recovery_address ON recovery_address.id = hotlist.id WHERE (hotlist.status = 3) AND (hotlist.repo_date IS NOT NULL);

-- View: recovery_geo
DROP VIEW IF EXISTS clarity.recovery_geo;
CREATE VIEW clarity.recovery_geo (`id` Int64, `lng` Nullable(Float64), `lat` Nullable(Float64)) AS SELECT id, toFloat64OrNull(trimBoth(splitByChar(' ', replaceOne(replaceOne(geo, 'POINT (', ''), ')', ''))[1])) AS lng, toFloat64OrNull(trimBoth(splitByChar(' ', replaceOne(replaceOne(geo, 'POINT (', ''), ')', ''))[2])) AS lat FROM clarity.recovery_address WHERE (geo LIKE 'POINT (%') AND (geo != '');

-- ------------------------------------------------------------
-- Materialized Views
-- ------------------------------------------------------------

-- Materialized View: aggregate_recovery_kit_scans_backfill
DROP TABLE IF EXISTS clarity.aggregate_recovery_kit_scans_backfill;
CREATE MATERIALIZED VIEW clarity.aggregate_recovery_kit_scans_backfill REFRESH EVERY 4 HOUR TO clarity.aggregate_recovery_kit_scans (`recovery_id` Int64, `kit_id` Int32, `integration_id` Int32, `remote_order_id` Nullable(String), `status` Int32, `org_id` Int32, `client_org_id` Int32, `lienholder_org_id` Nullable(Int32), `order_type` Int32, `is_staged` Bool, `plate` String, `us_state` String, `vin` String, `vehicle_year` String, `vehicle_make` String, `vehicle_model` String, `lookback_days` Int32, `created_at` DateTime64(6), `updated_at` DateTime64(6), `address_id` Int64, `house_number` Nullable(String), `road` Nullable(String), `city` Nullable(String), `state` Nullable(String), `postcode` Nullable(String), `repo_date` Nullable(Date32), `count` UInt64) DEFINER = `sql-console:dalton.mccleery@insightlpr.com` SQL SECURITY DEFINER AS SELECT h.id AS recovery_id, s.kit_id AS kit_id, h.integration_id AS integration_id, h.remote_order_id AS remote_order_id, h.status AS status, h.org_id AS org_id, h.client_org_id AS client_org_id, h.lienholder_org_id AS lienholder_org_id, h.order_type AS order_type, h.is_staged AS is_staged, h.plate AS plate, h.us_state AS us_state, h.vin AS vin, h.vehicle_year AS vehicle_year, h.vehicle_make AS vehicle_make, h.vehicle_model AS vehicle_model, h.lookback_days AS lookback_days, h.created_at AS created_at, h.updated_at AS updated_at, ra.address_id AS address_id, ra.house_number AS house_number, ra.road AS road, ra.city AS city, ra.state AS state, ra.postcode AS postcode, hm.repo_date AS repo_date, count(*) AS count FROM zod.public_hotlist AS h FINAL INNER JOIN zod.public_hotlist_meta AS hm FINAL ON hm.hotlist_id = h.id INNER JOIN clarity.recovery_address AS ra FINAL ON ra.id = h.id INNER JOIN clarity.recovery_geo AS rg ON rg.id = h.id INNER JOIN zod.public_kits AS k FINAL ON k.org_id = h.org_id INNER JOIN zod.scans_geo AS s FINAL ON (s.ocr = h.plate) AND (s.kit_id = k.id) AND (greatCircleDistance(rg.lng, rg.lat, s.lng, s.lat) <= 162) AND (s.scanned_at >= (toDateTime(hm.repo_date) - toIntervalDay(90))) AND (s.scanned_at <= (toDateTime(hm.repo_date) + toIntervalDay(1))) WHERE (h.status = 3) AND (hm.repo_date IS NOT NULL) AND (hm.repo_date >= (now() - toIntervalMonth(1))) AND (rg.lng IS NOT NULL) AND (rg.lat IS NOT NULL) AND (h.id NOT IN (SELECT DISTINCT recovery_id FROM clarity.aggregate_recovery_kit_scans)) GROUP BY h.id, s.kit_id, h.integration_id, h.remote_order_id, h.status, h.org_id, h.client_org_id, h.lienholder_org_id, h.order_type, h.is_staged, h.plate, h.us_state, h.vin, h.vehicle_year, h.vehicle_make, h.vehicle_model, h.lookback_days, h.created_at, h.updated_at, ra.address_id, ra.house_number, ra.road, ra.city, ra.state, ra.postcode, hm.repo_date;

-- Materialized View: public_hits_hourly_mv
DROP TABLE IF EXISTS clarity.public_hits_hourly_mv;
CREATE MATERIALIZED VIEW clarity.public_hits_hourly_mv (`date_hour` DateTime, `org_id` Int32, `kit_id` Int32, `hit_type` String, `count` UInt64, `updated_at` DateTime) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY (org_id, kit_id, hit_type, date_hour) SETTINGS index_granularity = 8192 AS SELECT toStartOfHour(public_hits.created_at) AS date_hour, public_kits.org_id, kit_id, hit_type, count(*) AS count, now() AS updated_at FROM zod.public_hits INNER JOIN zod.public_kits ON public_hits.kit_id = public_kits.id WHERE (hit_type != 'O') AND (public_kits.org_id IN (SELECT id FROM zod.public_orgs WHERE type = 1)) GROUP BY public_kits.org_id, kit_id, hit_type, date_hour;
