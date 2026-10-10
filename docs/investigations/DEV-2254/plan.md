# DEV-2254 — Plan

**Route:** bug · **Base:** `main` · **Branch:** `claude/DEV-2254` · **PR:** #382

## What's actually wrong

The admin org map (Bird's Eye) can be in **three** scope states. Only one is labelled today.

| State | Condition | Label today |
| --- | --- | --- |
| aggregate | nothing followed, nothing selected | **none** |
| subset | a Driver Selector subset, nothing followed | **none** |
| followed | a driver followed | `Showing only {name}'s stats` (crimson pill, `App.vue:90-96`) |

On top of that, two pieces of copy assert a scope they don't actually know:

- `MapStatsBar.vue:170,180,187,198` — tile tooltips say `your kit read` / `Plates you scanned` unconditionally, including for an admin reading a multi-driver total.
- `DashboardOverlay.vue:85-88` — the Live Hits header says `Org-wide hits...` whenever admin mode is on, **even while a driver is followed** and the list is actually filtered to them. Same bug, opposite direction.

The backend is correct and untouched: `ClarityScoutBirdsEyeController::stats()` sums over `scopedKits()`, which returns all org Scout kits when no `kit_ids` filter is sent. Confirmed independently by all three prep passes.

## Design

All three surfaces need the same answer to *"whose numbers are these?"*. Deriving it three times is exactly how two surfaces drift apart — the bug class this ticket is about. So derive it **once**.

`stores/map.js` gets a `statsScope` getter — it already owns `drivers`, `driverPositions` and `followedDriverKitId`, and reads `followedKitId` / `selectedKitIds` from `lib/adminScope.js`. The getter stays out of `lib/adminScope.js` on purpose: that module's own header says it exists to avoid a `fetch.js` ↔ map-store import cycle.

```
statsScope -> { state: 'aggregate' | 'subset' | 'followed',
                driverName, selectedCount, totalCount }
```

Every surface then reads that one getter.

## Label contract (settled at the Phase 2 gate)

| State | Pill text | Style |
| --- | --- | --- |
| aggregate | `All Drivers` | neutral grey |
| subset | `Showing N of M drivers` | neutral grey |
| followed | `Showing only {name}'s stats` | **existing crimson — unchanged** |

Driver (non-admin) surface: **no pill, no copy change.** Every edit is `adminMode`-gated. This is the regression risk the Scout knowledge card explicitly warns about — driver and admin share `App.vue`, `MapStatsBar.vue` and `DashboardOverlay.vue`.

## Tooltip wording — a deliberate refinement

In admin mode the tile tooltips **drop the ownership clause** rather than swapping `your kit` for `all drivers`.

Saying "all drivers" would be false whenever a subset is selected — re-creating this exact bug one layer down. The pill directly above already states *whose*; the tooltip only needs to say *what*. Within the approved "fix the copy" decision, flagged for sign-off at QA.

## Subtask DAG

Single node `t1` (complexity: **complex** — 4 files, two concerns). No fan-out: the surfaces share the getter, so splitting them across worktrees would duplicate it or serialise anyway.

**Blast radius:** none. No migrations, billing, auth/Shield, or ClickHouse purge paths touched.

## Verification

- `npm run build` — the SPA compiles, and `public/build` is **tracked** (15 files), so the rebuilt assets must be committed or the fix never reaches the served app.
- `php artisan test --compact --filter=ClarityScoutBirdsEyeControllerTest` — the untouched backend is still green.
- **No JS/Vue test runner exists in this repo.** Nothing pins the rendered label automatically; agent + human QA across the three scope states, plus a driver-surface regression check, is the real gate.

## Excluded sites (from the exhaustiveness sweep)

`HelpPanel.vue` (deep-link-only in admin, static glossary) · `ProfilePanel.vue` (unreachable in admin) · `FloatingStatCard`/`DayOverlay`/`TripOverlay` (trips disabled in admin) · `ScannerMap.vue` heatmap legends (genuinely org-wide, selector-independent) · `DashboardOverlay.vue:246-247` (a layer-visibility setting, not a stat) · `ClarityScoutBirdsEyeController` (correct as-is) · `ScannerMap.vue` follow/unfollow handlers (exercised in QA, not edited).
