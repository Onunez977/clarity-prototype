import sys, time, json
sys.path.insert(0, '/Users/daltonmccleery/Herd/chlocal/tools')
from compare import load_env
import clickhouse_connect
env = load_env()
cli = clickhouse_connect.get_client(
    host=env.get('CLICKHOUSE_HOST'), port=int(env.get('CLICKHOUSE_PORT', 8443)),
    username=env.get('CLICKHOUSE_USERNAME'), password=env.get('CLICKHOUSE_PASSWORD'),
    secure=str(env.get('CLICKHOUSE_HTTPS','true')).lower()=='true',
    connect_timeout=10, send_receive_timeout=300)
SP='/private/tmp/claude-501/-Users-daltonmccleery-Herd-platform--claude-worktrees-release-skill-vpn-monitoring-8c2bc8/4d24612c-2f16-45bf-8c2b-6932b851ad95/scratchpad'
targets = {
 'shipped-window.sql':  ("platform-wide Jul 2026", (15128, 14288, 11975)),
 'shipped-org165.sql':  ("largest forwarder (org 165) Jul 2026", (9340, 9262, 7230)),
}
allok = True
for f,(label,exp) in targets.items():
    sql = open(f'{SP}/{f}').read()
    t0=time.time(); r = cli.query(sql); dt=time.time()-t0
    got = tuple(int(x) for x in r.result_rows[0])
    s=dict(r.summary or {})
    ok = got == exp
    allok = allok and ok
    print(f"{label}")
    print(f"  expected all/successful/deduped = {exp}")
    print(f"  SHIPPED CLASS returned          = {got}   {'EXACT MATCH' if ok else '*** MISMATCH ***'}")
    print(f"  server: read_rows={int(s.get('read_rows',0)):,} read_bytes={int(s.get('read_bytes',0))/1e9:.2f}GB mem={int(s.get('memory_usage',0))/1048576:.0f}MB wall={dt:.2f}s")
print()
print("VERDICT:", "the shipped class reproduces every target figure exactly" if allok else "MISMATCH - investigate")
