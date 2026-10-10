# DEV-2600 Agent QA plan matrix (Pippin)

| AC | Expected | Result | Evidence |
|---|---|---|---|
| Suites HotlistAddressGeohashQueryTest | green, CLICKHOUSE_DRIVER=clickhouse @ :18131 | **PASS** 4/4 (23) | `agent-qa-pins.log`, junit xml |
| Product five-band + visibleOrderTiers | present; watch → updateOverlayLayers only | **PASS** | map.js / ScannerMap.vue / HotlistAddressGeohashQuery.php |
| AC1 Five clickable legend bands | 1 / 2–4 / 5–9 / 10–24 / 25+ | **PASS** | `07-legend-five-bands.png`, `ac1-baseline.json` |
| AC2 Hide 5–9; others stay; visual hidden | strike + aria-pressed=false | **PASS** | `08-hide-5-9.png`, `ac2-hide-5-9.json` |
| AC2b Multi-band independent | hide 1+25+ too | **PASS** | `09-multi-band-hide.png` |
| AC3 Pan keeps filter | hidden stays gone after refetch | **PASS** | `ac3-pan.json` |
| AC4 All-off drops Orders layer | visible=[] painted=0 | **PASS** | `11-all-off.png`, `ac4-layer-drop.json` |
| AC5 Date/driver/zone/Map Layers unaffected | controls remain; legend state persists | **PASS** | `12-date-7days-unaffected.png` |
| AC6 Org map same buttons | `/scout/org-map` | **PASS** | `ac6-org-map-url.txt` |
| AC7 10–24 paints `#7B1FA2` | live cells + suites | **PASS** | `ac7-colors.json` (counts 11/15/12) |
| Live Hit Density | OOS DEV-2601 | **OOS** | not toggled as acceptance |
| cloud_touched | false | **PASS** | Docker CH seed only |
| Fleet left up | :18088/:15440/:18131 | **PASS** | ledger LIVE |

**Verdict: PASS** → next Merry Verify (parent posts #27).
