# DEV-2183 — Plan

**Route:** bug · **Base:** main · **Sizing:** 3 files, 1 concern → single subtask (no split).

## t1 — Parameterize the order-query plate guard; Scout order layers use `>= 3`

**Files:**
- `app/Clickhouse/Queries/HotlistAddressGeohashQuery.php` — add `min_plate_length` arg (default `4`), thread into the raw query, change guard to `>= {$minPlateLength}`.
- `app/Http/Controllers/Api/ClarityScoutMapController.php` — `orderRows()` passes `'min_plate_length' => 3`.
- `app/Http/Controllers/Api/Concerns/ScoutMapHelpers.php` — `orderRows()` passes `'min_plate_length' => 3`.
- `tests/Feature/Clickhouse/HotlistAddressGeohashQueryTest.php` — TDD (new or extended).

**Why default 4:** `length(plate) > 3` ≡ `length(plate) >= 4` for integer lengths, so a default of 4 preserves the exact current behavior for the untouched caller (`KitUserPathController`) while Scout callers opt into `3` (`>= 3`).

**Edge cases pinned by tests:** plate length 2 (excluded always), 3 (excluded at default, included at min=3), 4 (always included); both `staging=false` and `staging=true`.

**Out of scope (excluded_sites):** KitUserPathController (kept `> 3`), ScoutCheckInRollupQuery, ClarityScoutDashboardController, Hotlist model scopes.

**Follow-up flagged:** the check-in rollup + dashboard-controller queries now diverge from the order map (`> 3` vs `>= 3`); surfaced for a later ticket, not fixed here.
