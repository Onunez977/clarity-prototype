# DEV-2594 plan

## Goal
Every Live Hits Pending Repossession row and every main-map logo marker opens Hit Details, including the newest hit, on desktop and compact.

## Root cause (working)
1. **Top-of-stack chrome** — Live Hits sits under/beside `ScoutControlsWidget` (z-25 vs z-20); first row is the one that loses clicks when height/offset drifts.
2. **Weak open feedback** — `focusHit` still selects when `lat/lng` are null (no pan). On compact, the expanded Live Hits panel stays open and can hide the sheet; older hits with coords pan the map so the tap “feels” successful.
3. **Both entry paths** — list uses `focusHit`; markers use `setSelectedHit`. Fix selection-side effects for both.

## Subtasks
- **t1** (complex) — overlay/sheet stacking + collapse-on-select + stable keys + controls height republish.

## Out of scope
Day/Trip overlay local sheets; CH query/disposition changes unless payload is proven wrong.
