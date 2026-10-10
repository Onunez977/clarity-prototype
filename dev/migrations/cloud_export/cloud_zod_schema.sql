-- ============================================================
-- Schema export from ClickHouse Cloud
-- Database: zod
-- Generated: 2025-12-19T14:10:28.064140
-- ============================================================

CREATE DATABASE IF NOT EXISTS zod;

-- ------------------------------------------------------------
-- Tables
-- ------------------------------------------------------------

-- Table: _peerdb_raw_mirror_2151eb80__196d__4bbc__b5fc__89b34c72b8ff (Engine: SharedMergeTree)
DROP TABLE IF EXISTS zod._peerdb_raw_mirror_2151eb80__196d__4bbc__b5fc__89b34c72b8ff;
CREATE TABLE zod._peerdb_raw_mirror_2151eb80__196d__4bbc__b5fc__89b34c72b8ff (`_peerdb_uid` UUID, `_peerdb_timestamp` Int64, `_peerdb_destination_table_name` String, `_peerdb_data` String, `_peerdb_record_type` Int32, `_peerdb_match_data` String, `_peerdb_batch_id` Int64, `_peerdb_unchanged_toast_columns` String) ENGINE = SharedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY (_peerdb_batch_id, _peerdb_destination_table_name) SETTINGS index_granularity = 8192;

-- Table: _peerdb_raw_mirror_95099dca__518c__4b30__9fe8__2df00c2d0cde (Engine: SharedMergeTree)
DROP TABLE IF EXISTS zod._peerdb_raw_mirror_95099dca__518c__4b30__9fe8__2df00c2d0cde;
CREATE TABLE zod._peerdb_raw_mirror_95099dca__518c__4b30__9fe8__2df00c2d0cde (`_peerdb_uid` UUID, `_peerdb_timestamp` Int64, `_peerdb_destination_table_name` String, `_peerdb_data` String, `_peerdb_record_type` Int32, `_peerdb_match_data` String, `_peerdb_batch_id` Int64, `_peerdb_unchanged_toast_columns` String) ENGINE = SharedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY (_peerdb_batch_id, _peerdb_destination_table_name) SETTINGS index_granularity = 8192;

-- Table: _peerdb_raw_mirror_b9ba7e93__e48e__4f02__8032__4f3223d89d34 (Engine: SharedMergeTree)
DROP TABLE IF EXISTS zod._peerdb_raw_mirror_b9ba7e93__e48e__4f02__8032__4f3223d89d34;
CREATE TABLE zod._peerdb_raw_mirror_b9ba7e93__e48e__4f02__8032__4f3223d89d34 (`_peerdb_uid` UUID, `_peerdb_timestamp` Int64, `_peerdb_destination_table_name` String, `_peerdb_data` String, `_peerdb_record_type` Int32, `_peerdb_match_data` String, `_peerdb_batch_id` Int64, `_peerdb_unchanged_toast_columns` String) ENGINE = SharedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY (_peerdb_batch_id, _peerdb_destination_table_name) SETTINGS index_granularity = 8192;

-- Table: kit_last_scan (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.kit_last_scan;
CREATE TABLE zod.kit_last_scan (`org_id` Int64, `org_name` String, `kit_id` Int32, `kit_name` String, `last_scan` DateTime64(6)) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', last_scan) ORDER BY (org_id, kit_id) SETTINGS index_granularity = 8192;

-- Table: public_address (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_address;
CREATE TABLE zod.public_address (`id` Int64, `house_number` Nullable(String), `road` Nullable(String), `city` Nullable(String), `state` Nullable(String), `postcode` Nullable(String), `geo` Nullable(String), `orig` String, `type_id` Nullable(Int32), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_api_keys (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_api_keys;
CREATE TABLE zod.public_api_keys (`id` Int32, `org_id` Nullable(Int32), `key` Nullable(String), `description` Nullable(String), `active` Nullable(Bool), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_approval_list (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_approval_list;
CREATE TABLE zod.public_approval_list (`id` Int64, `agent_org_id` Nullable(Int32), `staging_org_id` Nullable(Int32), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_authorized_staging_orgs (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_authorized_staging_orgs;
CREATE TABLE zod.public_authorized_staging_orgs (`id` Int32, `staging_org_id` Int32, `authorized_org_id` Int32, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_blacklist_plates (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_blacklist_plates;
CREATE TABLE zod.public_blacklist_plates (`id` Int32, `plate` String, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_blacklist_repo_client (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_blacklist_repo_client;
CREATE TABLE zod.public_blacklist_repo_client (`id` Int32, `repo_org_id` Int32, `client_org_id` Int32, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_hits (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hits;
CREATE TABLE zod.public_hits (`id` Int64, `hotlist_id` Int64, `scan_id` Int64, `hit_type` String, `created_at` DateTime64(6), `kit_id` Int32, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id TTL _peerdb_synced_at + toIntervalDay(1) WHERE _peerdb_is_deleted = 1 SETTINGS index_granularity = 8192;

-- Table: public_hits_meta (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hits_meta;
CREATE TABLE zod.public_hits_meta (`id` Int64, `properties` String, `created_at` DateTime64(6), `updated_at` Nullable(DateTime64(6)), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_hotlist (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hotlist;
CREATE TABLE zod.public_hotlist (`id` Int64, `integration_id` Int32, `remote_order_id` Nullable(String), `status` Int32, `org_id` Int32, `client_org_id` Int32, `lienholder_org_id` Nullable(Int32), `order_type` Int32, `is_staged` Bool, `plate` String, `us_state` String, `vin` String, `vehicle_year` String, `vehicle_make` String, `vehicle_model` String, `lookback_days` Int32, `created_at` DateTime64(6), `updated_at` DateTime64(6), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id TTL _peerdb_synced_at + toIntervalDay(1) WHERE _peerdb_is_deleted = 1 SETTINGS index_granularity = 8192;

-- Table: public_hotlist_address (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hotlist_address;
CREATE TABLE zod.public_hotlist_address (`id` Int32, `hotlist_id` Int64, `address_id` Int64, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_hotlist_expiration (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hotlist_expiration;
CREATE TABLE zod.public_hotlist_expiration (`id` Int64, `hotlist_id` Int64, `expiration_date` DateTime64(6), `created_at` Nullable(DateTime64(6)), `updated_at` Nullable(DateTime64(6)), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_hotlist_import_log (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hotlist_import_log;
CREATE TABLE zod.public_hotlist_import_log (`id` Int64, `start_time` DateTime64(6), `end_time` Nullable(DateTime64(6)), `successful` Bool, `integration_id` Int64, `import_setting_id` Int64, `rows_added` Nullable(Int32), `rows_updated` Nullable(Int32), `rows_removed` Nullable(Int32), `file_removed` Bool, `count_db_rows_before_import` Int32, `count_file_rows` Int32, `org_id` Int64, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_hotlist_json (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hotlist_json;
CREATE TABLE zod.public_hotlist_json (`hotlist_id` Int64, `json` String, `created_at` Nullable(DateTime64(6)), `updated_at` Nullable(DateTime64(6)), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY hotlist_id ORDER BY hotlist_id SETTINGS index_granularity = 8192;

-- Table: public_hotlist_meta (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hotlist_meta;
CREATE TABLE zod.public_hotlist_meta (`id` Int64, `hotlist_id` Nullable(Int64), `order_date` Date32, `repo_date` Nullable(Date32), `close_date` Nullable(Date32), `monthly_payments` Nullable(Decimal(10, 2)), `balance_on_account` Nullable(Decimal(10, 2)), `amount_past_due` Nullable(Decimal(10, 2)), `additional_info` Nullable(String), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_hotlist_plate_log (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hotlist_plate_log;
CREATE TABLE zod.public_hotlist_plate_log (`id` Int64, `hotlist_id` Int64, `old_plate` String, `old_state` String, `new_plate` String, `new_state` String, `created_at` DateTime64(6), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_hotlist_status_log (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_hotlist_status_log;
CREATE TABLE zod.public_hotlist_status_log (`id` Int64, `hotlist_id` Int64, `old_status` Int32, `new_status` Int32, `created_at` DateTime64(6), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_integrations (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_integrations;
CREATE TABLE zod.public_integrations (`id` Int32, `name` String, `status` String, `batch_id` Nullable(Int64), `mapping_id` Int32, `mapping_type` Nullable(String), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_kits (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_kits;
CREATE TABLE zod.public_kits (`id` Int32, `org_id` Int32, `kit_mapping` String, `kit_type` Int32, `name` String, `text_phone` Nullable(String), `fixed_latitude` Nullable(Float64), `fixed_longitude` Nullable(Float64), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_org_meta (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_org_meta;
CREATE TABLE zod.public_org_meta (`id` Int64, `properties` String, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_orgs (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_orgs;
CREATE TABLE zod.public_orgs (`id` Int32, `name` String, `address_id` Nullable(Int64), `type` Int32, `rdn_id` Nullable(String), `rdn_api_key` Nullable(String), `irepo_id` Nullable(String), `enable_genetech_hotlist` Bool, `enable_matrix_hotlist` Bool, `silent_hit_generation` Bool, `ls_id` Nullable(String), `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: public_scans (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_scans;
CREATE TABLE zod.public_scans (`id` Int64, `ocr` String, `us_state` String, `geo` String, `kit_id` Int32, `is_visible` Bool, `scanned_at` DateTime64(6), `created_at` DateTime64(6), `camera_name` String, `guid` UUID, `meta` String, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY (id, scanned_at) ORDER BY (id, scanned_at) TTL _peerdb_synced_at + toIntervalDay(1) WHERE _peerdb_is_deleted = 1 SETTINGS index_granularity = 8192;

-- Table: public_scans_metadata (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_scans_metadata;
CREATE TABLE zod.public_scans_metadata (`scan_id` Int64, `meta` String, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY scan_id ORDER BY scan_id TTL _peerdb_synced_at + toIntervalDay(1) WHERE _peerdb_is_deleted = 1 SETTINGS index_granularity = 8192;

-- Table: public_timezones (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_timezones;
CREATE TABLE zod.public_timezones (`gid` Int32, `tzid` Nullable(String), `geom` Nullable(String), `original_geom` String, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY gid ORDER BY gid SETTINGS index_granularity = 8192;

-- Table: public_user_orgs (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.public_user_orgs;
CREATE TABLE zod.public_user_orgs (`id` Int32, `user_id` Int32, `org_id` Int32, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) PRIMARY KEY id ORDER BY id SETTINGS index_granularity = 8192;

-- Table: scan_lag_5min (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.scan_lag_5min;
CREATE TABLE zod.scan_lag_5min (`kit_id` Int32, `time_bucket` DateTime, `scan_count` UInt64, `avg_lag_sec` Float64, `median_lag_sec` Float64, `min_lag_sec` Int64, `max_lag_sec` Int64, `stddev_lag_sec` Float64) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY (kit_id, time_bucket) TTL time_bucket + toIntervalDay(30) SETTINGS index_granularity = 8192;

-- Table: scan_ttl_compliance (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.scan_ttl_compliance;
CREATE TABLE zod.scan_ttl_compliance (`org_id` Int32, `org_name` String, `ttl_days` Int32, `cutoff_date` Date, `oldest_scan` Date, `newest_scan` Date, `total_scans` UInt64, `scans_past_ttl` UInt64, `status` String, `updated_at` DateTime DEFAULT now()) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', updated_at) ORDER BY org_id SETTINGS index_granularity = 8192;

-- Table: scans_geo (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.scans_geo;
CREATE TABLE zod.scans_geo (`id` Int64, `kit_id` Int32, `ocr` String, `us_state` Nullable(String), `scanned_at` DateTime64(6), `created_at` DateTime64(6), `camera_name` Nullable(String), `camera_position` Nullable(String), `guid` UUID, `is_visible` Bool, `lng` Float64, `lat` Float64, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8 DEFAULT 0, `_peerdb_version` UInt64 DEFAULT 0, INDEX idx_lng lng TYPE minmax GRANULARITY 4, INDEX idx_lat lat TYPE minmax GRANULARITY 4, INDEX idx_ocr ocr TYPE ngrambf_v1(3, 256, 2, 0) GRANULARITY 4) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) ORDER BY (kit_id, scanned_at, id) SETTINGS index_granularity = 8192;

-- Table: scans_metadata_extracted (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.scans_metadata_extracted;
CREATE TABLE zod.scans_metadata_extracted (`scan_id` Int64, `vehicle_type` Nullable(String), `vehicle_color` Nullable(String), `vehicle_make` Nullable(String), `is_not_plate` Bool, `ml_plate_state` Nullable(String), `address` Nullable(String), `simple_address` Nullable(String), `disposition` Nullable(String), `is_daytime` Bool, `_peerdb_synced_at` DateTime64(9) DEFAULT now64(), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64, INDEX idx_vehicle_type vehicle_type TYPE set(100) GRANULARITY 4, INDEX idx_vehicle_color vehicle_color TYPE set(200) GRANULARITY 4, INDEX idx_vehicle_make vehicle_make TYPE bloom_filter(0.01) GRANULARITY 4, INDEX idx_ml_plate_state ml_plate_state TYPE bloom_filter(0.01) GRANULARITY 4, INDEX idx_is_daytime is_daytime TYPE minmax GRANULARITY 4, INDEX idx_is_not_plate is_not_plate TYPE minmax GRANULARITY 4, INDEX idx_disposition disposition TYPE set(20) GRANULARITY 4) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', _peerdb_version, _peerdb_is_deleted) ORDER BY scan_id TTL _peerdb_synced_at + toIntervalDay(1) WHERE _peerdb_is_deleted = 1 SETTINGS index_granularity = 8192;

-- Table: scans_out_of_policy_data (Engine: SharedReplacingMergeTree)
DROP TABLE IF EXISTS zod.scans_out_of_policy_data;
CREATE TABLE zod.scans_out_of_policy_data (`scan_id` Int64, `guid` UUID, `kit_id` Int32, `scanned_at` DateTime64(6), `created_at` DateTime64(6), `org_id` Int32, `org_name` String, `ttl_days` Int32, `age_days` Int32, `refreshed_at` DateTime DEFAULT now()) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', refreshed_at) PRIMARY KEY (org_id, kit_id, scan_id) ORDER BY (org_id, kit_id, scan_id, scanned_at) SETTINGS index_granularity = 8192;

-- ------------------------------------------------------------
-- Views
-- ------------------------------------------------------------

-- View: scan_lag_anomalies
DROP VIEW IF EXISTS zod.scan_lag_anomalies;
CREATE VIEW zod.scan_lag_anomalies (`kit_id` Int32, `time_bucket` DateTime, `scan_count` UInt64, `avg_lag_sec` Float64, `median_lag_sec` Float64, `max_lag_sec` Int64, `kit_rolling_avg` Float64, `kit_stddev` Float64, `deviation_from_avg` Float64, `status` String) AS WITH kit_averages AS (SELECT kit_id, avg(avg_lag_sec) AS kit_avg_lag, stddevPop(avg_lag_sec) AS kit_stddev_lag FROM zod.scan_lag_5min WHERE time_bucket >= (now() - toIntervalDay(7)) GROUP BY kit_id) SELECT b.kit_id, b.time_bucket, b.scan_count, round(b.avg_lag_sec, 1) AS avg_lag_sec, round(b.median_lag_sec, 1) AS median_lag_sec, b.max_lag_sec, round(ka.kit_avg_lag, 1) AS kit_rolling_avg, round(ka.kit_stddev_lag, 1) AS kit_stddev, round(b.avg_lag_sec - ka.kit_avg_lag, 1) AS deviation_from_avg, multiIf((ka.kit_stddev_lag > 0) AND (b.avg_lag_sec > (ka.kit_avg_lag + (2 * ka.kit_stddev_lag))), 'ANOMALY', b.avg_lag_sec > (ka.kit_avg_lag * 1.5), 'ELEVATED', 'NORMAL') AS status FROM zod.scan_lag_5min AS b INNER JOIN kit_averages AS ka ON b.kit_id = ka.kit_id WHERE b.time_bucket >= (now() - toIntervalHour(24));

-- View: timezones_wkt
DROP VIEW IF EXISTS zod.timezones_wkt;
CREATE VIEW zod.timezones_wkt (`tzid` Nullable(String), `polygon` MultiPolygon) AS SELECT tzid, readWKTMultiPolygon(splitByChar(';', original_geom)[2]) AS polygon FROM zod.public_timezones;

-- ------------------------------------------------------------
-- Materialized Views
-- ------------------------------------------------------------

-- Materialized View: eric_test
DROP TABLE IF EXISTS zod.eric_test;
CREATE MATERIALIZED VIEW zod.eric_test (`id` Int64, `integration_id` Int32, `remote_order_id` Nullable(String), `status` Int32) ENGINE = SharedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', status) ORDER BY id SETTINGS index_granularity = 8192 AS SELECT public_hotlist.id AS id, integration_id, remote_order_id, public_hotlist.status AS status FROM zod.public_hotlist LEFT JOIN zod.public_orgs ON (org_id = public_orgs.id) AND (enable_genetech_hotlist = 1) WHERE (integration_id IN (1, 2, 5, 6, 8, 9, 10, 44, 1482)) AND (((public_orgs.id IS NULL) AND (is_staged = 1)) OR ((public_orgs.id IS NOT NULL) AND (is_staged = 0)));

-- Materialized View: kit_last_scan_mv
DROP TABLE IF EXISTS zod.kit_last_scan_mv;
CREATE MATERIALIZED VIEW zod.kit_last_scan_mv TO zod.kit_last_scan (`org_id` Int32, `org_name` String, `kit_id` Int32, `kit_name` String, `last_scan` DateTime64(6)) AS SELECT k.org_id, o.name AS org_name, s.kit_id, k.name AS kit_name, s.scanned_at AS last_scan FROM zod.public_scans AS s INNER JOIN zod.public_kits AS k ON s.kit_id = k.id INNER JOIN zod.public_orgs AS o ON k.org_id = o.id WHERE (s._peerdb_is_deleted = 0) AND (k._peerdb_is_deleted = 0) AND (o._peerdb_is_deleted = 0);

-- Materialized View: scan_lag_5min_mv
DROP TABLE IF EXISTS zod.scan_lag_5min_mv;
CREATE MATERIALIZED VIEW zod.scan_lag_5min_mv TO zod.scan_lag_5min (`kit_id` Int32, `time_bucket` DateTime, `scan_count` UInt64, `avg_lag_sec` Float64, `median_lag_sec` Float64, `min_lag_sec` Int64, `max_lag_sec` Int64, `stddev_lag_sec` Float64) AS SELECT kit_id, toStartOfFiveMinutes(created_at) AS time_bucket, count() AS scan_count, avg(dateDiff('second', scanned_at, created_at)) AS avg_lag_sec, median(dateDiff('second', scanned_at, created_at)) AS median_lag_sec, min(dateDiff('second', scanned_at, created_at)) AS min_lag_sec, max(dateDiff('second', scanned_at, created_at)) AS max_lag_sec, stddevPop(dateDiff('second', scanned_at, created_at)) AS stddev_lag_sec FROM zod.scans_geo WHERE _peerdb_is_deleted = 0 GROUP BY kit_id, time_bucket;

-- Materialized View: scan_ttl_compliance_mv
DROP TABLE IF EXISTS zod.scan_ttl_compliance_mv;
CREATE MATERIALIZED VIEW zod.scan_ttl_compliance_mv REFRESH EVERY 4 HOUR TO zod.scan_ttl_compliance (`org_id` Int32, `org_name` String, `ttl_days` Int64, `cutoff_date` Date, `oldest_scan` Date, `newest_scan` Date, `total_scans` UInt64, `scans_past_ttl` UInt64, `status` String, `updated_at` DateTime) DEFINER = `sql-console:redacted@example.com` SQL SECURITY DEFINER AS SELECT org_id, org_name, scan_ttl AS ttl_days, toDate((now() - toIntervalDay(scan_ttl)) - toIntervalHour(24)) AS cutoff_date, toDate(oldest_scan) AS oldest_scan, toDate(newest_scan) AS newest_scan, total_scans, scans_past_ttl, if(scans_past_ttl > 0, 'OUT OF POLICY', 'OK') AS status, now() AS updated_at FROM (SELECT k.org_id, any(o.name) AS org_name, any(JSONExtractInt(om.properties, 'scan_ttl')) AS scan_ttl, min(s.scanned_at) AS oldest_scan, max(s.scanned_at) AS newest_scan, count(*) AS total_scans, countIf(s.scanned_at < ((now() - toIntervalDay(JSONExtractInt(om.properties, 'scan_ttl'))) - toIntervalHour(24))) AS scans_past_ttl FROM (SELECT * FROM zod.public_scans FINAL) AS s INNER JOIN (SELECT * FROM zod.public_kits FINAL) AS k ON s.kit_id = k.id INNER JOIN (SELECT * FROM zod.public_org_meta FINAL) AS om ON k.org_id = om.id INNER JOIN (SELECT * FROM zod.public_orgs FINAL) AS o ON k.org_id = o.id WHERE JSONExtractInt(om.properties, 'scan_ttl') > 0 GROUP BY k.org_id);

-- Materialized View: scans_geo_mv
DROP TABLE IF EXISTS zod.scans_geo_mv;
CREATE MATERIALIZED VIEW zod.scans_geo_mv TO zod.scans_geo (`id` Int64, `kit_id` Int32, `ocr` String, `us_state` String, `scanned_at` DateTime64(6), `created_at` DateTime64(6), `camera_name` String, `camera_position` Nullable(String), `guid` UUID, `is_visible` Bool, `lng` Float64, `lat` Float64, `_peerdb_synced_at` DateTime64(9), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) AS SELECT id, kit_id, ocr, us_state, scanned_at, created_at, splitByChar(' ', camera_name)[1] AS camera_name, nullIf(trimBoth(substring(camera_name, length(splitByChar(' ', camera_name)[1]) + 1)), '') AS camera_position, guid, is_visible, toFloat64OrZero(extract(geo, 'POINT\\s*\\(\\s*([\\-0-9.]+)')) AS lng, toFloat64OrZero(extract(geo, 'POINT\\s*\\(\\s*[\\-0-9.]+\\s+([\\-0-9.]+)')) AS lat, _peerdb_synced_at, _peerdb_is_deleted, _peerdb_version FROM zod.public_scans WHERE (geo LIKE '%POINT%') AND (geo != '') AND (is_visible = 1) AND (length(ocr) >= 3) AND (toFloat64OrZero(extract(geo, 'POINT\\s*\\(\\s*([\\-0-9.]+)')) != 0) AND (toFloat64OrZero(extract(geo, 'POINT\\s*\\(\\s*[\\-0-9.]+\\s+([\\-0-9.]+)')) != 0);

-- Materialized View: scans_metadata_extracted_mv
DROP TABLE IF EXISTS zod.scans_metadata_extracted_mv;
CREATE MATERIALIZED VIEW zod.scans_metadata_extracted_mv TO zod.scans_metadata_extracted (`scan_id` Int64, `vehicle_type` Nullable(String), `vehicle_color` Nullable(String), `vehicle_make` Nullable(String), `is_not_plate` Bool, `ml_plate_state` Nullable(String), `address` Nullable(String), `simple_address` Nullable(String), `disposition` Nullable(String), `is_daytime` Bool, `_peerdb_synced_at` DateTime64(9), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64) AS SELECT scan_id, JSONExtract(meta, '1', 'Nullable(String)') AS vehicle_type, JSONExtract(meta, '2', 'Nullable(String)') AS vehicle_color, JSONExtract(meta, '3', 'Nullable(String)') AS vehicle_make, if(JSONExtract(meta, '4', 'Nullable(String)') = 'true', true, false) AS is_not_plate, JSONExtract(meta, '5', 'Nullable(String)') AS ml_plate_state, JSONExtract(meta, '6', 'Nullable(String)') AS address, JSONExtract(meta, '7', 'Nullable(String)') AS simple_address, JSONExtract(meta, 'disposition', 'Nullable(String)') AS disposition, JSONExtract(meta, 'is_daytime', 'Boolean') AS is_daytime, _peerdb_synced_at, _peerdb_is_deleted, _peerdb_version FROM zod.public_scans_metadata;

-- Materialized View: scans_out_of_policy_mv
DROP TABLE IF EXISTS zod.scans_out_of_policy_mv;
CREATE MATERIALIZED VIEW zod.scans_out_of_policy_mv REFRESH EVERY 1 HOUR TO zod.scans_out_of_policy_data (`scan_id` Int64, `guid` UUID, `kit_id` Int32, `scanned_at` DateTime64(6), `created_at` DateTime64(6), `org_id` Int32, `org_name` String, `ttl_days` Int64, `age_days` Int64, `refreshed_at` DateTime) DEFINER = `sql-console:redacted@example.com` SQL SECURITY DEFINER AS SELECT s.id AS scan_id, s.guid, s.kit_id, s.scanned_at, s.created_at, k.org_id, o.name AS org_name, JSONExtractInt(om.properties, 'scan_ttl') AS ttl_days, dateDiff('day', s.scanned_at, now()) AS age_days, now() AS refreshed_at FROM zod.public_scans AS s FINAL INNER JOIN zod.public_kits AS k FINAL ON s.kit_id = k.id INNER JOIN zod.public_orgs AS o FINAL ON k.org_id = o.id INNER JOIN zod.public_org_meta AS om FINAL ON k.org_id = om.id WHERE (s._peerdb_is_deleted = 0) AND (k._peerdb_is_deleted = 0) AND (o._peerdb_is_deleted = 0) AND (om._peerdb_is_deleted = 0) AND (JSONExtractInt(om.properties, 'scan_ttl') > 0) AND (s.scanned_at < ((now() - toIntervalDay(JSONExtractInt(om.properties, 'scan_ttl'))) - toIntervalHour(24)));
