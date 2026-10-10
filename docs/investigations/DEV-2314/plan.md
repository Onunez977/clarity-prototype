# DEV-2314 — Plan

**Scout: Adjust default zoom level** (route: bug · single simple node)

## t1 — Lower the two Scout zoom-visibility floors 12 → 11, rebuild assets

`resources/js/clarity-scout/components/ScannerMap.vue`
- line 129: `RANGE_HIT_MARKERS_MIN_ZOOM = 12` → `11`  (hit icons)
- line 134: `PATH_LAYERS_MIN_ZOOM = 12` → `11`  (path/density layers)

Then `npm run build`, commit regenerated `public/build` (+ `manifest.json`), and revert any boost-doc regen (CLAUDE.md/AGENTS.md/.junie) that the build sweeps in.

**Complexity:** simple · **Blast radius:** none · **UI impact:** yes (map)

### Explicitly NOT changed (Dalton, 2026-08-07 — "just the 2 named gates")
- `NO_SCAN_LABEL_MIN_ZOOM` (already 11) · `TRAIL_ARROWS_MIN_ZOOM` (14) · `LIVE_ZOOM` (16) · `bootZoom`/`fallbackZoom` default-view literals (12) · user-driven camera clamps.

### Testing
JS-only; no JS test runner. Per CLAUDE.md, no automatic PHP test run (change touches zero PHP). Verification = agent/human QA at the map + CI on push.

### Perf
No CH query/schema change. Lowering the floor lets the existing (uncapped) scan-path fetch fire ~4× wider one zoom level out — a QA item (60-day @ zoom 11), not a perf-gate trigger.
