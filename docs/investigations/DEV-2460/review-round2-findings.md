# DEV-2460 — review round 2 (3 lanes, in-session, opus)

**Scores: 88 / 87 / 88. MIN = 87 < 90 → gate FAILED.** One bounded fix pass follows.

Provenance: `review.sh` RN=1 could not run — all three harness `claude -p` lanes returned
`{"is_error":true,"terminal_reason":"api_error","result":"Not logged in · Please run /login"}`.
This session is non-interactive, so `/login` is impossible. Lanes re-ran in-session via
`insightlpr:adversarial-reviewer`, each given the defect taxonomy and this run's
`prep-context.json`. Recorded as `in-session-agent` in the manifest — a blessed substitution,
made visible rather than hidden. Raw auth-failure JSON preserved under
`.ai/_archive-rn1-authfail/`.

## What all three lanes independently CLEARED

Worth recording, because two of these were pre-emptive claims in my own docblocks that a
reviewer could reasonably have called self-serving:

- **The `toggleCard()` no-guard decision.** All three lanes attacked it; lane 2 traced the
  client-settable public `expandedCard` property and concluded *"The driver's reasoning holds;
  I could not break it."* Lane 3 independently confirmed authorization is enforced at
  resolution (the expanded path Livewire-mounts the widget, so its own `abort_unless` fires).
- **The gate ordering.** All three verified by reading `getStats()` that the branch precedes
  `toStat()`, and lane 3 traced every path out of `lockedStat()` to confirm no value, chart, or
  cache write is reachable. AC-4's security half is correctly built.
- **AC-7.** All three verified from the code rather than the claim: zero new ClickHouse call
  sites; the two `kit-status:*` keys are reused; page total is 3 CH queries before and after.
- **`scale_relevant: false`** — all three graded the resource envelope and agreed, with
  arithmetic rather than deferral.

## SCORING findings (all fixed in round 3)

| # | Lanes | Finding |
| --- | --- | --- |
| S1 | 3/3 | **The four hit cards no longer render in one row.** The grid is 4 columns; 7 cards puts `live-hits` in row 1. My docblock asserted they "never split" — false. `test_all_four_hit_cards_are_contiguous()` passes because it measures *array-index* adjacency while its message says "one row"; with 8 cards those coincided, with 7 they do not. Arithmetic, not a coding error: the mockup's hit row is slots 12-15, so it needs all 12 preceding slots filled and we ship 3. **Operator decision: ship the reflow, stop claiming otherwise.** |
| S2 | 3/3 | **The seam's own test is a tautology.** `test_a_gated_card_keeps_its_grid_slot...` re-implements `array_filter` inline and never calls `expandableCards()`. Replacing that method's body with `return self::cards();` leaves the whole suite green. Nothing drives `getStats()` with a gated card either — so the *declared blast radius* (gate before value) had **zero** coverage. |
| S3 | 1/3 (lane 3) | **The contiguity test now measures the wrong array.** It reflects into `cardWidgets()`, which this diff re-pointed from `cards()` to `expandableCards()`. Gate any hit card and the grid stays contiguous while the test fails, blaming a sibling for a layout violation it did not commit. `assertCount(7)` has the identical defect. Lane 3 noted the distinction was handled correctly in `test_company_metrics_cards_are_in_layout_order` in the same diff — understood in one test, missed in two. |
| S4 | 3/3 | **`canViewCardData()`'s contract is incomplete for the 9 siblings that must implement it.** Defaults to a hardcoded `true` where `static::canView()` would be a behavioural no-op for all 7 current cards *and* close the hole; says nothing about how a sibling derives the boolean, so each will hand-roll `Feature::active()` — which `okf/concepts/filament-conventions.md` explicitly forbids; and does not state that it must be total with no authenticated user (two tests already call it unauthenticated). |
| S5 | 1/3 (lane 1) | **The "never computes the metric" guarantee is breakable.** `lockedStat()` → `getCardLabel()` → `getHeading()`, and `getHeading()` is overridable. `MissedOpportunityClientGraph::getHeading()` already runs a cached ClickHouse query — and it occupies the mockup's row-3-col-3 slot, making it the seam's likely first consumer. |
| S6 | 1/3 (lane 1) | **The `getHeading()` guard returns the wrong label.** `KitUsers` yields `'KitUsers'`, not `'Active Kit Users'` — `TableWidget` exposes the heading via `getTableHeading()`/`static::$heading`, which the guard never consults. Latent now, visible the moment a `TableWidget`-based card is gated. |
| S7 | 2/3 (lanes 2, 3) | **A catch-free ClickHouse read was composed into the one deliberately fail-soft widget.** `HotlistCount::fetchHotlistCounts()` catches `Throwable` so the header row survives a CH outage; `KitUsers::cameraKitsCount()` documents the opposite policy on purpose. Composing them means a CH blip now takes out the four hotlist numbers that used to degrade to zeros. Two documented, opposed policies merged without reconciling either. |

## NON-SCORING findings (operator elected to fix all of them)

N1 (lanes 1,3) `kitUsers()`'s docblock claims each key resolves once; only the *instance* is
memoized and `activeKitUsersCount()` is called twice. · N2 (lanes 1,2) `strpos` returns `false`
on a miss and `assertGreaterThan(false, N)` passes vacuously. · N3 (lanes 2,3) `'Active Kit
Users'` now has three literal copies, and `KitUsers::toStat()` still shows the superseded
"inactive kits" figure. · N4 (lane 3) `lockedStat()` re-hardcodes the Livewire key prefix
instead of routing through `dashboardStat()` — the same duplication class this ticket exists to
fix. · N5 (lanes 1,2) two tests invoke `cardWidgets()` with no authenticated user. · N6 (lane 2)
the accepted loss of the Kit Users expand table is absent from `record.deviations[]`, which the
operator required. · N7 (lanes 2,3) `getColumns()` has no `lg` arm, so 1024-1279px inherits
`md`'s 3 columns while the grid below is 4-wide — and the theme.css comment reasons only about
`xl`, skipping the case that undercuts it. · N8 tombstone comment in `DashboardWidget`. ·
N9 `RecoveryLeaderTest:225` substring assertion no longer pins the label. · N10 the
`..._where_implemented` test name is stale.

## Exclusion challenged

Lane 1 argues `plan.json`'s exclusion of `MissedOpportunityClientGraph` is **wrong for the
purpose of validating the seam** — being Pennant-gated is exactly what makes it the seam's first
consumer, and its query-running `getHeading()` is the counterexample to S5. Accepted; S5's fix
addresses it.
