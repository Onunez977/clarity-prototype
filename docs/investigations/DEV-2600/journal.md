# Journal — DEV-2600

- 2026-09-28T17:09:00Z intake: route=feature app=clarity repo=insight-lpr/platform. Parent DEV-2224. Sprint 42. Assignee Dalton McCleery. Status To Do.
- 2026-09-28T17:09:00Z intake: live First Glance comment 42171 (2026-09-28 13:08 -0400, ~12:07 CT) supersedes 42086 (main→main) and 42095 (holding name TBD). Branch path is holding stack `scout` → main. Jeff merges final scout→main only. Hard stop: none. Stack mates on the same holding branch: DEV-2601, DEV-2602, DEV-2603, DEV-2566.
- 2026-09-28T17:10:00Z intake: fetched origin/scout. Tip is f52b5d85afadb3adc7e01d4153d603de9050e08b (matches the materialized holding commit). Feature branch `cursor/dev-2600-density-legend-filter-f382` cut from that tip. No alternate holding name.
- 2026-09-28T17:20:00Z prep: Kit User Path address density is already an independent filter. `KitUserPath` CheckboxLists `heatmapTiers` and `stagingHeatmapTiers` (five bands, including 10–24 and 25+) dispatch `load-geohash` / `load-staging-geohash` with the selected tiers. Alpine `loadGeohash` posts those tiers. `KitUserPathController::geojson` passes them into `HotlistAddressGeohashQuery`, whose HAVING keeps only the checked bands. Checked vs unchecked is the Filament checkbox visual. A third list, `hitDensityTiers`, filters hit density on a different scale (1, 2–5, 6–15, 16–50, 51+) and does not match the ticket's 5–9 / 10–24 example.
- 2026-09-28T17:22:00Z prep: Scout org map (`/scout/org-map`, `adminMode`) and the driver map share `DashboardOverlay.vue`. There is no separate direct-orders page. Direct Orders is the `hotlist` layer. The Orders legend (1 / 2–4 / 5–9 / 10+ addresses) is static markup: `div` rows, no click handler, no `aria-pressed`. It shows when either Direct or Staging is on. Live Hit Density (1 / 2–4 / 5+ hits) is a second static block on the same card and does not use 5–9 or 10–24. The ticket's "bottom of the map" does not match the code: the card sits top-right under Map Layers (inside the layers sheet on compact). One legend component exists.
- 2026-09-28T17:23:00Z prep: both heatmap endpoints hardcode all five source tiers in `ScoutMapHelpers::orderRows`, sum Direct+Staging per geohash, then label with `getCombinedTier` (`tier_10_plus` = "10+ addresses"). `fetchOrdersHeatmap` sends bounds and `layers` only. `ScannerMap` paints every feature in `ordersHeatmap`. Each feature already has `properties.tier`. Date range is not a parameter of the orders request. Org-map driver selector is not applied to `/map/heatmap/*`. Driver-map zone clip happens inside `ClarityScoutMapController::heatmapOrders` before the union.
- 2026-09-28T17:25:00Z prepped: gate=BLOCKED. The interaction port is clear (independent toggles, visual on/off, reapplied when the orders collection is replaced, no new abstraction). The band set is not. Ticket AC names a 10–24 band that the Scout legend does not have. Choosing "toggle the existing 10+ row" vs "split 10+ into 10–24 and 25+" changes `getCombinedTier*` and the legend copy. Preflight 25 does_not_fire either way: the query already returns every source tier; do not rewrite HAVING. Preflight 26 holding_scout. Cloud ClickHouse not queried.
- 2026-09-28T17:25:00Z gated: open question `band-set` only. Live Hit Density left out of scope because its labels are hit counts, not 5–9 / 10–24. Un-union of Direct vs Staging (Kit User Path's two color ramps) is not implied; do not plan it unless the answer asks.

## 2026-09-28T17:54Z — Q1 locked; Ultra-Plan #23 start

- Dalton (via Gandalf) locked **Q1 Option B**: split Orders combined **10+** into Kit **10–24** / **25+** on the same unioned Direct+Staging layer.
- Re-GET First Glance **42171** OK — holding `scout` → main.
- Gate → **CLEAN**. Claude-first Ultra-Plan (Opus draft → CloudAgent review → Opus reconcile → plan.json).
- 2026-09-28T17:54:12Z ultra-plan #23 start: Q1 LOCKED Option B (split combined 10+ → Kit User Path 10–24 / 25+ on the SAME unioned Direct+Staging Orders layer; do NOT un-union). Gate → CLEAN. Phase → planning. record.plan.started_at=2026-09-28T17:54:12Z. First Glance comment 42171 re-GET OK (still current: holding stack scout→main; Jeff merges final scout→main only; stack mates DEV-2601/2602/2603/2566). Preflight #24 smallest change; #25 does_not_fire; #26 holding_scout. Claude Code Opus co-pilot prompt written to claude-plan-prompt.md.

## 2026-09-28 13:32 CDT — CloudAgent review → Opus reconcile
- CloudAgent `bc-d7db0f46` finished: **REQUEST CHANGES** (must-fix: watch `[...visibleOrderTiers]` → `updateOverlayLayers()` only).
- Saved `cloud-plan-review.md` on box + Mac worktree.
- Started Opus reconcile (`claude-opus-5` / effort high) → `claude-plan-reconcile.md`.
- Next: finalize `plan.json`/`plan.md` + PR living ticket-status Phase Plan · Next Provision.

## 2026-09-28T18:55Z — Ultra-Plan CLEAN (#23 complete)

- Claude-first #23 complete: Opus draft → CloudAgent `bc-d7db0f46` REQUEST CHANGES → Opus reconcile DONE.
- Must-fix adopted: `ScannerMap` watch `[...visibleOrderTiers]` → `updateOverlayLayers()` only (never `syncHeatmaps` / `fetchOrdersHeatmap`).
- Authoritative `plan.md` + `plan.json` written (Option B five-band Orders legend + paint-time filter + watch).
- Phase → **planned**. Gate stays **CLEAN**. `record.plan.status=CLEAN` completed_at=2026-09-28T18:55:00Z.
- Next: **Provision** (Path A fleet-reachable Clarity site + keyed PG + Docker CH). No product implement yet.

## 2026-09-28T19:01Z — Provision CLEAN (Path A fleet)

- Prove PASS: site `http://127.0.0.1:18088/app/login` → 200; PG `127.0.0.1:15440/zod_dev_2600` (clarity); CH `http://127.0.0.1:18131/clarity_dev_2600` recovery_scans present, table_count=31; `CLICKHOUSE_DRIVER=clickhouse` (not pgsql).
- Checkout `/workspace/dev-2600-wt@f057bc29` (PR #476 tip). Holding base `scout`. Plan CLEAN Option B (split 10+ → 10–24/25+; `visibleOrderTiers` watch).
- Ledger LIVE on disk only: `docs/investigations/DEV-2600/qa-prep-ledger.json` (+ `.context/` copies). `mac_herd_refused=true`; `cloud_touched=false`; key-only DSNs.
- Phase → **provisioned**. Gate stays **CLEAN**. `record.provision.status=CLEAN` provision_mode=`path_a_fleet`.
- Next: **Implement** (Option B Orders density legend). No product code in this provision accept.

## 2026-09-28T19:12Z (2:12pm CDT) — Review rn=1 PASS

- Scores 94/93/95 (correctness/conventions/blast-radius); min=93 ≥ 85. Act-on Implement: **NONE**.
- `reviewed_sha` / product tip `4d618fa8153f7b9b5c0c190f6b8407a5330d0f95`; PR #476 tip `f10883019f71ff4fb701e73fd7407ad8c5a39818`; base `scout`.
- Gate: `REVIEW_GATE: pass min=93 threshold=85 lanes=3 rn=1 scores=94/93/95 conventions=93 sources=in-session-review:3 source=lane-min`.
- Artifacts on disk only under `.ai/` (wt + `/workspace/DEV-2600/.ai/`); not git-added.
- Soft: browser band click-through still Agent QA (:18088 / CH :18131); okf wiki queued; deploy 10–24 recolor documented.
- Phase → **reviewed**. Next: **Agent QA**.

## 2026-09-28T19:24Z — Verify/Build Record done

- Walked live Jira AC (description + Q1 Option B 42205) vs Agent QA PASS + tip spot-check.
- Build Record posted: Jira comment **42210** (marker `Insight AI — Build Record`).
- #18 PASS (Docker CH `:18131` clickhouse; cite Agent QA pins). #25 N/A does_not_fire. #16 conventions 93. Soft #27 cite https://github.com/insight-lpr/platform/pull/476#issuecomment-5876854396. #26 holding scout.
- Review scores **94/93/95** recorded. `phase=done`.
- Gates: `BUILD_RECORD_GATE: pass id=42210` · `REWORK_GATE: pass` (investigation artifacts only since product tip).
- PR #476 stays **DRAFT**. Fleet LEFT UP. Next: Undraft/CI → HITL before QA Ready. No Status/Fleet Board from Verify.
