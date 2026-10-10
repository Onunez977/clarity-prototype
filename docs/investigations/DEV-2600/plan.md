# DEV-2600 plan

Input: `docs/investigations/DEV-2600/prep-context.json`. Gate **CLEAN**. Phase **planned**. Next **Provision**.

**Q1 LOCKED Option B:** Split combined 10+ into Kit 10–24 and 25+ on the SAME unioned Direct+Staging Orders layer. Do NOT un-union. Live Hit Density OOS.

Holding `scout` @ `f52b5d85afadb3adc7e01d4153d603de9050e08b`. Branch `cursor/dev-2600-density-legend-filter-f382`. PR [#476](https://github.com/insight-lpr/platform/pull/476). First Glance comment 42171.

Claude-first #23: `claude-plan-draft.md` → `cloud-plan-review.md` (REQUEST CHANGES; must-fix visibleOrderTiers watch) → `claude-plan-reconcile.md` (adopted). This file + `plan.json` are authoritative.

## Locks

- **Q1 Option B.** Split combined Orders `10+` into Kit User Path `10–24` and `25+` on the SAME unioned Direct+Staging Orders layer. Do NOT un-union Direct and Staging. Live Hit Density out of scope (DEV-2601).

## Scope

In scope: five-band PHP split (`getCombinedTier` / Color / Label); clickable five-row Orders legend; paint-time `properties.tier` filter; `visibleOrderTiers` store Set + toggle; sibling watch → `updateOverlayLayers()` only; PHPUnit rewrite; `public/build` in the same commit.

Out of scope: Live Hit Density legend and `HitDirectHotspotQuery` (DEV-2601 — do not touch `recentHits`); un-unioning Direct and Staging; Kit `tierConditions` HAVING filter or checkbox chrome; a `tiers` param on `/map/heatmap/orders`; a shared legend component; a bottom-of-map legend; a Playwright spec; any `okf/` edit.

## Files

| File | Change |
|---|---|
| `app/Clickhouse/Queries/HotlistAddressGeohashQuery.php` | Five-band `getCombinedTier` / `…Color` / `…Label`; rewrite the `:223–227` docblock |
| `tests/Unit/Clickhouse/Queries/HotlistAddressGeohashQueryTest.php` | Five-band tier/label asserts; **rewrite** the color test; rename both `…cap_at_ten` methods |
| `resources/js/clarity-scout/stores/map.js` | `visibleOrderTiers` reactive Set + `toggleOrderTier` + `ORDER_TIERS`, exported |
| `resources/js/clarity-scout/components/DashboardOverlay.vue` | Five clickable legend rows + `isTierVisible`; rewrite the `:331–333` comment |
| `resources/js/clarity-scout/components/ScannerMap.vue` | Paint-time tier filter in `updateOverlayLayers` **+ a sibling `watch`** |
| `public/build/**` | `npm run build`, committed with the source change |
| `resources/js/clarity-scout/components/HelpPanel.vue:346–356` | **Optional** (scope call). If in: 5th swatch stop + "1 → 25+" only |

**Not touched:** `ScoutMapHelpers.php`; `toGeoJson`'s per-layer branch; `fetchOrdersHeatmap`; any Kit file; `okf/`.

## Steps

**1 · PHP five-band split** — `HotlistAddressGeohashQuery.php:228–256`

- `getCombinedTier`: `>=25 tier_25_plus` | `>=10 tier_10_24` | `>=5 tier_5_9` | `>=2 tier_2_4` | `tier_1`
- `getCombinedTierColor`: `#4A148C` | `#7B1FA2` | `#AB47BC` | `#CE93D8` | `#E1BEE7`
- `getCombinedTierLabel`: `25+ addresses` | `10-24 addresses` | `5-9 …` | `2-4 …` | `1 address`

`tier_10_plus` disappears (product refs: this file + test only). Rewrite docblock; drop "capped at 10". Labels keep ASCII hyphens.

**2 · Store** — `map.js`, beside `activeHeatmaps` / `toggleHeatmap`

`ORDER_TIERS` five ids; `visibleOrderTiers = reactive(new Set(ORDER_TIERS))` (all on); `toggleOrderTier(tier)` add/delete. Export all three. Not persisted.

**3 · Legend rows** — `DashboardOverlay.vue:331–354`

Rewrite comment (five bands, clickable, paint-time filter, DEV-2600). Replace four `<div>`s with `v-for` over `ORDER_LEGEND` (en-dash labels). `isTierVisible` **must spread** the Set. Keep consts beside `ordersActive`, not near `recentHits`.

**4 · Paint-time filter** — `ScannerMap.vue:1215–1237`

Spread `visibleOrderTiers`; filter `ordersHeatmap.features` by `properties.tier`; feed filtered GeoJSON; guard `layers.push` on non-empty (all-off drops the layer). Filter at paint, not fetch.

**5 · The watch (MUST)** — `ScannerMap.vue`, sibling to the `activeHeatmaps` watch (`:1841`)

```js
watch(() => [...mapStore.visibleOrderTiers], () => {
    if (map && typeof google !== 'undefined') {
        updateOverlayLayers();
    }
});
```

Repaint only — never `syncHeatmaps` / `fetchOrdersHeatmap`. Do not merge into the `activeHeatmaps` watch.

**6 · Explicitly unchanged:** `fetchOrdersHeatmap` sends no tiers; date range / driver selector / zone clip untouched; Direct + Staging stay unioned; no cache bust.

## Test plan

**PHPUnit** — `HotlistAddressGeohashQueryTest.php` (pure PHP; keep `#[Group]` attrs):

- Rename `test_combined_tier_buckets_cap_at_ten` → `test_combined_tier_buckets_split_at_ten_and_twenty_five`. Assert 10/24 → `tier_10_24`, 25/30 → `tier_25_plus`.
- Rename `test_combined_color_ramp_is_stepped_and_caps_at_ten` → `test_combined_color_ramp_is_stepped_across_five_bands`. **Rewrite the body** (do not invert `:57` alone). Sample 1/3/7/12/40; `assertCount(5, …)`; `assertSame('#7B1FA2', getCombinedTierColor(10))`; `assertSame('#4A148C', getCombinedTierColor(25))`.
- Labels: `(12)` → `10-24 addresses`; `(40)` → `25+ addresses`.

Run: `php artisan test --compact --filter=HotlistAddressGeohashQueryTest`.

**Browser** (driver map, Direct Orders on): hide 5–9 (others stay); independent multi-band on/off; **pan** — hidden band stays gone; all-off drops layer; date/driver/zone/Map Layers unaffected; org map same buttons; count 10–24 paints `#7B1FA2`. Then `pint --dirty`, `npm run build`, commit `public/build` same commit.

## Risks

- **Immediate color change on deploy** — cells count 10–24 go `#4A148C` → `#7B1FA2` even with all bands on. State in PR body.
- **`public/build` large generated diff** — required same commit; do not hand-edit hashed assets.
- **DEV-2601 shares this file** — keep hunks inside Orders block / beside `ordersActive`.
- **Wiki queued, not written** — `okf/concepts/scout-clickhouse-queries.md:263` goes stale; post-approval pass.
- **HelpPanel optional** — if left out, note in PR body (`:346–356` stays 4-stop / "1 → 10+").

## Provision note

Path A fleet-reachable Clarity site + keyed PG + Docker CH required before `phase=provisioned`. The band filter is client-side on GeoJSON `properties.tier`, but browser multi-band cell checks need Docker CH seeded with real cells across bands.
