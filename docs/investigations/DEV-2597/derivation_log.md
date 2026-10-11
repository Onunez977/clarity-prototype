# Derivation log — DEV-2597

## Perf measurement (t2)
- Source: ClickHouse Cloud `clarity` read-only
- Org picked by max hits in last 30d on hits_hot (aggregate only): kit_org_id=1271, ~16272 hits, 77 kits
- Query shape: FINAL + kit_org + kit_id IN + hit_type L/S + disposition actionable + open hotlist semi-join + recentHits LIMIT 1 BY ocr / hits LIMIT 101
- Raw rows never exported; counts + timings only
- Artifact: perf-measurement.json
