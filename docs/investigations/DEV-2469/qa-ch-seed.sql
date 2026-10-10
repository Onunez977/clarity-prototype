-- DEV-2469 QA seed (local Docker ClickHouse, literal clarity/zod databases).
--
-- Makes the LPR-repossession funnel JOIN. The leftover seeds in this container came from two
-- different tickets and did not join: billing events referenced agent org 9902 / QAVIN*, while
-- the hotlist rows were org 340/341 / QATESTVIN*, so db_repos was 0.
--
-- Expected after seeding, for org 2, window 2026-09:
--   denominator db_repos = 8   (8 case-claims, all recovered within claim_date + 7d)
--   numerator unique ocr = 40  (40 distinct plates, 120 scan rows -> dedup must collapse them)
--   ratio                = 5.0

-- 1. Hotlist orders the claims can resolve to: agent org 9902, matching VINs, status 3.
INSERT INTO zod.public_hotlist
  (id, integration_id, remote_order_id, status, org_id, client_org_id, lienholder_org_id,
   order_type, is_staged, plate, us_state, vin, vehicle_year, vehicle_make, vehicle_model,
   lookback_days, created_at, updated_at, _peerdb_synced_at, _peerdb_is_deleted, _peerdb_version)
SELECT
  830000 + number                          AS id,
  1, NULL, 3,
  9902                                AS org_id,
  2                                   AS client_org_id,
  NULL, 1, true,
  concat('QAP', leftPad(toString(number), 5, '0')) AS plate,
  'TX',
  concat('QAVIN00000000', toString(number)) AS vin,
  '2022', 'QA', 'Model', 30,
  toDateTime64('2026-08-25 12:00:00', 6, 'UTC'),
  toDateTime64('2026-09-05 12:00:00', 6, 'UTC'),
  now64(9), 0, 1
FROM numbers(1, 8);

-- 2. The recovered transition, inside claim_date (2026-09-03) + 7 days.
INSERT INTO zod.public_hotlist_status_log
  (id, hotlist_id, old_status, new_status, created_at, _peerdb_synced_at, _peerdb_is_deleted, _peerdb_version)
SELECT 930000 + number, 830000 + number, 1, 3,
       toDateTime64('2026-09-05 12:00:00', 6, 'UTC'), now64(9), 0, 1
FROM numbers(1, 8);

-- 3. Numerator: 120 scans for org 2 across September 2026, but only 40 DISTINCT plates.
--    The 3x duplication is deliberate -- uniqExact(ocr) must collapse it to 40, so a
--    count()-instead-of-uniqExact regression would read 120 and the ratio would be 15.0.
INSERT INTO clarity.scans_cold
  (id, kit_id, ocr, us_state, camera_name, guid, scanned_at, created_at, lng, lat, geo,
   kit_name, org_id, disposition, address, _peerdb_synced_at, _version, _peerdb_is_deleted)
SELECT
  700000 + number                                        AS id,
  501                                               AS kit_id,
  concat('QAPLATE', leftPad(toString(number % 40), 4, '0')) AS ocr,
  'TX', 'QA Cam', generateUUIDv4(),
  toDateTime64('2026-09-02 08:00:00', 6, 'UTC') + toIntervalHour(number % 200) AS scanned_at,
  toDateTime64('2026-09-02 08:00:00', 6, 'UTC'),
  -96.8, 32.8, (-96.8, 32.8),
  'QA Kit', 2, NULL, NULL, now64(9), 1, 0
FROM numbers(1, 120);

-- 4. A "today" slice that exercises the WINDOW arm without disturbing the month figures.
--    The claim REUSES vin QAVIN000000001, so uniqExact on (forwarder, agent, vin) keeps the
--    month's deduped/db_repos at 8; the scans reuse plates already in the 40, so the month's
--    distinct ocr stays 40. Today's own window sees 1 repo and 6 distinct plates -> 6.0.
INSERT INTO zod.public_billing_events
  (id, api_key_id, success, event_type, additional, ts, _peerdb_synced_at, _peerdb_is_deleted, _peerdb_version)
SELECT 940001, 99001, true, 'case-claim',
       '{"claimed_by_org_id":9902,"vin":"QAVIN000000001","hotlist_id":830001}',
       toUnixTimestamp(toDateTime('2026-09-12 15:00:00','UTC')),
       now64(9), 0, 1;

INSERT INTO clarity.scans_cold
  (id, kit_id, ocr, us_state, camera_name, guid, scanned_at, created_at, lng, lat, geo,
   kit_name, org_id, disposition, address, _peerdb_synced_at, _version, _peerdb_is_deleted)
SELECT
  760000 + number, 501,
  concat('QAPLATE', leftPad(toString(number % 6), 4, '0')),
  'TX', 'QA Cam', generateUUIDv4(),
  toDateTime64('2026-09-12 15:00:00', 6, 'UTC') + toIntervalMinute(number),
  toDateTime64('2026-09-12 15:00:00', 6, 'UTC'),
  -96.8, 32.8, (-96.8, 32.8), 'QA Kit', 2, NULL, NULL, now64(9), 1, 0
FROM numbers(1, 12);
