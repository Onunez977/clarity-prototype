# DEV-2309 — Implementation Plan

**Route:** bug · **Base:** main · **Nodes:** 1 (single-node fast path)

## t1 — Bypass ScanTimezoneScope on the activity aggregate + pinning test  *(simple)*

**Files:**
- `app/Console/Commands/CloseInactiveShiftsCommand.php`
- `tests/Feature/Console/Commands/CloseInactiveShiftsCommandTest.php`

**Change:** In `latestActivityFor()`, the `max('scanned_at')` aggregate over `Scan::query()` inherits the model's `ScanTimezoneScope` global scope, which injects `LEFT JOIN timezones ON ST_Within(scans.geo, timezones.geom)`. Add `->withoutGlobalScope(ScanTimezoneScope::class)` (importing the scope class) to drop that spatial join. Retain `ShowVisibleScan` (`is_visible = true`) — the ticket's suggested covering index `(kit_id, is_visible, scanned_at)` keeps `is_visible`, and retaining it preserves pre-bug behavior.

**Test (TDD, red→green):** `latest_activity_lookup_does_not_join_timezones()` — enable the `zod` query log, seed an open shift (>3h old) + a stale **visible** scan (~4h ago), run `scout:close-inactive-shifts`, assert (a) no executed query contains `timezones` or `ST_Within`, and (b) the shift is closed (`end_type=Assumed`). Fails before the fix (join present), passes after.

**Not touched:** the ClickHouse GPS path (`latestGpsFor` / `clarity.sc_positions`) is unchanged — CH not required for this node's test.

## Out of scope
- The `(kit_id, is_visible, scanned_at)` index on `scans` — `scans` is in the `zod` DB (separate migrations package), and the ticket frames the index as a post-deploy "confirm"/contingency. The spatial join is the timeout cause.

## Gates
- Sizing: 2 files, one concern → no split.
- Blast-radius: none (no migration/billing/auth/CH-purge globs).
- Perf (CH-at-scale): N/A — the change eliminates a Postgres spatial-join cost; it does not defer perf on a >1B-row ClickHouse surface.
