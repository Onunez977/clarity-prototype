import sys, json
sys.path.insert(0, '/Users/daltonmccleery/Herd/chlocal/tools')
from compare import load_env
import clickhouse_connect
env = load_env()
cli = clickhouse_connect.get_client(
    host=env.get('CLICKHOUSE_HOST'), port=int(env.get('CLICKHOUSE_PORT', 8443)),
    username=env.get('CLICKHOUSE_USERNAME'), password=env.get('CLICKHOUSE_PASSWORD'),
    secure=str(env.get('CLICKHOUSE_HTTPS','true')).lower()=='true',
    connect_timeout=10, send_receive_timeout=300)

Q = """
WITH m AS (SELECT id, properties FROM zod.public_org_meta_latest),
p AS (SELECT id,
    JSONExtractRaw(properties,'platform_repo_pro') AS pro_raw,
    JSONExtractString(properties,'platform_repo_pro_end') AS pro_end,
    JSONExtractRaw(properties,'platform_repo_pro_company_metrics') AS cm_raw,
    JSONExtractString(properties,'platform_repo_pro_tier') AS tier_raw,
    JSONExtractString(properties,'platform_repo_pro_downgrade_effective_at') AS dg_at,
    JSONExtractString(properties,'platform_repo_pro_downgrade_target_tier') AS dg_to
  FROM m),
q AS (SELECT id,
    (pro_raw != '' AND pro_raw NOT IN ('false','null','0','""')) AS pfs,
    (pro_end = '' OR parseDateTimeBestEffortOrNull(pro_end) > now()) AS ne, cm_raw,
    multiIf(dg_at != '' AND dg_to != '' AND parseDateTimeBestEffortOrNull(dg_at) < now(), dg_to,
            tier_raw != '', tier_raw, 'clarity_pro') AS tier
  FROM p),
ent AS (SELECT id FROM q WHERE multiIf(
      cm_raw != '' AND cm_raw != 'null', (pfs AND ne) AND (cm_raw NOT IN ('false','0','""')),
      (pfs AND ne) AND tier IN ('clarity_pro','clarity_pro_plus'))),
orgs AS (SELECT id, lower(replaceRegexpAll(coalesce(name,''),'[^a-zA-Z0-9]','')) AS n
         FROM zod.public_orgs FINAL WHERE _peerdb_is_deleted=0),
claims AS (
    SELECT ak.org_id AS f, JSONExtractInt(be.additional,'claimed_by_org_id') AS a,
           upper(trimBoth(JSONExtractString(be.additional,'vin'))) AS vin, be.success AS s
    FROM zod.public_billing_events AS be
    INNER JOIN (SELECT id, org_id FROM zod.public_api_keys FINAL WHERE _peerdb_is_deleted=0) AS ak
      ON ak.id = be.api_key_id
    WHERE be._peerdb_is_deleted=0 AND be.event_type IN ('case-claim') AND be.ts BETWEEN {f:Int64} AND {t:Int64}
),
kept AS (SELECT c.* FROM claims c LEFT JOIN orgs fo ON fo.id=c.f LEFT JOIN orgs ao ON ao.id=c.a
         WHERE position(coalesce(fo.n,''),'insightlpr')=0 AND position(coalesce(ao.n,''),'insightlpr')=0
           AND c.s AND c.vin!=''),
active AS (SELECT f AS org FROM kept UNION DISTINCT SELECT a AS org FROM kept WHERE a != 0)
SELECT (SELECT count() FROM ent) AS entitled,
       (SELECT count() FROM ent WHERE id IN (SELECT org FROM active)) AS with_claims
"""

# The four real dashboard filters, anchored to the newest data actually present
mx = cli.query("SELECT max(ts) FROM zod.public_billing_events WHERE event_type='case-claim' AND _peerdb_is_deleted=0").result_rows[0][0]
print("newest case-claim event:", cli.query(f"SELECT toDateTime({mx},'UTC')").result_rows[0][0], "(epoch", mx, ")")
DAY=86400
windows = {
  "Today (last full day of data)":      (mx-DAY,   mx),
  "Last 7 days":                        (mx-7*DAY, mx),
  "This month (to newest data)":        (int(cli.query(f"SELECT toInt64(toUnixTimestamp(toDateTime(toStartOfMonth(toDate(fromUnixTimestamp({mx}),'UTC')),'UTC')))").result_rows[0][0]), mx),
  "Last month (Jul 2026, full)":        (1782864000, 1785542399),
}
print("\n| dashboard filter | entitled orgs | with a claim | read ZERO | % zero |")
print("| --- | --- | --- | --- | --- |")
for label,(f,t) in windows.items():
    r = cli.query(Q, parameters={'f':f,'t':t})
    e,w = [int(x) for x in r.result_rows[0]]
    print(f"| {label} | {e} | {w} | {e-w} | {100.0*(e-w)/e:.0f}% |")
