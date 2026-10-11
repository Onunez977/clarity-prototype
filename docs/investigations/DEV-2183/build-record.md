Insight AI — Build Record
<!-- build-record: DEV-2183 -->

**Route:** bug · **Base branch:** main · **PR:** https://github.com/insight-lpr/platform/pull/265

## What changed & why

**Goal:** exclude Scout Map *order*-density points (Direct + Staging) for hotlist orders
with no actionable plate, matching the dashboard-stats `length(plate) >= 3` rule.

At intake the order query (`HotlistAddressGeohashQuery`) was found to already carry a
stricter `length(plate) > 3` guard — so the real work was to **relax** it to `>= 3` for the
Scout order layers, not add a guard where none existed.

| Change | Why |
|---|---|
| `HotlistAddressGeohashQuery.php` — parameterize the guard as `min_plate_length` (default **4**); SQL `length(plate) >= {$minPlateLength}` | `>= 4` ≡ the prior `> 3` for integer `length()`, so the default leaves every caller unchanged; Scout opts into `3`. `$minPlateLength` is `(int)`-cast + typed `int`, so it interpolates into SQL safely. |
| `ClarityScoutMapController::orderRows()` — pass `min_plate_length => 3` | Scout driver Direct/Staging order layers now use the dashboard `>= 3` rule. |
| `ScoutMapHelpers::orderRows()` — pass `min_plate_length => 3` | Admin Bird's Eye reuses the Scout order layers (DEV-2116) — kept in parity with the driver map. |
| `ClarityScoutMapControllerTest.php` — `seedGeohashCell` gains a `$plate` param; +3 tests | Pin the boundary (default-vs-3) and both endpoints surfacing a length-3 plate. |

## Resolved questions
- **Guard value** → `>= 3` (relax the existing `> 3`), matching dashboard stats · human:Dalton 2026-07-13
- **Shared query** → Scout-only via a `min_plate_length` param (default 4 == `> 3`); the non-Scout `KitUserPathController` repo heatmap stays on `> 3`, untouched · human:Dalton 2026-07-13
- **Sibling queries** → keep the ticket narrow (order query only); leave `ScoutCheckInRollupQuery` + `ClarityScoutDashboardController` on `> 3` and flag the new divergence as a follow-up · human:Dalton 2026-07-13

## Acceptance criteria
- ✅ Direct order query excludes rows where `LENGTH(plate) < 3` — `ClarityScoutMapController::orderRows` → `>= 3`.
- ✅ Staging order query excludes rows where `LENGTH(plate) < 3` — `ScoutMapHelpers`/`staging=true` path → `>= 3`.
- ✅ Behavior matches the dashboard-stats `>= 3` rule — guard is now character-identical to `HotlistCount.php:74-76`.

## Tests
- Added (TDD): `test_order_query_min_plate_length_guards_the_boundary`,
  `test_heatmap_orders_direct_includes_length_three_plate_orders`,
  `test_heatmap_orders_staging_includes_length_three_plate_orders`
  (in `tests/Feature/Http/Controllers/Api/ClarityScoutMapControllerTest.php`, `RequiresClickhouse`).
- **Local execution + agent/human QA skipped by request** (backend-only, one-line SQL guard).
  `vendor/bin/pint --dirty` and `php -l` clean on all changed files. CI runs the
  `RequiresClickhouse` suite on this main-based PR.

## Review
In-session adversarial review (high effort; rigor `review.sh` substituted — it fails
"Not logged in" under the embedded harness). **Score 95/100** (gate ≥ 90 → pass). No scoring
findings. Two informational notes: (1) Scout cache keys gain `min_plate_length` → first
per-viewport request recomputes post-deploy (5-min TTL); (2) `queryClickhouseRaw` docblock
omits `$minPlateLength`, consistent with its other scalars.

## How numbers were derived
No data queries were run for this change (derivation_log empty). The `>= 4` ≡ `> 3`
equivalence is arithmetic over ClickHouse's non-negative integer `length()`.

## Follow-up
Consider a separate ticket to decide whether `ScoutCheckInRollupQuery` (`> 3`) and the
`ClarityScoutDashboardController` stat queries should also move to `>= 3` for full
cross-surface consistency, now that the order map has.
