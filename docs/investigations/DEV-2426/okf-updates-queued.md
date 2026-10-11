# DEV-2426 — queued `okf/` corrections (DO NOT APPLY until this change is approved/merged)

Per `CLAUDE.md`'s WRITE rule the wiki is only updated **after** the change passes its gate. This
file is the queue. Each entry is a claim I verified as wrong or about-to-be-wrong, with the
evidence, so the post-approval pass is quick. It is NOT purely mechanical — each entry still needs checking against the merged code, since this file was itself drafted before `org_id` entered the key.

## `okf/concepts/check-in.md`

1. **"Identity & dedup: exactly one row per `(kit_id, address_id, hour_bucket)` — enforced by a
   unique index and `firstOrCreate`."** Two errors.
   - The key becomes `(kit_id, org_id, addr_lng, addr_lat, hour_bucket, is_staged)` (this ticket — `org_id` was added in review round 1; see the journal for why the originally-stated rationale was wrong).
   - It is **`insertOrIgnore`**, not `firstOrCreate` — and has been since DEV-2342
     (`ScoutRecordCheckIns::recordCheckInsForKit()` does a bulk `insertOrIgnore`). This one is
     wrong *today*, independent of DEV-2426.
2. **"`hotlist_id` / `is_staged` … the label never flips between runs."** False — a flip is
   precisely this ticket's bug. Post-fix the accurate statement is: the label no longer strands
   *stale*, but `address_id`/`hotlist_id` are frozen at first sighting by explicit decision and
   may name an address that is no longer the elected winner.
3. **"re-running the recorder never duplicates or rewrites a row."** False pre-fix (that is the
   bug). True post-fix only at the per-side grain — say so explicitly.
4. **"A multi-address order can produce one row per address."** Stale since DEV-2414 — co-located
   addresses now collapse to one row per side.
5. **DEV-1756 consumer note** needs the new documented boundary appended: after a mid-hour side
   change both surviving rows can carry the same `address_id`, so
   `COUNT(*) WHERE address_id = ?` can report two check-ins for one address at one place in one
   hour. Accepted (Dalton, 2026-08-25), not a defect to fix.

## `okf/concepts/scout-check-in-recorder.md`

6. **Lines ~35 and ~56: `unique(kit_id, address_id, hour_bucket)`** → `scout_check_ins_geo_hour_side_unique` on `(kit_id, org_id, addr_lng, addr_lat, hour_bucket, is_staged)`, plus
   the two new nullable `addr_lng`/`addr_lat` columns in the column list.
7. **Line ~48: "`GROUP BY (address_id, hour_bucket)` with Direct-wins `argMin`…"** Stale since
   DEV-2414 — it is now the two-stage `address_hour` → `ranked` election partitioning on
   `(addr_lng, addr_lat, hour_bucket, out_is_staged)`.
8. **`down()` asymmetry** is worth a Gotchas bullet: the DEV-2426 migration deliberately does not
   restore the old unique index, so a rollback leaves the table with no uniqueness guard.

## Both pages — the schedule

9. Both pages read as though `scout:record-check-ins` runs every five minutes ("re-enabled in
   DEV-2386"). It is **commented out** at `routes/console.php:59-65` (commit `4aec6244`,
   2026-08-21) and has been since. Nothing runs it on a cadence. This is the correction most
   likely to mislead someone reading the wiki to reason about production volume.

## `ScoutCheckInMatchQuery` class docblock (code, not wiki — handled inside node t1)

10. Its mechanism-3 description ("later **also joins** a Direct hotlist" → `min(is_staged)` moves
    it) implies simultaneous dual-listing. Measured against production: **0 of 4,519,947** book
    `address_id`s carry both `is_staged` values. The real condition is temporal — the same
    `address_id`'s side differing between two 15-minute rebuilds. Evidence:
    `flip-risk-measurement.md` §3. Being corrected in t1.

13. **A third residual gap** now belongs alongside the other two: the elected geo is itself mutable
    across book rebuilds (a re-geocoded address), so that too writes a second row for one visit.
    Measured bound on the driver: 24.92% of 198,300,757 `zod.public_address` rows have
    `_peerdb_version > 0`, ~166k/day synced.
14. **The production hand-apply runbook** needs the `platform.migrations` INSERT and the
    DDL→code-deploy window warning (during that window the old code writes NULL geos, which the new
    unique index treats as distinct — so there is no dedup at all until the code lands). Both are in
    `plan.md`; the recorder page should point at them.

## New content worth adding

11. The **flip-risk figures** (33.3% of (geo, side) election groups hold >1 address; 59.2% of book
    rows) belong on the recorder page — they are the first quantification of an exposure both
    pages previously described only qualitatively.
12. The **`platform.scout_check_ins` has no cardinality oracle** fact (platform-schema table, not
    in the `zod.public_*` CDC mirror) is a genuine gotcha for anyone trying to size it, and the
    schedule-window bound (order 10³) is the workaround. Candidate for `platform-gotchas.md`.
