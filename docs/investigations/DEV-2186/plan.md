# DEV-2186 — Plan

**Scope:** Scout **driver** map window becomes recent-focused (Today + last-7d). The 15/30/60-day windows are removed from the driver's pills and prefetch. Admin (Bird's Eye) is **deliberately unchanged**. Frontend-only + `public/build` rebuild. No backend change.

## Subtask t1 (simple, no blast radius)

Prune the driver window to Today + 7d, gated on `mapStore.adminMode`.

1. **`lib/formatters.js`** — add `driverRangeOptions` (rangeOptions filtered to `today` + `last_7`). Single source of truth for the driver's window set; keeps driver/admin lists deliberately separate.
2. **`components/DateRangeControl.vue`** — render a computed `visibleRangeOptions` = `adminMode ? rangeOptions : driverRangeOptions`. Admin keeps all 5 pills; driver sees Today + 7d.
3. **`composables/usePrefetch.js`** — when `mapStore && !mapStore.adminMode`, pass the driver range values into both prefetch calls; else pass `null` (all).
4. **`stores/dashboard.js`** — `prefetchAllRanges(ranges = null)` uses `ranges ?? [full 5]` (back-compatible default).
5. **`stores/map.js`** — `prefetchAllRangePaths(ranges = null)` uses `ranges ?? [full 5]`.
6. **`public/build`** — `npm run build` + commit; revert Boost-regenerated docs.

## Deliberately NOT touched (per Dalton, Q1 = keep Live boot)
- `dashboard.js` `range` default (`'today'`) — boot behavior unchanged.
- `map.js` `liveMode` default (`true`) — driver still opens in Live.
- Backend `getDateRange` — caller-agnostic, correct.

## Tests
No JS test runner; no PHP diff. Validating build = `npm run build`. Behavior verified in Phase 6 QA (driver pills = Today+7d, admin unchanged, driver prefetch trimmed). PHP suite left to CI.

## Coordination
`claude/DEV-2185` (unmerged) edits the same files for zoom-gating — whichever merges second resolves the conflict manually.
