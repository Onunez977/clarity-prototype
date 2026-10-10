# DEV-2441 — Implementation Plan

**Kit User Path — no data showing for Reliable Recovery Services (org 469)**

Route: bug · Base: `main` · Branch: `claude/DEV-2441` · PR [#372](https://github.com/insight-lpr/platform/pull/372)

## Root cause (verified against production ClickHouse)

`KitUserPathController::paths()` sources the route **exclusively** from `clarity.sc_positions` via
`KitRangePathsQuery`. That is a different table from `clarity.kit_scan_paths`, which the reporter
checked and correctly found populated — `kit_scan_paths` is read only by the Scout driver app,
never by this admin page.

Measured per kit over 30 days:

| Kit | Name | `kit_scan_paths` | `sc_positions` (what the page reads) |
| --- | --- | --- | --- |
| 10373 | Jerome Jozwiak | populated | **0 rows** |
| 13689 | Josh Query | populated | **0 rows** |
| 13865 | Jaylan Catledge | populated | **0 rows** |
| 14144 | Jason Ragsdale | populated | **0 rows** |
| 14305 | Henry Pacer | populated | **0 rows** |
| 14756 | Thomas Sargent | populated | **0 rows** |
| 10588 | Ron Johnson | populated | 3,746 rows — **2026-08-13 only** |
| 15349 | Clifton McAllister | populated | 19,589 rows — healthy |

Org 469 is a live customer instance of the scan-only-kit class already described by DEV-2222.
The other two hypotheses in the ticket are both refuted: the permission is granted (Don can open
the page — a missing permission would 403, not render blank), and the kits are selectable (so no
`kit_metadata` soft-delete is emptying the dropdown; all 151 of org 469's kits are live).

## Scope

Land the fallback under DEV-2441, reusing PR #320's review-hardened implementation as prior art
rebased onto current `main`, plus three fixes the operator un-deferred on 2026-08-27:

- **R1** — rescue mapping-less kits (`kit_scan_paths` is keyed by `kit_id`, needs no mapping).
- **R3** — per-kit-**per-day** fallback granularity, not whole-window. This is what fixes kit 10588.
- **R7** — make tier 2's `trip_date` viewer-local, so the per-day diff compares like with like.

`ClarityScoutBirdsEyeController::historicalContinuousPaths()` is a **ratified out-of-scope
boundary** (stays on DEV-2228) and is documented as such in-code, because it was the top repeat
review finding on DEV-2222 and lanes had no way to know it was deliberate.

DEV-2222 closes as superseded; DEV-2341 closes once R1/R2/R3 are all in. **No new tickets.**

## Two premises worth stating plainly

**R3 cannot be implemented without R7.** Tier 1 dates days viewer-local
(`KitRangePathsQuery:197` — `toDate(toDateTime(…,'UTC'), '<tz>')`); tier 2 dates them UTC
(`KitScanPathsQuery` — `toDate(timestamp)`, no timezone argument). A per-day set-difference across
two different definitions of "day" mis-diffs for the roughly six hours a day where the local and
UTC dates disagree — double-rendering or dropping a day. It cannot be repaired in PHP: once
ClickHouse has collapsed `timestamp` to a UTC date, the time-of-day is gone. So it is fixed in SQL.

**That fix introduces an injection vector if done carelessly.** `$timezone` is currently only used
via `Carbon::parse()`, which is safe. Interpolated into `toDate(timestamp, '<tz>')` it reaches raw
SQL, and it resolves from `$user->timezone` → `$this->dti['timeZone']` — user-influenced JSON. It
must be whitelisted against `timezone_identifiers_list()` first, mirroring
`KitRangePathsQuery::safeTimezone()`. The class already `addslashes()`es `$layer`, so treating
these arguments as untrusted is established precedent here.

## DAG — one node

The seven files interlock: the controller's per-day diff is only correct once the day key is
timezone-aware, and both `cachedHandle()`s depend on widening `ClickhouseQuery::buildCacheKey()`.
A per-file split would serialize on `depends_on` anyway while adding worktree and merge risk for
no parallelism. So: **single-node fast path**, no fan-out.

### t1 — steps

1. `ClickhouseQuery::buildCacheKey()` → `protected` (one line; both `cachedHandle()`s need it).
2. `cachedHandle()` on `KitRangePathsQuery` and `KitScanPathsQuery` — ported verbatim from PR #320.
   Same cache-key shape as `query(cache: true)`, but wraps `handle()` so exceptions **propagate**
   instead of being swallowed to `[]`. (This is R2, already ratified and landed on #320.)
3. **R7** — `trip_date` becomes `toDate(timestamp, '<validated tz>')`, behind a
   `timezone_identifiers_list()` whitelist.
4. **R1** — keep `kitsByMapping()` for the tier-1 mapping list, but also key the full `Kit`
   collection by id. Replace the `$byMapping === []` early return with a no-kits-at-all check, and
   gate tier 1 on `$mappings !== []` so a purely mapping-less selection goes straight to tier 2.
   Mirrors `driverPositions()` (`ClarityScoutBirdsEyeController:175-177`).
5. **R3** — build `covered[kit_id][YYYY-MM-DD]` from tier-1 segments; enumerate the window's local
   days; **skip any kit already covered on every window day** (so a GPS-healthy org still issues no
   tier-2 read at all — preserving PR #320's allocation property); one batched tier-2 read for the
   rest; drop any row whose `(kit_id, trip_date)` tier 1 already renders. R1 falls out for free — a
   mapping-less kit has no tier-1 coverage, so all its days fall back.
6. Preserve every decision settled across PR #320's five review rounds — `$localStart`/`$localEnd`
   captured **before** `Carbon::utc()` mutates in place; tier-1 outage → 503 while a genuine
   over-budget count stays 422; tier-2 rows accumulated locally and merged only after the loop; a
   tier-2 failure degrades to tier-1-only and never wipes kits that *are* rendering; tier 2
   deliberately outside `MAX_RENDER_POINTS`; name/color from the resolved `Kit`, never the CH row;
   the CH-decoded path `json_encode`'d once. **Do not** reintroduce the "counted points but zero
   segments" guard — `countPoints()` counts pre-split and `<2`-point segments are dropped, so that
   shape is legitimate and common. It was reverted on DEV-2222 and a pin test exists.
7. Residual cleanups that were repeat scorers: harden `scanPathRow()` against a `false` from
   `json_encode` (R6); strengthen or drop the near-tautological name/color test (R4); RN5's TTL fork
   and vacuous tier-2 test; and document the ratified Bird's Eye boundary.

### Tests

- **Controller** — scan-only kit falls back; a kit with a genuine track is not overridden; a
  **sparse** track (GPS on 1 of N days — the kit-10588 shape) falls back on the *other* days only;
  a mapping-less kit is rescued; an all-mapping-less selection still renders; a tier-1 throw
  returns 503 and does **not** fire tier 2; a tier-2 throw degrades to tier-1-only; day keys agree
  across a local/UTC boundary; a fully-covered org issues no tier-2 read.
- **Bird's Eye** — pin the R7 change on the one other `trip_date` consumer: a bucket whose UTC and
  viewer-local dates differ is now reported on the viewer-local date.
- **KitScanPathsQuery unit** — the timezone whitelist (a bogus/injection-shaped timezone falls back
  to UTC and never reaches SQL) and that `cachedHandle()` propagates rather than swallowing.

Anchor every ClickHouse fixture to `now()` — the hot tables carry a 90-day TTL.

## Gates

- **Sizing: PASS** — 4 code files (under the >6 threshold) + 3 test files, one concern. R1/R3/R7
  are facets of a single fallback, not separable features.
- **Perf: not required** — nothing defers perf on a >1B-row surface. `sc_positions` measured at
  331,106,659 rows and `kit_scan_paths` at 1,680,578; neither is in the >1B class. Tier 2 is one
  batched read on `kit_scan_paths`' own `(org_id, kit_id, timestamp)` key prefix — the same access
  pattern two existing production callers already run, narrowed further by step 5.
- **Blast radius** — no configured sensitive glob matches, so no formal tag. But R7 changes a
  **shared** query class whose `trip_date` feeds a live Scout surface
  (`ClarityScoutBirdsEyeController:544`), so that consumer gets explicit coverage and Phase 5 runs
  **3 lanes** (date-boundary logic is a standing 3-lane trigger).
