# Insight AI — Build Record

> Mirror of Jira comment **42210** on DEV-2600 (Verify/Build Record after Agent QA **PASS** on product tip `4d618fa8…`). Posted 2026-09-28 14:23:58 CDT (Jira created 15:23:58 -0400).

**Sprint 42 · Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/476 (**DRAFT** — Verify does not undraft)

## TL;DR

Option B five-band Orders density legend filter on Scout Org Map (Kit User Path parity on the **same** unioned Direct+Staging Orders layer). Combined `10+` split into `10–24` / `25+`. Legend rows are independent paint-time filters (`visibleOrderTiers` → `updateOverlayLayers()` only). Review rn=1 **94 / 93 / 95**. Agent QA **PASS** on real Docker CH `:18131`. Soft #27 evidence already on PR. Holding base **`scout`**. Live Hit Density **OOS** (DEV-2601).

## WHAT changed

* `HotlistAddressGeohashQuery.php`: five-band `getCombinedTier` / `getCombinedTierColor` / `getCombinedTierLabel` — `tier_1` | `tier_2_4` | `tier_5_9` | `tier_10_24` | `tier_25_plus`; color for 10–24 = `#7B1FA2`; 25+ stays `#4A148C`.
* `map.js`: `ORDER_TIERS`, `visibleOrderTiers` reactive Set (all on), `toggleOrderTier`.
* `DashboardOverlay.vue`: five clickable legend rows with `aria-pressed` + opacity/line-through when hidden.
* `ScannerMap.vue`: paint-time filter on `properties.tier`; sibling `watch` on `[...visibleOrderTiers]` calls **`updateOverlayLayers()` only** (never `syncHeatmaps` / `fetchOrdersHeatmap`); all-off drops the Orders layer.
* `HelpPanel.vue`: fifth Orders swatch stop `#7B1FA2`; copy `1 → 25+`.
* `HotlistAddressGeohashQueryTest.php`: five-band tier/color/label asserts (incl. `getCombinedTierColor(10) → #7B1FA2`).
* `public/build/**` committed with source.

Product tip `4d618fa8153f7b9b5c0c190f6b8407a5330d0f95`. Branch tip `f10883019f71ff4fb701e73fd7407ad8c5a39818` · `PRODUCT_TIP_ANCESTOR_OK`.

## WHY

Premier Adjusters Scout demo (2026-09-16): density legend on org/direct-orders map was static while Kit User Path bands already filter. Q1 locked **Option B** (comment 42205): split combined `10+` into Kit `10–24` / `25+` on the same unioned layer — do **not** un-union Direct and Staging. Paint-time filter only (features already carry `properties.tier`); no `tiers` query param; no path rewrite.

## HOW numbers were derived

* **Agent QA PASS** (2026-09-28 **14:20 CDT**):
  * `php artisan test --compact --filter=HotlistAddressGeohashQueryTest` → **4 passed / 23 assertions / 0 fail**
  * **`CLICKHOUSE_DRIVER=clickhouse`** @ **`127.0.0.1:18131`** / from_app `172.21.0.1:18131` · db `clarity_dev_2600` · **not pgsql**
  * Live Org Map `:18088/scout/org-map` · PG `:15440/zod_dev_2600` · seed_used=true (fleet Docker CH only, org 2 Austin five tiers) · `cloud_touched=false`
  * Live matrix ALL PASS for in-scope ACs; Live Hit Density **OOS** = DEV-2601
  * Pins: `docs/investigations/DEV-2600/agent-qa-pins.log` · report `qa-agent-report.md` · `qa-ac-results.json`
* **Review rn=1:** scores **[94, 93, 95]** · min 93 ≥ 85 · `reviewed_sha=4d618fa8153f7b9b5c0c190f6b8407a5330d0f95` · `rerun_required=false` · act_on **NONE** · manifest `.ai/review-manifest.json`
* Thin conventions **#16:** conventions lane **93** ≥ 85 — refuse redesign. Host `pint` unavailable on box; Sam conventions lane stands.
* Preflight **#18 PASS (required):** Agent QA real fleet Docker CH `CLICKHOUSE_DRIVER=clickhouse` @ **`:18131`**. Suites + pins cite. **pgsql does not clear.**
* Preflight **#25 N/A (`does_not_fire`):** Plan — legend/filter is paint-time only; no old-vs-new path rewrite; no Cloud parity required.
* Soft **#27 PASS:** https://github.com/insight-lpr/platform/pull/476#issuecomment-5876854396 (legend hide/show/all-off/all-on). Suites alone ≠ #27 clear.
* Preflight **#26:** holding base **`scout`**. Final scout→main is a separate merge (Jeff).
* Tip spot-check (Verify): five-band `getCombinedTier*` + `#7B1FA2` for ≥10; watch body `updateOverlayLayers()` only; HelpPanel fifth `#7B1FA2` swatch + `1 → 25+`; Live Hit Density block untouched in HelpPanel copy.

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 Legend entries clickable (org + direct-orders) | PASS | Live AC1 five buttons 1 / 2–4 / 5–9 / 10–24 / 25+ `aria-pressed`; DashboardOverlay `@click=toggleOrderTier` |
| AC-2 Click toggles band visibility | PASS | Live AC2 hide 5–9 others stay; strike-through |
| AC-3 Independent multi-band toggles | PASS | Live AC2b hid 1 + 5–9 + 25+ independently |
| AC-4 Matches Kit User Path behaviour (Option B five-band) | PASS | Q1 Option B; five Kit bands on unioned Direct+Staging; tip `ORDER_TIERS` |
| AC-5 Active vs hidden visually distinguishable | PASS | `aria-pressed` + opacity/line-through (Agent QA) |
| AC-6 Works alongside date / driver / zone / Map Layers | PASS | Live AC5 Today/7 Days/MAP LAYERS/DRIVERS unaffected |
| AC-7 Pan — hidden bands stay gone | PASS | Live AC3 after bounds refetch `stillHidden=true` |
| AC-8 All-off drops Orders layer | PASS | Live AC4 `visibleCount=0` / `allOffDropsLayer=true` |
| AC-9 Count 10–24 paints `#7B1FA2` | PASS | Live AC7 features 11/15/12 all `#7B1FA2`; suite `getCombinedTierColor(10)` |
| AC-10 Org map same five buttons | PASS | Live AC6 on `/scout/org-map` |
| Live Hit Density | OOS | DEV-2601 — not required; not failed |
| Suites on real Docker CH | PASS | 4/23 @ `:18131` clickhouse driver |
| Preflight #18 | PASS | Docker CH `:18131` · driver=clickhouse · cloud_touched=false · not pgsql |
| Preflight #25 | N/A | does_not_fire — paint-time only |
| Thin conventions #16 | PASS | conventions 93 ≥ 85 |
| Soft #27 | SOFT_PASS | https://github.com/insight-lpr/platform/pull/476#issuecomment-5876854396 |
| Holding #26 | NOTED | base/target `scout`; scout→main separate |

## Soft flags (non-blocking)

* Live Hit Density **OOS** (DEV-2601) — do not fail
* Immediate deploy recolor: cells count 10–24 go `#4A148C` → `#7B1FA2` even with all bands on (documented in PR body)
* okf wiki queued (not written this tip)
* Docs tips `[skip ci]` — CI trigger is next lane
* Soft twin / peer fleet ports untouched; fleet LEFT UP
* Holding `scout` — do not undraft as if main→main
* **HITL before QA Ready** (UI surface) — PM owns after Undraft/CI; Verify does not undraft

## What this ticket did NOT do

* Touch Live Hit Density / `recentHits` / `HitDirectHotspotQuery` (DEV-2601)
* Un-union Direct and Staging; add `tiers` query param; Kit HAVING rewrite
* Cloud ClickHouse writes / invent product PHP beyond tip
* Undraft / QA Ready / merge / Status Board / Fleet Board from Verify
* Tear down fleet (left UP for next lane)
* Final scout→main merge

## Review / conventions

Review rn=1 · **94 / 93 / 95** · min 93 ≥ 85 · `reviewed_sha=4d618fa8153f7b9b5c0c190f6b8407a5330d0f95` · `rerun_required=false` · act_on **NONE**. Thin conventions #16 PASS (conventions **93**). Real Sam lanes — **not** mechanical `[85]`.

## Tips

| Role | SHA |
| --- | --- |
| **Product tip / reviewed_sha** | `4d618fa8153f7b9b5c0c190f6b8407a5330d0f95` |
| **Branch tip (PR head)** | `f10883019f71ff4fb701e73fd7407ad8c5a39818` |

`PRODUCT_TIP_ANCESTOR_OK`. Holding base `scout` @ `f52b5d85afadb3adc7e01d4153d603de9050e08b`.

## PR

https://github.com/insight-lpr/platform/pull/476 — **DRAFT** · base `scout` · product tip `4d618fa8…` · branch tip `f1088301…`

## Next

**Undraft / CI**, then **HITL with Dalton before QA Ready** (UI). Verify does **not** undraft, flip QA Ready, merge, or post Status/Fleet Board. Holding base `scout` — final scout→main is a separate merge. Fleet env left **UP** (`:18088` / `:15440` / `:18131`); teardown on closeout / QA Ready exit is next lane.

## Verify exit note (gates)

`phase=done`. `BUILD_RECORD_GATE` + `REWORK_GATE` expected PASS after comment id recorded. Soft #27 URL present. #18 PASS · #25 N/A · #16 PASS · holding scout noted.
