# DEV-2116 — Ultra-Plan (subtask DAG)

## Sizing gate

This spans ClickHouse queries, a new admin API surface, a new Vue SPA entry, multi-driver map
rendering, and Filament nav — **>6 files and multiple distinct concerns**, so the Phase 3 sizing
gate flags it. **The user explicitly accepted a single-ticket build at plan approval** (the approved
plan's "Size / sequencing note" said it proceeds as one build unless a split is requested; no split
was requested). Recorded here as the override; proceeding as one ticket on `scanner-app`.

## DAG (execution order)

```
t1 OrgLivePositionsQuery ─┐
t2 OrgLivePaths          ─┴─> t3 Admin API (controller+routes+stats+gating) ─> t4 Filament nav
                                                                            └─> t5 SPA shell ─> t6 BirdsEyeHome+store+DriverSelector ─> t7 ScannerMap multi-driver
```

- **t1, t2** are parallelizable (disjoint new files). The rest is serial — the frontend nodes share
  state/contracts and t7 mutates the shared `ScannerMap.vue`, so they run sequentially in `REPO_PATH`
  (no worktree fan-out for the coupled frontend).
- **Blast-radius:** t3 carries `auth/gating` + `routes` tags. The gating approach
  (`Feature::active(CLARITY_SCOUT) && hasRole(ADMIN)`, org-scoped) and the new admin API surface were
  **explicitly decided and approved by Dalton this session** — that approval is the required
  human-approval line for the t3 blast-radius node. Phase 5 will run **3 review lanes** (blast-radius +
  semantic-correctness territory: org scoping / aggregation).

## Key design choices baked in

- Org-wide **stats** live in the new `ClarityScoutBirdsEyeController` (drop the `kit_id` filter,
  keep org scope) — the shared driver `ClarityScoutDashboardController::stats()` is **not** edited.
- Prefer a **new `birdsEye` Pinia store** over editing shared `map.js`/`dashboard.js`, and guard new
  `ScannerMap.vue` paths behind a birds-eye mode flag — so the driver SPA behavior is untouched.
- Reuse as-is: `KitScanPathsQuery` (already per-kit, org-wide, per-kit color), `HitDirectHotspotQuery`,
  `HotlistAddressGeohashQuery`, `HistoricPathBuilder`, `Kit::getKits()`.
- Routes registered **above** `scout/{any?}` (web.php:110); admin endpoints use the session cookie.

## Tests (pinning)

- `BirdsEyeAccessTest` — gating (non-admin / flag-off denied; admin+flag 200).
- `BirdsEyeOrgScopeTest` — org-wide scoping + selector kit_ids + cross-org isolation.
- `OrgLivePositionsQueryTest` / `OrgLivePathsQueryTest` — query shapes.
