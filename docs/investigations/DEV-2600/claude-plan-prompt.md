# Claude Code Opus co-pilot — DEV-2600 implement plan (read-only)

## Role
You are a **read-only co-pilot planner** for ticket DEV-2600 (Scout density legend interactive filter).

- Produce a concise markdown implement plan only.
- Do **not** mutate the environment, edit product files, commit, push, or run write tools.
- Do **not** invent product locks. Use only the locks and cites below.
- Do **not** expand scope beyond what is listed.

## Ticket
- **Key:** DEV-2600
- **Summary:** Scout & Admin Map — make the scan density legend an interactive filter (Kit User Path parity)
- **PR:** https://github.com/insight-lpr/platform/pull/476
- **Branch:** `cursor/dev-2600-density-legend-filter-f382` · **base:** `scout`
- **Worktree:** `/workspace/dev-2600-wt`
- **Investigation:** `/workspace/docs/investigations/DEV-2600/` (mirror under worktree)

## Locked product decision (Q1)
**Option B — LOCKED by Dalton/Gandalf.**

Split the combined Orders scale so **10–24** and **25+** are separate legend rows and separate feature tiers, matching Kit User Path address bands.

- Still **one unioned** Orders legend: Direct Orders + Staging Orders stay summed on the same layer.
- Do **NOT** un-union Direct and Staging into two color ramps / two legends.
- Changes `getCombinedTier`, `getCombinedTierColor`, `getCombinedTierLabel`, Orders legend markup, and `HotlistAddressGeohashQueryTest`.
- Still does **not** require a ClickHouse HAVING rewrite: `orderRows` already requests all five source tiers before PHP rebuckets.

## Live acceptance criteria (Jira DEV-2600)
- Density legend entries on the **org map** and **direct-orders map** are clickable.
- Clicking a band toggles that band's visibility on the map.
- Bands toggle **independently** (e.g. show 5–9 and 10–24 while 1 is hidden).
- Behaviour matches the existing **Kit User Path** implementation (independent toggles / Kit parity on bands).
- **Active versus hidden** bands are visually distinguishable.
- Filter works alongside existing **date range, driver, and zone** filters.

## Surface cites (from prep-context)
Use these as the touch surface; re-read before planning:

| Area | Cite |
|------|------|
| Orders legend (static today) | `DashboardOverlay.vue` Orders rows (~331–354); labels 1 / 2–4 / 5–9 / 10+; no click / aria-pressed |
| Layer visibility | `DashboardOverlay.vue` `ordersActive` when `hotlist` or `staging` on (~478–480); layer toggles (~535–553) |
| Paint path | `ScannerMap.vue` heatmap-orders GeoJsonLayer (~1215–1236) consumes `mapStore.ordersHeatmap` with no tier filter |
| Fetch | `stores/map.js` `fetchOrdersHeatmap` (~802–838) — bounds + layers only; **does not send tiers** |
| Source tiers | `ScoutMapHelpers::orderRows` (~121–126) already fetches `tier_1` … `tier_25_plus` |
| Combined stamp | `ScoutMapHelpers::unionToGeoJson` (~274–293) stamps `getCombinedTier*` (four-band today) |
| Combined scale | `HotlistAddressGeohashQuery` `getCombinedTier*` (~223–256) caps at `tier_10_plus` |
| Kit parity (bands) | `KitUserPath.php` CheckboxList five bands 1 / 2–4 / 5–9 / 10–24 / 25+ (~121–139, ~165–183) — **behaviour reference only; do not edit Kit** |

## Out of scope
- Live Hit Density legend / `HitDirectHotspotQuery`
- Any Kit User Path edits (`KitUserPath.php`, `kit-user-path.js`, Kit controller/request)
- Un-union of Direct vs Staging (two legends / two color ramps)
- ClickHouse HAVING / `tierConditions` rewrite
- Cloud ClickHouse writes
- Sending tiers to `/map/heatmap/orders` API

## Preflight constraints
- **#24:** Smallest change. No new abstractions, no new component framework, no new store abstraction, no new query class. Port Kit independent-toggle behaviour onto the existing Orders legend.
- **#25:** `does_not_fire` — visibility filter only; do not rewrite HotlistAddressGeohashQuery HAVING.
- **#26:** `holding_scout` — PR base is `scout`; holding stack scout → main.

## Required plan output
Write a **concise markdown implement plan** with:

1. **Files to touch** (expected):
   - `app/Clickhouse/Queries/HotlistAddressGeohashQuery.php` — split `getCombinedTier*`
   - `app/Http/Controllers/Api/Concerns/ScoutMapHelpers.php` — only if labels/colors need a call-site nudge (prefer no API shape change)
   - `tests/Unit/Clickhouse/Queries/HotlistAddressGeohashQueryTest.php`
   - `resources/js/clarity-scout/components/DashboardOverlay.vue` — five clickable Orders rows
   - `resources/js/clarity-scout/stores/map.js` — Pinia visible-tiers set
   - `resources/js/clarity-scout/components/ScannerMap.vue` — filter features by visible tiers
   - Plus any minimal Vue/Playwright test you recommend

2. **Concrete steps:**
   - Split `getCombinedTier` / `getCombinedTierColor` / `getCombinedTierLabel` so counts map to five bands (1, 2–4, 5–9, 10–24, 25+) instead of capping at `tier_10_plus`.
   - Orders legend: five rows matching Kit labels; each a `<button>` with `aria-pressed`.
   - Pinia: a set of visible tier ids (default **all bands on**).
   - `ScannerMap`: filter `ordersHeatmap` features by `properties.tier` ∈ visible set at paint time (so pan/zone refetch cannot resurrect a hidden band).
   - Inactive visual: `aria-pressed=false` + muted swatch/label; active keeps current swatch.
   - **Do not** send tiers to the heatmap API.
   - Keep date / driver / zone controls untouched.
   - Keep Direct+Staging union intact.
   - Note DEV-2601 may also touch `DashboardOverlay.vue` — keep the legend hunk small and separate.

3. **Test plan** (brief): PHPUnit combined-tier label/color assertions; one UI check that hiding one band drops only that tier and leaves date pill + layer toggles alone.

Keep the plan short, actionable, and Option-B-only. No alternate A path.
