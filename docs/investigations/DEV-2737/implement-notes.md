# DEV-2737 implement notes

Verify-only. `product_php` is false. No `app/` or `tests/` edits. No product PHP was invented. Cloud ClickHouse was not written. PHPUnit was not run (plan non-goals).

Phase **implemented**. Next **Agent QA**. Review is skipped (`NO_PRODUCT_PHP`). Decision stays `mismatch_pending_v1`.

## AC-1 path

This Month on LPR Repossessions is the last `db_repos` bucket of `LprRepossessionsQuery::monthlySeries` (13-month, UTC). It is not `windowSql`.

| Hop | What the code does | Cite |
| --- | --- | --- |
| 1 | Registry key `lpr-repossessions` maps to `LprRepossessions`. Widget heading is `LPR Repossessions`. | `CompanyMetricsOverview.php:107`; `LprRepossessions.php:163` |
| 2 | Page default `$date` is `this_month`. Compact card: `makeMetricWidget()` copies `$date` and `filters['date_toggles']`. Expanded: `DashboardWidget::mount()` uses `filters['date_toggles']` or `this_month`. V1 must record which surface was read. | `CompanyMetricsOverview.php:59`; `CompanyMetricsOverview.php:237-243`; `DashboardWidget.php:97` |
| 3 | `getCardTotal()` sends `today` / `last_7d` to `currentPeriodTotal()`. Every other toggle, including `this_month`, takes a bucket of `getMonthlyData(formatted: false)`. `this_month` is the last bucket. `last_month` is the one before it. | `DashboardWidget.php:356-367` |
| 4 | `getMonthlyData()` calls `fetchMonthlySeries(auth()->user()->org_id, now('UTC'))`, then walks 13 UTC month keys and reads `['db_repos']`. A missing bucket is 0. | `LprRepossessions.php:233-260`; `LprRepossessions.php:403-406` |
| 5 | `monthlySeries()` runs `monthlySeriesSql()`, which is `buildSql(bucketed: true)` for `endOfMonth()` back 12 months (`subMonthsNoOverflow` + `startOfMonth`). | `LprRepossessionsQuery.php:109-131`; `LprRepossessionsQuery.php:133-148` |
| 6 | Billing leg: `{clarity_db}.billing_claim_events_v`, `is_internal = 0`, `event_type = 'case-claim'`, org on either side (`org_id` or `agent_org_id`). Dedup is `BillingClaimDedup::dedupedCteSql()`. Recoveries: status-3 `public_hotlist FINAL` plus `public_hotlist_status_log` (no FINAL). Headline column is `db_repos = uniqExactIf(...)`. The class does not read `clarity.orgs`. | `LprRepossessionsQuery.php:170-216`; `BillingClaimDedup.php:22-24` |
| 7 | Description for `this_month` prints the same series array the headline indexes (`Sep '26` = last bucket, `Aug '26` = previous). `formatMonthlyData()` is not defined on this class or its dashboard concerns. The only `formatMonthlyData()` under `app/Filament/Widgets/Dashboard/` is `RecoveryLeader`. | `LprRepossessions.php:301-318`; `LprRepossessions.php:262-264` |
| 8 | Query-section SQL for `this_month` is `monthlySeriesSql()`, note says the card reads `db_repos` of the current bucket row. | `LprRepossessions.php:616-641` |
| 9 | Fidelity test asserts `monthlySeries()` delegates to `monthlySeriesSql()` and contains no `<<<SQL` heredoc. It is not a rendered-string strcmp against production. | `CardQuerySqlFidelityTest.php:146`; `CardQuerySqlFidelityTest.php:167-180` |

Grep proof: `docs/investigations/DEV-2737/artifacts/monthly-series-path.rg.txt`.

## V1_PENDING

Production UI re-pin is not filled. The question-gate figure ~509 is an impression, not a recorded headline. Do not invent an integer, and do not treat 533 as the number on screen. A parallel production UI pass may fill the fields below. Until the live headline is **533**, the follow-on AC in `follow-on-ac.md` stays **STANDING**.

| Field | Value |
| --- | --- |
| Surface (compact card or expanded widget) | |
| Session | admin impersonating Brad Webb, org 14 |
| Exact LPR Repos headline integer | |
| Date toggle | |
| Description `Sep '26` integer | |
| Description `Aug '26` integer | |
| Recorded at (UTC) | |

Open-month accrual between the question-gate session and this measure is unchecked until those fields are filled.

## V2 — org corroboration

Question Gate lock (2026-09-28), also `plan.json` `answers.Q1`: org **14**, Brad Webb / premieradjusters. The card scopes with `auth()->user()->org_id` (`LprRepossessions.php:233`).

This worker cannot cite a `users.org_id` row. Local Postgres `127.0.0.1:5432` database `zod` has schema `platform` and no `users` table (migrations are not applied). Fleet Postgres `:15439` (`zod_dev_2737`) is not listening on this worker. Org 14 stays the Question Gate lock plus `plan.json` answers. No org id was invented.

## V3 — 10s TTL

`ResolvesDashboardCacheTtl::baseDashboardCacheTTL()` returns `now()->addSeconds(10)` when `session()->has('admin_impersonator')`, and that arm runs before the open-period and year arms (`ResolvesDashboardCacheTtl.php:30-32`).

The question-gate session was admin impersonation, so the cache arm that applies is 10 seconds. Ten seconds cannot explain a gap on the order of 533 versus ~509. Without impersonation, index 0 (the open month, which this series uses) is 10 minutes (`:34-35`). That arm is also not a stored headline of 509. Cache TTL is recorded here so it is not left as an open cause.

## V4 — hand-mirrored SQL versus `buildSql`

`docs/investigations/DEV-2737/cloud-measure-org14.sql` was hand-mirrored. It was not emitted by calling `LprRepossessionsQuery::buildSql()`.

`monthlySeriesSql()` is the only 13-month window arithmetic (`LprRepossessionsQuery.php:125-131`): `$end = $through->clone()->utc()->endOfMonth()`, `$start = $end->clone()->subMonthsNoOverflow(12)->startOfMonth()`, then `buildSql(true, ...)`. For a through-date in September 2026 UTC those bounds are `2025-09-01 00:00:00` (`ts` 1756684800) through `2026-09-30 23:59:59` (`ts` 1790812799). The artifact uses those bounds. Status-log `{floor}` is start minus 7 days: `toDate('2025-08-25')`.

Placeholders in `buildSql()` (`LprRepossessionsQuery.php:138-148`, template `:178-217`) and the values the artifact substitutes:

| Placeholder | Artifact value | Source |
| --- | --- | --- |
| `{clarity_db}` | `clarity` | `clarityDb()` → `config('database.connections.clarity_clickhouse.database')`, default `clarity` (`config/database.php:164`) when the driver is not `pgsql` |
| `{db}` | `zod` | `zodDb()` → `config('database.connections.public_clickhouse.database')`, default `zod` (`config/database.php:217`) when the driver is not `pgsql` |
| `{org}` | `14` | Question Gate org |
| `{event_type}` | `'case-claim'` | `BillingEventType::CASE_CLAIM` |
| `{f}` / `{t}` | `1756684800` / `1790812799` | September 2026 `monthlySeriesSql()` window |
| `{d_from}` / `{d_to}` | `toDate('2025-09-01')` / `toDate('2026-09-30')` | same window, `toDate` literals |
| `{floor}` | `toDate('2025-08-25')` | UTC start minus 7 days |

The `deduped` CTE body is the statement returned by `BillingClaimDedup::dedupedCteSql()` (`BillingClaimDedup.php:22-24`): `success AND vin != ''`, `min(claim_date)`, grouped by `bucket, forwarder_org_id, agent_org_id, vin`. The artifact pretty-prints that statement. Collapsing whitespace, the CTE body matches the method. The file is not a raw byte copy of the one-line PHP string, and it is not `buildSql()` output.

The bucketed select matches `sql(true)`: `d.bucket`, `uniqExact` as `deduped`, `uniqExactIf` as `db_repos` with `toDate(hsl.created_at, 'UTC') >= d.claim_date - 7`, hotlist `FINAL` `status = 3` and `_peerdb_is_deleted = 0` with `d.agent_org_id != 0`, status-log without `FINAL`, `new_status = 3`, `h.id != 0`, then `GROUP BY d.bucket ORDER BY d.bucket`.

`FORMAT JSONEachRow` is a client format clause on the curl read. It is not part of `buildSql()`.

`CardQuerySqlFidelityTest` does not compare this file to `monthlySeriesSql()`. Predicate equivalence is this table, not a test assertion.

## V5 — executed Cloud measure

V5 is the read-only measure already executed. It is not re-run in Implement.

- When: 2026-09-28 ~09:28 CT, `ch-cloud-readonly` / `readonly_user`. No writes. ClickHouse Cloud MCP Production refused (`Forbidden`).
- Statement: `cloud-measure-org14.sql`. Write-up: `cloud-measure-org14-sep2026.md`. Raw rows: `cloud-measure-org14.jsonl`.
- Bucket `2026-09-01`: `db_repos` **533**, `deduped` **630**. That is M1.
- UI lock remains ~509 (`ui_is_exact` false). Comparison: `mismatch_pending_v1`.
- Do not reconcile `deduped` 630 to ~509. Aug `db_repos` 286 and Jul 251 in the jsonl are description-check context only, not UI integers.
