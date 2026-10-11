<!-- ticket-status -->
**Ticket:** [DEV-2278](https://insightlpr-team.atlassian.net/browse/DEV-2278)
**Phase:** done · **Route:** bug · **Mode:** normal
**Done:** intake · prep · gate · plan · provision · implement (68 tests) · review (3×100) · verify (agent-QA PASS) · human-QA (passed)
**Next:** —
**Open questions:** none
**Resolved questions:**
1. Direct Orders → clip to assigned zone(s) when the driver has ≥1 zone; org-wide (run-area) when none · human:Dalton 2026-08-03
2. Staging Orders → unchanged (zone-clipped, empty when no zones) · human:Dalton 2026-08-03
3. Help copy → HelpPanel.vue updated to describe the conditional Direct behavior · human:Dalton 2026-08-03
**Artifacts:** docs/investigations/DEV-2278/
<!-- /ticket-status -->

---

## Summary

**Bug:** Scout Direct Orders and Staging Orders map layers rendered blank for a Scout driver with no assigned zones (e.g. an admin-impersonated / force-login demo kit). Reported by Oscar Nunez during a live demo.

**Root cause:** `ClarityScoutMapController::heatmapOrders()` ran **both** order layers through `clipRowsToAssignedZones()`, which returns empty when the driver has zero assigned zones. Live Hit Density (opt-in zone clip → org-wide fallback) and Teammate Activity (force-login hard-codes it on) were unaffected, which is why only the two order layers went blank. The adminMode / HTML-not-JSON fetch-leak hypothesis was investigated and ruled out (the driver heatmap endpoints live in `routes/api.php` under `auth:sanctum` and return 200 JSON).

**Fix (product decision by Dalton):** Direct Orders now clips to the driver's assigned zone(s) when they have ≥1 zone, and renders org-wide (bounded by run-area + viewport bbox) when they have none. Staging Orders is unchanged (always zone-clipped — the deliberate AC#4 nudge). The in-app HelpPanel copy was updated to match, and assets rebuilt.

**Files:** `app/Http/Controllers/Api/ClarityScoutMapController.php` (conditional clip + docblocks), `tests/Feature/Http/Controllers/Api/ClarityScoutMapControllerTest.php` (inverted the zero-zone Direct test; kept the with-zone clip test), `resources/js/clarity-scout/components/HelpPanel.vue` (copy) + rebuilt `public/build` assets.

**Verification:** TDD red→green; 68 ClarityScoutMapController tests green; 3 independent adversarial review lanes (100/100, no scoring defects); agent-QA PASS (backend against real ClickHouse + browser via the exact repro); human-QA passed.
