# DEV-2737 — Opus reconcile (draft × cloud review)

Phase stays **Plan**. `provisioned` false. Next **Provision**. `product_php` **false**. Preflight #25 `does_not_fire`. Branch path main→main. First Glance 42082.

## 1. Adopted from the review

- **Fold 533 into the plan.** The draft predates the Cloud read and still writes the measure as future work. Bucket `2026-09-01`: `deduped` 630, `db_repos` **533**; UI lock **~509**. Comparison `mismatch_pending_v1`.
- **Hop 1 cites the PHP registry, not the wiki.** `CompanyMetricsOverview::cards()['lpr-repossessions'] => LprRepossessions::class` — `CompanyMetricsOverview.php:107`. Verified. The wiki row is corroboration.
- **Compact vs expanded mount.** The compact card is not `mount()`ed: `makeMetricWidget()` assigns `$widget->date = $this->date` and `filters['date_toggles']` (`CompanyMetricsOverview.php:237-243`), from the page default `this_month` (`:59`). `DashboardWidget::mount()` (`:97`) is the expanded path. Both land on `this_month`; the hop table must say which one the ~509 was read from.
- **Narrow the fidelity-test claim.** `CardQuerySqlFidelityTest` asserts `monthlySeries()` contains `::monthlySeriesSql(` and no `<<<SQL` (`:167-180`). It does **not** compare rendered strings. V4 survives; the draft's "holds the explainer equal to what executes" does not.
- **V1 records both integers** — headline and the `Sep '26: X | Aug '26: Y` line. `LprRepossessions` has no `formatMonthlyData()` (only `RecoveryLeader.php:543` does), so `getDescription()` prints the *same* array the headline indexes (`LprRepossessions.php:262, 311-318`). If those two disagree on screen, the defect is in the widget. Aug **286** is the description's expected value from the same measure.
- **Do not reconcile 630 to ~509.** `deduped` is not the headline.
- **Open-month accrual is a V1 check, not an explanation.** Demo notes 2026-09-25 → measure 2026-09-28; do not assume the ~24 arrived in those three days.
- **10s impersonation TTL cannot explain ~24** (`ResolvesDashboardCacheTtl.php:30-32`, paired at `LprRepossessions.php:209-214`) — record it, don't lean on it.
- **All #24 scope rejections** in the review's list, unchanged.

## 2. Rejected from the review

- **"The SQL that produced 533 is not in the checkout."** Stale as of this pass — `docs/investigations/DEV-2737/cloud-measure-org14.sql` is present. I verified it is **predicate-equivalent** to `buildSql(bucketed: true)` for org 14 / through-Sep-2026: claims predicates (`is_internal = 0`, `'case-claim'`, `ts 1756684800–1790812799`, `event_date 2025-09-01..2026-09-30`, `(14 = 0 OR org_id = 14 OR agent_org_id = 14)`) match `LprRepossessionsQuery.php:188-193`; the `deduped` CTE is a **byte match** for `BillingClaimDedup::dedupedCteSql()` (`BillingClaimDedup.php:24`); both sentinels (`d.agent_org_id != 0`, `h.id != 0`) and the FINAL/no-FINAL asymmetry are present (`:201-215`); floor `2025-08-25` = `utcStart - 7` (`:147`). Amended requirement: keep V4, but as *provenance confirmation* of a hand-mirrored statement, not as a missing artifact.
- **"A Postgres read of `users.org_id` is optional."** Keep V2 as a cheap one-line corroboration inside V1. It is not a re-opened Q1 and costs nothing; org 14 stays locked either way.
- Any implication that M2/M3 are optional garnish — they are the *named* localiser, gated on V1, read-only, and must not become a second product path.

## 3. Adopted from the primary draft (review-affirmed)

- **This Month is the last `db_repos` bucket of the 13-month `monthlySeries`, never `windowSql`** (`DashboardWidget.php:356-367`; `LprRepossessions.php:257-260, 403-406`; `LprRepossessionsQuery.php:125-131`). The single most load-bearing fact in the ticket.
- Org counted on **either** side, `$orgId = auth()->user()->org_id` — impersonation changes the viewer, not the SQL.
- V1→V5 ordering; M1 authoritative, M2 only on mismatch, M3 only if M2 is inconclusive.
- The candidate-cause list stays **hypotheses**, carried as follow-on context only.
- `RatioAgainstUniqueScans` / scans-per-repo description is a different numerator — out of scope.
- Read-only guardrails: no writes, no `SYSTEM`/`OPTIMIZE`, no settings changes, no `.env` edits, no PHPUnit at Cloud, no fleet DSN.

## 4. Measure decision

**533 ≠ ~509 → `mismatch_pending_v1`. Do not close. Do not patch.**

- `exact_count` = **533** (measured, `readonly_user`, no writes). UI stays **~509** (impression, not an integer) until V1.
- Not classifiable as benign on any of the draft's listed reasons: wrong filter, wrong org, month roll, and the 10s TTL are each ruled out or unevaluable without V1.
- **V1 is required and blocking for the verdict** (not for finalize).
- **Follow-on AC text, verbatim, no fix steps:**
  > Org 14, September 2026 UTC. `LprRepossessionsQuery::monthlySeries` bucket `2026-09-01` returns `db_repos` **533** (`deduped` 630) on production ClickHouse, read-only, 2026-09-28. The question-gate UI impression is ~509 on This Month under admin impersonation. If V1's live headline is **533**, withdraw this AC and close DEV-2737 with proof notes — the ~509 was not the live headline. If V1's headline is **not 533**, this AC stands as the evidence record for a separate fix ticket. This AC names no code change.
- Aug 286 / Jul 251 are description-check context only, not a second reconciliation target.

## 5. Final plan directives (must appear in plan.json / plan.md)

1. `phase: Plan` · `provisioned: false` · `next: Provision` · `product_php: false` · preflight #25 `does_not_fire` · #24 simple-wins · branch main→main · First Glance 42082.
2. Cloud measure recorded as **executed, read-only**: bucket `2026-09-01`, `db_repos` 533, `deduped` 630, UI ~509, `exact_count` 533, `comparison: mismatch_pending_v1`.
3. **M1 = the 13-month `monthlySeries` statement**, bounds `ts 1756684800–1790812799`, `event_date 2025-09-01..2026-09-30`, status-log floor `2025-08-25`. Explicitly delete the counsel plan's claim that a September-only `windowSql` is the card SQL.
4. Attach `cloud-measure-org14.sql` as the statement that produced 533, annotated **hand-mirrored, predicate-equivalent to `buildSql(bucketed: true)`** with the five equivalence points above. V4 (lift `monthlySeriesSql` from the expanded card's Query section) remains required as zero-drift confirmation.
5. Hop 1 → `CompanyMetricsOverview.php:107`; add the compact-vs-expanded note (`:237-243` copy vs `DashboardWidget.php:97` mount; both default `this_month`).
6. Fidelity claim narrowed to "`monthlySeries()` delegates to `monthlySeriesSql()` and contains no inline heredoc" (`CardQuerySqlFidelityTest.php:167-180`).
7. **V1 as a required re-pin**: headline integer, date toggle, and both description integers, while admin-impersonating org 14. Note that with no `formatMonthlyData()` on this class the description integers are the headline's own array.
8. State that the 10-second impersonation TTL cannot account for ~24, and that open-month accrual is **unchecked** until V1.
9. Exactly **one** follow-on AC, text as §4, evidence-only.
10. M2/M3 gated on V1 ≠ 533, read-only, explicitly not a second product path.
11. Wiki edits queued, not written. No `okf/` edit in this ticket.

## 6. Hard stops / non-goals

- No product PHP: no new query class, no predicate change, no sentinel edit, no `is_internal` backfill, no status-log retirement. The status-log retirement work stays out.
- No new or extended `LprRepossessionsQueryTest` / `LprRepossessionsTest` / `BillingClaimDedupTest` this phase.
- No tolerance band that turns 533 into a match for ~509. No invented UI integer.
- No Cloud writes, no `SYSTEM`/`OPTIMIZE`/settings, no `.env` edits, no fleet DSN, no PHPUnit at Cloud. Preflight #18 stays a note for a later ticket that actually changes query PHP.
- Q1 stays closed; org 14 locked.
- No close until V1.
