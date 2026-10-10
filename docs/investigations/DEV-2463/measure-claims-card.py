import sys, time, json, statistics, datetime
sys.path.insert(0, '/Users/daltonmccleery/Herd/chlocal/tools')
from compare import load_env
import clickhouse_connect
env = load_env()
cli = clickhouse_connect.get_client(
    host=env.get('CLICKHOUSE_HOST'), port=int(env.get('CLICKHOUSE_PORT', 8443)),
    username=env.get('CLICKHOUSE_USERNAME'), password=env.get('CLICKHOUSE_PASSWORD'),
    secure=str(env.get('CLICKHOUSE_HTTPS','true')).lower()=='true',
    connect_timeout=10, send_receive_timeout=180)
JF, JT, M3F = 1782864000, 1785542399, 1777593600

def run(sql, params=None, tag=''):
    t0=time.time(); r=cli.query(sql, parameters=params or {}); dt=time.time()-t0
    s=dict(r.summary or {})
    return {'tag':tag,'wall_s':round(dt,3),'rows':[list(x) for x in r.result_rows],
            'read_rows':int(s.get('read_rows',0)),'read_bytes':int(s.get('read_bytes',0)),
            'mem_mb':round(int(s.get('memory_usage',0))/1048576,1),
            'srv_s':round(int(s.get('elapsed_ns',0))/1e9,3)}

# SINGLE-PASS: all three stages from one scan, insightlpr rejected, optional org filter
SINGLE = """
WITH orgs AS (SELECT id, lower(replaceRegexpAll(coalesce(name,''),'[^a-zA-Z0-9]','')) AS n
              FROM zod.public_orgs FINAL WHERE _peerdb_is_deleted=0),
claims AS (
    SELECT ak.org_id AS forwarder_org_id,
           JSONExtractInt(be.additional,'claimed_by_org_id') AS agent_org_id,
           upper(trimBoth(JSONExtractString(be.additional,'vin'))) AS vin,
           be.success AS success
    FROM zod.public_billing_events AS be
    INNER JOIN (SELECT id, org_id FROM zod.public_api_keys FINAL WHERE _peerdb_is_deleted=0) AS ak
      ON ak.id = be.api_key_id
    WHERE be._peerdb_is_deleted=0 AND be.event_type IN ('case-claim')
      AND be.ts BETWEEN {f:Int64} AND {t:Int64}
),
kept AS (
    SELECT c.forwarder_org_id, c.agent_org_id, c.vin, c.success
    FROM claims AS c
    LEFT JOIN orgs AS fo ON fo.id = c.forwarder_org_id
    LEFT JOIN orgs AS ao ON ao.id = c.agent_org_id
    WHERE position(coalesce(fo.n,''),'insightlpr') = 0 AND position(coalesce(ao.n,''),'insightlpr') = 0
)
SELECT count() AS all_claims,
       countIf(success) AS successful,
       uniqExactIf((forwarder_org_id, agent_org_id, vin), success AND vin != '') AS deduped
FROM kept
"""

# SINGLE-PASS + 3-month bucket (sparkline shape) + per-org
SPARK = """
WITH orgs AS (SELECT id, lower(replaceRegexpAll(coalesce(name,''),'[^a-zA-Z0-9]','')) AS n
              FROM zod.public_orgs FINAL WHERE _peerdb_is_deleted=0),
claims AS (
    SELECT ak.org_id AS forwarder_org_id,
           JSONExtractInt(be.additional,'claimed_by_org_id') AS agent_org_id,
           upper(trimBoth(JSONExtractString(be.additional,'vin'))) AS vin,
           be.success AS success,
           toStartOfMonth(toDate(fromUnixTimestamp(be.ts),'UTC')) AS bucket
    FROM zod.public_billing_events AS be
    INNER JOIN (SELECT id, org_id FROM zod.public_api_keys FINAL WHERE _peerdb_is_deleted=0) AS ak
      ON ak.id = be.api_key_id
    WHERE be._peerdb_is_deleted=0 AND be.event_type IN ('case-claim')
      AND be.ts BETWEEN {f:Int64} AND {t:Int64}
),
kept AS (
    SELECT c.* FROM claims AS c
    LEFT JOIN orgs AS fo ON fo.id = c.forwarder_org_id
    LEFT JOIN orgs AS ao ON ao.id = c.agent_org_id
    WHERE position(coalesce(fo.n,''),'insightlpr') = 0 AND position(coalesce(ao.n,''),'insightlpr') = 0
      AND ({org:Int32} = 0 OR c.forwarder_org_id = {org:Int32} OR c.agent_org_id = {org:Int32})
)
SELECT bucket,
       count() AS all_claims,
       countIf(success) AS successful,
       uniqExactIf((forwarder_org_id, agent_org_id, vin), success AND vin != '') AS deduped
FROM kept GROUP BY bucket ORDER BY bucket
"""

res=[]
print("=== C: SINGLE-PASS totals, Jul 2026 (expect 15128/14288/11975) ===")
c=run(SINGLE, {'f':JF,'t':JT}, 'C-single-pass'); print(json.dumps(c,indent=1,default=str)); res.append(c)

print("\n=== D: per-org 165 (largest forwarder), 1 month (expect deduped 7230) ===")
d=run(SPARK, {'f':JF,'t':JT,'org':165}, 'D-org-1mo'); print(json.dumps(d,indent=1,default=str)); res.append(d)

print("\n=== E: per-org 165, 3 months bucketed (sparkline shape) ===")
e=run(SPARK, {'f':M3F,'t':JT,'org':165}, 'E-org-3mo'); print(json.dumps(e,indent=1,default=str)); res.append(e)

print("\n=== F: platform-wide 3 months bucketed ===")
f=run(SPARK, {'f':M3F,'t':JT,'org':0}, 'F-all-3mo'); print(json.dumps(f,indent=1,default=str)); res.append(f)
