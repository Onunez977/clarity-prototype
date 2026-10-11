# DEV-2179 — Plan

**Route:** feature · **Base:** `main` · **Shape:** single node (cohesive frontend-polling + backend-cache change)

## Subtask t1 — Auto-refresh the "Live Hits Pending Repossession" list (driver + admin) + reconcile caching + fix admin-mode poller leak

`complexity: complex` (5 files, spans frontend + backend) · `blast_radius: none` · `test_filter: ClarityScoutBirdsEyeControllerTest`

### Changes
1. **`MapShell.vue`** — add `&& !mapStore.adminMode` to the `LiveTracking` `v-if` so the driver-only `useKitPositionPolling` no longer mounts in admin Bird's Eye mode (fixes the double-stats-poll + nonexistent-admin-route `200 text/html` leak; knowledge/clarity/scout.md M3; likely DEV-2184). [Q3]
2. **`useKitPositionPolling.js`** (driver loop) — inside the existing `if (mapStore.liveMode)` tick block, add `dashboardStore.fetchRecentHits('today')` next to the existing `fetchStats('today')`. [Q1 driver surface]
3. **`useBirdsEyePolling.js`** (admin loop) — inside the existing tick `Promise.all` (already gated on visibility + `liveMode` + `adminMode`), add `dashboard.fetchRecentHits('today')` next to `fetchStats('today')`. [Q1 admin surface / AC3]
4. **`ClarityScoutBirdsEyeController@recentHits`** — wrap the query body in `Cache::remember($cacheKey, $ttl≈10s, …)`, mirroring the sibling `hits()` cache-key shape on the same controller; TTL matched to the ~10s poll cadence so polls / multiple tabs coalesce to ≤1 ClickHouse query per org per interval. [AC2]
5. **`ClarityScoutBirdsEyeControllerTest`** — add `test_recent_hits_response_is_cached` mirroring the driver-side precedent (`Cache::has` after one request).

### Why this shape
- The three JS files are one wiring decision: the MapShell guard determines which loop runs on which surface, so "both surfaces gets exactly one `fetchRecentHits`" only holds if all three are reasoned about together → single node, no fan-out overhead.
- `fetchRecentHits` rides the **existing** ~10s poll tick (Dalton: "combine with kit-position/stats polling") — no new timer.
- Reconciliation (AC2) = poll every ~10s against a ~10s admin cache.

### Verification
- **Automated:** `php artisan test --compact --filter=ClarityScoutBirdsEyeControllerTest` (backend cache).
- **Manual / agent-QA:** driver + admin in Live/Today; insert a live hit; card updates within a poll cycle without reload; no admin-mode kit-position/live-path console errors; cadence ~10s and coalesced.

### Excluded (with reason)
- Driver `ClarityScoutDashboardController@recentHits` — already cached (90s); Q2 left unchanged.
- `DashboardOverlay.vue` — reactive consumer, no change.
- `dashboard.js fetchRecentHits` — already adminMode-correct via `lib/fetch.js`, reused as-is.
