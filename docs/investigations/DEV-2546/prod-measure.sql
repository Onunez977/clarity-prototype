-- DEV-2546 · RN5 pre-fix measurement · READ-ONLY
-- Answers the four premises the RN4 review lanes could not settle from the source tree.
--
-- Run on the prod box against the `zod` database. Stages 1-3 EXECUTE NOTHING expensive.
-- Stage 4 is the only one that runs the real aggregate, and it is capped by a statement
-- timeout so it cannot run away. Read stage 3 before you run stage 4.
--
-- Enum values baked in from the code:
--   OverWatch hit_type = 'O'   (InsightLPR\BlackAdam\Enum\HitType::OverWatch)
--   NICB integration_id = 5    (InsightLPR\BlackAdam\Enum\IntegrationEnum::NICB)
--   cards cover L, H, S, Z     (Live, Historical, Staging Live, Staging Historical)
--   uncovered + selectable in the Type filter: F, P

\timing on

-- ============================================================
-- STAGE 1 — premise: is the Postgres branch hot in production?
-- The cards use the PG branch for any org WITHOUT the clickhouse:hits Pennant flag.
-- If that set is empty, H1 is reachable only via impersonation and its severity drops.
-- ============================================================
SELECT
    COUNT(*) FILTER (WHERE f.value::text IN ('true','1','"1"')) AS orgs_on_clickhouse,
    COUNT(*) FILTER (WHERE f.value::text NOT IN ('true','1','"1"')) AS orgs_on_postgres,
    COUNT(*) AS orgs_with_an_explicit_flag
FROM features f
WHERE f.name = 'clickhouse:hits';

-- Orgs that have NO row at all also fall through to Postgres (Pennant resolves them lazily).
SELECT COUNT(*) AS orgs_total FROM orgs;

-- ============================================================
-- STAGE 2 — premise: how many rows would go UNCOUNTED once the
-- fifth "Other Hits" card is removed (operator decision: four cards, always).
-- Previously measured on clarity.hits_cold only (F=34, P=0). This is the Postgres truth.
-- ============================================================
SELECT hit_type, COUNT(*) AS rows
FROM hits
WHERE hit_type IN ('F','P','M','O')
GROUP BY hit_type
ORDER BY hit_type;

-- ============================================================
-- STAGE 3 — the PLAN ONLY. Plain EXPLAIN does NOT execute the query.
-- This is the cheap answer to "is this a sequential scan over 318M rows?".
-- Substitute :busy_org_kit_ids (see the helper query below) before running.
-- ============================================================

-- helper: pick a representative busy org and its kits
SELECT k.org_id, COUNT(DISTINCT k.id) AS kits, COUNT(h.id) AS hits_today
FROM kits k
LEFT JOIN hits h ON h.kit_id = k.id AND h.created_at >= CURRENT_DATE
GROUP BY k.org_id
ORDER BY hits_today DESC
LIMIT 5;

-- the aggregate exactly as app/Filament/Concerns/HasHitMetricCards.php::applyHitMetricAggregate()
-- compiles it over ListHits::buildPostgresQuery(), with the DEFAULT filter state
-- (dispositions = [NEW_HIT, ACCESSIBLE], Hit Date = today).
EXPLAIN
SELECT hits.hit_type AS hit_type,
       COUNT(DISTINCT scans.ocr) AS unique_hits,
       COUNT(*)                  AS total_hits
FROM hits
JOIN hotlist          ON hotlist.id            = hits.hotlist_id
JOIN scans            ON scans.id              = hits.scan_id
JOIN kits             ON kits.id               = scans.kit_id
JOIN scans_metadata   ON scans_metadata.scan_id = hits.scan_id
JOIN orgs AS client_orgs      ON hotlist.client_org_id     = client_orgs.id
JOIN orgs AS lienholder_orgs  ON hotlist.lienholder_org_id = lienholder_orgs.id
WHERE hotlist.integration_id <> 5
  AND hits.hit_type <> 'O'
  AND hits.kit_id IN (:busy_org_kit_ids)
  AND hits.created_at >= CURRENT_DATE
  AND (
        scans_metadata.meta -> 'disposition' IS NULL
     OR scans_metadata.meta @> '[{"disposition":"accessible"}]'
  )
GROUP BY hits.hit_type;

-- ============================================================
-- STAGE 4 — the real cost. THIS EXECUTES THE QUERY.
-- Capped at 30s so it aborts instead of loading prod. If it times out, that
-- IS the measurement: the aggregate cannot complete inside a page render.
-- Run this only after reading stage 3's plan.
-- ============================================================
BEGIN;
SET LOCAL statement_timeout = '30s';
EXPLAIN (ANALYZE, BUFFERS)
SELECT hits.hit_type AS hit_type,
       COUNT(DISTINCT scans.ocr) AS unique_hits,
       COUNT(*)                  AS total_hits
FROM hits
JOIN hotlist          ON hotlist.id            = hits.hotlist_id
JOIN scans            ON scans.id              = hits.scan_id
JOIN kits             ON kits.id               = scans.kit_id
JOIN scans_metadata   ON scans_metadata.scan_id = hits.scan_id
JOIN orgs AS client_orgs      ON hotlist.client_org_id     = client_orgs.id
JOIN orgs AS lienholder_orgs  ON hotlist.lienholder_org_id = lienholder_orgs.id
WHERE hotlist.integration_id <> 5
  AND hits.hit_type <> 'O'
  AND hits.kit_id IN (:busy_org_kit_ids)
  AND hits.created_at >= CURRENT_DATE
  AND (
        scans_metadata.meta -> 'disposition' IS NULL
     OR scans_metadata.meta @> '[{"disposition":"accessible"}]'
  )
GROUP BY hits.hit_type;
ROLLBACK;
