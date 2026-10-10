# DEV-2659 — Ultra-Plan

**Phase:** plan. **Gate:** CLEAN. **Base:** `main` @ `a4b5074630771c1f4121a5ca34f255c71afe0755`.

This file is the primary plan. A later draft compare is not this plan.

Input: `docs/investigations/DEV-2659/prep-context.json`.

## Ship surface

Both funnel queries read `clarity.billing_claim_events_v` (`is_internal = 0`). Delete the `orgs` CTE and the insightlpr needle joins. One PHP definition of the dedup triple `(forwarder_org_id, agent_org_id, vin)`. `forwarder_org_id` stays in the key.

ACs 1–6 and 8 are in. AC-7 / Item 5 is out. AC-3 is met by deleting `clarity.orgs` from these queries. AC-9's type IN (1, 2) docblock is omitted because no orgs path remains.

No product PHP in this phase. No `okf/` edits. No Cloud ClickHouse writes. Do not mark `phase=provisioned`.

## What is already true

`25870f75661f07a27a69124a414e557d4c11fff0` (#449) is an ancestor. `database/schema/clickhouse-schema.sql` on this base:

| Object | Line |
| --- | --- |
| `clarity.billing_claim_events` | 18 |
| `clarity.billing_claim_events_v` | 85 |
| `clarity.billing_claim_events_mv` | 102 |
| `clarity.orgs` | 89 |

The view is the read surface. It is `FINAL` where `_peerdb_is_deleted = 0` and does not project `_peerdb_is_deleted`. The MV sets `is_internal` from `zod.public_orgs FINAL` over all org types, `event_type = 'case-claim'`, hourly append. `ORDER BY (event_date, id)`.

`UniqueLprCaseClaimsQuery` and `LprRepossessionsQuery` do not extend `ClickhouseQuery`. Their `orgs` CTEs are read only by the needle joins (`UniqueLprCaseClaimsQuery.php` 252–280, `LprRepossessionsQuery.php` 167–196).

`ResolvesDashboardCacheTtl::CLOSED_MONTH_SETTLE_FULL_SECONDS` is already 8 days. This plan does not retune cache.

## Decisions

| # | Decision |
| --- | --- |
| Read surface | `clarity.billing_claim_events_v`. Alias `org_id` as `forwarder_org_id`. |
| Orgs | Delete the CTE, both needle joins, and `kept`. Filter `is_internal = 0` on the view. No `FROM clarity.orgs`. No fan-out fixture. |
| AC-9 | No type IN (1, 2) docblock. Docblock says this class does not read `clarity.orgs` and exclusion is `is_internal = 0`. |
| Hazard 2 | Moot on this read path. Comment 41325 is the existing measurement (types 1, 2, 3, 4; zero claims on the two types outside 1 and 2). Not re-measured here. |
| Dedup | One class, `BillingClaimDedup`, owns the triple and the `deduped` CTE text. Claims window and monthlySeries use `uniqExactIf` on that triple in the same SELECT that counts `all_claims` and `successful`. Repos materializes the CTE because the hotlist join needs `min(claim_date)`. Pre-claim `oc` uses the same triple and still carries `hotlist_id`. |
| Single-pass | One reference to `billing_claim_events_v` per statement. Repos reads `deduped` once. Pre-claim still references `oc` twice. |
| Window | Keep `ts BETWEEN {f} AND {t}`. Also bound `event_date` to the UTC dates covering that span so `ORDER BY (event_date, id)` can prune. Do not drop `ts BETWEEN`. `today` / `last_7d` are not whole months. |
| Sentinels | Keep `d.agent_org_id != 0`, `h.id != 0`, and `h.hotlist_id != 0`. `org_id = 0` is not a join key after the needle joins go. Do not set `join_use_nulls = 1`. |
| Item 5 | Out. The triple stays three columns. |
| Base class | Do not extend `ClickhouseQuery`. |
| Cache | Do not change `UniqueLprCaseClaims` `heldFlexible` or the repos shared keys. |
| Hotlist legs | Stay on `zod.public_hotlist` and `zod.public_hotlist_status_log`. DEV-2657 / DEV-2660. |

## Subtasks

1. **t1 — shared triple.** `app/Clickhouse/Queries/BillingClaimDedup.php`. Static SQL only. No constructor. `tripleSql()` returns `(forwarder_org_id, agent_org_id, vin)`. `dedupedCteSql()` returns the repos CTE: `SELECT bucket, forwarder_org_id, agent_org_id, vin, min(claim_date) AS claim_date FROM claims WHERE success AND vin != '' GROUP BY bucket, forwarder_org_id, agent_org_id, vin`. Test: `tests/Unit/Clickhouse/BillingClaimDedupTest.php` asserts `uniqExact` is the function name in the claims call site contract (the helper itself does not emit `uniq`) and that the triple contains `forwarder_org_id`.

2. **t2 — claims query.** Depends on t1. `UniqueLprCaseClaimsQuery` reads `{clarity_db}.billing_claim_events_v`. Delete `orgs`, the needle joins, `kept`, `JSONExtract*`, and `public_api_keys`. `claims` applies `is_internal = 0`, `event_type`, `ts BETWEEN`, the `event_date` bound, and the either-side org predicate. Window and monthlySeries: one SELECT with `count()`, `countIf(success)`, and `uniqExactIf` of `BillingClaimDedup::tripleSql()`. Pre-claim adds `hotlist_id` and `ts` from the view. Leave the `hits_cold` join and `h.hotlist_id != 0`. Docblock: not a `ClickhouseQuery`; exclusion is `is_internal = 0`; this class does not read `clarity.orgs`. Tests: `tests/Feature/Clickhouse/UniqueLprCaseClaimsQueryTest.php` seeds `clarity.billing_claim_events` (the table behind the view), not `zod.public_billing_events`.

3. **t3 — repos billing leg.** Depends on t1. Can run beside t2. `LprRepossessionsQuery` uses the same `claims` filter and `BillingClaimDedup::dedupedCteSql()`. Outer SELECT still reads `deduped` once and keeps both sentinel guards and both hotlist joins. Same docblock rule as t2. Tests: `tests/Feature/Clickhouse/LprRepossessionsQueryTest.php` seeds the billing table the same way and still seeds hotlist and status-log rows.

`tests/Filament/Widgets/Dashboard/Concerns/CardQuerySqlFidelityTest.php` and `tests/Unit/Clickhouse/CompanyMetricsCacheScopeTest.php` stay green: the claims file still contains `billing_claim_events_v` and declares no `shared*` method, and the SQL strings the fidelity test reflects still match.

## Fleet Docker ClickHouse

Agent QA and Verify run with `CLICKHOUSE_DRIVER=clickhouse`. `RequiresClickhouse` skips when the driver is not `clickhouse` (`tests/Concerns/RequiresClickhouse.php`). A pgsql mirror is not the done-signal. ClickHouse Cloud is read-only and this plan does not query it.

Path, from `.claude/skills/release-prep/SKILL.md` (clickhouse-qa `up.sh`):

| | |
| --- | --- |
| Host | `127.0.0.1` |
| Driver | `clickhouse` |
| Container | `clarity-qa-clickhouse` |
| HTTP | `18123` |
| Schema | `database/schema/clickhouse-schema.sql` |
| Databases | `clarity_{wt}`, `zod_{wt}` |

Provision writes the ledger for this key before Agent QA. This phase does not mark provisioned and does not claim that container is free. Do not reuse the DEV-2654 `:18124` ledger or the SCHEMA-DRIFT `:18125` ledger as this key's cite. Do not use the agent scratch server on `:8123`.

Seed the table `clarity.billing_claim_events`. The view is not the insert target. Include one row whose `agent_org_id` is `0` and one hotlist row whose `id` is `0`, so the existing sentinel tests still fail if a guard is removed.

AC-2 and AC-6 production figures stay the ticket targets: Jul 2026 UTC, `ls-claim` excluded, platform-wide, all **15128** / successful **14288** / deduped **11975**. Comment 41325 already compared the view to the current SQL at that cardinality (0 differences) and recorded org 165, Jul 2026, as 24576 rows / 0.25 MB versus 43521627 rows / 1852.59 MB. This plan does not re-measure Cloud. Fleet Docker proves the seeded arithmetic and that the SQL does not mention `public_billing_events`, `JSONExtract`, `public_api_keys`, or `clarity.orgs`. `X-ClickHouse-Summary` on that Docker run is the AC-2 evidence for the seeded statement.

## Invariants

- `uniqExact`, never `uniq`.
- `{org} = 0` stays platform-wide.
- Synchronous failures still propagate. No `ClickhouseQuery::query()`.
- Pre-claim `hits_cold` predicate is unchanged. Comment 41325 says that join was not validated. Do not claim a new production rate.
- Wiki edit for `okf/concepts/billing-events.md` waits until approval. The page still describes `zod.public_billing_events`.

## Next

Provision (waits a fleet ledger for this key). Then Implement.
