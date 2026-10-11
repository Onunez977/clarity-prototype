--
-- PostgreSQL equivalents of ClickHouse tables used by the scanner portal.
-- Run against the local `zod` database when using CLICKHOUSE_DRIVER=pgsql.
--

CREATE SCHEMA IF NOT EXISTS clarity;

-- Bridge function: geohashEncode (used by HitDensityGeohashQuery)
CREATE OR REPLACE FUNCTION clarity."geohashEncode"(lng double precision, lat double precision, precision_val integer DEFAULT 6)
RETURNS character varying AS $$
DECLARE
    base32 text := '0123456789bcdefghjkmnpqrstuvwxyz';
    min_lat double precision := -90;
    max_lat double precision := 90;
    min_lng double precision := -180;
    max_lng double precision := 180;
    mid double precision;
    is_lng boolean := true;
    result text := '';
    ch_idx integer := 0;
    bit integer := 4;
    hash_len integer := 0;
BEGIN
    WHILE hash_len < precision_val LOOP
        IF is_lng THEN
            mid := (min_lng + max_lng) / 2;
            IF lng >= mid THEN
                ch_idx := ch_idx | (1 << bit);
                min_lng := mid;
            ELSE
                max_lng := mid;
            END IF;
        ELSE
            mid := (min_lat + max_lat) / 2;
            IF lat >= mid THEN
                ch_idx := ch_idx | (1 << bit);
                min_lat := mid;
            ELSE
                max_lat := mid;
            END IF;
        END IF;
        is_lng := NOT is_lng;
        bit := bit - 1;
        IF bit < 0 THEN
            result := result || substr(base32, ch_idx + 1, 1);
            hash_len := hash_len + 1;
            bit := 4;
            ch_idx := 0;
        END IF;
    END LOOP;
    RETURN result;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- kit_trips: trip segments from GPS data
CREATE TABLE IF NOT EXISTS clarity.kit_trips (
    kit_id integer NOT NULL DEFAULT 0,
    org_id integer NOT NULL DEFAULT 0,
    kit_mapping character varying(255) NOT NULL DEFAULT '',
    gl character varying(255) NOT NULL DEFAULT '',
    trip_date date NOT NULL DEFAULT CURRENT_DATE,
    trip_start timestamp NOT NULL DEFAULT NOW(),
    trip_end timestamp NOT NULL DEFAULT NOW(),
    trip_start_local timestamp NOT NULL DEFAULT NOW(),
    trip_end_local timestamp NOT NULL DEFAULT NOW(),
    duration_minutes integer NOT NULL DEFAULT 0,
    point_count integer NOT NULL DEFAULT 0,
    path_simplified jsonb NOT NULL DEFAULT '[]'::jsonb,
    avg_speed real NOT NULL DEFAULT 0,
    max_speed real NOT NULL DEFAULT 0,
    _version bigint DEFAULT 1
);

-- Add path_simplified if table already exists but column is missing
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'clarity' AND table_name = 'kit_trips' AND column_name = 'path_simplified'
    ) THEN
        ALTER TABLE clarity.kit_trips ADD COLUMN path_simplified jsonb NOT NULL DEFAULT '[]'::jsonb;
    END IF;
END $$;

-- scans_hot: recent scan data (for trip scan counts)
CREATE TABLE IF NOT EXISTS clarity.scans_hot (
    id bigserial PRIMARY KEY,
    kit_id integer NOT NULL DEFAULT 0,
    ocr character varying(255) NOT NULL DEFAULT '',
    us_state character varying(10) NOT NULL DEFAULT '',
    camera_name character varying(255) NOT NULL DEFAULT '',
    guid uuid DEFAULT gen_random_uuid(),
    scanned_at timestamp NOT NULL DEFAULT NOW(),
    created_at timestamp NOT NULL DEFAULT NOW(),
    lng double precision DEFAULT 0,
    lat double precision DEFAULT 0,
    kit_name character varying(255) DEFAULT '',
    org_id integer NOT NULL DEFAULT 0,
    disposition character varying(255),
    address text,
    _peerdb_synced_at timestamp DEFAULT NOW(),
    _version bigint DEFAULT 1,
    _peerdb_is_deleted smallint DEFAULT 0
);

-- scans_cold: historical scan data (for trip scan counts)
CREATE TABLE IF NOT EXISTS clarity.scans_cold (
    id bigserial PRIMARY KEY,
    kit_id integer NOT NULL DEFAULT 0,
    ocr character varying(255) NOT NULL DEFAULT '',
    us_state character varying(10) NOT NULL DEFAULT '',
    camera_name character varying(255) NOT NULL DEFAULT '',
    guid uuid DEFAULT gen_random_uuid(),
    scanned_at timestamp NOT NULL DEFAULT NOW(),
    created_at timestamp NOT NULL DEFAULT NOW(),
    lng double precision DEFAULT 0,
    lat double precision DEFAULT 0,
    kit_name character varying(255) DEFAULT '',
    org_id integer NOT NULL DEFAULT 0,
    disposition character varying(255),
    address text,
    _peerdb_synced_at timestamp DEFAULT NOW(),
    _version bigint DEFAULT 1,
    _peerdb_is_deleted smallint DEFAULT 0
);

-- hits_hot: recent hit data (for trip hit counts)
CREATE TABLE IF NOT EXISTS clarity.hits_hot (
    id bigserial PRIMARY KEY,
    hotlist_id bigint NOT NULL DEFAULT 0,
    scan_id bigint NOT NULL DEFAULT 0,
    hit_type character varying(50) NOT NULL DEFAULT '',
    hit_created_at timestamp NOT NULL DEFAULT NOW(),
    ocr character varying(255) NOT NULL DEFAULT '',
    scanned_at timestamp NOT NULL DEFAULT NOW(),
    camera_name character varying(255) NOT NULL DEFAULT '',
    scan_us_state character varying(10) NOT NULL DEFAULT '',
    scan_guid uuid DEFAULT gen_random_uuid(),
    kit_id integer NOT NULL DEFAULT 0,
    kit_name character varying(255) NOT NULL DEFAULT '',
    kit_org_id integer NOT NULL DEFAULT 0,
    hotlist_us_state character varying(10),
    hotlist_plate character varying(255),
    hotlist_status integer,
    hotlist_vin character varying(255),
    hotlist_org_id integer NOT NULL DEFAULT 0,
    lat double precision DEFAULT 0,
    lng double precision DEFAULT 0,
    _peerdb_is_deleted smallint DEFAULT 0,
    _version bigint DEFAULT 1
);

-- kit_scan_paths: 15-minute scan path buckets
CREATE TABLE IF NOT EXISTS clarity.kit_scan_paths (
    kit_id integer NOT NULL DEFAULT 0,
    org_id integer NOT NULL DEFAULT 0,
    "timestamp" timestamp NOT NULL DEFAULT NOW(),
    scan_count bigint NOT NULL DEFAULT 0,
    path_points jsonb NOT NULL DEFAULT '[]'::jsonb,
    kit_name character varying(255) NOT NULL DEFAULT '',
    _version bigint DEFAULT 1
);

-- hits_cold: historical hits for heatmap density
CREATE TABLE IF NOT EXISTS clarity.hits_cold (
    id bigserial PRIMARY KEY,
    hotlist_id bigint NOT NULL DEFAULT 0,
    scan_id bigint NOT NULL DEFAULT 0,
    hit_type character varying(50) NOT NULL DEFAULT '',
    hit_created_at timestamp NOT NULL DEFAULT NOW(),
    ocr character varying(255) NOT NULL DEFAULT '',
    scanned_at timestamp NOT NULL DEFAULT NOW(),
    camera_name character varying(255) NOT NULL DEFAULT '',
    scan_us_state character varying(10) NOT NULL DEFAULT '',
    scan_guid uuid DEFAULT gen_random_uuid(),
    kit_id integer NOT NULL DEFAULT 0,
    kit_name character varying(255) NOT NULL DEFAULT '',
    kit_org_id integer NOT NULL DEFAULT 0,
    hotlist_us_state character varying(10),
    hotlist_plate character varying(255),
    hotlist_status integer,
    hotlist_vin character varying(255),
    hotlist_org_id integer NOT NULL DEFAULT 0,
    lat double precision DEFAULT 0,
    lng double precision DEFAULT 0,
    _peerdb_is_deleted smallint DEFAULT 0,
    _version bigint DEFAULT 1
);

-- scout_open_addresses: fleet-wide open-order book (chlocal migration 111).
-- tupleElement() is ClickHouse-only, so this pgsql bridge row exists for
-- seeding/truncation parity — the matcher does not run under CLICKHOUSE_DRIVER=pgsql.
CREATE TABLE IF NOT EXISTS clarity.scout_open_addresses (
    org_id integer NOT NULL DEFAULT 0,
    is_staged boolean NOT NULL DEFAULT false,
    hotlist_id bigint NOT NULL DEFAULT 0,
    address_id bigint NOT NULL DEFAULT 0,
    geo point NOT NULL DEFAULT '(0,0)'::point,
    refreshed_at timestamp NOT NULL DEFAULT NOW()
);

-- Columns the app selects from hits that the original local bridge omitted.
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS disposition character varying(255);
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS address text;
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS remote_order_id character varying(64);
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS integration_id integer;
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS client_org_id integer;
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS client_org_name character varying(255);
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS lienholder_org_id integer;
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS lienholder_org_name character varying(255);
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS branch_id integer DEFAULT 0;
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS vehicle_year character varying(16);
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS vehicle_make character varying(64);
ALTER TABLE clarity.hits_hot ADD COLUMN IF NOT EXISTS vehicle_model character varying(64);

ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS disposition character varying(255);
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS address text;
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS remote_order_id character varying(64);
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS integration_id integer;
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS client_org_id integer;
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS client_org_name character varying(255);
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS lienholder_org_id integer;
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS lienholder_org_name character varying(255);
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS branch_id integer DEFAULT 0;
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS vehicle_year character varying(16);
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS vehicle_make character varying(64);
ALTER TABLE clarity.hits_cold ADD COLUMN IF NOT EXISTS vehicle_model character varying(64);

-- Postgres stand-ins for ClickHouse date helpers used by Company Metrics.
CREATE OR REPLACE FUNCTION toStartOfMonth(ts timestamp without time zone)
RETURNS timestamp without time zone LANGUAGE sql IMMUTABLE AS $$
    SELECT date_trunc('month', ts)
$$;

CREATE OR REPLACE FUNCTION toStartOfMonth(d date)
RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT date_trunc('month', d)::date
$$;

CREATE OR REPLACE FUNCTION toTimeZone(ts timestamp without time zone, tz text)
RETURNS timestamp without time zone LANGUAGE sql IMMUTABLE AS $$
    SELECT (ts AT TIME ZONE 'UTC' AT TIME ZONE tz)::timestamp
$$;

CREATE OR REPLACE FUNCTION toDate(ts timestamp without time zone)
RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT ts::date
$$;

CREATE OR REPLACE FUNCTION toDate(d date)
RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT d
$$;


CREATE OR REPLACE FUNCTION toDateTime(s text)
RETURNS timestamp without time zone LANGUAGE sql IMMUTABLE AS $$
    SELECT s::timestamp without time zone
$$;

CREATE OR REPLACE FUNCTION toStartOfHour(ts timestamp without time zone)
RETURNS timestamp without time zone LANGUAGE sql IMMUTABLE AS $$
    SELECT date_trunc('hour', ts)
$$;

-- clarity.hotlist mirrors the ClickHouse table from the Postgres hotlists seeded above.
CREATE OR REPLACE VIEW clarity.hotlist AS
SELECT
    h.id,
    h.org_id,
    h.integration_id,
    h.remote_order_id,
    h.client_org_id,
    c.name AS client_org_name,
    h.lienholder_org_id,
    l.name AS lienholder_org_name,
    h.status,
    h.order_type,
    CASE WHEN h.is_staged THEN 1 ELSE 0 END AS is_staged,
    h.plate,
    h.us_state,
    h.vin,
    h.vehicle_year,
    h.vehicle_make,
    h.vehicle_model,
    h.lookback_days,
    h.created_at,
    h.updated_at,
    NULL::bigint AS branch_id,
    now() AS _peerdb_synced_at,
    0::smallint AS _peerdb_is_deleted,
    1::bigint AS _version,
    hm.repo_date,
    hm.order_date,
    hm.close_date
FROM public.hotlist h
LEFT JOIN public.orgs c ON c.id = h.client_org_id
LEFT JOIN public.orgs l ON l.id = h.lienholder_org_id
LEFT JOIN public.hotlist_meta hm ON hm.hotlist_id = h.id;

-- hotlist_address_geohash: pre-aggregated address density by geohash cell
CREATE TABLE IF NOT EXISTS clarity.hotlist_address_geohash (
    org_id integer NOT NULL DEFAULT 0,
    source character varying(255) NOT NULL DEFAULT 'own',
    geohash character varying(255) NOT NULL DEFAULT '',
    address_count integer NOT NULL DEFAULT 0,
    center_lng double precision DEFAULT 0,
    center_lat double precision DEFAULT 0,
    _version bigint DEFAULT 1
);
