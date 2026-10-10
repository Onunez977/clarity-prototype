# DEV-2325 — Implementation Plan

**Bug:** `SendEndOfShiftSummary` exhausts retries on a Postgres `SQLSTATE[57014]` statement timeout while building a closed shift's summary (prod, shift 22). Route: bug · base: `main`.

**Root cause (verified):** `BuildShiftSummary::buildZoneSummaries()` runs a raw `public.scans ⋈ platform.zones ON ST_Contains(z.geom, s.geo)` join whose only join-condition is the spatial predicate — `kit_id`/`scanned_at`/`org_id` are WHERE filters. A zones/geo-GiST-driven plan can evaluate `ST_Contains` across an unbounded slice of the giant partitioned `scans` table before the filters bind. It runs on the **`platform` write** node because `SendEndOfShiftSummary::handle()` wraps the read in a `DB::connection('platform')->transaction()`, and Laravel routes in-transaction reads to the write PDO (`Connection.php:1280`). Not the DEV-2309 `ScanTimezoneScope` bug (that's Eloquent on `zod`; this is raw SQL that never touches the Scan model).

## Decisions (Dalton, gate)
- **Fix approach:** query-shape `MATERIALIZED` CTE, kept on the current (`platform`) connection. **No** `zod` re-route (avoids a replica-lag undercount of the freshest scans).
- **AC-3:** relabel only — record a build/timeout-specific `summary_skipped_reason` distinct from `mail_failed`; keep `Log::error` (Nightwatch-ingested); **no** new alert channel.

## Subtask t1 (single node → fast path, no worktree) · complexity: complex
Files: `app/Services/Scout/BuildShiftSummary.php`, `app/Jobs/Scout/SendEndOfShiftSummary.php`, `tests/Feature/Services/Scout/BuildShiftSummaryTest.php`, `tests/Feature/Jobs/Scout/SendEndOfShiftSummaryTest.php`

1. **Query fix** — rewrite `buildZoneSummaries()` (BuildShiftSummary.php:178-193):
   ```sql
   WITH shift_scans AS MATERIALIZED (
     SELECT id, scanned_at, geo FROM public.scans
     WHERE kit_id = ? AND scanned_at >= ? AND scanned_at <= ?
   )
   SELECT s.id AS scan_id, s.scanned_at AS scanned_at,
          z.id AS zone_id, z.name AS zone_name, z.color AS zone_color
   FROM shift_scans s
   JOIN platform.zones z ON public.ST_Contains(z.geom, s.geo)
   WHERE z.org_id = ? AND z.deleted_at IS NULL
   ORDER BY s.scanned_at, z.id
   ```
   Bindings `[kit_id, startedAt, endedAt, org_id]`. `MATERIALIZED` fences the small kit+window scan set (via the `(kit_id, scanned_at)` btree + partition pruning) so the spatial join runs over ~tens of rows. **Preserve** columns, `ORDER BY`, and the intentional no-`is_visible` filtering. Keep `DB::connection('platform')`. Optional `z.geom && s.geo` bbox pre-filter at implementer discretion.

2. **AC-3 relabel** — `SendEndOfShiftSummary::failed()` (125-142): `$reason = $exception instanceof QueryException ? 'build_failed' : 'mail_failed'`. Keep `Log::error`.

3. **Tests (TDD):**
   - `BuildShiftSummaryTest`: seed `public.scans` via `DB::connection('platform')->table('scans')->insert(...)` (dodges the documented cross-connection test-isolation gap), Zone with `rebuildGeometryFromDefinition()`; assert per-zone counts correct + emitted SQL carries the CTE fence (`enableQueryLog` on `platform`). Freeze `now()` near the fixture.
   - `SendEndOfShiftSummaryTest`: terminal `QueryException` → `build_failed`; genuine mail failure → `mail_failed`.

Test filter: `BuildShiftSummaryTest|SendEndOfShiftSummaryTest`.

## Explicitly out of scope (excluded_sites)
- `ClientSearchScansResource.php:92` — same-family latent risk (no prod evidence) → **follow-up ticket**.
- `handle()` lock/transaction scope narrowing — secondary risk, needs a claim/CAS for idempotency → optional follow-up.
- `loadZonePolygonsForMap()`, `Hit::whereIn` — bounded, not the cause.
- DEV-2309-fixed sites — untouched.

## Verification notes
- **AC-1** effectiveness at prod scale is not reproducible locally (dev DB has 0 rows in `public.scans`/`platform.zones`); per the AC's own wording it's verified post-deploy in `laravel.log`. The CTE bounding is high-confidence from first principles.
- **"Why now" (infra, FYI to reporter):** if prod `pg_partman` maintenance stalled, a bloated `scans` DEFAULT partition would independently degrade pruning — worth a DBA glance; the code fix helps regardless.

**Perf gate:** N/A (Postgres surface, not a >1B-row ClickHouse table). **Sizing:** 4 files, one ticket, no split.
