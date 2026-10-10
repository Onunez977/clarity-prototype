# DEV-2311 — Implementation Plan

**Route:** bug · **Base:** main · **Scope:** client-only (2 files)

## Root cause
On the admin "Bird's Eye" org-map, `noKitsReporting` (`App.vue`) evaluates true whenever
`adminMode && liveMode && drivers.length>0 && 0 real-GPS positions && scans_today>0`. It has
no signal for *"has the initial driver-positions fetch resolved yet"*, so during the mount-order
race (roster + lighter stats resolve before the heavier `/map/driver-positions` fetch) an empty
`driverPositions` reads as "genuinely empty" and the notice flashes, then disappears when GPS
positions arrive.

## Subtask t1 (single node, no fan-out)
Gate the notice behind a `positionsLoaded` flag and show a bare centered spinner during the
initial admin Live-mode load window.

- **`resources/js/clarity-scout/stores/map.js`**
  - `const positionsLoaded = ref(false)` near `drivers`/`driverPositions`.
  - In `fetchDriverPositions()`, set `positionsLoaded.value = true` after the try/catch, before
    `return driverPositions.value` — flips on the first attempt whether the fetch succeeds or its
    error is caught (per resolved Q: flip on error too, so it never spins forever).
  - Export `positionsLoaded` in the store return object.
- **`resources/js/clarity-scout/App.vue`**
  - `noKitsReporting` computed gains `&& mapStore.positionsLoaded` — gates both the centered card
    (`:38`) and the bottom pill (`:90`), which share the one computed.
  - New spinner block: `v-if="mapStore.adminMode && mapStore.liveMode && !mapStore.positionsLoaded"`,
    a bare centered `animate-spin` inline-SVG (no card, no text) in the same fixed centered overlay
    position as the notice card. No timeout (resolved Q).

## Decisions (resolved questions)
- Spinner = **bare centered animate-spin SVG**, no card/text (Dalton, 2026-08-10).
- **No timeout** — `positionsLoaded` flipping on success-or-error is the clearing signal (Dalton).

## Out of scope
- `DashboardOverlay.vue:91` ("No live hits pending repossession.") — same class, distinct element.
- Driver (non-admin) surface — gated off by `adminMode`, unaffected by design.
- Backend / query / endpoint-latency changes — none.

## Verification
- `npm run build` + commit rebuilt `public/build` assets.
- Full PHP suite as regression backstop (diff touches no PHP).
- Phase 5 review + Phase 6 agent-QA + Phase 7 human-QA (browser).
