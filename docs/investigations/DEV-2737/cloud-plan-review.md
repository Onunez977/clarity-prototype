# DEV-2737 cloud plan review

Review only. The primary draft is the plan under review. The counsel plan is not authority. Phase stays Plan. `provisioned` stays false. Next stays Provision. No product PHP, no plan rewrite, no commit.

Checked against live Jira [DEV-2737](https://insightlpr-team.atlassian.net/browse/DEV-2737), First Glance comment 42082, the question-gate locks, `docs/investigations/DEV-2737/` on `cursor/dev-2737-lpr-repos-verify-b380`, and the cited PHP. The Cloud figure below is taken from the read-only measure write-up. This pass did not query ClickHouse.

## Verdict

**holds-with-gaps**

The primary draft has the right code path: This Month is the last `db_repos` bucket of `LprRepossessionsQuery::monthlySeries`, not `windowSql`. Product PHP stays false. Preflight #25 does not fire. The draft was written before the Cloud read, so it still treats that read as future work. Finalize has to fold in **533 vs ~509**, keep V1, and name follow-on acceptance criteria without a fix.

## AC coverage

| AC | Lock | Draft | Gap |
| --- | --- | --- | --- |
| AC-1 Document the query path for the org and month | Org 14, This Month, `db_repos`, admin impersonation | Hop table matches the code | Cite the PHP registry, not only the wiki row |
| AC-2 Reconcile UI vs query, Cloud read if needed | UI ~509; Cloud read-only | V1–V5 and M1 are the right procedure | M1 already returned 533. The draft does not say so |
| AC-3 Proof notes if correct; follow-on ACs if wrong | Do not invent a count or a fix | Match / benign / real split is right | 533 is not a match for ~509. Do not close. Name the follow-on AC. Do not write PHP |

First Glance 42082 (main→main, no invented numbers, Cloud read-only) is respected. 533 is a measured integer from the write-up, not an invented UI count. ~509 stays the UI lock until V1.

## Agreements with the primary draft

- Card key `lpr-repossessions` is `LprRepossessions`. Heading is LPR Repossessions. The ~509 figure is `db_repos`, not `deduped` and not the scans-per-repo description.
- `getCardTotal()` sends `today` and `last_7d` to `currentPeriodTotal()`. `this_month` uses the last element of `getMonthlyData(formatted: false)` (`DashboardWidget.php:356-367`). That element is the current UTC month: the loop in `getMonthlyData()` puts `i = 0` last (`LprRepossessions.php:257-260`).
- The series is `LprRepossessionsQuery::monthlySeries($orgId, now('UTC'))` (`LprRepossessions.php:403-406`), which is `buildSql(bucketed: true)` from UTC start-of-month 12 months back through UTC `endOfMonth` (`LprRepossessionsQuery.php:125-131`).
- Billing source is `clarity.billing_claim_events_v` with `is_internal = 0`, `event_type = 'case-claim'`, `ts` and `event_date` bounds, and org 14 on either side (`LprRepossessionsQuery.php:188-193`). `deduped` is `BillingClaimDedup::dedupedCteSql()` (`BillingClaimDedup.php:22-24`). `db_repos` is the status-3 hotlist plus status-log `uniqExactIf` (`LprRepossessionsQuery.php:173`, joins at 200-215). The class does not read `clarity.orgs`.
- `getCardQueryStatements()` emits `monthlySeriesSql` for `this_month` and `windowSql` only for `today` and `last_7d` (`LprRepossessions.php:616-641`). A September-only `windowSql` is a cross-check, not the card.
- Org id is `auth()->user()->org_id` (`LprRepossessions.php:233`). Impersonation changes that user. It does not change the SQL. The impersonation TTL is 10 seconds (`ResolvesDashboardCacheTtl.php:30-31`), passed as the flexible pair (`LprRepossessions.php:209-214`). Ten seconds cannot explain a gap of about 24.
- On a real mismatch the draft refuses a patch and points at follow-on ACs. That holds. Preflight #25 does not fire: nothing in the draft rewrites the path or adds a second product query. M2 and M3 are extra read-only probes, not a new card path.
- No fleet DSN. No Cloud writes. Wiki edits stay queued. `provisioned` stays false.

The measure write-up’s bounds match `monthlySeries` for a through-date in September 2026: `ts` 1756684800–1790812799 is 2025-09-01 00:00:00 UTC through 2026-09-30 23:59:59 UTC, and the status-log floor is 2025-08-25. The headline row it reports is bucket `2026-09-01`.

## Gaps / risks / corrections required before finalize

- The draft never records 533. Its match rule is “M1 equals the V1 integer.” Until V1, the UI integer is still ~509. 533 is not that impression. Do not classify it as benign on the draft’s listed reasons (wrong filter, wrong org, month roll, or the 10-second cache).
- The exact SQL that returned 533 is not in `docs/investigations/DEV-2737/` and not in the measure write-up, only the shape and the bounds. Finalize must attach that statement. Do not treat the counsel plan’s September-only `windowSql` (floor 2026-08-25) as that statement.
- Hop 1 cites `okf/concepts/company-metrics-dashboard.md:28`. That row matches today. The binding registry is `CompanyMetricsOverview.php:107`.
- Compact cards are not mounted. `CompanyMetricsOverview::makeMetricWidget()` copies `$date` (`CompanyMetricsOverview.php:237-238`). `DashboardWidget::mount()` (`DashboardWidget.php:97`) is the expanded widget. Both default to `this_month`. The hop table should say so.
- `CardQuerySqlFidelityTest` does not strcmp a rendered explainer to a rendered executor. `test_the_executing_method_delegates_to_the_displayed_builder` checks that `monthlySeries()` calls `monthlySeriesSql(` and does not contain a heredoc (`CardQuerySqlFidelityTest.php:162-180`). V4 is still right: copy `monthlySeriesSql`. Do not claim the test already proved byte-identical rendered SQL.
- `getDescription()` for `this_month` prints the last and previous buckets of `getMonthlyData()` (`LprRepossessions.php:311-318`). This class has no `formatMonthlyData()`, so the formatted memo is the same integers as the headline. A Sep/Aug split in the description is a widget bug only if those integers differ. V1 should record both numbers. The measure’s August `db_repos` is 286. If the description’s August matches 286 and September does not match 533, the series is the live one and only the current bucket disagrees.
- Open month is a V1 check, not an explanation. The demo notes are 2026-09-25. The measure is 2026-09-28 ~09:28 CT. Do not assume the gap of about 24 arrived in those days.
- `deduped` 630 is not the headline. Do not reconcile 630 to ~509.

## Scope-creep rejections (#24)

- No product PHP in this ticket. No new query class, no predicate change, no sentinel edit, no `is_internal` backfill, no status-log retirement.
- The draft’s candidate causes (bucket vs window, `h.id != 0`, frozen `is_internal`, hotlist `FINAL` asymmetry, `claim_date - 7`) stay hypotheses. They are not a fix list. The status-log retirement work stays out.
- Do not add or extend `LprRepossessionsQueryTest`, `LprRepossessionsTest`, or `BillingClaimDedupTest` in this phase.
- Do not reopen Q1. Org 14 stays locked. A Postgres read of `users.org_id` is optional corroboration, not a new gate.
- Do not adopt the counsel plan’s month-scoped `windowSql` as the card query, and do not invent a tolerance that turns 533 into a match for ~509.
- Do not provision a fleet, invent a DSN, edit `.env`, or point PHPUnit at Cloud. Preflight #18 stays a note for a later ticket that actually changes query PHP.
- Do not edit `okf/` now.
- M2/M3 run only if V1 shows the live headline is still not 533. They are read-only. They must not become a second product path (that would make #25 fire).

## Measure fold-in (533 vs ~509)

Source: the Cloud read-only measure write-up, `readonly_user`, no writes. Bucket `2026-09-01`: `deduped` 630, `db_repos` **533**. UI lock remains **~509**.

That is a mismatch against the locked impression. It is not yet a confirmed widget defect, because V1 has not re-pinned the screen.

Required together:

- **V1.** While admin-impersonating org 14, record the exact LPR Repos headline, the date toggle, and the description line (`Sep '26` and `Aug '26`).
- **Follow-on AC, no fix PHP.** Org 14, September 2026 UTC, `monthlySeries` bucket `2026-09-01`, `db_repos` 533 (`deduped` 630). The question-gate UI is ~509 under admin impersonation on This Month. If V1’s headline is 533, withdraw the AC and close with proof notes (the ~509 impression was not the live headline). If V1’s headline is not 533, the AC stands as the evidence record. The AC does not name a code change.

August 286 and July 251 stay context for the description check. They are not a second reconciliation target.

## What must change in final plan.json / plan.md

- Set the Cloud measure as executed read-only: bucket `2026-09-01`, `db_repos` 533, `deduped` 630, UI still ~509, `exact_count` 533, comparison `mismatch_pending_v1`.
- Make M1 the 13-month `monthlySeries` statement (bounds and floor above). Delete the counsel plan’s claim that September-only `windowSql` is the card SQL.
- Attach the SQL text that produced 533. The checkout does not contain it.
- Decision: do not close. `product_php` stays false. Preflight #25 stays `does_not_fire`.
- Add V1 as a required re-pin, and name one follow-on AC whose only evidence is 533 vs ~509 plus the V1 integer. No fix steps.
- State that a 10-second impersonation TTL cannot account for the gap, and that open-month accrual is unchecked until V1.
- Point hop 1 at `CompanyMetricsOverview.php:107`, and note the compact card’s date is copied in `makeMetricWidget()`, not via `mount()`.
- Narrow the fidelity-test claim to “`monthlySeries` delegates to `monthlySeriesSql`”.
- Keep `provisioned` false, next Provision, branch path main→main, First Glance 42082. No fleet DSN. No Cloud write.