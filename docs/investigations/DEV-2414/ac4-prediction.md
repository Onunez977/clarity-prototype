# DEV-2414 — AC-4 predicted from production, read-only, BEFORE deploy

The ticket's own baseline comment asked for this: *"Worth doing before the fix so the post-change
number can be predicted rather than just observed."* This is that measurement.

**Method.** Both tables the matcher reads — `clarity.sc_positions` and
`clarity.scout_open_addresses` — live in ClickHouse Cloud, so the entire matcher runs against
production **read-only** (pure SELECT; no writes, no `platform.scout_check_ins` involvement). For
each sampled kit the OLD grain (`GROUP BY address_id, hour_bucket`) and the NEW grain
(`address_hour` → `ranked` → `rn = 1`) were run over the **same kit, same 15-minute window, same
book**, and their output rows counted. Real orgs, real staging partners resolved from
`zod.public_approval_list`, real bbox + 160.934 m geoDistance.

## Result

**19 kits sampled (16 with matches), one 15-minute window each.**

| | |
|---|---|
| OLD grain total | **332** |
| NEW grain total | **216** |
| **Fan-out removed** | **1.54x** |
| baseline | 2.07 / kit / 15-min |
| **predicted post-change** | **~1.35 / kit** |
| reduction | **~35%** |
| target ~1.0 | **not met on this evidence** |

Per-kit ratios are tight — mostly 1.3–1.9, with 1.00 where a kit had no co-location at all, and two
high outliers (3.0, 4.0) on counts too small to carry weight. Full sample:
the local-only sample table (not committed — raw per-kit rows stay out of the repo per the derivation-log rule; the aggregates above are the record).

Corroborating, fleet-wide: the book holds **4,485,647 rows over 2,351,184 distinct geos = 1.91
address rows per geo**, and its cluster distribution is

| cluster size | book rows | distinct geos |
|---|---|---|
| 1 | 1,397,272 | 1,397,272 |
| 2–9 | 2,699,409 | 934,313 |
| 10–99 | 312,842 | 19,464 |
| 100–999 | 27,190 | 125 |
| 1000+ | 48,934 | 10 |

The measured 1.54x sits below the naive 1.91x, which is expected: a kit's bbox sees a biased slice
of the book, not the fleet-wide average.

## What this means

The change works and removes about a third of the volume — but **on this evidence it does not reach
the ~1.0 target on its own.** The ticket already anticipated exactly this:

> *"If measurement shows it is still materially above target after this change, that is a follow-up
> conversation about a per-kit cap in the command, not a change to this ticket."*

So this is the ticket's own contingency, triggered with a number attached, before shipping.

## Honest limits of this estimate

1. **It measures matcher OUTPUT rows, not net `recorded`.** The 2.07 baseline counted rows actually
   inserted (`insertOrIgnore` absorbs the ~3x overlapping re-matches). The absolute OLD figure here
   (332 over 19 kits) is therefore NOT comparable to 2.07 — a different quantity. What transfers is
   the **ratio**, where numerator and denominator are the same quantity for the same kit and window.
2. **Applying that ratio to a differently-measured baseline is an assumption** — that dedup
   interacts with the insertOrIgnore absorption similarly on both sides. Reasonable, unverified.
3. **The residual gap is NOT captured.** Winner-flips need multiple cycles with book churn; a
   single window cannot show them, and they push the real number **up**. So ~1.35 is arguably a
   **lower** bound.
4. One 15-minute window per kit, 19 of a ~44-kit fleet, single point in time.

None of this displaces AC-4's post-deploy measurement — it predicts it.

## Query shapes (no raw rows)

- `SELECT n_bucket, sum(n), count() FROM (SELECT count() AS n, multiIf(...) AS n_bucket FROM clarity.scout_open_addresses GROUP BY tupleElement(geo,1), tupleElement(geo,2)) GROUP BY n_bucket` → the distribution above.
- Per kit: the full matcher CTE chain with `old_grain` and `ranked` computed side by side, returning only `count()` from each. Parameter classes: kit_mapping (lowercased), org_id, staging org id list, a 15-minute window anchored to the kit's last fix.
- All run over HTTPS against ClickHouse Cloud with `max_execution_time` caps. Read-only throughout.
