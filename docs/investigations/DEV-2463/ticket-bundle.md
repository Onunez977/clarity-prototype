# DEV-2463 — Company Metrics — Unique LPR Case Claims card

- **App:** Clarity (platform) · **Type:** Story · **Route:** feature · **Status:** To Do
- **Epic/parent:** DEV-2409 — Company Metrics Dashboard — mockup build-out (9 cards, one per ticket) [In Progress]
- **Blocked by:** DEV-2460 — foundation: mockup grid layout, 2 relabels, Kit Users move, Pro-gate seam [QA Complete — VERIFIED MERGED on origin/main]
- **Blocks:** DEV-2464 — LPR Repossessions card on the case-claim → repo funnel [In Progress, on HOLD]
- **Blocks:** DEV-2465 — Unique Scans to Case Claims card [BLOCKED: no definition exists]
- **Reporter/assignee:** Dalton McCleery · **Priority:** Medium · **Labels:** none

## The metric

**Unique LPR Case Claims** — unique plate-level case-claim count. **Grid slot: row 1, col 3.**

Cheapest of the three case-claim cards: touches only `billing_events` + `api_keys` (+ `orgs`
for the name). Never `hotlist` or `hotlist_status_log`.

## Successor to DEV-2424

DEV-2424 was closed `Done` on a blocker that was factually wrong ("no case-claim data exists
in Clarity"). Prep had swept for a case-claim **column**; case claims are **billing events**.
Verified against production: **359,857** `case-claim` events (2022-11-04 → today) and
**6,850** `ls-claim`. `additional` carries `hotlist_id` (366,331 rows), `claimed_by_org_id`,
`vin`. **246,571** distinct hotlist orders have been case-claimed.

## Spec source

**Backyard** `app/Models/Core/Extended/RepoReport.php` on `origin/deploy` (DEV-2129 + DEV-2388).
Read with `git show origin/deploy:<path>`; the local checkout runs behind.

| stage | definition |
| --- | --- |
| All case claims | raw case-claim events including failures |
| Successful | `success = true` |
| Deduped | DISTINCT `(forwarder_org_id, agent_org_id, vin)` among successful — the billed count |
| DB repos | deduped pairs with live `hotlist.status = 3` and a `hotlist_status_log.new_status = 3` transition on/after `claim_date − 7 days` |
| Repo rate | `db_repos / deduped` |

**Forwarder** = the api key's owning org. **Agent** = `claimed_by_org_id`.

## Decided already — do NOT re-litigate

| | decision |
| --- | --- |
| **Org axis** | `(forwarder_org_id = ? OR agent_org_id = ?)` — count the org on either side. Matches `RepoReport::orgDbReposSql()`, which `ClarityCommercialFeeSummary` already bills customers off. |
| **Route** | ticket body says **Postgres** `zod`, porting near-verbatim. **SUPERSEDED — see comment 39116 below.** |
| **Caching** | **One** `Cache::flexible([3600, 86400])` query per resolved window. Do **not** wire through `DashboardWidget::getMonthlyData()`'s 13-month loop. |
| **Exclusions** | Reject `insightlpr` **only**. **Never filter** `titlemax` in the counting path. |
| **Staging** | Not a meaningful extra filter — 99.8% of case claims are already on staged orders. Oscar's "staging repos" is phrasing, not an extra predicate. |
| **Prod Postgres cost** | Accepted unmeasured risk (no live EXPLAIN from sandbox). Mitigated by caching. **Moot if the route is ClickHouse.** |

### Why the titlemax rule matters

`isExternalRepoSystem()` (needle `titlemax`) only sets a **display flag** via `decorateRow()` —
nothing filters on it. `rejectInternalOrgs()` (needle `insightlpr`, non-alphanumerics stripped
first) is the **only** helper that removes rows. Collapsing them is a **−2.8% / −2.2% / −2.6%**
error across the three stages. Measured `titlemax` contribution for Jul 2026: **429 / 311 / 306**.

### Port rules the implementation must not get wrong

1. Reject `insightlpr` only; `titlemax` stays in every count.
2. Window is a **UTC calendar month**, inclusive `BETWEEN` on the integer epoch `ts`.
3. `all_claims` / `successful` come from `raw` with **no** success filter. `deduped` groups on
   `(forwarder_org_id, agent_org_id, vin)` among successful rows with a non-empty normalised
   VIN — **follow the SQL, not the docblock's "distinct (vin, agent)" prose**, which
   under-counts when one agent claims the same VIN through two forwarders.
4. The event discriminator column is `event_type`, **not** `type`.

## Cross-check target (Jul 2026, ls-claim off)

| stage | total | largest forwarder |
| --- | --- | --- |
| all claims | 15,128 | 9,340 |
| successful | 14,288 | 9,262 |
| deduped | 11,975 | 7,230 |

## The five open questions this ticket OWNS

1. **Which stage feeds the card** — `deduped` (billed count) or `all_claims` (raw incl. failures)?
   Label says "Unique" → points at `deduped`, but **confirm rather than infer**.
2. `ls-claim` in or out? Backyard defaults it off. 0 events Jul 2026; 6,850 lifetime.
3. **If the viewing org is titlemax**, surface that its number undercounts, or ship silently?
4. **Pennant gate?** Billing-adjacent data on a customer-facing card.
5. **Eloquent on `zod` vs a raw-SQL query class?**

## Card contract

- Register the key in `CompanyMetricsOverview::cards()` at its **exact mockup slot (row 1 col 3)**.
  Mockup-relative, **not** append-only — do not "tidy" the gaps back into append order.
- Add an explicit `getStatColor()` arm in `DashboardWidget` (`default => 'gray'` fails silently).
- Update `tests/Filament/Pages/DashboardCardExpansionTest.php`'s card-order assertion; keep
  `test_all_four_hit_cards_are_contiguous()` passing.
- Pro gate: `makeMetricWidget()` never calls `mount()` — use the `lockedStat()` seam from the
  foundation ticket.

## Acceptance criteria

- Reproduces the corresponding funnel stage of Brek's Repo Report for the same window and org
  set (all 15,128 / successful 14,288 / deduped 11,975 for Jul 2026, ls-claim off).
- **Exactly one cached query per resolved window** — no per-month multiplication.
- Respects the Today / Last 7d / This Month / Last Month filter.
- Non-Pro orgs do not see the value.
- Trend sparkline consistent with existing card styling.
- Existing metrics and their values are unaffected.
- The five open questions above are answered and recorded in the Build Record.

---

# Comment 39116 (2026-09-04) — ROUTE CORRECTION: the funnel reproduces in ClickHouse

All five tables are already mirrored:

| table | rows | size |
| --- | --- | --- |
| `zod.public_billing_events` | 36,632,891 | 506.67 MiB |
| `zod.public_api_keys` | 4,140 | 62.48 KiB |
| `zod.public_hotlist` | 156,096,149 | 5.30 GiB |
| `zod.public_hotlist_status_log` | 388,885,025 | 2.31 GiB |
| `zod.public_orgs` | 726,916 | 9.12 MiB |

Brek's `claimsCte()` + `recoveriesCte()` + funnel SQL translated to ClickHouse
(`additional->>'x'` → `JSONExtractString`, `::int` → `toInt32OrNull`, `hotlist` deduped via
`argMax(..., _peerdb_version)` grouped by `id`). Run against production ClickHouse Cloud,
read-only, Jul 2026, ls-claim off:

| stage | screenshot | CH port |
| --- | --- | --- |
| all claims | 15,128 | **15,128** |
| successful | 14,288 | **14,288** |
| deduped | 11,975 | **11,975** |
| DB repos | 9,116 | 9,130 (+14) |
| repo rate | 76.1% | 76.2% |

Per-forwarder matches to the unit: Location Services 9,340 / 9,262 / 7,230; TitleMax -
RepoSystems 429 / 311 / 306; InsightLPR 32 all-claims / 0 successful (self-check on the
exclusion rule: 15,160 − 32 = 15,128).

**Recommendation: switch the route to ClickHouse** for this card and DEV-2464, keeping every
other decision unchanged. Q5 becomes moot — it's an ordinary `ClickhouseQuery` subclass.

**Not yet established:** no server-side resource numbers. `readonly_user` lacks
`READ ON REMOTE`, so `clusterAllReplicas(system.query_log)` is denied. Only client wall clock.

---

# Comment 39128 (2026-09-04) — AUDIT of the five open questions

- **Q1 — which stage?** *Narrowed.* DB repos is **already in commercial use**: Backyard's
  `ClarityCommercialFeeSummary` calls `RepoReport::monthToDateDbRepos($now, $orgId)` at two
  call sites. `totalDbRepos()`'s docblock names its consumer: "the MTD figure the Clarity
  Dashboard Repo Rev card uses." For THIS card, "Unique" + `deduped` **being** the billed count
  point the same way. Needs confirmation, not discussion.
- **Q2 — `ls-claim`?** **Answered: out.** `case-claim` 364,106 / 321,930 successful lifetime vs
  `ls-claim` 6,886 / 5,754 — **1.8%**, zero in Jul 2026. `clarity.recoveries_mv` — the only
  case-claim signal wired into Clarity today — hardcodes `event_type = 'case-claim'`. Backyard's
  default is off, "so the numbers match accounting."
- **Q3 — titlemax disclosure.** *Genuinely product.* Correcting an earlier pass: the undercount
  **is real** and has nothing to do with our filtering — Backyard's own constant explains it
  ("Repos happen inside external RepoSystems for this forwarder and never fully hit our RDN
  clone"). Measured Jul 2026 repo rates: Location Services 74.2%, American Recovery 82.1%,
  Millennium 80.9%, Primeritus 79.1%, **TitleMax 51.0%**, Burns 80.7%, Victory 73.0%, Bell &
  Williams 89.3%. TitleMax reads **~28 points below the pack** purely as a data-coverage
  artifact. Raised with Oscar as customer-facing copy.
- **Q4 — Pennant gate?** *Narrowed to "which tier".* `FeatureEnum` carries 17
  `platform_repo_pro_*` cases; `ProTierEnum::features()` maps BASIC none / PRO_LITE 5 /
  CLARITY_PRO 13 / CLARITY_PRO_PLUS 14. Nearest analogues `MISSED_OPPORTUNITY` and
  `CLIENT_ANALYTICS` both sit at `CLARITY_PRO`+.
- **Q5 — Eloquent vs raw SQL?** **Moot — close it.** ClickHouse route → `ClickhouseQuery`.
- **Q6 — NOT on the list but should be: 83% of orgs read zero.** Jul 2026: only **256 orgs**
  appear on either side of a deduped case claim (8 forwarders, 248 agents) against **1,536 orgs**
  with any repossession. Same issue as DEV-2409 comment 38625 (at 84%), which was answered by
  redefining the metric rather than deciding the presentation. Needs: show 0, show N/A, or hide
  the card.

Runtime note: the funnel ran **8.65s warm** against ~32s cold.
