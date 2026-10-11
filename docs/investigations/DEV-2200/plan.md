# DEV-2200 — Implementation Plan

**Route:** bug · **Base:** main · **Sizing:** 2 core files + 2 test files, single concern → no split.

## Root cause (verified vs current main)
The Scout "Live Hits Pending Repossession" card feed (`recentHits()`) omits `vehicle_year`,
`vehicle_make`, `vehicle_model`, `hotlist_us_state` from its `->map()` projection, while the
map-pin feed (`hits()`) includes them. Both read the same denormalized ClickHouse hit row via an
unrestricted `->get()`, so the columns are already loaded on `$hit`; `MapShell.vue` hides the
vehicle row when the keys are absent. Fix = additive projection only.

## Subtask t1 (single-node fast path, on the ticket branch)
Add the 4 fields to both `recentHits()` `->map()` blocks, mirroring `ClarityScoutMapController::hits()`
(L262-265) verbatim:

```php
'vehicle_year' => $hit->vehicle_year,
'vehicle_make' => $hit->vehicle_make,
'vehicle_model' => $hit->vehicle_model,
'hotlist_us_state' => $hit->hotlist_us_state,
```

- `app/Http/Controllers/Api/ClarityScoutDashboardController.php` — driver, after `'lng'` (~L149)
- `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php` — admin, after `'lng'` (~L582)

**Tests (TDD, red→green):**
- Dashboard: mirror `test_recent_hits_includes_lat_lng` (~L562) → assert the 4 vehicle/state keys
  are present in the `recentHits` payload (seed a hit with non-null values).
- Bird's-Eye: add the equivalent key-list assertion (no existing projection-key coverage).
- Filter: `recent_hits` (covers both classes' recent-hits tests + the new assertions). Both classes
  are `RequiresClickhouse`-gated → Phase 4 must bring CH up (`QA_PROVISION_CH=yes`).

**No change:** `hits()` methods (reference), Vue, ClickHouse queries, built assets.

## Gates
- **Blast radius:** none (no migrations/billing/auth/CH-purge). No approval line required.
- **Perf (CH-at-scale):** not required — no query change; the `->get()` already runs and the added
  keys are in-memory only.
- **Staging-only note:** rollout instruction (QA: Yolanda), not a code gate — carry to handoff.
