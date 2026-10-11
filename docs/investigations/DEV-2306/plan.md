# DEV-2306 — Plan

Single cohesive concern → one subtask, single-node fast path (no worktree fan-out).

## t1 — Standardize Scout date-range filters

**Goal:** Admin (Bird's Eye) and Scout User (driver PWA) both show **Today (default), Yesterday, 7d, 15d, 30d**.

### Edits
1. `resources/js/clarity-scout/lib/formatters.js`
   - `rangeOptions` → `[Today, Yesterday, 7d, 15d, 30d]` (remove 60d, add Yesterday at pos 2)
   - `driverRangeOptions` → identical 5 entries
   - New `driverPrefetchRanges = ['today','yesterday','last_7']` (deliberately-light driver warm-set, DEV-2186/DEV-2306)
   - Replace the stale decoupling docblock with a short DEV-2306 note
2. `resources/js/clarity-scout/composables/usePrefetch.js`
   - Driver (`!adminMode`) prefetch scope uses `driverPrefetchRanges`, **not** `driverRangeOptions` (so pills=5 but warm-set stays light)
3. `resources/js/clarity-scout/stores/dashboard.js:93`
   - `allRanges` default → `['today','yesterday','last_7','last_15','last_30']`
4. `resources/js/clarity-scout/components/DateRangeControl.vue:73`
   - zoom-snap list → `['last_15','last_30']`
5. `tests/Feature/ClarityScout/ScoutDateRangeStandardizationTest.php` (new)
   - Pin the Scout dashboard + Bird's Eye stat endpoints accept each standardized range and resolve correct windows
6. `npm run build` → commit rebuilt `public/build` assets (platform commits built assets)

### Decisions (from Phase 2)
- Driver stays mobile-light: shows 15/30 pills but doesn't background-warm them (on-demand on tap).
- Keep two named exports (identical), not one unified array.

### Out of scope
- `DashboardOverlay.vue` legends (map visuals, not pills)
- Backend `last_60` allow-lists/maps — graceful, left tolerant (unreachable from UI)

### Gates
- Sizing: 4 source files + 1 test, single concern → OK (<6, one concern)
- Perf (WS6): no new/changed CH query, no deferred-perf-at-scale → **not required**
- Blast radius: none (no migrations/billing/auth/CH-purge)
