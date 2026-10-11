# DEV-2465 — Company Metrics — Unique Scans to Case Claims card

Type: Story · Status: To Do · Sprint 40 · App: Clarity · Reporter/Assignee: Dalton McCleery
Parent epic: DEV-2409 "Company Metrics Dashboard — mockup build-out (9 cards, one per ticket)"
Blocked by: DEV-2460 (foundation: mockup grid layout, 2 relabels, Kit Users move, Pro-gate seam) — QA Complete
Blocked by: DEV-2463 (Unique LPR Case Claims card) — QA Complete

## DESCRIPTION (verbatim)

## ✅ UNBLOCKED 2026-09-09 — Oscar defined it

This ticket was raised as _"no definition exists"_. It does now. Two comments on DEV-2409, three minutes apart:

> **option 1 is fine** (comment 39274)

> this should show **total number of unique scans / by unique case claims = new metric** (comment 39276)

Option 1 was _"96.6% of claimed vehicles were seen by our cameras first"_ — **a rate.** The second comment is the formula behind it, not a different metric.

### Why the two comments agree

They read as contradictory only if "unique scans" means distinct scan ids, which would give ≈10× rather than a percentage. It does not: **Oscar has already confirmed "unique scans" means period-distinct** `uniqExact(ocr)` — recorded for DEV-2467 (branch `claude/DEV-2467`, commit 92c1be76, and `okf/…/scan.md` business rules). Under his own unit the formula _is_ a rate. Re-measured read-only against production, Jul 2026 UTC, fleet-wide:

| reading of "unique scans ÷ unique case claims" | Jul 2026 | value |
| --- | --- | --- |
| **orders with a pre-claim scan ÷ claimed orders** | 11,449 / 11,855 | **96.6%** |
| distinct plates ÷ deduped claim triples | 11,311 / 11,975 | 94.4% |
| distinct `scan_id` ÷ deduped claim triples | 119,364 / 11,975 | 9.97× — not a rate |

**Build the first one.** Both rates are correct; the first is grain-consistent (orders over orders) where the second divides plates by claim triples. **Awaiting Oscar's confirmation of which of the two rates** — see Still Open below. Do not build the 9.97× reading.

### The rate form also dissolves the near-duplicate trap

Comment 39127 flagged that a plate-distinct **count** here (11,311) sits within 6% of DEV-2463's _Unique LPR Case Claims_ (11,975) two rows up — the trap DEV-2469 fell into. As a **rate** it repeats nothing else on the grid.

## The metric

**Unique Scans to Case Claims** — the share of case-claimed vehicles our cameras had already seen before the claim was made.

**Grid slot: row 2, col 3** — confirmed against the mockup image by DEV-2460 on 2026-09-01 (attachment 24421, recorded cell-by-cell in `docs/investigations/DEV-2460/mockup-confirmation.md`, verdict "the inferred order is correct in every cell"). The old "question 2 — where does the card sit" is **closed**; do not re-ask it.

## Route — single connection, ordinary ClickHouse

The original body called this a _"cross-connection Postgres → ClickHouse card pattern this repo does not have today"_ and _"the largest engineering unknown in the epic"_. **Both claims are withdrawn** (comment 39127). `zod.public_billing_events` is mirrored into ClickHouse, so the claim side and the hits side both live on `clarity_clickhouse`: one connection, one ordinary `ClickhouseQuery`. The inherited "Route: Postgres `zod`" decision is likewise superseded — the whole funnel family is on ClickHouse (DEV-2463 comment 39116).

## Data path (re-measured 2026-09-09, read-only)

**Denominator** — reuse DEV-2463's shipped `UniqueLprCaseClaimsQuery` rather than re-porting the claim CTE. It already returns `all_claims / successful / deduped` from one scan and reproduces Brek's funnel exactly.

**Numerator** — successful `case-claim` events resolved to `hotlist_id` via `JSONExtractInt(be.additional, 'hotlist_id')`, joined to `clarity.hits_cold` on `hotlist_id`, keeping hits whose `scanned_at` precedes the claim's own epoch `ts`.

Fleet-wide Jul 2026, reproducing comment 39127 within append drift (+8 hits, +1 plate):

| stage | measured 2026-09-09 | 39127 (2026-09-04) |
| --- | --- | --- |
| claimed orders (successful, `hotlist_id != 0`) | **11,855** | 11,855 |
| orders with ≥ 1 pre-claim scan | **11,449 (96.6%)** | 11,449 |
| distinct plates, pre-claim | 11,311 | 11,310 |
| distinct `scan_id`, pre-claim | 119,364 | 119,356 |
| distinct hit rows, pre-claim | 120,987 | 120,979 |

### ⚠️ `hits_cold` duplicates measure 1.54x on THIS population

Bare `count()` over the pre-claim hit set returns **186,683** against `uniqExact(id)`'s **120,987** — **1.543x over**, worse than the ~1.33x recorded fleet-wide. `FINAL` does **not** fix it (`hit_created_at` is mutable and in the sort key). Every aggregate in the implementation must use `uniqExact`, never bare `count()`.

### Before/after the claim is a ~10% rounding decision, not a fork

89.6% of the activity is pre-claim, and orders-with-a-pre-claim-scan (11,449) is 99.7% of orders-with-any-scan (11,485). Pre-claim is the causally sensible reading; it was settled by measurement, not put to product.

## What a RATE changes in the card contract

None of the ten cards renders a non-integer today, so this is real scope, not cosmetics:

* `DashboardWidget::toStat()` (`app/Filament/Widgets/Dashboard/Concerns/DashboardWidget.php:280-288`) formats with `number_format()` at **zero decimals**. Override `toStat()` on the card — the precedent is `StagingLiveHitLeader.php:229-240` and `RecoveryLeader.php:232-238`, which already override it for their own reasons.
* **The 13-month sparkline must be per-month rates computed from two per-month aggregates.** A rate series cannot be summed. Use the house one-batched-call shape (`BatchMonthlySparklineData`, `okf/concepts/client-analytics.md:83`) — one query, not 13.
* **Divide-by-zero guard.** ~83% of orgs have no case-claim activity in a window, so the denominator is legitimately 0 for most viewers. Guard it; the _display_ choice (0 / N/A / hide) is the open epic-wide question — put it behind the same seam the sibling cards use rather than inventing a local answer.
* Explicit `getStatColor()` arm (`DashboardWidget.php:299-309`) — `default => 'gray'` fails silently.
* Consider whether "unique" still belongs in the card label now that the number is a rate.

## Inherited decisions — do not re-litigate

* **Claim CTE / org axis:** `(forwarder_org_id = ? OR agent_org_id = ?)`, matching `RepoReport::orgDbReposSql()`.
* **Exclusions:** reject `insightlpr` on **either** side (`position(coalesce(n,''), 'insightlpr') = 0` against a `FINAL` `public_orgs` CTE, as DEV-2463 ships it). **Never filter** `titlemax` in the counting path — it is a display flag; only `rejectInternalOrgs()` removes rows. Getting this backwards is a −2.6% error.
* **Caching:** one `Cache::flexible([3600, 86400])` query per resolved window; **not** through `DashboardWidget::getMonthlyData()`'s 13-month loop.
* **Port rules:** UTC calendar-month window, inclusive `BETWEEN` on the integer epoch `ts` · dedup on `(forwarder_org_id, agent_org_id, vin)` following the SQL not the docblock prose · the event discriminator column is `event_type`, **not** `type`.
* `static::clarityDb()`, never a hardcoded `clarity.` prefix · always `_peerdb_is_deleted = 0` · ClickHouse timestamps are UTC · read `hits_cold`, never `hits_hot` (its ~90-day TTL would silently truncate).

## Card contract

* Register at the **exact mockup slot** (row 2 col 3); the `cards()` array is mockup-relative, **not** append-only.
* Update the card-order assertion in `tests/Filament/Pages/DashboardCardExpansionTest.php`; keep `test_all_four_hit_cards_are_contiguous()` passing; bump the card count in `tests/Filament/Widgets/Dashboard/Concerns/DashboardCardVisibilityTest.php:191`.
* Respect the Today / Last 7d / This Month / Last Month filter; use the `lockedStat()` Pro-gate seam — `makeMetricWidget()` never calls `mount()`.

## Still open

1. **Which of the two rates** — 96.6% (orders ÷ orders, recommended) or 94.4% (plates ÷ claim triples). Both satisfy Oscar's wording; they differ by 2.2 points and by grain.
2. **Lookback bound.** "Before the claim" currently admits a sighting from any point in the vehicle's history, potentially years earlier. `hotlist.lookback_days` is the obvious bound. **Unmeasured**; raised with Oscar, unanswered.
3. **Zero-state** (epic-wide): 0 / N/A / hide for the ~83% of orgs with no claim activity.

## Acceptance criteria

* Card present at row 2 col 3, rendering a **percentage**, reproducing 96.6% for Jul 2026 fleet-wide from the shipped class itself (not a retyped query).
* Denominator comes from DEV-2463's `UniqueLprCaseClaimsQuery`, not a re-port of the claim CTE.
* Every aggregate uses `uniqExact`; a bare `count()` anywhere in the numerator is a defect (1.54x over on this population).
* Exactly **one** query per resolved window; the 13-month sparkline is one batched call of per-month rates, not a summed series.
* Zero-denominator orgs render without a division error.
* Respects the Today / Last 7d / This Month / Last Month filter. Non-Pro orgs do not see the value.
* Existing metrics and their values are unaffected.

## COMMENT 39127 (Dalton, 2026-09-04) — the measurement pass

**CORRECTION — this card is buildable. The data exists, the path is single-connection, and the "largest engineering unknown in the epic" framing is wrong.**

Earlier conclusion that this card was "structurally unbuildable, and the answer is exactly 0" came from testing `clarity.recovery_scans` — the scan↔recovery junction — where the case-claim population genuinely is empty: **zero** of 3,894 case-claimed and **zero** of 230,258 staged recoveries appear there, all-time, because `recovery_scans_mv` requires `recoveries.geo != (0,0)` and no staged recovery carries a geocoded point. That measurement is correct and it is why DEV-2464 and DEV-2469 are disjoint populations. But **this ticket never proposed that path.** Its own engineering note proposes `clarity.hits_cold`. The hits path is rich.

Successful `case-claim` events for Jul 2026, resolved to `hotlist_id` and joined to `clarity.hits_cold`:

| | Jul 2026 |
| --- | --- |
| case-claimed hotlist orders | 11,855 |
| of those, with ≥ 1 hit | **11,485 (96.9%)** |
| hits | 135,008 |
| distinct `scan_id` | 133,102 |
| distinct `ocr` | 11,346 |

Split at true timestamp precision — `hits_cold.scanned_at` against the claim's own epoch `ts`:

| timing | hits | distinct scans | distinct plates | orders |
| --- | --- | --- | --- | --- |
| **before the claim** | 120,979 | 119,356 | 11,310 | 11,449 |
| at or after the claim | 14,029 | 13,843 | 5,477 | 5,552 |

**89.6% of the activity is pre-claim**, the causally sensible reading, and 11,449 of 11,855 claimed orders (**96.6%**) had at least one pre-claim hit.

The real fork is the unit, and it is ~10.6x:
* distinct **plates** = 11,310 against 11,449 orders → **98.8%**, so plate-distinct is approximately an order count
* distinct **scans** = 119,356 against 120,979 hits → **98.7%**, so scan-distinct is approximately a hit count

⚠️ **And plate-distinct nearly duplicates its own neighbour.** DEV-2463 (_Unique LPR Case Claims_, row 1 col 3) reads **11,975** deduped for Jul 2026. A plate-distinct rendering of this card reads **11,310** — within **6%**. Two cards in the same grid showing near-identical numbers is the trap DEV-2469 fell into.

`zod.public_billing_events` is mirrored into ClickHouse (36,632,891 rows / 506.67 MiB), so the claim side and the hits side both live on `clarity_clickhouse`. Single connection, ordinary `ClickhouseQuery`.

Caveats:
* **All figures are fleet-wide.** The card is org-scoped, so per-org values will be far smaller. No per-org breakdown was done.
* **No lower bound on hit age.** "Before the claim" here admits a sighting from any point in the vehicle's history, potentially years earlier. A shipped card needs a lookback bound; `hotlist.lookback_days` is the obvious candidate. **Unmeasured** — the one sub-question still genuinely open (raised with Oscar).
* `hits_cold` duplicate hazard. Every figure above uses `uniqExact(id)`, never bare `count()`; that table's un-mergeable duplicates put `count()` ~1.33x over, and `FINAL` does **not** fix it because `hit_created_at` is mutable and in the sort key.
* The ticket is right that `hits_hot`'s ~90-day TTL would truncate; `hits_cold` was used exclusively.
* Single window (Jul 2026).
