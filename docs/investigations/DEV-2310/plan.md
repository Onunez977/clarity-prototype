# DEV-2310 — Plan

**Scout Map: show active kit icons (and trails) regardless of the selected date filter.**

Route: bug · Base: main · Single subtask (t1), single-node fast path (tightly-coupled, no parallelism benefit, no per-node test).

## Root cause
`DateRangeControl.selectRange()` flips `mapStore.liveMode = false` on any non-Today pill. `liveMode` conflates two concerns:
- **Historical-vs-live overlays** (scan-paths, zoom defaults, today-scoped stats/hits) — correctly Today-gated. **Leave alone.**
- **The always-current active-kit layer** (marker icons, live GPS trail, trail arrows, and the polls feeding them) — wrongly gated too. **Decouple.**

Backend (`kitPosition`, `driverPositions`) is range-agnostic (fixed ~24h cutoff) — **no backend change**.

## t1 — the decoupling (4 files, frontend only)

**Driver (own dot + own live trail):**
- `stores/map.js` `setLiveMode(false)`: drop `clearKitPosition()` (keeps dot + `liveTrail`).
- `MapShell.vue`: drop `liveMode` from the `LiveTracking` v-if (line 12) → driver poll (`useKitPositionPolling` → dot + `fetchLivePath`) keeps running; remove the defensive `clearKitPosition` watch (324-328).

**Admin (per-driver markers + trails):**
- `ScannerMap.vue` `updateDriverMarkers` (712-716): remove the `!liveMode` early-return.
- `useBirdsEyePolling.js`: always run `fetchDriverPositions` + `fetchDriverPaths` every tick; keep `fetchStats`/`fetchRecentHits('today')` gated on `liveMode`.

**Trails (both surfaces — Dalton: persist, overlay both):**
- `ScannerMap.vue`: ungate live-trail (1354, 1366), admin driver-trails (1383, keep `adminMode`), and trail arrows (817, 863). Historical scan-paths (1322) untouched → trail draws over them.

**Comments:** update the now-stale comments at map.js:891-893/903-905, MapShell:324-325, ScannerMap:710-711/1805-1806 (Dalton: keep comments minimal).

## Explicitly out of scope
Historical scan-path overlay (stays), liveMode zoom defaults, App.vue My Location button + no-kits banner (leave as-is), `useKitPositionPolling` (false positive), all backend.

## Blast radius / gates
No sensitive globs (no migrations/billing/auth/CH purge). No CH query change → no perf gate. No JS test runner → verification via agent-browser + human QA across both surfaces × 5 ranges. `npm run build` + commit `public/build` required (revert any regenerated CLAUDE.md/AGENTS.md/boost docs).
