WITH
claims AS (
    SELECT
        org_id AS forwarder_org_id,
        agent_org_id AS agent_org_id,
        vin AS vin,
        success AS success,
        event_date AS claim_date,
        toStartOfMonth(event_date) AS bucket
    FROM clarity.billing_claim_events_v
    WHERE is_internal = 0
      AND event_type = 'case-claim'
      AND ts BETWEEN 1756684800 AND 1790812799
      AND event_date BETWEEN toDate('2025-09-01') AND toDate('2026-09-30')
      AND (14 = 0 OR org_id = 14 OR agent_org_id = 14)
),
deduped AS (
    SELECT bucket, forwarder_org_id, agent_org_id, vin, min(claim_date) AS claim_date
    FROM claims
    WHERE success AND vin != ''
    GROUP BY bucket, forwarder_org_id, agent_org_id, vin
)
SELECT
    d.bucket AS bucket,
    uniqExact((d.forwarder_org_id, d.agent_org_id, d.vin)) AS deduped,
    uniqExactIf((d.forwarder_org_id, d.agent_org_id, d.vin), toDate(hsl.created_at, 'UTC') >= d.claim_date - 7) AS db_repos
FROM deduped AS d
LEFT JOIN (
    SELECT id, org_id, vin FROM zod.public_hotlist FINAL
    WHERE _peerdb_is_deleted = 0 AND status = 3
) AS h ON h.org_id = d.agent_org_id AND h.vin = d.vin AND d.agent_org_id != 0
LEFT JOIN (
    SELECT hotlist_id, created_at FROM zod.public_hotlist_status_log
    WHERE _peerdb_is_deleted = 0 AND new_status = 3
      AND toDate(created_at, 'UTC') >= toDate('2025-08-25')
) AS hsl ON hsl.hotlist_id = h.id AND h.id != 0
GROUP BY d.bucket
ORDER BY d.bucket
FORMAT JSONEachRow
