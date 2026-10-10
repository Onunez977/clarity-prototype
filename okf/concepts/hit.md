---
type: DomainEntity
title: Hit
description: A match between a Scan and a Hotlist entry; the central event that drives notifications and recoveries.
resource: app/Models/Hit.php
tags: [domain, lpr, postgres, clickhouse]
timestamp: 2026-09-25T15:26:00Z
---

# Hit

A **Hit** is a match between a [Scan](scan.md) and a [Hotlist](hotlist.md) entry. It is the central event of the Clarity system: when a scanned plate matches a plate on an organization's hotlist, a hit is created, notifications fire, and the hit can be dispositioned (reviewed/actioned). Hits are transactional records in **PostgreSQL** (`zod.hits` — the `Hit` model uses the `zod` connection, `public` schema) and mirrored to **ClickHouse** for analytics.

## Definition

A hit links one scan to one hotlist entry and carries a `hit_type` describing how the match was made. Hits trigger alerts and are the upstream event for [Recoveries](recovery.md).

## How hits are created (PostgreSQL triggers)

Hits are **not** created by application code — they are created by **PostgreSQL database triggers and functions** that live in the `zod.public` schema. The schemas/functions are defined in the `insight-lpr/laravel-zod-migrations` composer package (see [Repositories](repositories.md)), which owns the core `zod` schema, tables, triggers, and functions.

- **Live hits (`L` / `S`):** a trigger on `zod.public.scans` fires **AFTER INSERT** (not on UPDATE), calling the function **`find_hits()`**, which matches the new scan against hotlist entries and inserts hit rows. `L` = live match against the scanner's own org's hotlist (Repo Companies, `orgs.type=1`); `S` = live match against a **Staging Hotlist** (`hotlist.is_staged`), gated by the `approval_list` (agent org approved) or `orgs.silent_hit_generation`.
- **Historical hits (`H` / `Z`):** a trigger on `zod.public.hotlist` fires **AFTER INSERT OR UPDATE** (not on `hits`), calling the function **`find_historicals()`**, which looks back through already-captured scans (within `hotlist.lookback_days`) for matches against the new/changed hotlist entry and inserts historical hit rows. `H` = historical match for a direct (non-staged) hotlist; `Z` = historical match for a Staging Hotlist (same `approval_list` / `silent_hit_generation` gating as `S`).

Because hit creation is trigger-driven, the application layer (Clarity) does not write hit rows directly — it reads, notifies, and dispositions them. Treat `find_hits()` and `find_historicals()` as authoritative for hit creation logic; their definitions live in `vendor/insight-lpr/laravel-zod-migrations` (repo: https://github.com/insight-lpr/laravel-zod-migrations), not in this codebase.

## Key attributes / columns

| Column | Type | Description |
|--------|------|-------------|
| `id` | — | Primary key |
| `hotlist_id` | FK → `hotlist.id` | The matched [Hotlist](hotlist.md) entry |
| `scan_id` | FK → `scans.id` | The matching [Scan](scan.md) |
| `kit_id` | FK → `kits.id` | The kit that produced the scan |
| `hit_type` | `HitTypeEnum` | `L` Live / `H` Historical / `S` Staging Live / `Z` Staging Historical / `M` Missed Opportunity / `F` Missed Opportunity Spotted & Closed / `P` Probable Match |
| `org_id` | FK → `orgs.id` | Owning organization (direct, not via kit) |

## Hit types

Defined in `App\Enums\HitTypeEnum` (this repo; uses Black Adam's `EnumHasValues`/`IsDataType` concerns — see [Repositories](repositories.md)):

- **`L` Live** — a real-time match as scans arrive.
- **`H` Historical** — a match against already-captured scans.
- **`S` Staging Live** — a real-time match against a Staging Hotlist (from another org).
- **`Z` Staging Historical** — a historical match against a Staging Hotlist (from another org).
- **`M` Missed Opportunity** — a scan matching a hotlist the org doesn't have.
- **`F` Missed Opportunity Spotted & Closed** — a missed opportunity later spotted and closed.
- **`P` Probable Match** — a probable/fuzzy match.

Staging hits (`S`/`Z`) are visible only to approved agent orgs (see [Multi-Tenancy](multi-tenancy.md) → staging visibility).

## Relationships

- A Hit belongs to one [Scan](scan.md) and one [Hotlist](hotlist.md).
- A Hit belongs to an [Organization](organization.md) directly (`org_id`).
- Hit alert recipients are resolved by `app/Models/Concerns/HitAlertSettings.php`.

## Business rules

- **Trigger-driven creation:** hits are created by PostgreSQL triggers/functions, not by application code. `find_hits()` — trigger `AFTER INSERT ON public.scans` — produces live hits (`L` for direct hotlists on Repo Company orgs, `S` for Staging Hotlists). `find_historicals()` — trigger `AFTER INSERT OR UPDATE ON public.hotlist` — produces historical hits (`H` for direct hotlists, `Z` for Staging Hotlists) by looking back through scans within `hotlist.lookback_days`. Both are gated by `hotlist.status IN (1)`, `order_type IN (1,3,88)`, `is_visible`, and (for staging) the `approval_list` / `orgs.silent_hit_generation`. The application reads, notifies, and dispositions hits — it does not insert them. See "How hits are created" above.
- **Match types:** exact match, or fuzzy match (a Pro feature — `FeatureEnum::FLEX_SEARCH`; see [DTO and Enums](dto-and-enums.md)).
- **Notifications:** hits trigger notifications and alerts; recipients are resolved via [Alert Settings](alert-settings.md) (`HitAlertSettings`), dispatched by `HitEvent` → `HitEventListener` (see [Events and Observers](events-and-observers.md)).
- **Disposition:** hits can be dispositioned (reviewed/actioned); disposition changes sync to ClickHouse via `app/Jobs/SyncDispositionToClickhouse.php`.
- **ClearPlan:** flex-match hits are sent to ClearPlan (`SendFlexMatchToClearPlanJob`, `ResendHitsToClearPlanJob`).
- **Staging visibility:** staged hits are only visible to agent orgs on the relevant `ApprovalList`.
- **Frozen `hotlist_status` on the hit row:** a hit's denormalized `hotlist_status` is captured at hit-write time and **never back-updated**, so it can be stale if the order's status later changes. Surfaces that need *current* status re-resolve it via a semi-join to the live `clarity.hotlist FINAL` (map markers DEV-2113, heatmaps, check-ins); surfaces reading the frozen column (e.g. the Scout recent-hits list, DEV-2117) can show a stale status. See [Scout dashboard stats & map counting](scout-dashboard-stats.md).

- **Distance from Given / Nearest Given Address:** Pro columns gated by `FeatureEnum::HIT_ADDRESS_DISTANCE`; dual compute paths (PostgreSQL `hotlist_address` vs ClickHouse `clarity.recovery_address` fallback). When N/A is correct vs bug-candidate — see [Hit Address Distance](hit-address-distance.md). Motivating ticket DEV-2739 (all-N/A) is **unproven** on root cause.

## Where it lives in code

- Model: `app/Models/Hit.php` (PostgreSQL `zod` connection, `public` schema; table `zod.hits`)
- Hit types: `app/Enums/HitTypeEnum.php` (this repo)
- Alert resolution: `app/Models/Concerns/HitAlertSettings.php`
- Policy: `app/Policies/HitPolicy.php`
- Filament resource: `app/Filament/Resources/HitsResource.php` (app panel, "Search")
- ClickHouse mirror: `app/Clickhouse/Models/Hit.php` (table `hits_cold`, read-only)
- ClickHouse queries: `app/Clickhouse/Queries/HitStatsBatchQuery.php`, `app/Clickhouse/Queries/HitDensityGeohashQuery.php`
- Jobs: `app/Jobs/SyncDispositionToClickhouse.php`, `app/Jobs/SendFlexMatchToClearPlanJob.php`, `app/Jobs/ResendHitsToClearPlanJob.php`
- **Hit-creation triggers/functions:** `find_hits()` (trigger `AFTER INSERT ON public.scans`) and `find_historicals()` (trigger `AFTER INSERT OR UPDATE ON public.hotlist`) — defined in `vendor/insight-lpr/laravel-zod-migrations` (https://github.com/insight-lpr/laravel-zod-migrations), applied to the `zod.public` schema. Not in this repo.

## See also

- [Hit Address Distance](hit-address-distance.md) — Distance from Given / Nearest Given Address N/A contract
- [Scan](scan.md) — the matching read
- [Hotlist](hotlist.md) — the watched vehicle
- [Recovery](recovery.md) — downstream outcome of a hit
- [Missed Opportunity](missed-opportunity.md) — `M`/`F` hit types; `MissedOpportunity extends Hit`
- [Events and Observers](events-and-observers.md) — `HitEvent` → `HitEventListener` → `notifySubscribers()`
- [Alert Settings](alert-settings.md) — recipient resolution + channels
- [DTO and Enums](dto-and-enums.md) — full `HitTypeEnum` (L/H/S/Z/M/F/P)
- [Data Architecture](data-architecture.md) — PostgreSQL `zod.hits` (public schema) vs ClickHouse `hits_cold` / `hotlist_status_aggregate`
- [ClickHouse Integration](clickhouse-integration.md) — `clarity.hits_cold`/`hits_hot`
- [Multi-Tenancy](multi-tenancy.md) — staging visibility and org scoping
- [Drift Watch](drift-watch.md) — tracking `find_hits()` / `find_historicals()` upstream changes
