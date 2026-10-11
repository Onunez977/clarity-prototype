# DEV-2463 — Phase 2 question-gate interview (verbatim)

Operator: Dalton McCleery · 2026-09-08 · mode: normal

## Q1 — Which funnel stage feeds the card?
**Answer: `deduped` (11,975).** The billed count; matches the card name "Unique". Recorded for AC-7.
Driver recommendation was `deduped`; all three prep candidates concurred.

## Q6 — Base class: extend `DashboardWidget` or bypass it?
**Answer: EXTEND `DashboardWidget`.** This OVERRODE the driver's recommendation (bypass), which
had 2 of 3 candidates, DEV-2464, and both recently shipped siblings behind it.

Consequences the plan must carry:
- The ticket's literal card-contract instruction now applies as written: add an explicit
  `'unique-lpr-case-claims'` arm to `getStatColor()`.
- `getMonthlyData()` MUST be overridden so the sparkline reads the ONE 13-month
  `toStartOfMonth` grouped query, not the base class's up-to-13-separate-cached-query loop.
  That override is what satisfies the one-query AC while keeping the inheritance.
- The single-window path (`today` / `last_7d`) needs its own one-query implementation.
- Open detail for the plan: the grep backstop found `getStatColor()` in BOTH
  `DashboardWidget:299` and `RendersDashboardStat:165`. Confirm which owns the match arms.

## Q7 — Most orgs read 0: show 0, N/A, or hide?
**Answer: SHOW 0, no special treatment** — decided after the driver went and measured the
denominator the operator asked for.

The ticket's premise did not survive measurement. Comment 39128 framed this as "83% of orgs
would see a permanent 0". Measured against the orgs that can actually see the card:

| denominator | reads zero |
| --- | --- |
| all orgs (726,869) | 99.96% |
| orgs with any repossession (1,536) — the ticket's figure | 83% |
| **orgs entitled to this card (29)** | **14%** |

29 orgs hold `COMPANY_METRICS` (27 `clarity_pro` + 2 `clarity_pro_plus`, of 42 Pro orgs).
Only 4 read zero across a month; the worst case is 41% on the **Today** filter, which is
ordinary behaviour for a daily metric rather than a population problem.

**What survives as a hard requirement:** because a genuine `0` is now a known-normal reading
(12 of 29 orgs on Today), a FAILED query must be visibly distinct from it.
`ClickhouseQuery::query()` swallows `Throwable` to `[]` and would render `0`. That AC stands.

## Q8 — Carry the two shared-infra fixes from DEV-2464?
**Answer: YES — cherry-pick `cc34c05c`'s exact diff** for both
`database/schema/clickhouse-schema.sql` (+`zod.public_billing_events`) and
`tests/Concerns/RequiresClickhouse.php` (+4 `zod.public_*` truncations). Taking the exact diff
avoids two sessions authoring the same schema line differently, and means DEV-2464 need not
re-touch either blast-radius file when it resumes.

---

## Answered earlier from code / measurement (not put to the operator)

| # | question | answer |
| --- | --- | --- |
| Q2 | `ls-claim` in or out? | OUT — `case-claim` only. Use `BillingEventType::CASE_CLAIM`. |
| Q3 | titlemax disclosure? | N/A to this card — the undercount is a property of the repo *rate*, which this card never computes. Stays live on DEV-2464. |
| Q4 | Pennant gate / tier? | No new case — reuse `FeatureEnum::COMPANY_METRICS`. Comment 39129's instruction is stale relative to shipped code. |
| Q5 | Eloquent vs raw SQL? | Moot on the ClickHouse route. But do NOT extend `ClickhouseQuery` — its `query()` swallows `Throwable`. |
