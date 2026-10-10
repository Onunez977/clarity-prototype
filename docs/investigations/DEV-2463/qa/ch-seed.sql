-- DEV-2463 QA seed for the Unique LPR Case Claims card. Idempotent: truncates its own three
-- tables first, so re-applying after a suite run (which truncates the literal clarity/zod
-- databases) restores exactly this scenario.
--
-- Designed so every port rule is observable in one screenshot:
--   forwarder = org 2 (the entitled Pro org the dev-login persona belongs to)
--   agent     = org 3
--   5 successful distinct VINs                 -> deduped 5
-- + 1 exact duplicate triple                   -> successful 6, deduped still 5
-- + 1 failed claim (success = false)           -> all_claims 7, successful 6
-- + 1 ls-claim                                 -> ignored entirely
-- + 1 successful claim forwarded by InsightLPR -> EXCLUDED by the insightlpr needle
--   TitleMax appears as an agent and is NEVER filtered.
-- Expected card value (deduped, this UTC month): 5
TRUNCATE TABLE IF EXISTS zod.public_orgs;
TRUNCATE TABLE IF EXISTS zod.public_api_keys;
TRUNCATE TABLE IF EXISTS zod.public_billing_events;

INSERT INTO zod.public_orgs (id, name, _peerdb_is_deleted, _peerdb_version) VALUES
  (2, 'Test Company', 0, 1),
  (3, 'TitleMax - RepoSystems', 0, 1),
  (9, 'InsightLPR, Inc.', 0, 1);

INSERT INTO zod.public_api_keys (id, org_id, _peerdb_is_deleted, _peerdb_version) VALUES
  (7001, 2, 0, 1),
  (7009, 9, 0, 1);

INSERT INTO zod.public_billing_events (id, api_key_id, success, event_type, additional, ts, _peerdb_is_deleted, _peerdb_version) VALUES
  (8001, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1HGCM82633A000001"}', 1789084800, 0, 1),
  (8002, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1HGCM82633A000002"}', 1789084800, 0, 1),
  (8003, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1HGCM82633A000003"}', 1789084800, 0, 1),
  (8004, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1HGCM82633A000004"}', 1789084800, 0, 1),
  (8005, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1HGCM82633A000005"}', 1789084800, 0, 1),
  (8006, 7001, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1HGCM82633A000001"}', 1789084800, 0, 1),
  (8007, 7001, 0, 'case-claim', '{"claimed_by_org_id":3,"vin":"1HGCM82633A000007"}', 1789084800, 0, 1),
  (8008, 7001, 1, 'ls-claim',   '{"claimed_by_org_id":3,"vin":"1HGCM82633A000008"}', 1789084800, 0, 1),
  (8009, 7009, 1, 'case-claim', '{"claimed_by_org_id":3,"vin":"1HGCM82633A000009"}', 1789084800, 0, 1);
