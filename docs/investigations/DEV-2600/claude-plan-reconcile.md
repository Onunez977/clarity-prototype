# DEV-2600 — Claude plan reconcile

Reconciles `claude-plan-draft.md` against `cloud-plan-review.md` (verdict: REQUEST CHANGES). Every must-fix cite was re-read in this worktree. No product code was changed. Option B is locked and is not reopened below.

---

## 1. Verification pass — what the cites actually say

| Claim under review | Verified at | Result |
|---|---|---|
| `updateOverlayLayers` is a plain function, not a `computed`/render context | `ScannerMap.vue:1147` | **Confirmed.** `function updateOverlayLayers() { if (!overlay) return; … }` — imperative, rebuilds `layers[]`, assigns to the deck.gl overlay. A reactive read inside it is a snapshot, not a subscription. |
| The working repaint pattern spreads the Set in a `watch` getter | `ScannerMap.vue:1838–1845` | **Confirmed**, including the comment "The getter returns a fresh array each run, so the watch fires only when the reactive Set's membership changes." |
| `syncHeatmaps` fetches and must not be used for a band toggle | `ScannerMap.vue:1006–1034` | **Confirmed.** It nulls `lastFetchedView`, diffs active/prev, and pushes `fetchOrdersHeatmap` / `fetchHitsHeatmap`. Wrong tool for a paint-only filter. |
| Without a watch, the only repaint path is the idle refetch | `ScannerMap.vue:979`, `:1000` | **Confirmed.** `refreshActiveHeatmaps` and `refreshScanPaths` both end in `updateOverlayLayers()`. A legend click reaches neither. |
| Orders paint block is one unioned `GeoJsonLayer` over `mapStore.ordersHeatmap` | `ScannerMap.vue:1215–1237` (draft said 1218–1236) | **Confirmed.** Single `id: 'heatmap-orders'`, `data: ordersData`, fill/line from `f.properties.color`. |
| Only one Orders legend, 4 hardcoded rows, comment says "capped at 10" | `DashboardOverlay.vue:331–354` | **Confirmed.** Comment at `:331–333`; rows at `:337–352`; 4th swatch is `#4A148C` / "10+ addresses". `#7B1FA2` appears nowhere in the legend today. |
| `ordersActive` spreads the Set because `.has()` misses deletes | `DashboardOverlay.vue:475–480` | **Confirmed**, with the DEV-2031 agent-QA A6 comment. `teammatesActive` (`:482–485`) repeats the guard. |
| Store pattern: `reactive(new Set())`, sibling toggle, exported from setup return | `map.js:253`, `:1011–1017`, `:1529`/`:1561`/`:1589` | **Confirmed.** Not persisted. |
| Test pins the old cap | `HotlistAddressGeohashQueryTest.php:40–41`, `:51–58`, `:68` | **Confirmed.** `$cap = getCombinedTierColor(10)`; `assertCount(4, …)`; `assertSame($cap, getCombinedTierColor(25))`; `assertSame('#4A148C', $cap)`; label `10+ addresses`. Method names `test_combined_tier_buckets_cap_at_ten` and `test_combined_color_ramp_is_stepped_and_caps_at_ten` both encode the cap. |
| `getCombinedTier*` is a 4-band PHP rebucket; `getTier`/`getTierColor` already 5-band | `HotlistAddressGeohashQuery.php:191–221`, `:223–256` | **Confirmed.** Kit ramp `#E1BEE7 / #CE93D8 / #AB47BC / #7B1FA2 / #4A148C` lives at `:214–220`. Docblock at `:223–227` literally says "capped at 10". |
| No CH change needed — the query already asks for all five source tiers | `ScoutMapHelpers.php:126` | **Confirmed.** `'tiers' => ['tier_1','tier_2_4','tier_5_9','tier_10_24','tier_25_plus']`. Preflight #25 does not fire. |
| `properties.tier` is stamped on every unioned feature | `ScoutMapHelpers.php:287–293` → `geohashFeature` `:305–328` | **Confirmed.** A client-side filter on `properties.tier` is viable with no API change. |
| `tier_10_plus` blast radius | repo-wide grep | **Confirmed.** Product code: `HotlistAddressGeohashQuery.php` + the unit test only. Everything else is DEV-2600 investigation artifacts. (`okf/concepts/scout-clickhouse-queries.md` uses the prose "capped at 10+", not the token — queue, do not edit.) |
| HelpPanel ramp is 4 stops and says "1 → 10+" | `HelpPanel.vue:346–356` | **Confirmed.** Stops `#4A148C/#AB47BC/#CE93D8/#E1BEE7`; copy at `:355`. |
| `phpunit.xml` excludes no groups | `phpunit.xml` | **Confirmed** — no `<groups>`/exclude block, so the `#[Group('clickhouse')]` tags on this pure-PHP test do not stop it running in CI. Keep the attributes as-is. |

---

## 2. CloudAgent findings — adopt / reject

### Adopted

1. **MUST-FIX — the toggle needs a `watch`, not a spread inside `updateOverlayLayers`.** Adopted in full. `ScannerMap.vue:1147` is a plain function; Vue only tracks reads inside render / `computed` / a `watch` getter, so the draft's Step 4 would flip `aria-pressed` and leave the cells painted until the next idle refetch (`:979`). That misses the AC outright. Sibling watch on `[...mapStore.visibleOrderTiers]` calling `updateOverlayLayers()` **only** — never `syncHeatmaps`, never `fetchOrdersHeatmap`.
2. **Keep the filter spread inside `updateOverlayLayers` as the paint snapshot.** Adopted. The watch is the subscription; the spread inside the builder is how the filter reads current membership. Both, not either.
3. **The legend button keeps its own spread (`isTierVisible`).** Adopted — a template read during render *is* a real subscription, which is exactly why `ordersActive` spreads at `:478–480`.
4. **Rewrite the HTML comment at `DashboardOverlay.vue:331–333` in the same hunk.** Adopted. The draft's Step 3 only replaced the four `<div>`s and would have left "capped at 10" sitting above a five-band legend.
5. **The color test needs a rewrite, not an inverted line 57.** Adopted — this is the sharper of the two test notes. `$cap` is *defined* as `getCombinedTierColor(10)` at `:51` and asserted `=== '#4A148C'` at `:58`; after the split, count 10 is `#7B1FA2`. Flipping only `:57` leaves `:58` failing. Rewrite the whole method and rename both cap-named methods.
6. **PR body must say 10–24 cells change color on deploy even with all bands on.** Adopted. `getCombinedTierColor` maps `>=10 → #4A148C` today (`:241`); after the split 10–24 becomes `#7B1FA2`. Prep's "map does not change until a click" is stale under Option B. This is the one user-visible change that is not gated behind a click.
7. **Do not add a Playwright spec; do not claim there is no runner.** Adopted — the draft's "no JS test runner" line is wrong (`test:e2e` exists, `tests/e2e/` already covers Scout). Correct framing: a runner exists, and seeding multi-band cells plus map-idle timing is more harness than preflight #24 allows. Browser check is the right bar, on its own merits.
8. **Keep en dashes on the Scout legend; leave PHP labels ASCII.** Adopted. `DashboardOverlay.vue:342` already uses `2–4`; `getCombinedTierLabel` (`:251–254`) and `ScoutMapHelpers.php:250–253` use hyphens. The draft's "labels matching Kit exactly" was imprecise — glyphs differ by surface. Do not unify hover `properties.label` with the legend in this ticket.
9. **Guard `layers.push` on a non-empty filtered list** so "all bands off" drops the layer instead of pushing an empty one. Adopted (draft already had this; confirmed correct).
10. **HelpPanel stays optional.** Adopted as a *scope call*, unchanged from the draft. If folded in: swatch gains a 5th stop and `:355` copy becomes "1 → 25+" — nothing else. Do not extract a shared legend component.
11. **Ticket wording "bottom of the map" is stale; do not add a bottom legend.** Adopted. The key is the top-right card under Map Layers (`DashboardOverlay.vue:303–304`).
12. **Do not edit `okf/`; queue `scout-clickhouse-queries.md:263`.** Adopted — CLAUDE.md WRITE rule.
13. **`public/build` rebuilt and committed in the same commit as the Vue change.** Adopted; otherwise QA on Herd tests the old bundle.
14. **Stay inside the Orders block; do not touch `recentHits`** (DEV-2601 lands in the same file). Adopted.

### Rejected / not applicable

1. **Nothing in the review proposes a new abstraction, an un-union, a Kit HAVING port, or a tiers query param** — the review explicitly rules each out, so there is nothing to reject on that axis. For the record, all four remain rejected: `tierConditions` (`:172–181`) is the Kit HAVING map and is not on the Scout path; Direct + Staging stay unioned on one layer and one legend; `/map/heatmap/orders` gains no parameter; no new component/store/query class.
2. **Live Hit Density / `HitDirectHotspotQuery` / `recentHits`** — out of scope (DEV-2601). Rejected as an in-ticket change even though the three-band hit legend at `DashboardOverlay.vue:310–328` sits immediately above the hunk.
3. **The review's framing that the watch is "not a new abstraction"** — agreed, and adopted on that basis. A second `watch` beside `:1841` is the house pattern; this does not license any further structural change.
4. **Draft's "package.json has no test script"** — rejected as a factual error, replaced by finding 7 above.
5. **Draft's line reference "`ScannerMap.vue:1218–1236`"** — corrected to `1215–1237` (the comment block at `:1215–1217` is part of the hunk).

---

## 3. FINAL IMPLEMENT PLAN — authoritative (supersedes the draft where they differ)

**Option B, locked:** split the combined Orders `10+` band into `10–24` and `25+` on the same unioned Direct + Staging layer, and make all five legend rows clickable per-band visibility toggles. No un-union. Live Hit Density untouched.

### Files to touch

| File | Change |
|---|---|
| `app/Clickhouse/Queries/HotlistAddressGeohashQuery.php` | Five-band `getCombinedTier` / `…Color` / `…Label`; rewrite the `:223–227` docblock |
| `tests/Unit/Clickhouse/Queries/HotlistAddressGeohashQueryTest.php` | Five-band tier/label asserts; **rewrite** the color test; rename both `…cap_at_ten` methods |
| `resources/js/clarity-scout/stores/map.js` | `visibleOrderTiers` reactive Set + `toggleOrderTier` + `ORDER_TIERS`, exported |
| `resources/js/clarity-scout/components/DashboardOverlay.vue` | Five clickable legend rows + `isTierVisible`; rewrite the `:331–333` comment |
| `resources/js/clarity-scout/components/ScannerMap.vue` | Paint-time tier filter in `updateOverlayLayers` **+ a sibling `watch`** |
| `public/build/**` | `npm run build`, committed with the source change |
| `resources/js/clarity-scout/components/HelpPanel.vue:346–356` | **Optional** (scope call). If in: 5th swatch stop + "1 → 25+" only |

**Not touched:** `ScoutMapHelpers.php` (calls the three statics by name — `:290–292`); `toGeoJson`'s per-layer branch (`:240–255`, different scale); `fetchOrdersHeatmap` (`map.js:807–825`, sends bounds + `layers` only); any Kit file; `okf/`.

### Steps

**1 · PHP five-band split** — `HotlistAddressGeohashQuery.php:228–256`

```php
getCombinedTier:       >=25 tier_25_plus | >=10 tier_10_24 | >=5 tier_5_9 | >=2 tier_2_4 | tier_1
getCombinedTierColor:  #4A148C          | #7B1FA2          | #AB47BC     | #CE93D8     | #E1BEE7
getCombinedTierLabel:  25+ addresses    | 10-24 addresses  | 5-9 …       | 2-4 …       | 1 address
```

`tier_10_plus` disappears (product references are this file + the test only). Rewrite the `:223–227` docblock: the combined ramp is now the five-band Kit User Path scale on one unioned Direct + Staging layer — drop "capped at 10". Labels keep ASCII hyphens, matching `ScoutMapHelpers.php:250–253`.

**2 · Store** — `map.js`, beside `activeHeatmaps` (`:253`) and `toggleHeatmap` (`:1011`)

```js
const ORDER_TIERS = ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'];
const visibleOrderTiers = reactive(new Set(ORDER_TIERS));   // default: all bands on

function toggleOrderTier(tier) {
    if (visibleOrderTiers.has(tier)) {
        visibleOrderTiers.delete(tier);
    } else {
        visibleOrderTiers.add(tier);
    }
}
```

Export `visibleOrderTiers`, `toggleOrderTier`, `ORDER_TIERS` from the setup `return` (`:1529`, beside `:1561`/`:1589`). Not persisted — mirrors `activeHeatmaps`. No new store, no new module.

**3 · Legend rows** — `DashboardOverlay.vue:331–354`, inside the existing `<div v-show="ordersActive">`

Rewrite the `:331–333` comment (drop "capped at 10"; state five bands, clickable, paint-time filter, DEV-2600). Replace the four `<div>`s with a `v-for` over a local `ORDER_LEGEND` const — `{ tier, color, label }` × 5, labels `1 address` / `2–4 addresses` / `5–9 addresses` / `10–24 addresses` / `25+ addresses` (**en dashes**, matching `:342`):

```html
<button v-for="t in ORDER_LEGEND" :key="t.tier" type="button"
        :aria-pressed="isTierVisible(t.tier)"
        @click="mapStore.toggleOrderTier(t.tier)"
        class="flex items-center gap-1.5 text-left …">
  <span class="inline-block size-3 rounded-sm" :style="{ backgroundColor: t.color }"
        :class="isTierVisible(t.tier) ? '' : 'opacity-25'"></span>
  <span class="text-xs" :class="isTierVisible(t.tier) ? 'text-gray-600' : 'text-gray-300 line-through'">{{ t.label }}</span>
</button>
```

`isTierVisible` **must spread the Set** — `[...mapStore.visibleOrderTiers].includes(tier)` — the DEV-2031 A6 gotcha documented at `:475–477`. Put `ORDER_LEGEND` / `isTierVisible` next to `ordersActive` (`:478`), not near `recentHits`.

**4 · Paint-time filter** — `ScannerMap.vue:1215–1237`

```js
const ordersData = mapStore.ordersHeatmap;
const visibleTiers = [...mapStore.visibleOrderTiers];
const filteredOrders = ordersData?.features?.length
    ? { ...ordersData, features: ordersData.features.filter(f => visibleTiers.includes(f.properties?.tier)) }
    : null;
```

Feed `data: filteredOrders` and guard the `layers.push` on `filteredOrders?.features?.length` (so all-bands-off drops the layer). Filtering **at paint, not at fetch** is what stops a pan/zone refetch resurrecting a hidden band.

**5 · The watch (MUST — this is the correction)** — `ScannerMap.vue`, sibling to `:1841`

```js
// DEV-2600: Orders legend band toggles are a PAINT filter — repaint only.
// Never syncHeatmaps/fetchOrdersHeatmap: the data is already client-side and
// carries properties.tier. Spread so the getter tracks Set membership.
watch(() => [...mapStore.visibleOrderTiers], () => {
    if (map && typeof google !== 'undefined') {
        updateOverlayLayers();
    }
});
```

The spread in Step 4 is the snapshot the filter reads; **this watch is the subscription**. Both are required. Do not merge this into the `activeHeatmaps` watch — that one routes to `syncHeatmaps`, which fetches.

**6 · Explicitly unchanged:** `fetchOrdersHeatmap` sends no tiers; date range / driver selector / zone clip untouched; Direct + Staging stay unioned on one layer with one legend; no cache bust (the 5-min cache wraps raw rows in `ClickhouseQuery.php:24–27`, and `tier` is stamped later in `unionToGeoJson`).

### Test plan

**PHPUnit** — `tests/Unit/Clickhouse/Queries/HotlistAddressGeohashQueryTest.php` (extends `PHPUnit\Framework\TestCase`, no DB, no CH; keep the existing `#[Group]` attributes — `phpunit.xml` excludes no groups):

- Rename `test_combined_tier_buckets_cap_at_ten` → `test_combined_tier_buckets_split_at_ten_and_twenty_five`. Assert 1→`tier_1`, 2 and 4→`tier_2_4`, 5 and 9→`tier_5_9`, **10 and 24→`tier_10_24`**, **25 and 30→`tier_25_plus`**.
- Rename `test_combined_color_ramp_is_stepped_and_caps_at_ten` → `test_combined_color_ramp_is_stepped_across_five_bands`. **Rewrite the body** (do not just invert `:57`): sample 1 / 3 / 7 / 12 / 40, `assertCount(5, array_unique([...]))`, `assertSame('#7B1FA2', getCombinedTierColor(10))`, `assertSame('#4A148C', getCombinedTierColor(25))`, `assertNotSame(getCombinedTierColor(10), getCombinedTierColor(25))`. The old `$cap = …(10); assertSame('#4A148C', $cap)` at `:51`/`:58` must go.
- Labels: add `getCombinedTierLabel(12)` → `10-24 addresses`; change `(40)` → `25+ addresses`.

Run: `php artisan test --compact --filter=HotlistAddressGeohashQueryTest`. Do not point it at dev data.

**Browser (agent-browser / manual, per AC):** driver map with Direct Orders on —

- Hide `5–9`: only that band's cells vanish; the other four still paint; the row shows `aria-pressed="false"`, `opacity-25` swatch, struck-through label.
- Turn two bands on and one off independently (AC: 5–9 and 10–24 on, 1 off).
- **Pan the map**, confirm the hidden band does not come back after the refetch.
- Turn every band off: the Orders layer disappears entirely.
- Confirm the date pill, driver selector, zone clip, and the Map Layers toggles are unaffected.
- Spot-check the org map (`adminMode`) only to confirm the same buttons render — it's the same `DashboardOverlay` + `ScannerMap`.
- Confirm a cell with count 10–24 renders `#7B1FA2`, not `#4A148C`.

Fleet Docker ClickHouse is needed only to get real cells into more than one band; the filter itself is client-side on GeoJSON that already carries `properties.tier`.

**Then:** `vendor/bin/pint --dirty --format agent`, `npm run build`, commit `public/build` **in the same commit** as the Vue change.

### Out of scope

Live Hit Density legend and `HitDirectHotspotQuery` (DEV-2601 disposition, `DashboardOverlay.vue:310–328` / `:51–123` — do not touch `recentHits`); un-unioning Direct and Staging; porting Kit's `tierConditions` HAVING filter or checkbox chrome; a `tiers` param on `/map/heatmap/orders`; a shared legend component; a bottom-of-map legend; a Playwright spec; any `okf/` edit.

### Risks

- **Immediate color change on deploy, independent of any click.** Cells with count 10–24 go `#4A148C` → `#7B1FA2`; ≥25 is unchanged. Intended under Option B (Kit parity), but it is a visible change to existing maps — **state it in the PR body**. Prep's "map does not change until a click" is stale.
- **`public/build` is a large generated diff** (hashed filenames). Required; without it Herd/QA runs the old bundle. Do not hand-edit hashed assets, and do not sweep in an unrelated rebuild of `main`'s committed build.
- **DEV-2601 shares this file.** Keep every hunk inside the Orders legend block and the script consts beside `ordersActive` to keep the merge clean.
- **Wiki queued, not written:** `okf/concepts/scout-clickhouse-queries.md:263` ("capped at 10+") and the Orders-ramp description in DEV-2031 territory go stale. Land them in a post-approval pass per CLAUDE.md.
- **HelpPanel goes stale if left out** (`:346–356`: 4 swatch stops, "1 → 10+"). Optional by AC; if excluded, note it in the PR body so it is not mistaken for an oversight.
