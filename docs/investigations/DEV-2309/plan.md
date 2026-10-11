# DEV-2309 — Implementation plan

**Phase 3 (single arbiter).** Human-readable twin of `plan.json`. If the two disagree,
`plan.json` is what the workers execute.

## Shape of the work

One node, `t1`. Two files, one concern: make the every-30-minutes
`scout:close-inactive-shifts` sweep survive a single failing kit, tell us what it did, and
stop asking Postgres for an unbounded `max(scanned_at)` over a ~4.6B-row table.

| | |
| --- | --- |
| Nodes | 1 (`t1`) |
| Complexity | `complex` (touches 2 files — the `>1 file` rule; still a single concern) |
| Test filter | `CloseInactiveShiftsCommandTest` |
| Parallelizable | yes (`depends_on: []`) |
| Blast radius | none |
| Excluded sites | 5 |

## Gates

**Sizing gate — does not fire.** Two files, one concern, well under the >6-file / multiple-concern
threshold. **Do not split this ticket.**

**Blast-radius gate — does not fire.** Nothing here touches `database/migrations/**`, billing jobs
or resources, Shield/Policies/auth config, `Clickhouse*Purge|Cleanup`, or `PruneScans`.
`blast_radius` is `[]`, which is why the node's `complex` rating comes purely from the file count.

**ClickHouse-at-scale perf gate — does not fire. `perf_validation.required = false`.** The
billion-row surface in this ticket (`public.scans`, ~4.6B) is **Postgres**, not ClickHouse. The only
ClickHouse touch is adding a `timestamp_utc >= started_at` lower bound to an existing
`max()` on `clarity.sc_positions` — a consistency change, not a new hot path. No ClickHouse
performance validation is being deferred here, so nothing about CH perf deferral should be
recorded downstream.

## t1 — guard, log, and bound the sweep

### What changes in `app/Console/Commands/CloseInactiveShiftsCommand.php`

1. **Per-shift try/catch around the whole body.** The `try` opens before
   `latestActivityFor()` and closes after `SendEndOfShiftSummary::dispatch()`, so the lookup,
   the close transaction, and the dispatch are all covered. On `\Throwable`: `report($e)`,
   `Log::warning` with `shift_id`/`kit_id`/`error`, increment `$failedCount`, `continue`.
   Today a single timeout aborts every remaining shift, which is why only kit 10224 ever
   appears in the logs.
2. **A failed lookup is not "no activity".** Because the `catch` sits outside the lookup, a
   throw never reaches the `$latestActivity ?? $shift->started_at` fallback. The failing shift
   is left open rather than being falsely closed as `assumed`.
3. **`Log::info` completed line.** `closed`, `skipped`, `failed`, `duration_ms` (measured with
   `microtime(true)`), mirroring `ScoutRecordCheckIns.php:114-121`. This is the observability
   acceptance criterion — the command runs `runInBackground()`, so `$this->info()` output goes
   nowhere. The `$this->info()` summary stays for dry-run and local invocation, extended with
   the failed count.
4. **Both lookups take a `Carbon $since`.** `latestActivityFor(int $kitId, Carbon $since)` gains
   `->where('scanned_at', '>=', $since)`; `latestGpsFor(int $kitId, Carbon $since)` gains
   `->where('timestamp_utc', '>=', ...)`. The call site passes `$shift->started_at`. The
   Postgres bound is the actual performance lever — it lets the planner prune to the monthly
   partitions and use the existing per-partition `(kit_id, scanned_at DESC)` index. The
   ClickHouse bound is for consistency of meaning, not speed.
5. **Semantics that follow from the bound.** "Latest activity" now means *activity during the
   shift*. A scan recorded before `started_at` is ignored, and a shift with no in-shift activity
   still closes stamped at `started_at` — the existing null-fallback behavior, unchanged.
6. **Keep round 1.** `withoutGlobalScope(ScanTimezoneScope::class)` stays (PR #309 removed the
   `timezones`/`ST_Within` spatial join); the `ShowVisibleScan` global scope stays applied.
7. **`latestGpsFor`'s own `catch (\Throwable) { return null; }` stays.** That swallow is
   deliberate and documented — it is the "ClickHouse unreachable / local pgsql bridge has no
   such table" degrade-to-scans-alone path, and removing it would break the local test
   environment. Only the Postgres lookup propagates to the new per-shift catch.
8. **Exit code stays `SUCCESS`** even when `failed > 0`. A non-zero exit would make the
   scheduler treat a partially-successful sweep as a failed run.

### What changes in `tests/Feature/Console/Commands/CloseInactiveShiftsCommandTest.php`

All seven existing tests stay, including `latest_activity_lookup_does_not_join_timezones`.
Four additions:

- **Query-shape pin** — the `scanned_at` aggregate in the zod query log carries a
  `scanned_at >= ` bound, and still carries no `timezones` / `ST_Within`. Asserts positively on
  the new bound rather than only negatively on the old join.
- **Pre-shift scan ignored** — shift started 8h ago, only scan is 12h ago; the shift closes and
  `ended_at` equals `started_at`, not the pre-shift scan.
- **Throwing shift is isolated** — two kits with open stale shifts; kit A's Postgres lookup is
  forced to throw. Command exits `SUCCESS`, shift A stays open, shift B closes as `assumed`,
  and `SendEndOfShiftSummary` is pushed only for B.
- **Completed log carries the failed count** — `Log::spy()`, assert
  `scout:close-inactive-shifts: completed` with `closed`/`skipped`/`failed`/`duration_ms`.

The new tests are Postgres-only. Anything touching `sc_positions` keeps the existing
`requireClickhouse()` guard, so the additions run in normal CI.

## Excluded sites

Every site from prep's exhaustiveness sweep is either inside `t1` or listed here. The two
`buggy` sites (`CloseInactiveShiftsCommand.php:47,55-56,110-115` and `:137-153`) are both
covered by `t1`.

| Site | Why excluded |
| --- | --- |
| `app/Services/Scout/EnsureOpenShift.php:132-137` | Safe — `exists()` with two timestamp lower bounds, request-local, already bounded and already drops `ScanTimezoneScope`. |
| `app/Services/Scout/EnsureOpenShift.php:152-159` | Safe — 24h window plus `limit(5000)`; no unbounded aggregate. |
| `app/Console/Commands/ScoutRecordCheckIns.php:67-121` | Safe — the pattern being copied, not a second defect. Read-only reference. |
| `app/Jobs/Scout/SendEndOfShiftSummary.php` | Safe — DEV-2325 shipped; the dispatch is only relocated inside the new try/catch. |
| `app/Dto/Scout/ShiftSummaryDto.php:52` | Safe — comment-only mention of the command name; not a caller. |

## Out of scope (already decided — do not reopen)

- ClickHouse cutover for the scan max — evaluated and deferred; revisit only if the post-deploy
  timeout criterion fails.
- Any zod-owned DDL: no index, no migration on `public.scans`, in any repo.
- Raising `statement_timeout`.
- Removing `ShowVisibleScan` or changing `is_visible` semantics; broader `ScanTimezoneScope`
  refactor.
- Re-parenting the ticket, and the `onOneServer` duplicate-run glance.
- The two post-deploy criteria (no `SQLSTATE[57014]` across a full day; kit 10224's stuck shift)
  are verification, not merge gates.
