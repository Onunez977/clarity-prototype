# DEV-2106 — Implementation plan (subtask DAG)

Single cohesive frontend concern (Scout driver-app map-first responsiveness via two shared composables). 8 files, but one story — not a split candidate.

| id | title | files | deps | complexity |
| --- | --- | --- | --- | --- |
| t1 | `useViewport` composable | `composables/useViewport.js` (new) | — | simple |
| t2 | `useCollapsible` composable | `composables/useCollapsible.js` (new) | — | simple |
| t6 | BottomSheet shared width clamp | `components/BottomSheet.vue` | — | simple |
| t3 | Controls widget → chip + clamp | `components/ScoutControlsWidget.vue` | t1,t2 | complex |
| t4 | DashboardOverlay → chips, hide key, toast offset, clamp | `components/DashboardOverlay.vue` | t1,t2 | complex |
| t5 | App.vue bottom bar → tap handle + clamp | `App.vue` | t1,t2 | complex |
| t7 | Verify MapStatsBar/DateRangeControl ≤375px | `components/MapStatsBar.vue`, `DateRangeControl.vue` | t5 | simple |

**Order:** t1 + t2 + t6 → t3 / t4 / t5 → t7. Implemented sequentially in the run worktree (disjoint files, shared new modules, single reviewer pass) rather than per-node worktree fan-out.

**Blast radius:** none (no migrations/billing/auth/CH). **Out of scope:** DriverSelector + adminMode branches, Day/Trip FloatingStatCard, BottomSheet behavior.

**Clamp convention:** `w-[280px]` → `w-[min(280px,calc(100vw-2.5rem))]`; `min-width:380px` → `min-width:min(380px,calc(100vw-2rem))`.
