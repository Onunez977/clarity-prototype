# DEV-2426 — bounding `platform.scout_check_ins` without a production oracle

The row count sizes the migration: adding columns is cheap, but building a **UNIQUE** index holds
`ACCESS EXCLUSIVE` for a duration proportional to table size. `prod-cardinality.sh` cannot help —
verified that `platform.scout_check_ins` is **not** in the `zod.public_*` CDC mirror (the probe
returns `UNKNOWN_TABLE`, which is a real server response, not an auth failure). So the bound is
derived from the repo's own history of when the recorder could actually write.

## The recorder only ever ran in two windows

`scout:record-check-ins` writes only when scheduled (or invoked by hand) **and**
`config('app.experimental.clarity_scout')` is on. `routes/console.php` history:

| commit | when | effect |
|---|---|---|
| `af40ee78` | 2026-08-10 12:18 | recorder + schedule introduced (DEV-2313) |
| `5145096a` | 2026-08-12 22:28 | **disabled** — "it borked 142" (the DEV-2342 incident) |
| `6511a998` | 2026-08-21 08:30 | **enabled** |
| `4aec6244` | 2026-08-21 10:10 | **disabled** — still disabled on `main` today |

- **Window 1: 2026-08-10 → 2026-08-12, ~2.4 days.** Cycles were pathologically slow in this
  window (36–148 min each, avg 104 — the PHP address round-trip DEV-2342 later removed), so the
  5-minute cadence did not produce 5-minute cycles. DEV-2342's own commit message records the
  actual throughput: **701 check-ins across 17 cycles**.
- **Window 2: 2026-08-21 08:30 → 10:10, ~1.7 hours.** At a 5-minute cadence that is ~20 cycles.
  At the measured 2.07 rows/kit/cycle over a ~44-kit fleet (~91 rows/cycle) that is **~1,800
  rows**. This is the window the DEV-2414 baseline comment (38511) was measured in.
- Plus ad-hoc manual invocations (the baseline comment cites 9 runs, ~820 rows — overlapping
  window 2, not additive to it).

## Bound

**Order 10^3 rows — low thousands, not millions.** Even a generous doubling for unrecorded manual
runs keeps it under ~10k.

## What follows for the migration

- A `UNIQUE` index build on a few thousand rows completes in **milliseconds**. `CREATE INDEX
  CONCURRENTLY` is unnecessary here (and awkward inside a Laravel migration's transaction anyway).
  The index-build lock is a **non-issue** — this does not need to be treated as a large-table DDL.
- The write path is **quiescent**: the schedule is off, so nothing is inserting during the
  migration window. There is no concurrent-writer hazard to design around.
- The genuine migration hazard is therefore **not** locking but **legacy-row compatibility** — see
  the note below.

## The real hazard: a UNIQUE index over legacy rows

Existing rows have no address-geo column. If a migration backfilled geo and then added
`unique(kit_id, <geo>, hour_bucket, is_staged)`, the index build would **fail on duplicates** —
because the duplicates this bug creates are exactly rows that share (kit, geo, hour, side) while
differing in `address_id`. The bug's own output is what would reject the constraint.

Leaving the new geo columns **nullable and unbackfilled** avoids this cleanly: PostgreSQL treats
`NULL`s as **distinct** in a unique index by default (`NULLS DISTINCT`), so every legacy row is
automatically exempt and can never collide. New rows always carry a non-null geo and dedup
normally. Local PostgreSQL is 16.2; this default behaviour predates any version in play, and
`NULLS NOT DISTINCT` (PG 15+) would have to be asked for explicitly to change it.

That makes "document the boundary, don't backfill" the mechanically safe option — and it matches
what DEV-2414 already did for this same table at its own grain boundary. Backfilling would require
deduplicating the legacy rows first, which is a data-repair decision, not a schema one.
