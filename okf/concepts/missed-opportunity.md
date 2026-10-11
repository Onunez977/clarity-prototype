---
type: DomainEntity
title: Missed Opportunity
description: The Missed Opportunity domain concept — a scan that matched a hotlist the org did NOT have at the time (a recoverable opportunity). Pro feature (FeatureEnum::MISSED_OPPORTUNITY), hit types M/F, hotlist_org_id scoping, the App\Models\MissedOpportunity (extends Hit) and ClickHouse clarity.missed_opportunities models. Extracted from business-rules.md / client-analytics.md / HitEvent.
resource: app/Models/MissedOpportunity.php
tags: [domain, lpr, hit, hotlist, missed-opportunity, pro-feature, clickhouse]
timestamp: 2026-06-26T20:20:00Z
---

# Missed Opportunity

A **Missed Opportunity** is a [Scan](scan.md) that matched a [Hotlist](hotlist.md) the org did **not** have at the time — a vehicle you drove past and could have recovered, but didn't hold the assignment. It is the inverse of a [Hit](hit.md): a hit is a scan↔*your* hotlist match; a missed opportunity is a scan↔*someone else's* hotlist match that you could have captured. It is a **Pro feature** (`FeatureEnum::MISSED_OPPORTUNITY`).

> Extracted from the legacy `.claude/docs/business-rules.md` and `client-analytics.md`, cross-checked against `HitEvent` and the real models.

## Hit types `M` and `F`

Missed opportunities are recorded as hits with two dedicated `HitTypeEnum` values (see [DTO and Enums](dto-and-enums.md)):

| Case | Value | Title | Meaning |
|------|-------|-------|---------|
| `Missed_Opportunity` | `M` | Missed Opportunity | **Live** — the scan matched a hotlist you don't hold, captured at scan time. |
| `Missed_Opportunity_Historical` | `F` | Missed Opportunity Spotted & Closed | **Historical** — later spotted (the hotlist was subsequently closed/recovered by someone else). |

Like live/historical hits (`L`/`S` from `find_hits()`, `H`/`Z` from `find_historicals()`), `M`/`F` are produced by the upstream PostgreSQL trigger machinery in `insight-lpr/laravel-zod-migrations` — see [Hit](hit.md). The `hit_type` values themselves are defined in this repo (`app/Enums/HitTypeEnum.php`).

## `hotlist_org_id` — whose hotlist you missed

The defining column is **`hotlist_org_id`** — the org that *owns* the hotlist you didn't have. Missed-opportunity queries scope by `hotlist_org_id`, not `org_id`:

- `App\Clickhouse\Models\MissedOpportunity::scopeForOrg($query, int $orgId)` → `where('hotlist_org_id', $orgId)`.
- The ClickHouse table `clarity.missed_opportunities` (a `ReplacingMergeTree`, **not** a view — see [ClickHouse Integration](clickhouse-integration.md)) carries the full denormalized hit/scan/hotlist payload plus `hotlist_org_id`, `client_org_id`, `lienholder_org_id`, `branch_id`, `disposition`.
- `notNicb()` excludes the NICB integration (`IntegrationEnum::NICB`).

So "missed opportunities for org X" = distinct hotlists whose `hotlist_org_id = X` that X was not holding when the scan occurred.

## Two models

| Model | Location | DB | Purpose |
|-------|----------|----|---------|
| `App\Models\MissedOpportunity` | `app/Models/MissedOpportunity.php` | PostgreSQL (`zod`) | **Extends `Hit`** — a missed opportunity *is* a hit. Used for the admin `MissedOpportunityResource` list/disposition workflow. Casts `type` → `HitTypeEnum`; relations `hotlist`, `kit`, `scan`, `activity`. Scopes: `forOrg`, `hitType`, `status`, `plateNumber`, `vin`, `vl6`, `ranked`, `coordinates`, `createdBetween`. |
| `App\Clickhouse\Models\MissedOpportunity` | `app/Clickhouse/Models/MissedOpportunity.php` | ClickHouse `clarity.missed_opportunities` | Analytics reads. `FiltersCdcDeletes` + `HasMonthGrouping`. Scopes: `forOrg` (→`hotlist_org_id`), `between` (scanned_at), `forBranch`, `forClient`, `forVin`, `forClients`, `forLienholders`, `forKits`, `forDispositions`, `excludeDisposition`, `forHitType`, `forCreatedBetween`, `notNicb`, `selectTotal`/`groupByClient`/`byMonth` (use `countDistinct(hotlist_id)`). |

Because the PG model extends `Hit`, missed opportunities flow through the same notification path: `HitEvent` normalizes an `M`-typed `Hit` into a `MissedOpportunity` instance and routes to `MissedOpportunityNotification` (see [Events and Observers](events-and-observers.md) and [Alert Settings](alert-settings.md)).

## Metrics

In [Client Analytics](client-analytics.md), "Missed Opportunities" = `countDistinct(hotlist_id)` over `clarity.missed_opportunities` scoped by `hotlist_org_id` + date range + branch/client (via `ClientRankedMetricsBatch::getMissedMetrics`). The lifetime widget (`LifetimeMissedOpportunitiesWidget`) computes the same over all-time. Distinct-hotlist counting means the same missed vehicle scanned multiple times counts once.

## Rules

- **Pro-only** — `FeatureEnum::MISSED_OPPORTUNITY`; gate the resource/widgets with `HasProFeature` (see [Filament Conventions](filament-conventions.md)). `ProTierEnum::CLARITY_PRO` and above include it.
- **Scope by `hotlist_org_id`**, not `org_id`, on the ClickHouse side. Getting this backwards is the classic missed-opportunity bug.
- **Distinct hotlists** (`countDistinct(hotlist_id)`) for counts, not raw row counts.
- **`M` = live, `F` = historical** — match the same live/historical distinction as `L`/`H`.
- **Notifications reuse the hit pipeline** — `HitEvent` → `HitEventListener` → `notifySubscribers()`, routed to `MissedOpportunityNotification` for `M`/`F`.

## Where it lives in code

- PG model: `app/Models/MissedOpportunity.php` (extends `App\Models\Hit`)
- ClickHouse model: `app/Clickhouse/Models/MissedOpportunity.php`
- Admin resource: `app/Filament/Resources/MissedOpportunityResource.php`
- Widgets: `app/Filament/Widgets/Client/MissedOpportunitiesWidget.php`, `app/Filament/Widgets/Client/Lifetime/LifetimeMissedOpportunitiesWidget.php`, `app/Filament/Widgets/Dashboard/MissedOpportunityClientGraph.php`
- Notification: `app/Notifications/Hits/MissedOpportunityNotification.php`
- Event normalization: `app/Events/HitEvent.php`
- Enum: `app/Enums/HitTypeEnum.php` (`M`, `F`), `app/Enums/FeatureEnum.php` (`MISSED_OPPORTUNITY`)

## See also

- [Hit](hit.md) — parent concept; `MissedOpportunity extends Hit`
- [HitTypeEnum](dto-and-enums.md) — `M` / `F` values and the full enum
- [Client Analytics](client-analytics.md) — the missed-opportunity metric
- [ClickHouse Integration](clickhouse-integration.md) — `clarity.missed_opportunities` table
- [Events and Observers](events-and-observers.md) — `HitEvent` M-normalization
- [Alert Settings](alert-settings.md) — `M`/`F` notification routing
