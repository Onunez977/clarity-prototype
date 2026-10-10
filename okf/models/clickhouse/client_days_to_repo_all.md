---
type: DataModel
title: client_days_to_repo_all (DaysToRepoAggregate)
description: ClickHouse view exposing summed days-to-repossession and repo counts by hour, org, client, and branch — broken out by recovery type — for the Client Analytics days-to-repo metric.
resource: app/Clickhouse/Models/DaysToRepoAggregate.php
tags: [model, clickhouse, clarity, recovery, aggregate, client-analytics]
timestamp: 2026-07-06T00:00:00Z
---

# client_days_to_repo_all

`client_days_to_repo_all` exposes summed days-to-[recovery](../../concepts/recovery.md) and repossession counts per hour, per org, per client org, and per branch, broken out by recovery type (all, involuntary, voluntary, investigate, impound, LPR staging, unknown). It is the read side behind the Client Analytics "days to repo" metric — see [Client Analytics](../../concepts/client-analytics.md) and [ClickHouse Integration](../../concepts/clickhouse-integration.md). The metric is a ratio: `SUM(sum_days_to_repo_*) / SUM(count_repos_*)`. Note the ClickHouse engine here is a **`View`** — it reads over the underlying days-to-repo `ReplacingMergeTree` table (documented as `clarity.client_days_to_repo` in the integration page) rather than being a merge-tree itself.

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `client_days_to_repo_all`
- Engine: `View`
- Model class: `App\Clickhouse\Models\DaysToRepoAggregate`
- Source: `app/Clickhouse/Models/DaysToRepoAggregate.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `hour_timestamp` | `DateTime` | no | Hour bucket (UTC); the `between()` scope filters this range. |
| `org_id` | `UInt32` | no | Owning organization (LPR/lienholder org). FK → `public.orgs.id`. Filtered by `forOrg()`. |
| `client_org_id` | `UInt32` | no | Client organization. FK → `public.orgs.id`. Filtered by `forClient()`. |
| `branch_id` | `UInt32` | no | Branch the order is assigned to. Filtered by `forBranch()`. |
| `sum_days_to_repo_all` | `Int64` | no | Sum of days-from-order-to-repo across all recovery types; numerator of the days-to-repo average. |
| `count_repos_all` | `UInt64` | no | Count of repossessions across all types; denominator of the days-to-repo average. |
| `sum_days_to_repo_involuntary` | `Int64` | no | Days-to-repo sum for involuntary recoveries. |
| `count_repos_involuntary` | `UInt64` | no | Repo count for involuntary recoveries. |
| `sum_days_to_repo_voluntary` | `Int64` | no | Days-to-repo sum for voluntary recoveries. |
| `count_repos_voluntary` | `UInt64` | no | Repo count for voluntary recoveries. |
| `sum_days_to_repo_investigate` | `Int64` | no | Days-to-repo sum for investigate-type recoveries. |
| `count_repos_investigate` | `UInt64` | no | Repo count for investigate-type recoveries. |
| `sum_days_to_repo_impound` | `Int64` | no | Days-to-repo sum for impound recoveries. |
| `count_repos_impound` | `UInt64` | no | Repo count for impound recoveries. |
| `sum_days_to_repo_lpr_staging` | `Int64` | no | Days-to-repo sum for LPR-staging recoveries. |
| `count_repos_lpr_staging` | `UInt64` | no | Repo count for LPR-staging recoveries. |
| `sum_days_to_repo_unknown` | `Int64` | no | Days-to-repo sum for recoveries with unknown type. |
| `count_repos_unknown` | `UInt64` | no | Repo count for recoveries with unknown type. |

## Casts & enums

None declared.

## Relationships

No Eloquent relationships are defined; org/client/branch are plain id columns.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Derives from the Clarity-owned `platform.recoveries` table (a `platform`-schema, Clarity add-on table): `platform.recoveries` → `clarity.recoveries` / `clarity.recovery_*` → the `clarity.client_days_to_repo` `ReplacingMergeTree`, over which this `client_days_to_repo_all` view aggregates. See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md).
- Being a `View`, it holds no rows of its own and carries no `_peerdb_*` columns.

## Notable scopes & behavior

- **`forOrg($orgId)`** — filters `org_id` (primary tenant scope; see [Multi-Tenancy](../../concepts/multi-tenancy.md)).
- **`forClient($clientOrgId)`** / **`forBranch($branchId)`** — optional `when()` filters applied only when the id is present.
- **`between($start, $end, $timezone = 'UTC')`** — filters `hour_timestamp` after converting bounds to UTC.
- **`byMonth`** — uses `HasMonthGrouping::getMonthSelectSql('hour_timestamp')` and selects `SUM(sum_days_to_repo_all) as total_days, SUM(count_repos_all) as total_count` grouped/ordered by month; the caller divides `total_days / total_count` to get the average.
- Because sums and counts are kept as separate additive columns (not a precomputed average), any filter combination can be re-summed accurately before dividing.

## See also

- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines, aggregate tables, and the `clarity.client_days_to_repo` source
- [Client Analytics](../../concepts/client-analytics.md) — days-to-repo metric definition
- [Recovery](../../concepts/recovery.md) — the underlying recovery concept and types
- [recovery_kit_scan_report](recovery_kit_scan_report.md) — recovery + kit scan-count analytics
