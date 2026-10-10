-- THREE months, bucketed by month in ONE query. The BatchMonthlySparklineData shape.
WITH
claims AS (
    SELECT ak.org_id AS forwarder_org_id,
           JSONExtractInt(be.additional,'claimed_by_org_id') AS agent_org_id,
           upper(trimBoth(JSONExtractString(be.additional,'vin'))) AS vin,
           be.success AS success,
           toDate(fromUnixTimestamp(be.ts),'UTC') AS claim_date,
           toStartOfMonth(toDate(fromUnixTimestamp(be.ts),'UTC')) AS bucket
    FROM zod.public_billing_events AS be
    INNER JOIN (SELECT id, org_id FROM zod.public_api_keys FINAL WHERE _peerdb_is_deleted=0) AS ak
      ON ak.id = be.api_key_id
    WHERE be._peerdb_is_deleted=0 AND be.event_type IN ('case-claim')
      AND be.ts BETWEEN __FROM__ AND __TO__
),
deduped AS (
    SELECT bucket, forwarder_org_id, agent_org_id, vin, min(claim_date) AS claim_date
    FROM claims
    WHERE success AND vin != '' AND (forwarder_org_id = __ORG__ OR agent_org_id = __ORG__)
    GROUP BY bucket, forwarder_org_id, agent_org_id, vin
),
pairs AS (SELECT DISTINCT vin, agent_org_id FROM deduped WHERE agent_org_id != 0),
floor_date AS (SELECT min(claim_date) - 7 AS d FROM deduped),
rec AS (
    SELECT d.vin AS vin, d.agent_org_id AS org_id, max(hsl.created_at) AS repo_ts
    FROM pairs AS d
    INNER JOIN (SELECT id, org_id, vin FROM zod.public_hotlist FINAL
                WHERE _peerdb_is_deleted=0 AND status=3) AS h
      ON h.org_id = d.agent_org_id AND h.vin = d.vin
    INNER JOIN (SELECT hotlist_id, created_at FROM zod.public_hotlist_status_log
                WHERE _peerdb_is_deleted=0 AND new_status=3
                  AND toDate(created_at,'UTC') >= (SELECT d FROM floor_date)) AS hsl
      ON hsl.hotlist_id = h.id
    GROUP BY d.vin, d.agent_org_id
)
SELECT d.bucket AS month,
       count() AS deduped_rows,
       countIf(toDate(rec.repo_ts,'UTC') >= d.claim_date - 7) AS db_repos
FROM deduped AS d
LEFT JOIN rec ON rec.vin = d.vin AND rec.org_id = d.agent_org_id
GROUP BY d.bucket ORDER BY d.bucket
