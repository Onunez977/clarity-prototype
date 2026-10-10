# DEV-2278 — Plan

**Route:** bug · **Base:** main · **Product decision:** Direct Orders gets a zero-zone fallback; Staging unchanged; Help copy updated.

## Root cause
`ClarityScoutMapController::heatmapOrders()` runs both order layers through `clipRowsToAssignedZones()`, which returns `[]` when the driver has no assigned zones. Force-Login/impersonated demo kits have no zone → both order layers render blank.

## Fix (single subtask, single-node fast path)

**t1 — Direct Orders zero-zone fallback (+ tests + Help copy)** · complexity: complex · test_filter: `ClarityScoutMapControllerTest` · ui_impact: yes · blast_radius: none

1. **Controller** (`app/Http/Controllers/Api/ClarityScoutMapController.php`)
   - Precompute `$hasAssignedZones = $user->zones()->exists();` once before the layer loop (~:449).
   - Gate the Direct clip: `if ($staging || $hasAssignedZones) { $rows = $this->clipRowsToAssignedZones($rows, $user); }` (:452).
   - Result: Staging always zone-clipped (unchanged); Direct clipped when the driver has ≥1 zone, org-wide (run-area/bbox) when zero.
   - Update docblocks (:417-419 heatmapOrders, :624-628 clipRowsToAssignedZones).

2. **Tests** (`tests/Feature/Http/Controllers/Api/ClarityScoutMapControllerTest.php`) — TDD, red→green
   - Invert `test_heatmap_orders_direct_is_empty_without_assigned_zones` (:1154) → assert the seeded in-run-area Direct cell **renders** with no zones assigned (rename to `..._direct_renders_org_wide_without_assigned_zones`).
   - Fix the stale comment at :1104 ("Direct ignores zones either way").
   - Leave unchanged: `test_heatmap_orders_direct_clips_to_assigned_zones` (:1130), all staging tests, plate-length tests — still correct.

3. **Help copy** (`resources/js/clarity-scout/components/HelpPanel.vue`)
   - Reword :158 and :346: Direct = clipped to your zone(s) when you have any, org-wide when you have none; Staging always clipped; Live Hit Density always org-wide.
   - `npm run build`, revert boost-doc regen (CLAUDE.md / AGENTS.md / .junie/guidelines.md), commit rebuilt `public/build` assets.

## Acceptance criteria
- Direct Orders renders for a zone-less impersonated driver (org-wide); still clips to zone(s) when the driver has any.
- Staging Orders unchanged (zone-clipped; empty when no zones).
- Live Hit Density + Teammate Activity unaffected.
- HelpPanel copy accurate; assets rebuilt & committed.

## Excluded
- `ClarityScoutBirdsEyeController::heatmapOrders` (run-area clip, not per-user zones — not implicated; test-coverage gap is a separate follow-up).
- `heatmapHits` (already opt-in clip / org-wide).

## Sizing
3 files, one concern. Under the 6-file threshold; no split. No blast-radius approval node. No perf gate (query unchanged, bounded by run-area+bbox).
