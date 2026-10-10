-- DEV-2464 QA seed for the LPR Repossessions card (db_repos). Idempotent: truncates
-- only the tables this seed owns, so re-applying after a suite run (which truncates
-- the literal clarity/zod databases) restores exactly this scenario.
--
-- Isolated-cloud runner MUST apply this SQL to clarity_clickhouse BEFORE Playwright
-- tests/e2e/lpr-repossessions.spec.ts. Case B depends on it. See RUNNER.md.
--
-- Designed so Case B passes on the DEFAULT This Month window (no Last Month toggle):
--   forwarder = org 2 (Test Company — Pro org the login persona belongs to)
--   agent     = org 3 (TitleMax - RepoSystems)
--   org 9     = InsightLPR, excluded by the insightlpr needle (no counted claims)
--
-- THIS UTC month (September 2026 relative to the Case B FAIL date):
--   3 successful distinct case-claim triples, claim ts 2026-09-05 15:00 UTC
--     (1788620400). Only VIN 1QA2464VIN000002 has a status=3 hotlist + status_log
--     at 2026-09-06 16:00 UTC, so uniqExactIf counts db_repos.
--   Today (UTC calendar day) is left EMPTY so scenario H can still find a zero
--     window (E2E_LPR_REPOSSESSIONS_ZERO_WINDOW=today).
--
-- Optional last-month pair (2026-08-15 claim / 2026-08-16 repo) is present so
-- Last Month is non-zero if toggled. Case B must NOT rely on that toggle.
--
-- Expected Pro org 2 compact LPR Repossessions (db_repos):
--   This Month = 1
--   Last Month = 1
--   Today      = 0
--
TRUNCATE TABLE IF EXISTS zod.public_orgs;
TRUNCATE TABLE IF EXISTS zod.public_api_keys;
TRUNCATE TABLE IF EXISTS zod.public_billing_events;
TRUNCATE TABLE IF EXISTS zod.public_hotlist;
TRUNCATE TABLE IF EXISTS zod.public_hotlist_status_log;

INSERT INTO zod.public_orgs (id, name, type, _peerdb_is_deleted, _peerdb_version) VALUES
  (2, 'Test Company', 1, 0, 1),
  (3, 'TitleMax - RepoSystems', 1, 0, 1),
  (9, 'InsightLPR, Inc.', 1, 0, 1);

INSERT INTO zod.public_api_keys (id, org_id, _peerdb_is_deleted, _peerdb_version) VALUES
  (7001, 2, 0, 1),
  (7009, 9, 0, 1);

-- 8001 = the counted This Month claim (official Case B PASS follow-up).
-- 8002/8003 = additional this-month successful triples (no repo — not in db_repos).
-- 8004 = optional last-month counted pair.
INSERT INTO zod.public_billing_events (id, api_key_id, success, event_type, additional, ts, _peerdb_is_deleted, _peerdb_version) VALUES
  (8001, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1QA2464VIN000002"}', 1788620400, 0, 1),
  (8002, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1QA2464VIN000003"}', 1788620400, 0, 1),
  (8003, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1QA2464VIN000004"}', 1788620400, 0, 1),
  (8004, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1QA2464VIN000001"}', 1786795200, 0, 1);

INSERT INTO zod.public_hotlist (
  id, integration_id, status, org_id, client_org_id, order_type, is_staged,
  plate, us_state, vin, vehicle_year, vehicle_make, vehicle_model, lookback_days,
  created_at, updated_at, _peerdb_is_deleted, _peerdb_version
) VALUES
  (9001, 1, 3, 3, 3, 1, false, '', '', '1QA2464VIN000002', '', '', '', 0, '2026-09-05 15:00:00', '2026-09-06 16:00:00', 0, 1),
  (9002, 1, 3, 3, 3, 1, false, '', '', '1QA2464VIN000001', '', '', '', 0, '2026-08-15 12:00:00', '2026-08-16 13:00:00', 0, 1);

INSERT INTO zod.public_hotlist_status_log (
  id, hotlist_id, old_status, new_status, created_at, _peerdb_is_deleted, _peerdb_version
) VALUES
  (9101, 9001, 1, 3, '2026-09-06 16:00:00', 0, 1),
  (9102, 9002, 1, 3, '2026-08-16 13:00:00', 0, 1);
