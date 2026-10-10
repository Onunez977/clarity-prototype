---
type: Concept
title: Client Analytics
description: The Client Analytics dashboard (pro feature) — metric definitions, formulas, hotlist status transitions, the ClickHouse tables/scopes that power them, the widget hierarchy, caching, and batch computation. Corrected from the legacy .claude/docs/client-analytics.md.
resource: app/Filament/Widgets/Client/Concerns/ClientStatWidget.php
tags: [domain, lpr, clickhouse, analytics, filament, client-portal, pro-feature]
timestamp: 2026-06-26T19:45:00Z
---

# Client Analytics

Client Analytics is the **Repo Company-facing dashboard** that reports assignment performance to a lender/client (`client_org_id`). It is a **Pro feature** gated by `FeatureEnum::CLIENT_ANALYTICS` and is **ClickHouse-only** — there is no PostgreSQL fallback at runtime (see [ClickHouse Integration](clickhouse-integration.md)). Read [Data Architecture](data-architecture.md) first.

> This page supersedes the legacy `.claude/docs/client-analytics.md`. The table names (`client_stats_aggregate`, `client_days_to_repo_hourly`, `aggregate_recovery_kit_scans`), the `client_stats_aggregate` "view", and several formulas there were stale. The real tables/scopes/formulas are below.

## The status-transition model (foundation)

All assignment metrics derive from **hotlist status transitions** stored in `clarity.hotlist_status_aggregate` (`AggregatingMergeTree`, `unique_hotlists AggregateFunction(uniq, Int64)`). Each row is a status change with `old_status` → `new_status`, bucketed by `status_hour`, scoped to `org_id` + `client_org_id` + `branch_id`. Metrics count distinct hotlists that reached a target `new_status` via `uniqMerge(unique_hotlists)`.

`HotlistStatusEnum` (verified in `app/Enums/HotlistStatusEnum.php`):

| Value | Name | Meaning |
|-------|------|---------|
| `0` | `Other` | Pre-assignment / other |
| `1` | `Open` | Accepted / active assignment |
| `2` | `Hold` | On hold |
| `3` | `Repossessed` | Repossessed |
| `4` | `ClosedPositive` | **Declined** (closed, client declined) |
| `5` | `Closed` | **Closed cohort** (closed without decline) |
| `90` | `NewFromClient` | New assignment arriving from a client |

## Metric definitions + formulas

Each stat widget extends `ClientStatWidget` (`app/Filament/Widgets/Client/Concerns/ClientStatWidget.php`). Formulas verified against the widgets:

| Metric | Widget | Formula | Source |
|--------|--------|---------|--------|
| **Total Assignments** | `TotalAssignmentsWidget` | `uniqMerge(unique_hotlists)` where `new_status = Open (1)` (the `openAssignments()` scope) | `clarity.hotlist_status_aggregate` |
| **Total Accepted** | `TotalAcceptedWidget` | assignments where `new_status = Open (1)` AND `old_status IN (Other (0), NewFromClient (90))` (the `accepted()` scope) | `clarity.hotlist_status_aggregate` |
| **Total Repossessions** | `TotalRepossessionsWidget` | `uniqMerge` where `new_status = Repossessed (3)` (`repossessions()`) | `clarity.hotlist_status_aggregate` |
| **Total Closed** | `TotalClosedWidget` | `uniqMerge` where `new_status = Closed (5)` AND `old_status IN (Open (1), Hold (2))` (`closedCohort()`), via `CohortClosedQuery` | `clarity.hotlist_status_aggregate` |
| **Total Declined** | `TotalDeclinedWidget` | `uniqMerge` where `new_status = ClosedPositive (4)` AND `old_status IN (Open (1), Hold (2))` (`declined()`), via `CohortDeclinedQuery` | `clarity.hotlist_status_aggregate` |
| **Avg Recovery Rate** | `AverageRecoveryRateWidget` | `round((Repossessions / Assignments) * 100)`, 0 when assignments = 0; formatted `{value}%` | `clarity.hotlist_status_aggregate` |
| **30-Day Recovery Rate** | `ThirtyDayRecoveryRateWidget` | same ratio, range forced to last 30 days (ignores `date_type`); default cache TTL 30 min | `clarity.hotlist_status_aggregate` |
| **Days to Repo** | `DaysToRepoWidget` | `round(SUM(sum_days_to_repo_all) / SUM(count_repos_all), 1)`; `< 1` shown when `0 < value < 1`; lower-is-better | `clarity.days_to_repo` (`DaysToRepoAggregate`) |
| **Current Open Assignments** | `CurrentOpenAssignmentsWidget` | `count()` of open hotlists **right now** (no date range, snapshot); default cache TTL 30 min | `clarity` CDC mirror via `ZodPublicHotlist::open()` |
| **Missed Opportunities** | `MissedOpportunitiesWidget` | count of missed-opportunity rows (see [Missed Opportunity](missed-opportunity.md)) | `clarity.missed_opportunities` |
| **LPR Assists** | `LprAssistsWidget` | `count(DISTINCT recovery_id)` via `LprAssist::getTotal` / `groupedByClientIds()` — see [LPR Assist](lpr-assist.md) | `clarity.lpr_assists` (view over `clarity.recovery_scans`) |

> **Drift correction:** legacy docs had "Total Declined = `new_status = 5` (Closed)". Real `declined()` uses `ClosedPositive (4)`; `Closed (5)` is `closedCohort()`. And `Total Closed` is the `closedCohort()` scope, not a single status.

### Lifetime widgets

`app/Filament/Widgets/Client/Lifetime/*` — `LifetimeTotalAssignmentsWidget`, `LifetimeTotalRepossessionsWidget`, `LifetimeRecoveryRateWidget`, `LifetimeLprAssistsWidget`, `LifetimeMissedOpportunitiesWidget`. These compute over **all-time** (no date filter) using the same underlying scopes/tables, extending `LifetimeStatWidget` (`app/Filament/Widgets/Client/Concerns/LifetimeStatWidget.php`).

### Extra widgets (legacy docs missed these)

- `TotalClientAssignmentsWidget` — org-wide total assignments (not per-client).
- `TotalClosedWidget` — closed cohort count (above).
- `AssignmentBreakdownChart` / `HistoricalCoverageBreakdownChart` — chart widgets (`ClientChartWidget` / `ClientClickhouseChartWidget` concerns).
- `DaysToRepoChart` — days-to-repo distribution chart.
- `MissedOpportunityClientGraph` (`app/Filament/Widgets/Dashboard/`) — admin-side missed-opportunity graph.

## Date ranges + comparison periods

`ClientStatHelper` (`app/Filament/Widgets/Client/Concerns/ClientStatHelper.php`) computes date ranges in the user's timezone:

- `date_type` values: `this_month` (default), `ytd`, `last_30d`, `last_90d`, `custom`.
- Comparison ("previous") periods exist for `this_month` (last month), `ytd` (last year), `last_30d` (30–60d ago), `last_90d` (90–180d ago). **`custom` has no comparison period** (`getPreviousPeriodDates` returns `null`).
- Percentage change vs. previous period: `((current - previous) / previous) * 100`; if previous = 0, returns `100` when current > 0 else `0`. Direction-aware coloring via `isLowerBetter()` (e.g. `DaysToRepoWidget` = lower is better → green on negative change).

## Caching + batch computation

Two layers:

1. **Batch cache** — `ClientRankedMetricsBatch` (`app/Clickhouse/Queries/Client/ClientRankedMetricsBatch.php`) computes **all per-client metrics in one pass** (assignments, accepted, closed, declined, repos, missed, lpr_assists) and stores them under cache key `client-ranked-metrics-batch:org_id=…:branch_id=…:start=…:end=…:timezone=…`. Widgets read their slice via `getValueFromBatchCache()` + `getBatchCacheKey()` (e.g. `TotalAssignmentsWidget` → `'assignments'`). This avoids N queries for N widgets.
2. **Per-widget cache** — if no batch slice exists, the widget caches its own `getModelQueryForDates()` result under `{widget}:{date_type}:org=…:branch=…:client=…`.

Cache TTL (`getCacheTTL`): **production = 10 min** (default), **10 seconds when an admin is impersonating**, **5 seconds in non-production**. Some widgets override the default (e.g. `CurrentOpenAssignmentsWidget` / `ThirtyDayRecoveryRateWidget` default 30 min). `getModelQueryForDates` is the contract every stat widget implements for its raw value.

## Charts + the Client table

- Chart widgets extend `ClientChartWidget` / `ClientClickhouseChartWidget` (`app/Filament/Widgets/Client/Concerns/`) and use `BatchMonthlySparklineData` for monthly series.
- `ClientTable` (`app/Filament/Widgets/Client/ClientTable.php`) renders the per-client leaderboard built from `ClientRankedMetricsBatch` output.
- Client search scans: `app/Filament/Client/Resources/ClientSearchScansResource.php` (the `/client` portal resource — see [Filament Conventions](filament-conventions.md)).

## Rules

- **ClickHouse-only at runtime** — no PostgreSQL fallback. Gate UI behind `CLIENT_ANALYTICS`; tests skip when ClickHouse is unavailable.
- **Always use the model scopes** (`openAssignments`, `accepted`, `repossessions`, `closedCohort`, `declined`, `uniqMerge(unique_hotlists)`) — don't hand-roll the status integer checks; the enum values are easy to get wrong (esp. `ClosedPositive (4)` = declined).
- **Distinct counts use `uniqMerge`** (approximate, scalable), not exact counts, for performance.
- **Timezone correctness** — all `between()` calls UTC-normalize via Carbon; pass `$this->getTimezone()`.

## Where it lives in code

- Widgets: `app/Filament/Widgets/Client/*.php`, `app/Filament/Widgets/Client/Lifetime/*.php`
- Concerns: `app/Filament/Widgets/Client/Concerns/` — `ClientStatWidget`, `ClientStatHelper`, `ClientChartWidget`, `ClientClickhouseChartWidget`, `LifetimeStatWidget`
- Batch queries: `app/Clickhouse/Queries/Client/ClientRankedMetricsBatch.php`, `CohortClosedQuery.php`, `CohortDeclinedQuery.php`, `BatchMonthlySparklineData.php`
- Models/scopes: `app/Clickhouse/Models/HotlistStatusAggregate.php`, `DaysToRepoAggregate.php`, `MissedOpportunity.php`, `LprAssist.php`, `ZodPublicHotlist.php`
- Enum: `app/Enums/HotlistStatusEnum.php`
- Pro gate: `app/Enums/FeatureEnum.php` (`CLIENT_ANALYTICS`)

## See also

- [ClickHouse Integration](clickhouse-integration.md) — connections, CDC, tables
- [ClickHouse Query Patterns](clickhouse-query-patterns.md) — the scopes and `uniqMerge` mechanics
- [Missed Opportunity](missed-opportunity.md) — the M/F hit-type metric
- [LPR Assist](lpr-assist.md) — recoveries credited to a near-repo plate scan
- [DTO and Enums](dto-and-enums.md) — `HotlistStatusEnum`, `FeatureEnum`, `RdnMetricType`
- [Filament Conventions](filament-conventions.md) — widget/concern patterns
- [Hotlist](hotlist.md) — the assignment being measured
