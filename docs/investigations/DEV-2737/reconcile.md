# DEV-2737 reconcile

Verify-only. Evidence record. No fix PHP. `product_php` is false.

## AC-1 hops

| Hop | Detail |
| --- | --- |
| 1 | `CompanyMetricsOverview::cards()['lpr-repossessions'] => LprRepossessions` (`CompanyMetricsOverview.php:107`). Heading `LPR Repossessions` (`LprRepossessions.php:163`). |
| 2 | Compact: `makeMetricWidget()` copies `$date` and `filters['date_toggles']` (`CompanyMetricsOverview.php:237-243`) from page default `this_month` (`:59`). Expanded: `DashboardWidget::mount()` (`DashboardWidget.php:97`). Both land on `this_month` unless the page filter says otherwise. |
| 3 | This Month does not use `windowSql`. `getCardTotal()` sends `today` / `last_7d` to `currentPeriodTotal()`. `this_month` is the last bucket of `getMonthlyData()` (`DashboardWidget.php:356-367`), current UTC month, column `db_repos` (`LprRepossessions.php:257-260`). |
| 4 | `fetchMonthlySeries()` → `LprRepossessionsQuery::monthlySeries($orgId, now('UTC'))` → `buildSql(bucketed: true)` (`LprRepossessions.php:403-406`; `LprRepossessionsQuery.php:125-131`). |
| 5 | `billing_claim_events_v`, `is_internal = 0`, `event_type = 'case-claim'`, org on either side, `BillingClaimDedup`, status-3 hotlist `FINAL` + status-log, `db_repos = uniqExactIf(...)`. Does not read `clarity.orgs`. |
| 6 | `CardQuerySqlFidelityTest` asserts `monthlySeries()` delegates to `monthlySeriesSql()` and has no heredoc (`:167-180`). Not a rendered-string strcmp. |

## Numbers

| Source | September 2026 `db_repos` | `deduped` |
| --- | ---: | ---: |
| Cloud read-only `monthlySeries` bucket `2026-09-01` (2026-09-28) | **533** | 630 |
| Question-gate UI impression, This Month, admin impersonating org 14 | ~509 | not a UI column |

`exact_count` = 533. UI integer is not exact. Do not reconcile `deduped` 630 to ~509.

## Verdict

**`mismatch_pending_v1`.** Do not close. Do not patch.

V1 (exact headline, date toggle, description `Sep '26` and `Aug '26` integers) is unfilled. See `implement-notes.md` section `V1_PENDING`.

## Follow-on AC

Status **STANDING** until V1 = 533. Text from `plan.json` `decision.follow_on_ac` (also `follow-on-ac.md`):

> Org 14, September 2026 UTC. LprRepossessionsQuery::monthlySeries bucket 2026-09-01 returns db_repos 533 (deduped 630) on production ClickHouse, read-only, 2026-09-28. The question-gate UI impression is ~509 on This Month under admin impersonation. If V1's live headline is 533, withdraw this AC and close DEV-2737 with proof notes — the ~509 was not the live headline. If V1's headline is not 533, this AC stands as the evidence record for a separate fix ticket. This AC names no code change.

M2/M3 stay gated on a V1 headline that is not 533. They are read-only localisers and must not become a second product path. No code change is named here.
