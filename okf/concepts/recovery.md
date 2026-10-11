---
type: DomainEntity
title: Recovery
description: A repossessed vehicle record — the successful outcome of a hit. Materialized-view-backed.
resource: app/Models/Recoveries.php
tags: [domain, lpr, postgres, clickhouse]
timestamp: 2026-06-26T10:31:00Z
---

# Recovery

A **Recovery** is a repossessed vehicle record — the successful outcome of a [Hit](hit.md) that led to a vehicle being recovered. Recoveries are used for analytics, reporting, and leaderboard widgets. The `recoveries` table lives in the **`platform` schema** of the `zod` database (the `Recoveries` model uses the default `platform` connection) and is defined by a local migration (`create_platform_recoveries_table`). It is **materialized-view-backed** for read paths.

## Definition

A recovery record mirrors the relevant [Hotlist](hotlist.md) columns plus a geocoded recovery address and a `repo_date`. It is the basis for recovery-rate analytics, days-to-repo metrics, and [LPR Assist](lpr-assist.md) counts.

## Key attributes / columns

| Column | Type | Description |
|--------|------|-------------|
| `id` | — | Primary key |
| `org_id` | FK → `orgs.id` | Owning [Organization](organization.md) |
| `address_id` | FK → addresses | Geocoded recovery address |
| `house_number` / `road` / `city` / `state` / `postcode` | — | Normalized address parts |
| `geo` | PostGIS Point | Recovery location |
| `repo_date` | date | Date of repossession |
| (hotlist mirror columns) | — | Plate, VIN, YMM, status, etc. |

Indexed on `repo_date` and `org_id`.

## Relationships

- A Recovery belongs to an [Organization](organization.md) (`org_id`).
- A Recovery corresponds to a [Hotlist](hotlist.md) assignment that was closed via repossession.
- Recovery locations are geocoded by `app/Jobs/GeocodeRecoveryAddress.php`.
- Recovery views are refreshed by `app/Jobs/RefreshRecoveryViews.php`.

## Business rules

- **Materialized views:** the application uses materialized views for recovery data (`recoveries`, `recovery_address`). **Never manipulate materialized views directly — they refresh automatically.** See [Data Architecture](data-architecture.md) and [Testing Conventions](testing-conventions.md).
- **Geocoding:** recovery addresses are geocoded asynchronously.
- **Analytics only:** recoveries drive dashboard widgets (recovery counts, recovery leader, days-to-repo, [LPR Assists](lpr-assist.md)) — they are not the transactional source of truth for the assignment (that's the hotlist).

## Where it lives in code

- Model: `app/Models/Recoveries.php` (PostgreSQL `zod` connection)
- Migration: `database/migrations/2025_06_16_141529_create_platform_recoveries_table.php`
- View models: `app/Models/Views/RepoScanReport.php`
- Report model: `app/Models/RecoveriesKitUserScansReport.php`
- Policy: `app/Policies/RecoveriesKitUserScansReportPolicy.php`
- Jobs: `app/Jobs/RefreshRecoveryViews.php`, `app/Jobs/GeocodeRecoveryAddress.php`
- ClickHouse: `app/Clickhouse/Models/Recovery.php` (view `clarity.recoveries`), aggregate `clarity.aggregate_recovery_kit_scans`
- ClickHouse queries: `app/Clickhouse/Queries/RecoveryLeaderQuery.php`, `app/Clickhouse/Queries/KitUserRecoveryLeaderboardQuery.php`
- Filament widgets: `RecoveryCounts`, `RecoveryLeader` (app panel); recovery widgets (client panel)

## See also

- [Hit](hit.md) — the upstream event
- [Hotlist](hotlist.md) — the assignment being recovered
- [Kit](kit.md) — the kit that scanned the recovered vehicle
- [LPR Assist](lpr-assist.md) — when a nearby plate scan credits the recovery to LPR
- [Data Architecture](data-architecture.md) — PostgreSQL `platform.recoveries` vs ClickHouse `clarity.recoveries` view / `clarity.aggregate_recovery_kit_scans`
