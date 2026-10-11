# DEV-2463 — driver verification (read-only, production)

Everything here was measured by the driver against production ClickHouse Cloud as
`readonly_user` on **2026-09-08**, or read from Backyard `origin/deploy`. Aggregates and
server-side counters only — no raw rows.

## 1. `rejectInternalOrgs` — the port is faithful, but the ticket's rule is under-specified

**Read from source.** `git show origin/deploy:app/Models/Core/Extended/RepoReport.php`:

- `EXTERNAL_REPO_SYSTEM_NEEDLE = 'titlemax'` (:54), `INTERNAL_ORG_NEEDLE = 'insightlpr'` (:61).
- `isInternalTestOrg()` (:327) — `strtolower(preg_replace('/[^a-z0-9]+/i', '', $name))` then
  `str_contains($normalized, 'insightlpr')`. **Non-alphanumerics stripped first**, exactly as
  the ticket records.
- `isExternalRepoSystem()` (:319) — `str_contains(strtolower($forwarder), 'titlemax')`, **no**
  normalisation, and used only by `decorateRow()` for a display flag. Nothing filters on it.
  The ticket's titlemax rule is confirmed against source.

**The under-specification.** `rejectInternalOrgs(array $rows, string $nameKey)` (:340) filters
**one** name key, and the two call sites pass whichever axis the report is *grouped by*:
`'forwarder'` for the funnel view (:191) and `'agent'` for the agent breakdown (:253). It is a
**row-level filter on the grouping key**, not a claim-level filter on both sides.

This card has no grouping key — it is one number for a viewing org counted on *either* side. So
"reject insightlpr" has three possible readings. Measured, Jul 2026:

| reading | all claims | successful | deduped |
| --- | --- | --- | --- |
| 0 — no exclusion | 15,160 | 14,288 | 11,975 |
| 1 — drop if **either** side internal | **15,128** | **14,288** | **11,975** |
| 2 — drop if **forwarder** internal (backyard funnel view) | **15,128** | **14,288** | **11,975** |
| 3 — drop if **agent** internal (backyard agent view) | 15,160 | 14,288 | 11,975 |

Diagnostics that explain it: `fwd_internal_rows = 32`, **`agt_internal_rows = 0`**,
`both_internal_rows = 0`.

**InsightLPR never appears as an agent.** So readings 1 and 2 are numerically identical, both
hit the ticket's 15,128 target, and the choice between them is a distinction without a
difference *today*. It stops being one the first time InsightLPR claims as an agent.

**Driver decision (recorded, not assumed silently):** implement reading **1 — symmetric, drop
if either side is internal.** Rationale: this card's own org axis is symmetric
(`forwarder_org_id = ? OR agent_org_id = ?`), so a symmetric exclusion is the internally
consistent choice; it reproduces the AC target exactly; and it costs zero rows today. Flagged
for confirmation rather than treated as settled, because reading 2 is the more literal port.

## 2. The ticket's "83% read zero" figure has a denominator problem

Comment 39128 Q6 states: *"only **256 orgs** appear on either side of a deduped case claim …
against **1,536 orgs** with any repossession. So roughly **83% of orgs** would see a permanent 0."*

Measured, Jul 2026: **256** orgs appear on either side of a deduped case claim — that number is
**confirmed exactly**. But the total org count is **726,869**, not 1,536.

So there are two true statements with very different force:

| denominator | figure | reads zero |
| --- | --- | --- |
| orgs with any repossession (1,536) | 256 / 1,536 | **83%** |
| all orgs in `zod.public_orgs` (726,869) | 256 / 726,869 | **99.96%** |

Neither is the decision-relevant one. **The denominator that matters is "orgs that can see this
dashboard"** — Pro-tier orgs holding `FeatureEnum::COMPANY_METRICS`. That count lives in
Postgres (`org_meta` / Pennant), not in ClickHouse, and **the driver did not measure it.** The
presentation question (show 0 / show N/A / hide the card) should be decided against that number,
not against either figure above.

Recording this because the 83% was carried onto the ticket as the basis for a product decision,
and it is the narrowest of the three available denominators.

## 2b. RESOLVED — the real zero-rate, measured against the denominator that matters

§2 said the decision-relevant denominator is "orgs that can see this dashboard" and that the
driver had not measured it. **Measured now** (read-only, 2026-09-08), by replicating
`Org::isProEnabled(FeatureEnum::COMPANY_METRICS)` against `zod.public_org_meta_latest` —
including the `platform_repo_pro` + `platform_repo_pro_end` expiry check, the individual
`platform_repo_pro_company_metrics` flag override, the pending-downgrade override, and the
back-compat rule that a Pro org with **no** tier key maps to `CLARITY_PRO`.

Entitlement, production:

| | count |
| --- | --- |
| orgs with Pro enabled at all | **42** |
| of those, holding `COMPANY_METRICS` | **29** (27 `clarity_pro` + 2 `clarity_pro_plus`) |
| `pro_lite` (no `COMPANY_METRICS`) | 13 |

And the zero-rate among those 29, per dashboard filter:

| dashboard filter | entitled | with a claim | read ZERO | % zero |
| --- | --- | --- | --- | --- |
| Today | 29 | 17 | 12 | **41%** |
| Last 7 days | 29 | 25 | 4 | **14%** |
| This month | 29 | 25 | 4 | **14%** |
| Last month (Jul 2026) | 29 | 25 | 4 | **14%** |

**This inverts the premise of the ticket's Q6.** The concern was framed as "83% of orgs would
see a permanent 0". Against the audience that can actually see the card, the figure is **14%**
on three of the four filters, and the worst case is **41% on Today** — which is not a
population problem at all, it is the ordinary behaviour of a daily metric on a business that
does not claim every day.

The three denominators, side by side, to make the gap explicit:

| denominator | reads zero | is it decision-relevant? |
| --- | --- | --- |
| all orgs (726,869) | 99.96% | no — the vast majority cannot see the dashboard |
| orgs with any repossession (1,536) | 83% | no — most are not Pro-entitled |
| **orgs entitled to this card (29)** | **14%** (41% on Today) | **yes** |

Only **4 orgs** read a persistent zero across a month-long window, and they are legitimately
zero — Pro customers who do not do case claims.

**What survives as a real requirement:** not a presentation treatment, but the code hazard
already folded into the ACs. `ClickhouseQuery::query()` swallows `Throwable` to `[]`, so a
failed query renders as `0` — and since a genuine `0` is now known to be a normal, expected
reading on this card (12 of 29 orgs on Today), the two states MUST be distinguishable. That is
the finding, not the framing.

## 3. `ls-claim` — confirmed out, from three independent directions

Already established on the ticket; re-confirmed here only where it was cheap:

- `event_type` really is the discriminator column, and `'case-claim'` / `'ls-claim'` really are
  the values (port rule 4 holds).
- Zero `ls-claim` events in Jul 2026 — so including or excluding it cannot move any target figure
  for the cross-check window.

## 4. What the driver did NOT verify

- ~~Pro-tier org count~~ — **now measured, see §2b.** 29 entitled orgs; 14% read zero on a month window, 41% on Today.
- **`titlemax` disclosure** — this card has no repo *rate*, and titlemax's claims are all counted
  (429/311/306, never filtered). The undercount comment 39128 documents is a property of **DB
  repos**, which this card does not compute. So the ticket's Q3 looks **N/A here** and live on
  DEV-2464 — but that is the driver's reading of the metric, not a measured fact.
- **Concurrency**, and a genuinely **cold** read — see `perf-measurement.md` §6.
