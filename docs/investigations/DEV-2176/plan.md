# DEV-2176 — Implementation Plan (Approach B: restore dual-write)

**History:** Approach A (repoint disposition reads to the CDC mirror) was implemented and 3-lane-reviewed; the review + live-prod measurement showed it carried a ~3.7s/query perf regression on a 3.5B-row table, was incomplete (2 raw-SQL heatmap query classes still on the flat column), and had tombstone/empty-string edges. Dalton pivoted (2026-07-09) to the simpler, uniform fix.

## Approach B (shipped)

Re-add the ClickHouse sync dispatch removed on 2026-04-14 (commit `1b241ae1`, "rely on PG entirely") so a disposition write lands in **both** Postgres and the flat ClickHouse `disposition` columns again. Every read path (MO/Hits/Scans list selects, Scout `validDisposition`/`liveValid` scopes, and the `HitDirectHotspotQuery`/`HitDensityGeohashQuery` heatmaps) reads the flat column, so all surfaces become correct with **zero read-side changes** and no read perf cost.

### Change (2 files, +52/-0)
- `app/Models/Scan.php` — restore `use App\Jobs\SyncDispositionToClickhouse;`, the `$this->syncDispositionToClickhouse($disposition);` call inside `updateDisposition()` (after the Postgres meta write), and the private `syncDispositionToClickhouse()` helper (`SyncDispositionToClickhouse::dispatch($this->id, $disposition)`). Faithful revert of the three hunks 1b241ae1 removed.
- `tests/Feature/Http/Controllers/DispositionControllerTest.php` — two `Queue::fake()` regression tests: (1) POST /scan/disposition dispatches the sync with the right scan_id+disposition; (2) `Scan::updateDisposition()` dispatches for every caller (covers both the individual DispositionController path and the bulk BulkDispositionAction path, which share the method).

### Why this covers everything
Both write entrypoints (DispositionController + BulkDispositionAction) funnel through `Scan::updateDisposition()`. The job's ALTER…UPDATE targets all five wide tables (missed_opportunities/hits_hot/hits_cold/scans_hot/scans_cold). All read surfaces read those columns. So one restored dispatch fixes MO, Hits, Scans, and Scout uniformly.

### Decisions
- **No backfill** (Dalton): the flat column becomes correct going forward only; hits dispositioned during the 2026-04-14→deploy outage stay "Awaiting Review" until re-dispositioned. Accepted.
- Async in prod (Horizon/redis): the dispatch is non-blocking, so a disposition write never fails on a CH hiccup; the job retries (tries=3) and fails to failed_jobs if CH is down — PG write already succeeded.

### Out of scope
- Deprecating the flat column / repointing reads to CDC (Approach A — rejected as too costly).
- The dead-code cleanup of `SyncDispositionToClickhouse` (no longer dead — it's dispatched again).

### Local test note
The job hardcodes `clarity.` / the write connection; local Docker CH uses suffixed DBs, so literal `clarity`/`zod` databases were created in the local container (per the known hardcoded-prefix local-CH limitation) to run the tests. CI/prod use literal `clarity`/`zod`, so the job resolves natively there.
