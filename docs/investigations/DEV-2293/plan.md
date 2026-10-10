# DEV-2293 — Implementation Plan

**Scout Org Map: filter driver picker to drivers active in the selected time range**
Route: feature · Base: main · Mode: normal · Single node (6 files, one cohesive concern)

## Design (as approved at the gate)
The driver picker (`DriverSelector.vue`, admin-only via `mapStore.adminMode`) is served by
`GET /scout/org-map/api/drivers` → `ClarityScoutBirdsEyeController::drivers()`, which today is
**Postgres-only** (Kit + KitSettings) and ignores the selected date range.

We make it range-aware by **annotating** the roster, not shrinking it:

- Backend computes the set of `kit_id`s that scanned in the selected window (ClickHouse, bounded),
  and returns the **full** org roster with a new `active_in_range` boolean per entry.
- Frontend keeps `store.drivers` as the full roster (so `App.vue`'s `followedDriverName` /
  `noKitsReporting` and the nameplate toggle are all untouched — AC-4), and `DriverSelector`:
  - shows only `active_in_range` entries by default (AC-1),
  - reveals inactive matches **with a note** when the admin searches (Q1: surface-with-a-note),
  - keeps a selected/followed driver resolvable from the full roster (Q2: keep silently),
  - shows a true empty-state when zero drivers are active (AC-3).
- The list refetches on **range change** via the existing `ScoutHome.vue` `dashboard.range`
  watcher (AC-2); `fetchDrivers` stays out of the ~10s live poll (Q3).

## Subtask t1 — complexity: complex · parallelizable: false · blast_radius: none
`test_filter: ClarityScoutBirdsEyeControllerTest` · ui_impact: true

**Backend** — `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`
- `drivers()`: read + whitelist a `range` param (same ladder + fallback-to-`today` as `scanPaths()`),
  resolve `[start,end]` via `getDateRange($range, $user->timezone)`.
- New private `activeKitIdsForRange($orgId, $kitIds, $start, $end): array` — reuse the
  `stats()` pattern (`ClickhouseScan::forWindow($start)->final()->forOrg($orgId)`,
  `whereIntegerInRaw('kit_id', $kitIds)`, `whereBetween('scanned_at', [...])`, `distinct pluck`).
- Annotate each mapped entry with `active_in_range`; return the **full** roster.
- `Cache::remember` the active-set lookup keyed by org+range at `mapCacheTtl($range)`.
- Update the class/method doc-comment that currently calls `drivers()` "Postgres-only".

**Frontend**
- `stores/map.js` `fetchDrivers(range = null)` → `buildUrl('/drivers', range ? { range } : {})`.
- `composables/useBirdsEyePolling.js` initial call → `fetchDrivers(dashboard.range)` (not in the poll).
- `views/ScoutHome.vue` `dashboard.range` watcher → `if (mapStore.adminMode) mapStore.fetchDrivers(newRange)`.
- `components/DriverSelector.vue` → default visible = active; search reveals inactive-with-note;
  selected chips resolve from full roster; distinct true-empty-state string.

**Tests** — `tests/Feature/Http/Controllers/Api/ClarityScoutBirdsEyeControllerTest.php`
- AC-1: kit with an in-range `scans_hot` row is `active_in_range:true`; one without is `false`.
- AC-1/AC-2: a 40-day-old scan is inactive at `range=today`, active at `range=last_60`.
- AC-3: org with zero active drivers still returns the roster with all `active_in_range:false`
  (backend contract for the empty-state).
- Update the 3 existing `drivers()` tests + the "Postgres-only" doc-comment; anchor all fixture
  scan timestamps to `now()`-relative offsets (scans_hot 90-day TTL).

## Excluded sites
`orgScoutKits()`/`scopedKits()` (range-agnostic, shared) · `ClarityScoutMapController` (driver-side) ·
`ScoutUsersResource:516` (doc ref) · `App.vue` consumers (safe under annotate) · nameplate toggle (AC-4).

## Gates
- No blast-radius (read controller; no migrations/billing/auth/CH-purge).
- Perf gate NOT required — the CH read reuses the proven bounded `stats()` query at the same ≤90-day
  range ladder already running in production; not a deferred-perf design.
