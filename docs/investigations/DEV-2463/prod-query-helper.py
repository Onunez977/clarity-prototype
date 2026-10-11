"""Read-only production ClickHouse over HTTPS via stdlib. Returns rows + the
X-ClickHouse-Summary server-side envelope. Credential stays in-process."""
import sys, json, base64, urllib.request, time
sys.path.insert(0, '/Users/daltonmccleery/Herd/chlocal/tools')
from compare import load_env

_e = load_env()
_HOST = _e.get('CLICKHOUSE_HOST'); _PORT = int(_e.get('CLICKHOUSE_PORT', 8443))
_USER = _e.get('CLICKHOUSE_USERNAME'); _PW = _e.get('CLICKHOUSE_PASSWORD')
_SEC = str(_e.get('CLICKHOUSE_HTTPS', 'true')).lower() == 'true'
_URL = f"{'https' if _SEC else 'http'}://{_HOST}:{_PORT}/"

def q(sql, fmt='JSONCompact', timeout=300):
    body = (sql.rstrip().rstrip(';') + f" FORMAT {fmt}").encode()
    req = urllib.request.Request(_URL, data=body, method='POST')
    req.add_header('Authorization', 'Basic ' + base64.b64encode(f"{_USER}:{_PW}".encode()).decode())
    t0 = time.time()
    with urllib.request.urlopen(req, timeout=timeout) as r:
        raw = r.read(); hdr = r.headers.get('X-ClickHouse-Summary')
    wall = time.time() - t0
    summary = json.loads(hdr) if hdr else {}
    out = json.loads(raw) if fmt.startswith('JSON') else raw.decode()
    return {'data': out.get('data') if isinstance(out, dict) else out,
            'meta': out.get('meta') if isinstance(out, dict) else None,
            'summary': {k: int(v) for k, v in summary.items() if str(v).isdigit()},
            'wall': wall}
