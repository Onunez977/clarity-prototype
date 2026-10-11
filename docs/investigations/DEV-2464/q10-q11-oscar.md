# DEV-2464 q10 / q11 — Oscar's answers, and the measurement that closes q11

Asked as DEV-2464 comment 39323 (2026-09-09, @-mention verified resolved to his account).
Answered same day.

## q10 — TitleMax disclosure: OUT OF SCOPE

> "copy we handle later, outside this ticket"

No card-level treatment in DEV-2464. TitleMax stays **in** the count (never filtered — removing it
measures as a 2.2%-2.8% error across the funnel), with a number that structurally understates them
because their repossessions happen inside their own RepoSystem. The disclosure copy is a separate
piece of work.

Nothing to build. Worth one line in the Build Record so the understatement is on the record rather
than discovered later.

## q11 — 83% read zero: THE DENOMINATOR WAS WRONG

> "we only need to care about Orgs that have a Clarity account. not worried about the 1270 orgs that
> did not have case claims"

He is right, and the reframing is decisive. My 83.1% used **every org with a repossession** (1,528)
as the denominator. Most of those are not Clarity customers at all — they appear in hotlist data but
cannot open the dashboard, so they were never going to see this card either way.

The population that matters is orgs **entitled to `COMPANY_METRICS`**. So I measured it.

### Deriving the entitled set

Mirrored `Org::isProEnabled(COMPANY_METRICS)` exactly against `zod.public_org_meta`, rather than
approximating it:

- `pro` = `platform_repo_pro` truthy **AND** (`platform_repo_pro_end` absent or not past)
- individual `platform_repo_pro_company_metrics` flag wins if present (**it is set for 0 orgs**)
- otherwise tier-based: a past `platform_repo_pro_downgrade_effective_at` swaps in the downgrade
  target; else `platform_repo_pro_tier`; else **`clarity_pro`** by the back-compat arm
- entitled iff the effective tier is `clarity_pro` or `clarity_pro_plus`

| | orgs |
| --- | --- |
| carry any `platform_repo_pro*` key | 4,118 |
| `pro` after the end-date check | 42 |
| entitled via an individual flag | 0 |
| entitled via tier | 29 |
| **entitled to `COMPANY_METRICS`** | **29** |

That lands on **exactly the 29** that `okf/concepts/company-metrics-dashboard.md` recorded
independently on 2026-09-08, which is the cross-check that the predicate is right rather than merely
plausible.

### The answer

Of the 29 orgs that can actually see this card:

| window | see a non-zero number | read 0 | median | max |
| --- | --- | --- | --- | --- |
| **Aug 2026** | **29 / 29 — 100.0%** | 0 | 35 | 973 |
| Jul 2026 | 25 / 29 — 86.2% | 4 | 30 | 867 |

**The card reads meaningfully for essentially its entire audience.** The "83% read zero" figure was
an artefact of counting orgs that were never the audience. q11 is closed, favourably.

### The one residual, and why it still matters
4 of 29 read zero in July — 13.8%. So a zero-state does occur; it is a legitimate "no claims this
month", not systemic emptiness. That keeps the **propagate-don't-swallow** decision worth having
(DEV-2463's precedent): a genuine zero and a failed query must not look alike. It does **not** justify
hiding the card, and no zero-state special-casing is needed.

## Net effect on the ticket
Both questions are closed and **neither adds work**. q10 removes a possible scope item; q11 removes a
possible visibility branch. The gate is now CLEAN — every question answered, both infra blockers
already on main.
