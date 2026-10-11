# DEV-2600 Agent QA report (Pippin)

**VERDICT: PASS**

| Field | Value |
|---|---|
| Ticket | DEV-2600 — Option B five-band Orders density legend filter |
| PR | https://github.com/insight-lpr/platform/pull/476 (DRAFT — **not** undrafted) |
| Holding base | `scout` |
| Product tip | `4d618fa8153f7b9b5c0c190f6b8407a5330d0f95` |
| Branch tip | `f10883019f71ff4fb701e73fd7407ad8c5a39818` |
| Ancestor | `PRODUCT_TIP_ANCESTOR_OK` |
| Review rn=1 | PASS · act-on NONE |
| Site | http://127.0.0.1:18088 |
| PG | 127.0.0.1:15440 / zod_dev_2600 |
| CH | 127.0.0.1:18131 / clarity_dev_2600 · **CLICKHOUSE_DRIVER=clickhouse** (not pgsql) |
| cloud_touched | **false** |
| seed_used | **true** (fleet Docker CH only) |
| Fleet | LEFT UP (:18088/:15440/:18131); peers 18082–18087 / 18124–18130 refused |
| Undraft / Board | **not done** |
| git-add `.ai/` `.context/` `live-qa-evidence/` | **not done** |

## Suites

```
php artisan test --compact --filter=HotlistAddressGeohashQueryTest
→ 4 passed (23 assertions) · Duration ~0.3–0.5s
CLICKHOUSE_DRIVER=clickhouse @ 172.21.0.1:18131 (app container)
```

Log: `/workspace/docs/investigations/DEV-2600/agent-qa-pins.log`  
JUnit: `/workspace/docs/investigations/DEV-2600/HotlistAddressGeohashQueryTest.xml`

## Product asserts

- `HotlistAddressGeohashQuery::getCombinedTier*` five-band (`tier_1` … `tier_25_plus`); `getCombinedTierColor(10) → #7B1FA2`
- `map.js`: `ORDER_TIERS`, `visibleOrderTiers`, `toggleOrderTier`
- `ScannerMap.vue`: paint-time filter on `visibleOrderTiers`; sibling `watch` calls **`updateOverlayLayers()` only** (no `syncHeatmaps` / `fetchOrdersHeatmap`)
- `DashboardOverlay.vue`: five clickable legend rows with `aria-pressed` + opacity/line-through

## Live matrix (Org Map `/scout/org-map`, Direct Orders on)

| AC | Status | Notes |
|---|---|---|
| 1 Five bands | PASS | buttons 1 / 2–4 / 5–9 / 10–24 / 25+ |
| 2 Hide 5–9 | PASS | others stay; strike-through |
| 2b Multi-band | PASS | independent on/off |
| 3 Pan | PASS | hidden bands stay gone after bounds refetch |
| 4 All-off | PASS | `visibleCount=0` → Orders layer drops |
| 5 Date/Map Layers | PASS | Today/7 Days/MAP LAYERS/DRIVERS unaffected |
| 6 Org map | PASS | primary live surface |
| 7 `#7B1FA2` for 10–24 | PASS | live features counts 11/15/12 all `#7B1FA2` |
| 8 Hit Density | OOS | DEV-2601 — not required / not failed |

Login: non-prod OTP bypass `BYPASSED_NON_PROD` as `test@tester.com`. Fleet-only fixes during QA (restored / not committed): temporary `URL::forceScheme` gate (FORCE_HTTPS already false); `GRANT` on `platform.activity_log_id_seq` for clarity.

## Seed

Empty `hotlist_address_geohash` at start; raw path query needs `hotlist` + `public_address` + `public_hotlist_address`. Seeded **fleet Docker CH only** with org_id=2 Austin clusters covering all five tiers. **cloud_touched=false**.

## Evidence

`/workspace/docs/investigations/DEV-2600/live-qa-evidence/` (screenshots + AC JSON)  
Mirror: `/workspace/dev-2600-wt/.context/live-qa-evidence/`  
Also: `qa-ac-results.json`, `qa-plan-agent.md` (this folder)

## Blockers

None for PASS. Note: agent-browser `@ref` click on legend was flaky; DOM `.click()` on the same Vue buttons exercised handlers successfully (aria-pressed + store updates). No short webm recorded (interaction captured via screenshots + JSON).

## Next

**Merry Verify** — parent posts #27 GitHub comment. Do not undraft / do not Board from this agent.
