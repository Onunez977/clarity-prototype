# Plan — DEV-2595

## Outcome
Admin org-map Live Hits Pending Repossession returns the full date-range window (not a newest-20 slice that collapses to today).

## Subtasks
### t1 — Remove BirdsEye `recentHits` limit(20) + regression test
- **Approach:** Match driver (`95da4d7f`). One-line removal + comment. Test seeds >20 today open hits + older hits; `range=last_7` must include a pre-today row; smoke `last_15`/`last_30`.
- **Files:** `ClarityScoutBirdsEyeController.php`, `ClarityScoutBirdsEyeControllerTest.php`
- **Complexity:** simple · **UI:** no (API payload only; existing scroll list)
- **Blast radius:** none

## Excluded
- Map `MAX_MAP_HITS` caps (separate surface)
- Driver `recentHits` (already fixed)

## Perf
Not required — not a deferred >1B-row CH redesign.
