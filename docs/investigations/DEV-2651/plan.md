# DEV-2651 — Implementation plan

Nine nodes. Source of truth for machine consumption is `plan.json`; this file explains the
build order, why each node exists as its own node, and the TDD pinning test that must go red
before the node's change lands.

All nine operator decisions from `journal.md` (Phase 2 question gate) are treated as settled.
Nothing here relitigates them.

## Shape

```
wave 1 (parallel)     t1 trait        t2 unique-scan-counts     t6 leaderboard wording
                        |
wave 2 (parallel)     t3 case-claims  t4 lpr-repos  t5 recovery-counts
                        \______________|______________/
                                       |
wave 3 (serial)                      t7 registry cutover
                                       |
                                     t8 delete absorbed classes + repoint fixtures
                                       |
                                     t9 e2e (7 specs + helper)
```

`t7` is deliberately its own node and deliberately LAST among the PHP changes. Every one of
the four merges would otherwise touch `CompanyMetricsOverview::cards()`, which makes the
registry the only genuine contention point in the diff. Two orderings were possible:

- registry FIRST — the four absorbed keys vanish before their figures are folded onto the
  survivors, so every intermediate commit ships a grid that has *lost* a number;
- registry LAST — the merges are purely additive (a survivor gains a description line and a
  second dataset while its partner card still exists), so every intermediate commit is a
  superset of main. Only the final node removes anything.

Registry-last is chosen. The cost is one duplicated figure on the grid mid-branch, which
never reaches a reviewer's environment; the benefit is that no intermediate state is a
regression and `git bisect` over the branch stays meaningful.

`t8` (class deletion) is split out from `t7` because deleting a class still named in
`cards()` fatals at class load. They are one logical cutover in two safe halves.

## Nodes

### t1 — `Concerns/RatioAgainstUniqueScans` (new trait)

**Why its own node.** All three ratio merges need it, and building it inside the first merge
would serialise t3/t4/t5 behind that merge for no reason. It is also the only place decision 8
can be discharged: the standardized `UNAVAILABLE_VALUE` / `numeratorUnavailable` degrade path
lives on the trait, so `UniqueScansToLprRepossession`'s missing fail-safe is fixed *by
construction* when `LprRepossessions` adopts it, rather than by remembering to add it.

`SharedHitStatsData` cannot be reused — it is hit-card-only, written against
`HitStatsBatchQuery`/`HitPlateStatsBatchQuery` with a `$hitWidgetMap` keyed to the four hit
cards, and none of the four survivors uses it. It is a *pattern reference* (`:609-618` toStat
with `->description()`, `:669-687` two datasets, `:696-705` legend-on-expanded), not a parent.

The trait deliberately does **not** carry a denominator. Each survivor's existing headline
series *is* the denominator, which is what satisfies decision 9: the count dataset and the
ratio dataset are computed from the same month-key array, so they cannot bucket apart.

It also declares the new gate seam `canViewRatioFigure()`, defaulting to
`canViewCardData()`. Defaulting that way means t3 and t4 (fully Pro-gated cards) need no
override at all, and only `RecoveryCounts` carries the partial-gate override.

**TDD pin.** New `tests/.../Concerns/RatioAgainstUniqueScansTest.php` over a local stub card
and a `Counting*` `ScanPlateStatsBatchQuery` seam (never real ClickHouse):

1. the shared month/range cache keys contain no class name — mutate by reintroducing
   `buildWidgetCacheKey()` and the test must go red (this is the contract
   `UniqueScansLeaderboard`'s sparkline depends on);
2. a thrown numerator batch sets `$numeratorUnavailable`, writes **nothing** to the month
   keys, and writes the backoff marker — the "fabricated 0 at a one-year TTL" hazard;
3. an existing backoff marker suppresses the query *and* flags unavailable, rather than
   returning zeros;
4. `ratio()` returns `0.0` for a zero denominator;
5. `canViewRatioFigure()` returns whatever `canViewCardData()` returns when not overridden.

### t2 — merge `scan-count` into `unique-scan-counts`, and ungate it

**Why its own node.** It is the only merge with no ratio, so it does not depend on t1 and can
run in wave 1. It is also the node that carries decision 2's entitlement change.

Two traps, both load-bearing and both easy to get wrong:

- **Do not touch `ScanPlateStatsBatchQuery::sharedMonthCacheKey()` or the unique-plate fetch
  methods.** `UniqueScansLeaderboard::chartSeries()` is a *passive reader* of the month keys
  this card fills. Moving that namespace makes the leaderboard sparkline read 0 on a cold
  cache, and nothing on the leaderboard side would fail.
- **The ported total leg needs its own sub-namespace.** `UniqueScanCounts::buildWidgetCacheKey()`
  (`:220-225`) appends `':tz='` and is already used by `currentPeriodTotal()`/`getRangeTotal()`
  for the `today`/`last_7d`/`last_month` keys. A naive reuse for the *total* series would let
  the total overwrite the unique-plate window value under the same key. Use e.g.
  `buildWidgetCacheKey('scan-total:'.$dateType, $orgId)`.

Decision 9 for this card means the ported total leg buckets on `bucketZone()`
(`ScanPlateStatsBatchQuery::safeTimezone($viewer)`), not `ScanCount`'s raw viewer timezone.
The two datasets then share one axis; the residual viewer-tz question stays with the viewer-timezone follow-on.

Preserve `ScanCount`'s the known setTable-discard bug `Scan::hot()::query()` setTable-discard bug verbatim.

**TDD pin.** `UniqueScanCountsTest`:

1. `canViewCardData()` is **true** for an ADMIN on a non-entitled org — write this first; it
   fails against main's `:84-89` gate and is the whole of decision 2;
2. `toStat()->getDescription()` renders `'N total scans'` with the ported total, not the
   unique count (mutate by feeding the unique series to the description and it must go red);
3. `getData()` returns exactly two datasets over 13 shared labels, and `getOptions()` has
   **no** `scales.y1` — AC-4's explicit single-axis exception, asserted negatively so a
   copy-paste from t3 cannot slip in;
4. both series resolve their month keys from `bucketZone()`, asserted by recording the zone
   that reaches each query seam (`DB::listen` never fires for the ClickHouse driver, hence
   the recording-subclass pattern this repo already uses).

### t3 — merge `unique-scans-to-case-claims` into `unique-lpr-case-claims`

`UniqueLprCaseClaims` already buckets UTC (`:139-174`) and already labels UTC (`:186-210`),
so decision 9 costs nothing here beyond not introducing a second denominator fetch. The card
keeps its full `COMPANY_METRICS` gate, so `canViewRatioFigure()` is inherited unchanged.

The ticket's "collapse the private `heldFlexible()` duplicate onto `HeldFlexibleCache`" item
resolves itself: that duplicate is on `UniqueScansToCaseClaims` (`:457-491`), which t8
deletes. This survivor already uses the trait.

**TDD pin.** `UniqueLprCaseClaimsLabelAxisTest` is squarely in AC-4's blast radius, so the
axis assertions go there: `getOptions()['scales']['y1']` exists, is `position => 'right'`,
has `grid.drawOnChartArea => false`, and both axes carry a title. In `UniqueLprCaseClaimsTest`:
the `'N scans per case claim'` description, two datasets with `yAxisID` `y` and `y1` over the
same 13 labels, and the `'--'` degrade when the numerator seam throws.

### t4 — merge `unique-scans-to-lpr-repossession` into `lpr-repossessions`

Structurally identical to t3. The point of interest is decision 8: the absorbed card has
**zero** `UNAVAILABLE_VALUE`/`numeratorUnavailable` occurrences today, against 6/9 and 4/14
in its two siblings, so on a numerator failure it renders a fabricated ratio. Adopting t1's
trait is how that gets fixed, and the degrade test below is a case that could not have
existed before this ticket.

Leave `COLD_BLOCK_SECONDS`/`COLD_LOCK_SECONDS`/`REFRESH_LOCK_SECONDS` (`:101-137`) alone —
they are derived from this card's measured 33.7s contended p99 and re-measuring them is
out of scope.

**TDD pin.** `LprRepossessionsTest`: stub the numerator seam to throw and assert the headline
still renders while the ratio renders `UNAVAILABLE_VALUE` and `getData()` suppresses the ratio
dataset. Then the description text, the two `yAxisID`s, and `scales.y1`.

### t5 — merge `unique-scans-to-repo` into `recovery-counts` (three changes at once)

The largest node. `RecoveryCounts` is 51 lines today: no ratio machinery, no herd guard, no
gate, and 13 per-month Eloquent round trips (`:32-38`).

1. **the recovery-totals batching work pulled in (decision 5).** The headline moves onto `RecoveryTotalsBatchQuery`
   behind `HeldFlexibleCache`, the shape `UniqueScansToRepo.php:560-627` already ships. This
   is not only an efficiency change: once the headline and the ratio denominator sit on one
   card, `RecoveryTotalsBatchQuery`'s own docblock warning that the two paths can disagree by
   a few rows within a TTL becomes a *visible* contradiction. Batching both removes the
   possibility rather than narrowing it.
2. **The trait.** Numerator, degrade path, UTC label axis. Note the consequence to state in
   the docblock: the card's labels move from viewer-tz to UTC. The label axis MUST co-vary
   with the data axis — `getData()` zips labels to data positionally and `generateColors()`
   matches the highlight against the label *string*, so a one-month skew mislabels all 13
   bars silently rather than failing.
3. **The new partial-gate seam (decision 3).** `canViewCardData()` stays at its ungated
   default so Total Repossessions is visible to all orgs exactly as today.
   `canViewRatioFigure()` is overridden with the sanctioned Pro shape
   (`auth()->check() && static::canView() && (Feature::active(COMPANY_METRICS) || session()->has('admin_impersonator'))`).
   When it is false the description line is omitted **and** `getData()` returns the single
   count dataset with no `y1`. The ratio must never be *computed* on that path — the whole
   point of the gate is to keep the tz=UTC `ScanPlateStatsBatchQuery` scan (60.7M rows /
   2.44 GB peak) bounded at the ~29 entitled orgs.

Decision 9's residual for this card: `repo_date` is a ClickHouse `Date` with no time axis and
cannot be shifted. Record that in the class docblock — the viewer-timezone follow-on AC-4 requires exactly this.

**TDD pin.** `RecoveryCountsTest`, in this order:

1. the headline issues **one** batched call, not 13 — stub `RecoveryTotalsBatchQuery` and
   count invocations; this is the recovery-totals batching work and it fails against main immediately;
2. the headline and the ratio denominator read the same month keys (mutate by re-pointing the
   headline at the Eloquent path and it must go red);
3. **the anti-leak case:** for a non-entitled ADMIN, the numerator query seam is never
   invoked, the description is absent, `getData()` has one dataset, and `getOptions()` has no
   `y1` — while the headline still renders its real value;
4. for an entitled ADMIN: description present, two datasets, `y1` present.

### t6 — drop "unique" from the leaderboard's five user-facing sites

Independent of everything else; wave 1. Five sites, all verified: `:58` heading, `:110` chart
subtitle, `:141` axis title, `:255` dataset label, `:276-277` description (twice). The class
name, the card key `unique-scans-leaderboard`, and the cache namespace do **not** change —
AC-6 forbids renaming a surviving key, and the key is also the toggle key and the cache
namespace.

**TDD pin.** One test asserting no case-insensitive `'unique'` survives in the rendered
heading, the `RawJs` options source, the dataset label or the description — plus an explicit
`assertSame('unique-scans-leaderboard', $widget->getCardKey())` so the rename stops at the
copy.

### t7 — registry cutover

The 12-card order (decision 1, the ticket's proposal as written):

| # | key | # | key |
|---|---|---|---|
| 0 | `unique-scan-counts` | 6 | `staging-live-hits` |
| 1 | `unique-lpr-case-claims` | 7 | `staging-historical-hits` |
| 2 | `lpr-repossessions` | 8 | `missed-opportunities` |
| 3 | `recovery-counts` | 9 | `unique-scans-leaderboard` |
| 4 | `live-hits` | 10 | `recovery-leader` |
| 5 | `historical-hits` | 11 | `staging-live-hit-leader` |

All three pinned constraints hold: leaderboards are the last three in order; the four hit
cards are contiguous (4-7); `unique-scan-counts` (0) precedes `unique-scans-leaderboard` (9).

Rewrite the class docblock from `DashboardCardExpansionTest.php:156-159`, **not** from the
class's own prose at `:52-56` — that prose already contradicts its own `cards()` array on
main. Drop the recent card-reorder FREEZE paragraph about Unique Scans to Case Claims's slot; that card
no longer exists.

The gated allowlist becomes five keys:
`staging-live-hit-leader`, `missed-opportunities`, `unique-lpr-case-claims`,
`unique-scans-leaderboard`, `lpr-repossessions`. `unique-scan-counts` leaves it (decision 2);
`recovery-counts` does **not** join it (decision 3 gates a *figure*, not the card). Keep the
allowlist explicit rather than a tautology over `cards()` — gating a card is a deliberate,
reviewed act.

Hygiene in the same node: `DashboardWidget::getStatColor()`'s four now-unreachable arms
(`:326-341`; an extra arm is safe in PHP, a missing one is not, so this is cleanup not a fix),
`DashboardMetricCard.php:41` (names `UniqueScanCounts` as a `canViewCardData()` overrider — it
no longer is), and `SharedHitStatsData.php:693` (excludes `ScanCount`, which is gone).

**TDD pin.** `DashboardCardExpansionTest::test_company_metrics_cards_are_in_layout_order()`
rewritten to the 12-key map *first* — it fails against main by construction — and
`assertCount(12)` at `:95`. `DashboardCardVisibilityTest`: `assertCount(12)` at `:208` and the
five-key allowlist at `:59-73` and `:481-497`. Both files also use `'scan-count'` as a generic
*real* key in unrelated tests (`:238-239`, `:389`, `:237`); repoint those at
`'unique-scan-counts'`.

### t8 — delete the absorbed classes, repoint the fixtures

Decision 4. Deleting `ScanCount` is not free: three sites use it as a generic non-hit-card
exemplar. `UniqueScanCounts` is the replacement in all three — it is still a `DashboardWidget`
+ `ExplainsCardQuery`, still the scan family, and after t2 it is ungated, so the fixtures need
no entitlement setup:

- `tests/Filament/Pages/ThrowingQueryCard.php:8` — `extends ScanCount` fatals at class load;
- `DashboardMonthLabelsTest.php:7,95,173` — the month-bucket overflow guard month-bucket-overflow guard;
- `DashboardCardMetricsTest.php:10,206,451,469,475` — includes a `Livewire::test` mount, so
  the replacement must be mountable.

The hazard to guard against here is a fixture swap that silently defeats the regression it
guards. After repointing, revert the `monthKeyStart()` anchoring by hand and confirm
`DashboardMonthLabelsTest` goes red. If it does not, the fixture swap lost the coverage.

Before deleting ~3,300 lines of the absorbed cards' own tests, read them for assertions the
survivors now own — the shared-numerator month-key contract, the herd-guard windows, the `--`
degrade — and fold anything t1/t3/t4/t5 did not already reproduce into the trait test or the
survivor's test. Deleting coverage along with the class is the easy mistake in this node.

**TDD pin.** No new behaviour, so the pin is negative and mechanical: the three repointed
suites must pass *and* still fail under their original mutations, and
`DashboardCardExpansionTest` must still pass with the four classes gone.

### t9 — the seven e2e specs

Decision 6: fix all seven, including the two already red on main.

**Establish the red baseline first.** On `main`, run `unique-lpr-case-claims.spec.ts` and
`lpr-repossessions.spec.ts` and record the failures. Both assert
`claimsAt === recoveryCountsAt + 1`, which is `2 === 4` against current `cards()`. The
assertions came from an earlier card-slot mockup ticket/an earlier adjacency-spec ticket and the recent card-reorder (`df3ad2560`, 2026-09-16) reordered
`cards()` without updating them. Nothing caught it because Playwright is **not** in CI — no
workflow references `playwright` or `tests/e2e`; `npm run test:e2e` is the only entry point.
Say this explicitly in the PR body so the pre-existing drift is not read as this diff's
breakage.

Two specs lose their subject card entirely (`unique-scans-to-case-claims.spec.ts`,
`unique-scans-to-lpr-repossession.spec.ts`): delete them, folding any assertion that survives
the merge into the survivors' specs.

While re-deriving the adjacency arithmetic, prefer an index-of-key lookup over
`recoveryCountsAt + N` offsets. The offsets are exactly why two specs rotted silently across
a reorder that no test caught.

## Cross-cutting rules for every node

- Tests stub the query classes using the existing `Counting*` / `Recording*` subclass pattern.
  Never hit real ClickHouse.
- Never rename a surviving card key: the key is the cache namespace and the toggle key.
- Do not touch `app/Clickhouse/Queries/*`.
- Do not edit `okf/`. `company-metrics-dashboard.md` is stale on main *independently* of this
  ticket (16 cards, a 5-card gated list where the code has nine); correct that drift and this
  ticket's change together in one post-merge pass.
- Run `composer format` (Pint) before finishing.
- Phase 6 QA risk carried forward: the hardcoded clarity-prefix issue (`ScanStatsBatchQuery` hardcodes `clarity.`) will
  500 the Company Metrics page under local ClickHouse QA. Work around it; do not fix it here.
