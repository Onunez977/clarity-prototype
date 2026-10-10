# DEV-2464 — ticket bundle (verbatim source for prep)

Story · Clarity (platform) · Sprint 40 · In Progress · reporter/assignee Dalton McCleery
Epic parent: DEV-2409 "Company Metrics Dashboard — mockup build-out (9 cards, one per ticket)" (In Progress)
Blocked by: DEV-2460 "Company Metrics — foundation: mockup grid layout, 2 relabels, Kit Users move, Pro-gate seam" — **QA Complete, merged to main via PR #384 (d46b5a6f); lockedStat seam verified on origin/main**
Blocked by: DEV-2463 "Company Metrics — Unique LPR Case Claims card" — **To Do, NOT built**

## Summary
Company Metrics — LPR Repossessions card on the case-claim → repo funnel

## Description (verbatim)

## The metric

**LPR Repossessions** — the **DB repos** stage of Brek's Backyard Repo Report funnel.

**Grid slot: row 1, col 4.** One metric, one ticket. **The headline card of the epic.**

**Run the Unique LPR Case Claims card first** — it proves the same `zod` claim CTE end-to-end at low risk and settles the shared questions this ticket inherits. **Depends on the foundation ticket** being merged first.

## ⚠️ The definition was rejected once — do not rebuild the old one

An earlier implementation counted repossessions our LPR _contributed to_ (the repo has an attributed scan). **Oscar rejected that outright** (DEV-2409 comment 38627):

> LPR Repossessions should not be a count of repos where LPR assisted, instead it should count staging repos that went through case claim and was subsequently marked repossessed by the claiming agency.

The LPR-assisted `LprRepossessionCountQuery` / `LprRepossessions` classes on `claude/DEV-2409` implement the **rejected** definition. Do not port them. The measurement work that went with them — the `count()` → `uniqExact(id)` fix, the cross-month residue, the "84% of orgs read 0" analysis — is preserved in `docs/investigations/DEV-2409/number-validation.md`, and was correct for the old definition only.

## The definition

Deduped `(forwarder_org_id, agent_org_id, vin)` claim triples among **successful** `case-claim` billing events, whose agent org has a **live** `hotlist.status = 3` and a `hotlist_status_log.new_status = 3` transition on or after `claim_date − 7 days`, agent-matched on `org_id`.

**Spec to port:** `RepoReport::claimsCte()` + `recoveriesCte()` + `orgDbReposSql()` from **backyard** `app/Models/Core/Extended/RepoReport.php` on `origin/deploy` (DEV-2129 + DEV-2388). Read it with `git show origin/deploy:<path>` — the local checkout runs behind.

**Forwarder** = the api key's owning org. **Agent** = `claimed_by_org_id` (Oscar's "claiming agency"). Note the report runs on Postgres `zod` and **deliberately avoids** `platform.recoveries`, which its own docblock calls "the stalled MV".

## ⚠️ This is the expensive card

It is the **only** one of the three case-claim cards that joins `hotlist` (**152M rows**) and `hotlist_status_log` (**386M rows**). The recoveries leg measured **~87 s uncached**. The caching decision below is **load-bearing, not optional** — a 13×–39× multiplication through the sparkline loop was never viable.

## Decided already — do NOT re-litigate

| | decision |
| --- | --- |
| **Org axis** | `(forwarder_org_id = ? OR agent_org_id = ?)` — count the org on either side. Matches `RepoReport::orgDbReposSql()`, which `ClarityCommercialFeeSummary` already bills customers off. |
| **Route** | **Postgres** `zod`, porting the SQL near-verbatim. Removes the whole class of mirror-semantics bugs that caused the B5 investigation. |
| **Caching** | **One** `Cache::flexible([3600, 86400])` query per resolved window. Do **not** wire through `DashboardWidget::getMonthlyData()`'s 13-month loop. |
| **Exclusions** | Reject `insightlpr` **only**. **Never filter** `titlemax` in the counting path. |
| **Staging** | Not a meaningful extra filter — 99.8% of case claims are already on staged orders, and Brek's funnel filters on staging nowhere. Oscar's "staging repos" is phrasing, not an extra predicate. |
| **Prod Postgres cost** | **Accepted unmeasured risk.** No live `EXPLAIN` was reachable from the sandbox. Mitigated by the caching rule. Surface it in the Build Record. If this query is ever moved onto a per-render path, measure first. |

### Why the titlemax rule matters

`isExternalRepoSystem()` (needle `titlemax`) only sets a **display flag** via `decorateRow()` — **nothing filters on it**; it exists so the UI can keep the row out of a _blended rate_, while the row stays in the funnel and in the Total. `rejectInternalOrgs()` (needle `insightlpr`) is the **only** helper that removes rows. Collapsing the two is a **−2.6%** error on DB repos and is exactly what produced the B5 investigation.

### Port rules the implementation must not get wrong

1. Reject `insightlpr` only; `titlemax` stays in every count.
2. Window is a **UTC calendar month**, inclusive `BETWEEN` on the integer epoch `ts`.
3. `deduped` groups on `(forwarder_org_id, agent_org_id, vin)` among successful rows with a non-empty normalised VIN — **follow the SQL, not the docblock's "distinct (vin, agent)" prose**, which under-counts when one agent claims the same VIN through two forwarders.
4. **DB repos reads _live_ `hotlist`**, so it drifts upward over time. Pin comparisons to a snapshot date; do not chase a ±1.
5. The event discriminator column is `event_type`, **not** `type`.

## Cross-check target (Jul 2026, ls-claim off)

| stage | total | largest forwarder |
| --- | --- | --- |
| all claims | 15,128 | 9,340 |
| successful | 14,288 | 9,262 |
| deduped | 11,975 | 7,230 |
| **DB repos** | **9,116–9,117** | **5,355–5,356** |
| repo rate | 76.1% | 74.1% |

The ±1 is the same single vehicle at both levels and is **expected, not an error** — the recoveries CTE reads live `hotlist` by design, so that column can only rise after a screenshot is taken.

## Open questions

* **Owned here:** does the card show **DB repos alone** (one number), or expose the whole five-column funnel in the expanded card state?
* **Inherited from the Unique LPR Case Claims card** — answer consistently, don't re-decide: `ls-claim` in or out · titlemax-viewer disclosure · Pennant gate · Eloquent-on-`zod` vs a raw-SQL query class.

## Card contract

* Register the key in `CompanyMetricsOverview::cards()` at its **exact mockup slot** (row 1 col 4). Mockup-relative, **not** append-only.
* Add an explicit `getStatColor()` arm in `DashboardWidget` (`default => 'gray'` fails silently).
* Update `tests/Filament/Pages/DashboardCardExpansionTest.php`'s card-order assertion; keep `test_all_four_hit_cards_are_contiguous()` passing.
* Pro gate: `makeMetricWidget()` never calls `mount()` — use the `lockedStat()` seam from the foundation ticket.

## Acceptance criteria

* The card reproduces Brek's Repo Report for the same window and org set: DB repos **9,116–9,117**, largest forwarder **5,355–5,356**, ±1 expected.
* **Exactly one funnel query per resolved window per org.** No per-month multiplication.
* Respects the Today / Last 7d / This Month / Last Month filter.
* Non-Pro orgs do not see the value.
* Trend sparkline consistent with existing card styling.
* Existing metrics and their values are unaffected.
* The unmeasured production-Postgres cost is restated in the Build Record.

## Jira comment 39117 (2026-09-04, Dalton) — ROUTE CORRECTION, verbatim

**CORRECTION — route: the full funnel, including this card's expensive recoveries leg, reproduces in ClickHouse.**

Full evidence is on DEV-2463 (comment 39116). This card inherits the route decision, so the parts that bear specifically on it:

### The mirror check the epic gated ClickHouse on is done

`zod.public_hotlist_status_log` **is** mirrored — **388,885,025 rows / 2.31 GiB** — as are `zod.public_hotlist` (156,096,149 / 5.30 GiB), `zod.public_billing_events`, `zod.public_api_keys` and `zod.public_orgs`. Those are the two tables this ticket calls out as the expensive leg (152M / 386M rows in Postgres). In columnar storage the status log is 2.31 GiB.

### This card's own AC number was reproduced

Brek's `claimsCte()` + `recoveriesCte()` + `orgDbReposSql()` ported to ClickHouse and run read-only against production, Jul 2026, ls-claim off:

| stage | AC target | CH port |
| --- | --- | --- |
| deduped | 11,975 | **11,975** |
| **DB repos** | **9,116–9,117** | **9,130** |
| repo rate | 76.1% | 76.2% |

+14 on DB repos, i.e. 0.15%, in the direction this ticket's own port rule 4 predicts — DB repos reads _live_ `hotlist`, the screenshot is 2026-08-27, this ran 2026-09-04. **Not snapshot-pinned, so treat it as consistent-with rather than proven-identical.** The way to close it is to re-run Brek's Postgres funnel today and confirm it also reads 9,130.

Timing: ~32s client wall clock uncached for the whole funnel, against the ~87s this ticket records for the Postgres recoveries leg. The one-cached-query-per-window rule stays load-bearing either way — this changes the margin, not the architecture.

### Recommendation

Build this card on **ClickHouse**, not Postgres `zod`. Every other decision on the ticket stands unchanged: org axis `(forwarder_org_id = ? OR agent_org_id = ?)`, one `Cache::flexible([3600, 86400])` query per resolved window, reject `insightlpr` only, **never** filter titlemax in the counting path, UTC calendar-month window on the integer epoch `ts`, dedup on the SQL's `(forwarder_org_id, agent_org_id, vin)` key rather than the docblock's two-column prose, and `event_type` not `type`.

Side effect worth noting: the "**accepted unmeasured risk**" this ticket carries — production Postgres cost with no reachable `EXPLAIN` — is retired by the switch, because the ClickHouse path is measurable from the sandbox.

### Still open on the route

No server-side envelope. `readonly_user` lacks `READ ON REMOTE`, so `clusterAllReplicas(system.query_log)` is denied and the local replica's log had nothing for the query — so `read_rows`, `read_bytes` and peak memory are **unknown**, only client wall clock is. Given this is the epic's most expensive card, get those numbers before the route is frozen.

## Jira comment 39129 (2026-09-04, Dalton) — INHERITED-QUESTION AUDIT, verbatim

**The inherited questions have been audited on DEV-2463 (comment 39128). Two bear directly on this card.**

### This card's own question — DB repos alone, or the whole funnel — has a billing precedent

> **Owned here:** does the card show **DB repos alone** (one number), or expose the whole five-column funnel in the expanded card state?

The DB-repos figure is already in commercial use. Backyard's `ClarityCommercialFeeSummary` calls `RepoReport::monthToDateDbRepos($now, $orgId)` at two call sites, and `RepoReport::totalDbRepos()`'s docblock names its consumer: _"the MTD figure the Clarity Dashboard Repo Rev card uses."_ Same `(forwarder_org_id = ? OR agent_org_id = ?)` axis this ticket already records as the one `ClarityCommercialFeeSummary` bills customers off.

So **DB repos, one number, is the answer that keeps the dashboard and the fee summary consistent.** Any other stage means a customer's card and their fee summary disagree for the same month on the same axis. Exposing the full funnel in the expanded state is then additive and harmless — but the compact card should be DB repos.

_Caveat: verified as an input to the fee summary; whether repos are charged per-unit I did not verify._

### Per-forwarder DB repos measured, including this ticket's cross-check target

Jul 2026, ClickHouse port, read-only:

| forwarder | deduped | DB repos | repo rate |
| --- | --- | --- | --- |
| **Location Services, LLC (LPR)** | **7,230** | **5,365** | **74.2%** |
| American Recovery Service | 2,696 | 2,214 | 82.1% |
| Millennium Capital and Recovery Corporation | 812 | 657 | 80.9% |
| Primeritus Financial Services | 809 | 640 | 79.1% |
| TitleMax - RepoSystems | 306 | 156 | 51.0% |
| Burns National LLC | 57 | 46 | 80.7% |
| Victory Recovery Services  Inc | 37 | 27 | 73.0% |
| Bell & Williams Associates Inc. | 28 | 25 | 89.3% |

This ticket's largest-forwarder target is **5,355–5,356** at 74.1%; measured **5,365** at 74.2% — **+9/+10**, matching the total's +14 and the same live-`hotlist` drift direction that port rule 4 predicts. Consistent, but **not snapshot-pinned**.

### The titlemax question is more serious than it reads

Every other forwarder sits between 73% and 89%. TitleMax reads **51.0%** — roughly 28 points below the pack — because their repossessions happen inside their own RepoSystem and never fully reach our clone. A TitleMax viewer would see a rate near half their peers', which reads as poor performance rather than missing data. That is customer-facing copy and is with Oscar.

_Note I had earlier called this question incoherent; that was wrong and is corrected on DEV-2463._

### Other inherited answers

* `ls-claim`: out. 1.8% of claim volume, zero in Jul 2026, already excluded in production by `clarity.recoveries_mv`, and Backyard's default.
* **Pennant gate:** a new `FeatureEnum` case at `CLARITY_PRO` + `CLARITY_PRO_PLUS`, matching `MISSED_OPPORTUNITY` and `CLIENT_ANALYTICS`. Confirm the tier only.
* **Eloquent-on-`zod`** vs raw-SQL query class: moot on the ClickHouse route (comment 39117).
* **83% of orgs read 0** on this card — 256 orgs appear on either side of a deduped case claim in Jul 2026, against 1,536 with any repossession. Same problem as DEV-2409 comment 38625 at 84%; the redefinition moved it rather than solving it. With Oscar.

Runtime: the funnel ran **8.65s warm** vs ~32s cold. Cold is the figure that matters for the one-cached-query-per-window rule, which stays load-bearing.
