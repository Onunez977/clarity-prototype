# DEV-2313 — Plan

**Deliverable:** a nightly background process that snapshots each Scout-enabled driver's daily check-in counts (direct + staging) into a new Postgres table, so the count is preserved point-in-time (accurate history even after hotlists close). **UI stays hidden**; the live read endpoint is unchanged.

## Single node (t1) — Phase 4 single-node fast path

| Aspect | Detail |
|---|---|
| Files | migration, `ScoutCheckInRollup` model, `ScoutAggregateCheckIns` command, `routes/console.php` schedule line, feature test |
| Approach | Reuse the existing `ScoutCheckInRollupQuery`; command fans out over Scout-enabled drivers and idempotently upserts per (kit_id, bucket_date) |
| Patterns | `CloseInactiveShiftsCommand` (Scout scheduled cmd) + `AggregateGeoHashScanDaily` (idempotent daily upsert) |
| Blast radius | `database/migrations/**` — additive create-table only; **approved by Dalton** (chose "add the nightly snapshot") |
| Perf gate | Not required — reuses the already-tuned production query at a bounded nightly fan-out |
| Test | `ScoutAggregateCheckInsCommandTest` (RequiresClickhouse) — persisted counts, idempotent re-run, non-Scout skipped, flag-off no-op |

## Confirmed decisions (owner)
- Persistence target: **Postgres** platform table (not a ClickHouse MV).
- Granularity/cadence: **per-kit per-day, nightly ~4:00** for the prior day.
- **No backfill** (a backfill would undercount since-closed hotlists — inaccurate).
- Fan-out: **Scout-enabled drivers** (clarity-scout permission + KitSettings kit + active CLARITY_SCOUT flag).
- Endpoint left live/unchanged; per-driver ClickHouse failure = skip-and-log.

## Explicitly out of scope
Restoring the frontend check-in cards; repointing the endpoint to read the table; the stale HelpPanel/okf doc cleanup; point-in-time hotlist-status reconstruction.
