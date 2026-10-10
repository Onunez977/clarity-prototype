import sys; sys.path.insert(0,"/private/tmp/claude-501/dev2464")
from chq import load,q,fmt
env=load(".env")

BASE = """
WITH
orgs AS (
  SELECT id, lower(replaceRegexpAll(coalesce(name,''),'[^a-zA-Z0-9]','')) AS n
  FROM zod.public_orgs FINAL WHERE _peerdb_is_deleted = 0
),
claims AS (
  SELECT ak.org_id AS forwarder_org_id,
         JSONExtractInt(be.additional,'claimed_by_org_id') AS agent_org_id,
         upper(trimBoth(JSONExtractString(be.additional,'vin'))) AS vin,
         be.success AS success,
         toDate(fromUnixTimestamp(be.ts),'UTC') AS claim_date
  FROM zod.public_billing_events AS be
  INNER JOIN (SELECT id, org_id FROM zod.public_api_keys FINAL WHERE _peerdb_is_deleted=0) AS ak
    ON ak.id = be.api_key_id
  WHERE be._peerdb_is_deleted = 0 AND be.event_type = 'case-claim'
    AND be.ts BETWEEN toUnixTimestamp(toDateTime('2026-07-01 00:00:00','UTC'))
                  AND toUnixTimestamp(toDateTime('2026-07-31 23:59:59','UTC'))
),
kept AS (
  SELECT c.* FROM claims AS c
  LEFT JOIN orgs AS fo ON fo.id = c.forwarder_org_id
  LEFT JOIN orgs AS ao ON ao.id = c.agent_org_id
  WHERE position(coalesce(fo.n,''),'insightlpr') = 0
    AND position(coalesce(ao.n,''),'insightlpr') = 0
    AND (__ORG__ = 0 OR c.forwarder_org_id = __ORG__ OR c.agent_org_id = __ORG__)
),
deduped AS (
  SELECT forwarder_org_id, agent_org_id, vin, min(claim_date) AS claim_date
  FROM kept WHERE success AND vin != ''
  GROUP BY forwarder_org_id, agent_org_id, vin
)
"""

# A: backyard-faithful -- zod.public_hotlist (status=3) JOIN zod.public_hotlist_status_log (new_status=3)
A = BASE + """
, pairs AS (SELECT DISTINCT vin, agent_org_id FROM deduped WHERE agent_org_id != 0)
, rec AS (
  SELECT d.vin AS vin, d.agent_org_id AS org_id, max(hsl.created_at) AS repo_ts
  FROM pairs AS d
  INNER JOIN (SELECT id, org_id, vin FROM zod.public_hotlist FINAL
              WHERE _peerdb_is_deleted=0 AND status=3) AS h
    ON h.org_id = d.agent_org_id AND h.vin = d.vin
  INNER JOIN (SELECT hotlist_id, created_at FROM zod.public_hotlist_status_log
              WHERE _peerdb_is_deleted=0 AND new_status=3) AS hsl
    ON hsl.hotlist_id = h.id
  GROUP BY d.vin, d.agent_org_id
)
SELECT count() AS deduped_rows,
       countIf(toDate(rec.repo_ts,'UTC') >= d.claim_date - 7) AS db_repos
FROM deduped AS d LEFT JOIN rec ON rec.vin = d.vin AND rec.org_id = d.agent_org_id
"""

# B: denormalized -- clarity.hotlist FINAL, repo_date column, NO status_log join
B = BASE + """
, rec AS (
  SELECT assumeNotNull(h.vin) AS vin, h.org_id AS org_id, max(h.repo_date) AS repo_date
  FROM clarity.hotlist AS h FINAL
  WHERE h.status = 3 AND h.repo_date IS NOT NULL AND h.vin IS NOT NULL AND h.vin != ''
  GROUP BY h.vin, h.org_id
)
SELECT count() AS deduped_rows,
       countIf(rec.repo_date >= d.claim_date - 7) AS db_repos
FROM deduped AS d LEFT JOIN rec ON rec.vin = d.vin AND rec.org_id = d.agent_org_id
"""

for org in (0, 165):
    tag = "platform-wide" if org == 0 else f"org {org}"
    print(f"===== {tag} =====")
    for label, sql in (("A zod+status_log (faithful)", A), ("B clarity.hotlist repo_date", B)):
        r = q(sql.replace("__ORG__", str(org)), env)
        row = r["rows"][0] if r["rows"] else {}
        print(fmt(label, r))
        print(f"{'':34s} deduped={int(row.get('deduped_rows',0)):>7,}  db_repos={int(row.get('db_repos',0)):>7,}")
    print()
