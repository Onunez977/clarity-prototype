# DEV-2600 — cloud plan review

Reviewed the Opus implement plan (Option B, locked) against live Jira AC, `docs/investigations/DEV-2600/` prep, and the cited surfaces. No product code was changed.

**Verdict: REQUEST CHANGES**

One must-fix. The rest of the plan holds: five-band split on the same unioned Orders layer, no HAVING rewrite, no new abstraction, HelpPanel left optional.

## Must-fix

**Step 4 does not subscribe the map to a legend click.** `updateOverlayLayers` (`ScannerMap.vue:1147`) is a plain function. Vue tracks reactive reads only inside render, `computed`, or a `watch` getter. Spreading `visibleOrderTiers` inside that function snapshots the set at call time. It does not schedule a deck.gl repaint.

The working pattern is the heatmap watch, which spreads the Set in the getter and then calls an imperative repaint:

```1841:1845:resources/js/clarity-scout/components/ScannerMap.vue
watch(() => [...mapStore.activeHeatmaps], (active, prev) => {
    if (map && typeof google !== 'undefined') {
        syncHeatmaps(active, prev);
    }
});
```

`syncHeatmaps` (`ScannerMap.vue:1006`) fetches. A band toggle must not use it. Add a sibling watch on `[...mapStore.visibleOrderTiers]` that calls `updateOverlayLayers()` only. Keep the filter spread inside `updateOverlayLayers` as the snapshot the filter reads. The legend button can keep its own spread: a template read during render is a real subscription, which is why `ordersActive` spreads at `DashboardOverlay.vue:475–480`.

Without the watch, `aria-pressed` flips and the cells stay until the next idle path (`refreshActiveHeatmaps` → `updateOverlayLayers` at `ScannerMap.vue:979`). That misses AC “clicking a band toggles that band’s visibility on the map.”

## 1. AC coverage

Live AC (DEV-2600, status To Do) is the six bullets in the description. The draft covers them without the un-union or the Live Hit Density legend.

| AC | Draft | Evidence |
|---|---|---|
| Org map and direct-orders legend entries are clickable | One Orders block becomes buttons | `DashboardOverlay.vue:331–354` is the only density key. Direct Orders is layer `hotlist` (`:542–546`). Org map is the same overlay in `adminMode`. No second legend. |
| Click toggles that band on the map | Paint-time feature filter | Blocked by the must-fix above. Mechanism is right once a watch calls `updateOverlayLayers`. |
| Independent bands (5–9 and 10–24 on, 1 off) | Per-tier Set, default all on | Matches `toggleHeatmap` (`map.js:1011–1017`). |
| Kit User Path parity | Five address bands, not Kit’s fetch path | Kit labels and purple ramp are `KitUserPath.php:132–137` and `getTierColor` non-staging (`HotlistAddressGeohashQuery.php:214–220`). Kit itself posts tiers and filters with HAVING (`tierConditions` at `:172–181`). Preflight #25 forbids that port. User-visible parity is the five bands and independent toggles. |
| Active vs hidden distinguishable | `aria-pressed` + `opacity-25` + `line-through` | Matches the prompt. Kit’s checkbox chrome stays on Kit. |
| Works alongside date, driver, zone | Toggle does not call `setRange`, change the driver selector, or send `zone_ids` | `fetchOrdersHeatmap` (`map.js:807–825`) sends bounds + `layers` only. Zone clip stays in `heatmapOrders`. |

Out of scope stays out: Live Hit Density (`DashboardOverlay.vue:310–328`, three hit bands), `HitDirectHotspotQuery`, Kit files, un-union, tiers on `/map/heatmap/orders`.

Ticket wording “bottom of the map” does not match the code. The key is the top-right card under Map Layers (`DashboardOverlay.vue:299–304`), collapsed into the layers sheet on compact. Prep already settled that. Do not add a bottom legend.

## 2. Repo patterns

- Store: `reactive(new Set())` beside `activeHeatmaps` (`map.js:253`), not persisted, exported from the setup `return` (`map.js:1529`). No new store.
- Legend: stay inside `<div v-show="ordersActive">`. Spread the Set in `isTierVisible`. A bare `.has()` is the DEV-2031 A6 miss documented at `DashboardOverlay.vue:475–477`.
- Paint vs fetch: filter `ordersHeatmap.features` by `properties.tier`. `unionToGeoJson` already stamps that property (`ScoutMapHelpers.php:287–292`). `toGeoJson`’s per-layer branch (`:240–255`) is a different scale and stays. Guard `layers.push` on a non-empty filtered list so “all bands off” drops the layer.
- `ScoutMapHelpers.php` needs no edit. It calls the three statics by name.

Nit on copy: the draft’s Vue labels use en dashes (`2–4`) and say “matching Kit exactly.” Kit HTML and the PHP labels use ASCII hyphens (`KitUserPath.php:134`, `getCombinedTierLabel` at `HotlistAddressGeohashQuery.php:251–254`). The Scout legend already uses en dashes (`DashboardOverlay.vue:342`). Keep the legend’s en dash. Do not unify hover `properties.label` (hyphen, from PHP) with the legend in this ticket.

Also rewrite the HTML comment at `DashboardOverlay.vue:331–333` (“capped at 10”) in the same hunk. Step 3 as written only replaces the four `<div>`s.

## 3. Preflight #24

Smallest change holds, after the watch (that watch is the existing pattern, not a new abstraction).

Rejected, and the draft does not add them: new component, new store module, new query class, CheckboxList port, tiers query param.

`HelpPanel.vue:346–356` is correctly optional. Four swatch stops and the sentence “1 → 10+” will be stale. AC does not mention help text. If it is folded in, change the swatch and that sentence only. Do not extract a shared legend component for the help panel and the map.

## 4. Preflight #25 — does not fire

Confirmed. `orderRows` already requests `tier_1` … `tier_25_plus` (`ScoutMapHelpers.php:126`). `getCombinedTier*` is a PHP rebucket after the query (`:228–256`). `tierConditions` (`HotlistAddressGeohashQuery.php:172–181`) is the Kit HAVING map and is not on the Scout path. Splitting the combined cap does not change the SQL or a numeric aggregate. No cloud ClickHouse read was required.

The 5-minute cache wraps `HotlistAddressGeohashQuery::handle` raw rows (`ClickhouseQuery.php:24–27`), not the FeatureCollection. `tier_10_plus` is stamped later in `unionToGeoJson`. No cache bust.

## 5. Provisionability

- PHPUnit: `HotlistAddressGeohashQueryTest` extends `PHPUnit\Framework\TestCase` (no database). `php artisan test --compact --filter=HotlistAddressGeohashQueryTest` is enough for the rebucket. Do not point it at dev data.
- Browser: both maps share `DashboardOverlay` + `ScannerMap`. Check one driver map with Direct Orders on, then the org map (`adminMode`) only to confirm the same buttons. Hide 5–9, confirm only that band drops, `aria-pressed=false` on that row, date pill / driver selector / zone clip / Map Layers unchanged, then pan and confirm the hidden band stays hidden.
- No ClickHouse write. Fleet Docker ClickHouse is needed only when the browser check wants real cells in more than one band. The filter itself is client-side on GeoJSON that already has `properties.tier`.
- `npm run build` rewrites committed `public/build` (not gitignored). `package.json` `build` is `vite build && node scripts/version-sw.mjs && php artisan filament:assets`. Herd serves that manifest. Commit the build output with the source change. Do not hand-edit hashed assets.

Nit: the draft says `package.json` has no test script. It has `test:e2e` (`playwright test`), and `tests/e2e/` already covers Scout. Do not add a Playwright spec for this ticket. Seeded multi-band cells plus map idle is more harness than #24 allows. The browser check above is the right bar. Do not justify skipping it by claiming there is no runner.

Test-plan nit, so the color test is not a false recipe: inverting line 57 is not sufficient. `HotlistAddressGeohashQueryTest.php:51–58` sets `$cap` to `getCombinedTierColor(10)` and asserts `$cap === '#4A148C'`. After the split, count 10 is `#7B1FA2` and count 25 stays `#4A148C`. Put both in the distinct-color set (five values) and rename `test_combined_*_cap_at_ten`. The tier/label cases in the draft (10 and 24 → `tier_10_24`, 25 and 40 → `tier_25_plus`, label `10-24 addresses`) match the code.

## 6. Hard stops / risks

- **Color change is immediate, even with every band on.** Today `getCombinedTierColor` maps `>= 10` to `#4A148C` (`HotlistAddressGeohashQuery.php:241`). Kit’s 10–24 purple is `#7B1FA2` (`KitUserPath.php:136`); 25+ stays `#4A148C`. Cells with count 10–24 change shade on deploy. Cells ≥ 25 do not. Say that in the PR body. Prep’s “map does not change until a click” is stale under Option B.
- **`tier_10_plus` removal is safe in code.** References are `HotlistAddressGeohashQuery.php:231` and `:251`, plus the unit test (`:40–41`). `getTier()` already emits `tier_10_24` / `tier_25_plus` (`:191–198`). Grep hits elsewhere are journal, this review’s inputs, and `okf/concepts/scout-clickhouse-queries.md:263` (“capped at 10+”). Queue that wiki line. Do not edit `okf/` in this change.
- **`public/build` commit** is required and will be a large generated diff (hashed filenames). Same commit as the Vue change, or QA on Herd tests the old bundle.
- **DEV-2601** is disposition on the org-map Live Hits list (`DashboardOverlay.vue` roughly `:51–123`), same file, different hunk. Keep DEV-2600 inside the Orders block (`:331–354`) and the new script consts next to `ordersActive`. Do not touch `recentHits`.
- Stack mates DEV-2602, DEV-2603, DEV-2566 do not own this legend (prep blast radius). Holding base remains `scout`.

## 7. Verdict

**REQUEST CHANGES.**

Must-fix before implement: in `ScannerMap.vue`, watch `[...mapStore.visibleOrderTiers]` and call `updateOverlayLayers()` only. Do not treat a spread inside `updateOverlayLayers` as the subscription, and do not route the toggle through `syncHeatmaps` / `fetchOrdersHeatmap`.

After that one correction the plan is the right Option B change: five combined bands, one unioned Direct+Staging layer, paint-time filter, PHPUnit on the statics, browser check for the click, `public/build` rebuilt, HelpPanel optional, wiki queued.
