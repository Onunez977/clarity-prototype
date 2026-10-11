I re-read every cited surface. Plan below (Option B only).

---

# DEV-2600 — Implement Plan (Option B, LOCKED)

## Verified facts that shape the plan

- `orderRows` (`ScoutMapHelpers.php:126`) already requests all five source tiers → **no CH change** (preflight #25 holds).
- The Orders legend exists in **exactly one** place: `DashboardOverlay.vue:331–354`. The org map and direct-orders map both render it (admin reuses the real App.vue via `adminMode`). No second legend to port.
- Kit's five-band colors are `#E1BEE7 / #CE93D8 / #AB47BC / #7B1FA2 / #4A148C` (`KitUserPath.php:133–137`) — identical to `getTierColor()`'s non-staging ramp. The combined split therefore reuses **`#7B1FA2` for 10–24** and keeps **`#4A148C` for 25+**.
- `HotlistAddressGeohashQueryTest:57` currently asserts `getCombinedTierColor(25) === getCombinedTierColor(10)`. That assertion **must invert** — it is the test that pins the old cap.
- This repo has **no JS test runner** (`package.json` has no test script; built assets are committed). So no Vue/Playwright test — the UI leg is PHPUnit-adjacent + a browser check, and `public/build` must be rebuilt and committed.

## Files to touch

| File | Change |
|---|---|
| `app/Clickhouse/Queries/HotlistAddressGeohashQuery.php` | Split `getCombinedTier` / `…Color` / `…Label` into five bands; update the docblock at ~223–227 that says "capped at 10" |
| `tests/Unit/Clickhouse/Queries/HotlistAddressGeohashQueryTest.php` | Five-band tier/label asserts; invert the 10-vs-25 color-equality assert |
| `resources/js/clarity-scout/components/DashboardOverlay.vue` | Five clickable legend rows + `toggleOrderTier` wiring |
| `resources/js/clarity-scout/stores/map.js` | `visibleOrderTiers` reactive Set + `toggleOrderTier`; export both |
| `resources/js/clarity-scout/components/ScannerMap.vue` | Filter `ordersHeatmap.features` by visible tiers at paint time |
| `public/build/**` | Rebuild + commit (repo tracks built assets) |
| `resources/js/clarity-scout/components/HelpPanel.vue:346–356` | *Recommended, small:* the ramp swatch is 4 stops and the copy says "1 → 10+" — both contradict the shipped scale after this change. Add the 5th stop, change copy to "1 → 25+". Flagging as a scope call, not assuming it. |

**Not touched:** `ScoutMapHelpers.php` — `unionToGeoJson` calls the three statics by name and needs no edit; `toGeoJson`'s per-layer branch is a different scale and stays. No API shape change, no tiers param.

## Steps

**1 · PHP five-band split** (`HotlistAddressGeohashQuery.php:228–256`)

```php
getCombinedTier:  >=25 tier_25_plus | >=10 tier_10_24 | >=5 tier_5_9 | >=2 tier_2_4 | tier_1
getCombinedTierColor: #4A148C | #7B1FA2 | #AB47BC | #CE93D8 | #E1BEE7
getCombinedTierLabel: 25+ addresses | 10-24 addresses | 5-9 addresses | 2-4 addresses | 1 address
```

Rewrite the ~223–227 docblock: the ramp is now the five-band Kit User Path scale, still one unioned Direct+Staging layer. `tier_10_plus` disappears entirely — grep confirms it is referenced only here and in the test.

**2 · Store** (`map.js`, beside `activeHeatmaps` at ~253 and `toggleHeatmap` at ~1011)

```js
const ORDER_TIERS = ['tier_1','tier_2_4','tier_5_9','tier_10_24','tier_25_plus'];
const visibleOrderTiers = reactive(new Set(ORDER_TIERS));   // default: all on
function toggleOrderTier(tier) { visibleOrderTiers.has(tier) ? visibleOrderTiers.delete(tier) : visibleOrderTiers.add(tier); }
```

Export `visibleOrderTiers`, `toggleOrderTier`, `ORDER_TIERS` from the `return {}` (~1529). Not persisted — mirrors `activeHeatmaps`. No new store, no new module.

**3 · Legend rows** (`DashboardOverlay.vue:334–354`)

Replace the four hardcoded `<div>`s with a `v-for` over a local `ORDER_LEGEND` const (`{ tier, color, label }` × 5, labels matching Kit exactly: `1 address`, `2–4 addresses`, `5–9 addresses`, `10–24 addresses`, `25+ addresses`). Each row becomes:

```html
<button type="button" :aria-pressed="isTierVisible(t.tier)" @click="mapStore.toggleOrderTier(t.tier)"
        class="flex items-center gap-1.5 text-left …">
  <span class="inline-block size-3 rounded-sm" :style="{ backgroundColor: t.color }"
        :class="isTierVisible(t.tier) ? '' : 'opacity-25'"></span>
  <span class="text-xs" :class="isTierVisible(t.tier) ? 'text-gray-600' : 'text-gray-300 line-through'">{{ t.label }}</span>
</button>
```

`isTierVisible` **must spread the Set** — `[...mapStore.visibleOrderTiers].includes(tier)` — same reactive-Set gotcha called out at `DashboardOverlay.vue:475–477` (DEV-2031 agent-QA A6); a bare `.has()` won't re-run on delete.

Keep this hunk contained to the Orders `<div v-show="ordersActive">` block — DEV-2601 also lands in this file.

**4 · Paint-time filter** (`ScannerMap.vue:1218–1236`)

```js
const ordersData = mapStore.ordersHeatmap;
const visibleTiers = [...mapStore.visibleOrderTiers];
const filteredOrders = ordersData?.features?.length
    ? { ...ordersData, features: ordersData.features.filter(f => visibleTiers.includes(f.properties?.tier)) }
    : null;
```

Guard the `layers.push` on `filteredOrders?.features?.length`. Filtering **at paint** (not at fetch) is what makes a pan/zone refetch unable to resurrect a hidden band. Spreading inside the layer-builder keeps the reactive dependency so deck.gl re-renders on toggle.

**5 · Explicitly unchanged:** `fetchOrdersHeatmap` sends no tiers; date range / driver / zone controls untouched; Direct+Staging stay unioned on one layer and one legend.

## Test plan

**PHPUnit** — `tests/Unit/Clickhouse/Queries/HotlistAddressGeohashQueryTest.php`:
- `getCombinedTier`: 1→`tier_1`, 4→`tier_2_4`, 9→`tier_5_9`, **10 and 24 → `tier_10_24`**, **25 and 40 → `tier_25_plus`**.
- `getCombinedTierLabel(40)` → `25+ addresses`; add `getCombinedTierLabel(12)` → `10-24 addresses`.
- Color: **invert** line 57 to `assertNotSame(getCombinedTierColor(10), getCombinedTierColor(25))`, and keep five distinct values.

Run: `php artisan test --compact --filter=HotlistAddressGeohashQueryTest` (unit, no CH).

**UI (browser / agent-browser, per AC):** with Direct Orders on — hide `5–9`, confirm only 5–9 cells vanish and the other four bands still paint; confirm `aria-pressed=false` + muted swatch on the hidden row; confirm the date pill, driver filter, zone clip, and the Map Layers toggles are unaffected; pan the map and confirm the hidden band does not return after the refetch.

**Then:** `vendor/bin/pint --dirty --format agent`, `npm run build`, commit `public/build`.

## Notes / risks

- Splitting the cap changes rendered colors for any cell ≥10 (was `#4A148C`, now `#7B1FA2` for 10–24). That is intended by Option B and is Kit parity, but it is a visible change to existing maps — worth a line in the PR body.
- `HelpPanel.vue` will state a scale the map no longer uses unless included; I'd fold it in, but it's your call.
- Wiki: per CLAUDE.md, **queue** any okf update (the Orders ramp is described in DEV-2031 territory) and land it only after approval.
