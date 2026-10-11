# DEV-2137 — Implementation Plan

**Route:** feature · **Base:** main · **Sizing:** 4 files, one cohesive surface → single node (no split, no fan-out).

## Subtask t1 (complex, single-node fast path)
Restore the **Live Hits Pending Repossession** list in the Scout Admin/Org Map view, correct the existing `recentHits` endpoint, and wire the date filter.

### Backend — `ClarityScoutBirdsEyeController@recentHits` (494–535)
Rewrite to mirror the sibling `hits()` (258–339) — the established correct pattern on the same controller:
- **Include Staging Live**: `forHitTypes([Live, Staging_Live])->validDisposition()` (was Direct-only `liveValid()`).
- **Current status** (Dalton, Q4): resolve open hits via the **FINAL semi-join** against `clarity.hotlist` — pluck distinct `hotlist_id`s, filter to those whose current `status` is `Open`, then fetch. Drops already-repossessed vehicles; agrees with the map pins. (Closes the DEV-2117 gap for this surface.)
- **Range param** (AC 5): `?range` (default `today`) via `getDateRange()` + `forCreatedBetween($start,$end)`.
- Preserve payload shape, `limitBy(1,'ocr')->limit(20)` plate-dedup, and the try/catch.

### Frontend store — `stores/dashboard.js` (27–54)
- `fetchRecentHits(range)` accepts a range and passes it to `buildUrl('/dashboard/recent-hits', {range})`.
- `setRange()` calls `fetchRecentHits(newRange)` alongside `fetchStats(newRange)` (root cause of AC-5 mismatch — it currently refetches only stats).

### Frontend component — `components/DashboardOverlay.vue` (38, 56, 89)
- Remove only the `!mapStore.adminMode` clause on the collapsed pill (38) and expanded panel (56) so the list renders in admin mode too (driver branch untouched; admin-aware copy at 73–75 already exists).
- Replace `{{ hit.status }}` (89) with the Direct/Staging Live pill reusing MapShell's `HIT_TYPE_LABELS` (`{L:'Direct Live', S:'Staging Live'}`) + `hit_type==='S' ? amber : blue` chip.

### Tests — `ClarityScoutBirdsEyeControllerTest.php`
recentHits(): Direct+Staging included · respects `?range` · scoped by `kit_org_id` + org Scout kits (org-wide) and narrows under `kit_ids` · **current-status**: repossessed-now hit excluded despite frozen `Open`, inverse included.

## Acceptance-criteria coverage
- AC-1/2 (render org-wide, collapsible) → DashboardOverlay guard removal (existing collapse UI reused).
- AC-3 (list label) → line 89 pill swap.
- AC-4 (detail label) → **already satisfied** (MapShell) — regression check only.
- AC-5 (date scoping / moves-together) → range param + `setRange` wiring.
- AC-6 (driver unchanged) → no driver-path edits; guard edits preserve the driver branch.

## Blast radius
None — read-only ClickHouse query change + Vue/JS. No migration/auth/billing/CH-purge. No approval gate required.

## Notes
- No JS test runner (platform) → Vue verified via agent/human QA; PHP feature tests are the automated gate.
- `platform` commits built assets → `npm run build` + commit `public/build`.
- Run `vendor/bin/pint --dirty --format agent` before finalizing.
