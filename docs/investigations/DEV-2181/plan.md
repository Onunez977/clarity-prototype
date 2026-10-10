# DEV-2181 — Plan

**Scout Admin Map — filter "Live Hits Pending Repossession" by selected driver/kit**
Route: feature · Base: main · Sizing: 1 node, 3 files, no blast-radius → within limits.

## Problem
On the admin Bird's-Eye map the pending-repo list doesn't track the current selection:
- **Combobox** (DriverSelector → `setSelectedKits`): the backend already scopes to `kit_ids`, but the list only catches up on the next ~10s poll — it lags on selection change.
- **Click-to-follow** (map dot → `setFollowedDriver`): scopes only the stats tiles ("Showing only X's stats"); the pending list is never follow-scoped, because `buildUrl` special-cases `followedKitId` for `/dashboard/stats` only.

Dalton confirmed (gate): scope the pending list for **both** interactions.

## Subtask t1 (single node — not parallelizable)
Three edits that ship together:

1. **`stores/map.js` `setSelectedKits`** — add `dashboard.fetchRecentHits(dashboard.range)` to the existing `Promise.all` → instant refresh on combobox change (AC1/AC2).
2. **`stores/map.js` `setFollowedDriver` / `clearFollowedDriver`** — add `fetchRecentHits(range)` alongside the existing `fetchStats()` → follow/un-follow re-scopes the list (derived AC).
3. **`lib/fetch.js` `buildUrl`** — broaden the follow-priority predicate so a followed driver narrows **both** `/dashboard/stats` and `/dashboard/recent-hits` (required for edit 2 to actually scope to the single followed driver rather than the selection/org-wide).
4. **`composables/useBirdsEyePolling.js`** — comment-only: the "fresh org-wide" note is now misleading.

## Verification
- `ClarityScoutBirdsEyeControllerTest` (existing) — regression proof the `kit_ids` contract stays green (no backend diff).
- `npm run build` + commit built assets (revert boost-doc regen).
- Behavioral: Phase 6 agent-qa + Phase 7 human-qa browser walkthrough (only real check for a JS-only change).

## Out of scope
Backend `recentHits`/`scopedKits` (correct + tested), driver `ClarityScoutDashboardController@recentHits`, heatmaps, DEV-2180 (data-correctness of *which* hits appear — related, not blocking).
