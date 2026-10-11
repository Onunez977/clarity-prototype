-- DEV-2463 — the SINGLE-PASS claims-only card query, as measured against production
-- ClickHouse Cloud (read-only, readonly_user) on 2026-09-08.
--
-- Differences from DEV-2464's measure-*.sql, all deliberate:
--   * the `pairs` / `floor_date` / `rec` CTEs and BOTH hotlist legs are GONE. This card
--     needs only the claims funnel, so zod.public_hotlist (136M) and
--     zod.public_hotlist_status_log (389M) are never touched.
--   * all three funnel stages come from ONE scan via countIf/uniqExactIf, instead of three
--     separate references to the `claims` CTE. Measured: 3 references = 114-120M read_rows
--     (billing_events scanned three times); this single-pass form = 39.9M read_rows.
--   * rejectInternalOrgs(insightlpr) is applied explicitly, on BOTH the forwarder and the
--     agent side, with non-alphanumerics stripped first -- matching backyard's helper.
--     titlemax is NEVER filtered (port rule 1).
--   * the month bucket makes the sparkline a GROUP BY inside the same query, so "exactly one
--     cached query per resolved window" and "trend sparkline" are both satisfied.
--   * every date function passes 'UTC' explicitly (neither CH connection block in
--     config/database.php sets a timezone).
--
-- {org:Int32} = 0 means platform-wide; otherwise the org is counted on EITHER side.
WITH
orgs AS (
    SELECT id, lower(replaceRegexpAll(coalesce(name, ''), '[^a-zA-Z0-9]', '')) AS n
    FROM zod.public_orgs FINAL
    WHERE _peerdb_is_deleted = 0
),
claims AS (
    SELECT
        ak.org_id                                               AS forwarder_org_id,
        JSONExtractInt(be.additional, 'claimed_by_org_id')      AS agent_org_id,
        upper(trimBoth(JSONExtractString(be.additional, 'vin'))) AS vin,
        be.success                                              AS success,
        toStartOfMonth(toDate(fromUnixTimestamp(be.ts), 'UTC')) AS bucket
    FROM zod.public_billing_events AS be
    INNER JOIN (
        SELECT id, org_id FROM zod.public_api_keys FINAL WHERE _peerdb_is_deleted = 0
    ) AS ak ON ak.id = be.api_key_id
    WHERE be._peerdb_is_deleted = 0
      AND be.event_type IN ('case-claim')          -- ls-claim deliberately excluded
      AND be.ts BETWEEN {f:Int64} AND {t:Int64}    -- integer epoch, inclusive
),
kept AS (
    SELECT c.*
    FROM claims AS c
    LEFT JOIN orgs AS fo ON fo.id = c.forwarder_org_id
    LEFT JOIN orgs AS ao ON ao.id = c.agent_org_id
    WHERE position(coalesce(fo.n, ''), 'insightlpr') = 0
      AND position(coalesce(ao.n, ''), 'insightlpr') = 0
      AND ({org:Int32} = 0 OR c.forwarder_org_id = {org:Int32} OR c.agent_org_id = {org:Int32})
)
SELECT
    bucket,
    count()                                                                   AS all_claims,
    countIf(success)                                                          AS successful,
    uniqExactIf((forwarder_org_id, agent_org_id, vin), success AND vin != '')  AS deduped
FROM kept
GROUP BY bucket
ORDER BY bucket
