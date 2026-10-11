# DEV-2737 plan

Input: `docs/investigations/DEV-2737/prep-context.json`. Gate **CLEAN**. Phase **implemented** (verify-only). Next **Agent QA**. `provisioned` is true. Product PHP is false. V1 is pending. Decision remains `mismatch_pending_v1`. Follow-on AC is **STANDING** (`follow-on-ac.md`).

**Authoritative plan** from Claude-first Ultra-Plan (#23): Opus primary draft → CloudAgent review → Opus reconcile. See `claude-plan-draft.md`, `cloud-plan-review.md`, `claude-plan-reconcile.md`.

Branch path main→main. First Glance comment 42082. Base `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`. The prior LPR Repossessions coverage work `1bc1a0cc00f971b76c15632155ed92f10846c35f` is an ancestor.

## Locks

- **Q1.** Org **14** (Brad Webb / premieradjusters).
- **Q2.** UI **~509** on **This Month**. Production ClickHouse. Admin impersonating Brad Webb. Figure is LPR Repos headline `db_repos` on `LprRepossessions`. Exact UI integer waits on **V1**.

## t1 — Code path (confirmed, no edit)

| Hop | Detail |
| --- | --- |
| 1 | Registry: `CompanyMetricsOverview::cards()['lpr-repossessions'] => LprRepossessions::class` (`CompanyMetricsOverview.php:107`). Wiki row is corroboration only. |
| 2 | Compact card: `makeMetricWidget()` copies `$date` and `filters['date_toggles']` (`:237-243`) from page default `this_month` (`:59`). Expanded: `DashboardWidget::mount()` (`:97`). Both land on `this_month`. Record which surface V1 is read from. |
| 3 | **This Month does not use `windowSql`.** `getCardTotal()` sends `today`/`last_7d` to `currentPeriodTotal()`; everything else takes the **last bucket** of `getMonthlyData()` (`DashboardWidget.php:356-367`). That bucket is current UTC month (`LprRepossessions.php:257-260`). |
| 4 | Series → `LprRepossessionsQuery::monthlySeries($orgId, now('UTC'))` → `buildSql(bucketed: true)` (`LprRepossessions.php:403-406`; `LprRepossessionsQuery.php:125-131`). |
| 5 | Billing: `{clarity_db}.billing_claim_events_v`, `is_internal = 0`, `event_type = 'case-claim'`, org on **either** side, dedup via `BillingClaimDedup`, status-3 hotlist FINAL + status-log, `db_repos = uniqExactIf(...)`. Does **not** read `clarity.orgs`. |
| 6 | Fidelity: `CardQuerySqlFidelityTest` asserts `monthlySeries()` delegates to `monthlySeriesSql()` and has no heredoc (`:167-180`) — not a rendered-string strcmp. |

Impersonation sets `auth()->user()->org_id` and shortens cache TTL to **10 seconds** (`ResolvesDashboardCacheTtl.php:30-32`). Ten seconds cannot explain a ~24 gap.

Preflight **#25 does_not_fire**. Preflight **#24** verify-first / simple wins. No product PHP.

## t2 — Cloud measure (executed, READ-ONLY)

Measured 2026-09-28 ~09:28 CT via `ch-cloud-readonly` (`readonly_user`). **No writes.** MCP Production refused; curl path used instead.

**M1 = 13-month `monthlySeries` shape** (not September-only `windowSql`):

- Window: `2025-09-01` → `2026-09-30` UTC (`ts` 1756684800–1790812799); status-log floor `2025-08-25`
- Statement: `docs/investigations/DEV-2737/cloud-measure-org14.sql` (hand-mirrored, predicate-equivalent to `buildSql(bucketed: true)`; `deduped` CTE byte-matches `BillingClaimDedup::dedupedCteSql()`)
- Write-up: `cloud-measure-org14-sep2026.md`

| bucket | deduped | db_repos |
| --- | ---: | ---: |
| 2026-09-01 | 630 | **533** |

UI lock: ≈ **509**. Comparison: **`mismatch_pending_v1`**. `exact_count` = **533**. Do not reconcile `deduped` 630 to ~509. Aug `db_repos` 286 / Jul 251 are description-check context only.

## Decision

**Do not close. Do not patch.** `product_php` stays false.

### V1 (required re-pin)

While admin-impersonating org 14, record:

1. Exact LPR Repos headline integer
2. Date toggle
3. Description `Sep '26` and `Aug '26` integers

This class has no `formatMonthlyData()` — description prints the same array the headline indexes. If those disagree on screen, the defect is in the widget.

### Follow-on AC (evidence only — no fix PHP)

> Org 14, September 2026 UTC. `LprRepossessionsQuery::monthlySeries` bucket `2026-09-01` returns `db_repos` **533** (`deduped` 630) on production ClickHouse, read-only, 2026-09-28. The question-gate UI impression is ~509 on This Month under admin impersonation. If V1's live headline is **533**, withdraw this AC and close [DEV-2737](https://insightlpr-team.atlassian.net/browse/DEV-2737) with proof notes — the ~509 was not the live headline. If V1's headline is **not 533**, this AC stands as the evidence record for a separate fix ticket. This AC names no code change.

M2/M3 run only if V1 ≠ 533; read-only; must not become a second product path.

Open-month accrual is **unchecked** until V1. Candidate causes in the draft stay hypotheses, not a fix list. The status-log retirement work stays out. No `okf/` edits this ticket. No fleet DSN. No Cloud writes.

## Non-goals

Product PHP; invented UI integer / tolerance band; reconciling 630 to ~509; rewritten repos query; Cloud writes; fleet DSN; PHPUnit at Cloud; extending LprRepossessions* tests this phase.
