# DEV-2058 — Ultra-Plan

Scout hit modal: stop obscuring the marker (reuse BottomSheet) + show vehicle info labeled by hit type. CH-only (hits_cold); `vehicle_color` deferred. Gate: CLEAN.

## Subtask DAG

Two independent subtasks — disjoint files, fully parallelizable (no `depends_on`).

```
t1 (backend + its test)        t2 (frontend Vue x2)
        |                              |
        +--------- parallel -----------+
```

### t1 — Backend (controller + test)
- **Files:** `app/Http/Controllers/Api/ClarityScoutTripsController.php`, `tests/Feature/Http/Controllers/Api/ClarityScoutTripsControllerTest.php`
- Expand `day()` (~L295) and `show()` (~L446) hits_cold `.select([...])` to add `vehicle_year`, `vehicle_make`, `vehicle_model`, `hotlist_us_state`; map them into `hits_detail` (~L350-361 / ~L461-469), nullable-safe.
- Bump cache key (`scanner:day:{orgId}:{kitId}:{date}` + `scanner:trip`) with a `:v2` suffix so stale cached payloads don't omit the new fields.
- No migration — fields already exist in `clarity.hits_cold`.
- Extend `insertHitCold()`/`insertHitHot()` helpers to populate the four columns; assert `hits_detail` items carry them + `hit_type`.
- **Test filter:** `ClarityScoutTripsControllerTest`

### t2 — Frontend (DayOverlay.vue + TripOverlay.vue)
- **Files:** `resources/js/clarity-scout/components/DayOverlay.vue`, `resources/js/clarity-scout/components/TripOverlay.vue`
- Replace the `fixed inset-0 z-50 bg-black/70` hit lightbox (DayOverlay L242-283, TripOverlay L156-197) with the already-imported `BottomSheet` (same as the "Route Overview" sheet at DayOverlay:103 — bottom-28/z-30 above the bottom-nav pill). This resolves AC-1 by construction: the tapped marker stays visible.
- Inside the sheet: keep plate/time/images; add a `hitTypeLabel` badge (copy MapShell.vue L118-129 computed: L→'Direct Live', S→'Staging Live') and a vehicle info row (`vehicle_year` + `vehicle_make` + `vehicle_model` + `hotlist_us_state`), each `v-if`-guarded for nullable values. `vehicle_color` DEFERRED.
- Add BottomSheet import to TripOverlay.vue if missing.
- **Test filter:** none (UI). `ui_impact: true` — run `npm run build` after.

## Notes
- **Parallelizable:** target_files are disjoint (backend PHP vs frontend Vue). The controller test lives with the controller change in t1 (it depends on that change), so no cross-subtask `depends_on`.
- **Blast radius:** none — no migrations, billing, auth, or schema changes.
