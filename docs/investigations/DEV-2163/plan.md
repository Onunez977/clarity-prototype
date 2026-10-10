# DEV-2163 — Plan

**Route:** bug · **Base:** main · **Files:** 2 · **Subtasks:** 1 (single-node fast path)

## Root cause (confirmed in code)
`ScoutCheckInRollupQuery`'s `addresses` CTE (ScoutCheckInRollupQuery.php:47-62) sources candidate
open-assignment addresses from `clarity.recovery_address` — a repossession-only MV. Open hotlists
almost never have a row there, so ~0 addresses match → `{direct_bucket:0, staging_bucket:0}` for every kit.

## Fix (one subtask, TDD)
Repoint the `addresses` CTE to the open-assignment path
`clarity.hotlist(status=Open) → zod.public_hotlist_address → zod.public_address`, mirroring the
established `HotlistAddressGeohashQuery` idiom:
- **EWKT parse:** `extract()` + `toFloat64OrNull` (NOT `readWKTPoint`, which throws on malformed geo →
  `ClickhouseQuery::query()` swallows the whole query to `[]`, indistinguishable from the bug).
- **No JOIN on `zod.public_address`** (~189M rows) — `a.id IN (subquery)` rides the id PK.
- **No FINAL** on the two zod tables — rely on `_peerdb_is_deleted=0` + the matcher's
  `DISTINCT (address_id, is_staged, hour_bucket)`.
- **Guards:** `geo` NULL/empty, `addr_lng/addr_lat` NULL, and `(0,0)`.
- **Plate filter:** `length(plate) > 3` on the open-hotlist set (gate decision — match sibling).
- **Perf (gate decision):** scan-window **bbox prefilter** — compute the kit's scan-window min/max
  lng/lat and prefilter the addresses CTE to that box before the `geoDistance` join, so a busy kit
  matches only addresses near where it actually scanned (not all ~17k org addresses). Documented in
  the class docblock (AC5).

**Preserved unchanged:** `OPEN_STATUS`, `PROXIMITY_METERS` (160.934m = 0.1mi, matches
`LprVisitCountQuery`), the `matches` CTE dedup, the `countIf(is_staged)` direct/staging split, and
`hotlistOrgFilter()` staging-org authorization.

## Tests (re-fixtured onto the real source)
Replace `insertRecoveryAddress()` with a helper seeding `zod.public_address` (EWKT geo) +
`zod.public_hotlist_address`; repoint the 6 check-in tests + the contract envelope test; add a
malformed-geo (NULL/empty/(0,0)) guard test. `RequiresClickhouse` already truncates both zod tables.

## Out of scope
`NearestGivenAddressController` (separate correct `recovery_address` consumer), the `recovery_address`
MV refresh pipeline, and the `RequiresClickhouse` truncate list (unchanged).

## Blast radius
None of the sensitive globs (no migration/billing/auth/purge/cleanup). Single caller
(`ClarityScoutDashboardController::checkInCounts`). Note: a query timeout degrades silently to `[]`
(same shape as the bug) — the bbox prefilter + a load check at QA guard against that.
