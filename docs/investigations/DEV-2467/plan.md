# DEV-2467 — Plan

**Route:** feature · **Base:** main · **Node count:** 1 (single-node fast path)

## Why one node

The work is a single card and it is strictly sequential: the widget cannot be written before
the query class it consumes, and the registration edits cannot be verified before the widget
exists. There is no disjoint file partition, so a fan-out would serialise anyway with extra
worktree cost.

## Sizing gate — fired, split rejected

8 files, threshold 6. **Recommendation: do not split.** Four of the eight are the tests this
ticket's own contract mandates. The remaining four are two new classes and two one-line edits.
The DEV-2409 epic is already split one-metric-per-ticket, so this ticket *is* the split unit —
splitting further ships a query class with no consumer. DEV-2460 in this same epic fired the
same gate and recorded the same rejection.

## Perf gate — go, on prior measurement

`perf_validation.required = false`. Both adopted shapes were measured against production
ClickHouse Cloud by DEV-2466 on 2026-09-08:

| shape | read_rows | read_bytes | peak mem | server ms | source |
| --- | --- | --- | --- | --- | --- |
| 13-month series, GROUP BY month | 60,738,996 | 1.31 GB | 2.44 GB | 1,102 | findings §4 |
| single window, GROUP BY kit_id WITH ROLLUP | 5,573,739 | 142.7 MB | 249 MB | 606 | findings §7 |

The **combined** 13-month × per-kit rollup was never measured and is deliberately not adopted.
It would take the group count from 13 to roughly 13 × kits (~1,500 `uniqExact` states) against
2.18B rows.

## Operator decisions folded in

| # | Decision |
| --- | --- |
| Q1 | Colour = `success`, reused from Scan Count. No custom palette entry. |
| Q2 | Divergences are a **Build Record obligation only**. No card copy, no tooltip, no affiliate-report change. |
| Q3 | Pro gate = `FeatureEnum::COMPANY_METRICS`. The asymmetry with the ungated Scan Count sibling is intended. |

## Ordered steps

1. Baseline the AC-relevant tests **before** touching anything.
2. TDD `ScanPlateStatsBatchQuery` — test red, then green.
3. TDD `UniqueScanCounts` — test red, then green.
4. Register: `cards()` index 2 + `getStatColor()` arm + docblock corrections.
5. Fix both hardcoded card counts and the layout-order expectation.
6. Confirm `test_all_four_hit_cards_are_contiguous()` passes **unmodified**.
7. `composer format`.
8. Full suite, compared against the step-1 baseline.

## Invariants the worker may not violate

- No hardcoded `clarity.` prefix — `static::clarityDb()` only.
- No `FINAL` on `scans_cold` for this aggregate.
- No `kit_id` routing to reach an org — `org_id` leads the sort key.
- No `scans_hot`.
- No re-derived date arithmetic — `resolveCardRange()`, and `monthKeyStart()` anchored to day 01.
- No routing the batch through `ClickhouseQuery::query()` — it swallows `Throwable` to `[]`, which caches a failure as a zero.
- No routing the DEV-2468-shared key through `buildWidgetCacheKey()` — it prefixes the widget class basename, so a sibling card could not read it.
- No `try/catch` inside `getRangeTotal()` — the base class documents why a bounded-window failure must propagate.
