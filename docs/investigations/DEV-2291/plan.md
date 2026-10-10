# DEV-2291 — Implementation Plan

**Bug:** Scout "Error while loading page" when assigning a kit user to a zone.
**Root cause:** `ZoneAssignmentsChanged` / `ZoneUpdated` are `ShouldBroadcastNow`, dispatched synchronously inside `ManageZones::save()`'s `DB::transaction()`. A Pusher/Reverb delivery failure (anything the vendor `PusherBroadcaster` doesn't itself swallow) propagates uncaught → the whole save rolls back → Filament renders its generic error toast. First-assignment-only because the broadcaster is a no-op for a zero-driver zone.

**Fix (Dalton-approved):** add Laravel's native `Illuminate\Contracts\Broadcasting\ShouldRescue` marker to both events, keeping `ShouldBroadcastNow`. `BroadcastManager::queue()` then wraps each synchronous dispatch in `rescue()` (report + swallow). Silent + logged — no admin-facing warning.

## Subtask DAG (1 node — single-node fast path)

### t1 — ShouldRescue on the two Scout zone broadcast events · complexity: complex · no blast radius
- **Files:** `app/Events/Scout/ZoneAssignmentsChanged.php`, `app/Events/Scout/ZoneUpdated.php`, `tests/Filament/Pages/ManageZonesTest.php`, `tests/Feature/Events/ZoneAssignmentsChangedTest.php`
- **Change:** `implements ShouldBroadcastNow, ShouldRescue` on both events (+ import).
- **TDD:**
  1. Failing test: force a *throwing* broadcaster (not `Event::fake()`) while assigning a driver via `ManageZones`; assert the zone + `zone_user` row persist and `save()` surfaces no exception; assert the failure is `report()`'d (observable).
  2. Add the markers → green.
  3. Regression guard: assert both events `implements ShouldRescue`.
  4. Confirm `test_save_rolls_back_and_does_not_broadcast_when_geometry_rebuild_fails` still passes — genuine in-transaction data errors must still roll back.
- **test_filter:** `Zone` (covers ManageZones, ZoneAssignmentsChanged, ZoneUpdated, ZoneResource, ZoneActivityLog, ZoneListBroadcasts). Full suite runs post-merge.

## Gates
- **Sizing:** 1 node / 4 files / single concern → no split.
- **Blast-radius:** none (touches `app/Events/Scout/**` + tests; no migrations/billing/auth/CH-purge globs).
- **CH-at-scale perf:** N/A — no ClickHouse surface touched (`perf_validation.required=false`).
- **Coverage:** all 8 buggy call sites from the exhaustiveness sweep are covered transitively by the event-level change; `excluded_sites=[]`.
