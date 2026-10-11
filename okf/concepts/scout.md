---
type: Concept
title: Scout
description: The Clarity Scout feature area — a driver-facing PWA plus admin Filament surfaces for live scanner tracking, patrol zones, and shifts. Explicit-grant-only Pro feature.
resource: resources/js/clarity-scout/App.vue
tags: [domain, scout, lpr, pwa, clickhouse, feature-flag]
timestamp: 2026-06-30T12:00:00Z
---

# Scout

**Scout** (a.k.a. Clarity Scout) is a feature area aimed at the **driver / scanner operator** rather than the office user. It gives a driver a live map of where their kit is, the scan tracks it has laid down, the hits and order density around them, the [Zones](zone.md) they are assigned to patrol, and an end-of-[shift](scout-shifts-and-trips.md) summary. It also gives org admins a **Bird's Eye** org-wide view of all their drivers at once.

This page is the hub for the Scout sub-concepts: [Zones](zone.md), [Scout user assignment](scout-user-assignment.md), [frontend architecture](scout-frontend.md), [real-time tracking](scout-real-time-tracking.md), [Bird's Eye (admin map)](scout-birds-eye.md), the [API surface](scout-api.md), [shifts & trips](scout-shifts-and-trips.md), [dashboard stats & map counting](scout-dashboard-stats.md), and the [ClickHouse query reference](scout-clickhouse-queries.md).

## The three surfaces

| Surface | Audience | Where it lives | Auth |
|---------|----------|----------------|------|
| **Driver PWA** | The scanner operator (a [User](scout-user-assignment.md) with an assigned [Kit](kit.md)) | Vue SPA at `resources/js/clarity-scout/`, served by the `scout/{any?}` route → `view('clarity-scout')` | Sanctum bearer token (`/api/scout/login`) + `ClarityScoutMiddleware` |
| **Admin Scout panel** | Org admins | Filament resources/pages (`ScoutUsersResource`, `ZoneResource`, `ManageZones`) under the **"Scout"** navigation group | Filament web session + `ClarityScoutAdminMiddleware` / `ZonePolicy` |
| **Bird's Eye org map** | Org admins | The *same* Vue app booted in `adminMode`, served by `scout/org-map/{any?}` → `view('clarity-scout-birds-eye')` | Filament web session + `ClarityScoutAdminMiddleware` |

The Bird's Eye view deliberately **reuses the real driver `App.vue` and Pinia stores** in an admin mode rather than cloning them — the admin API endpoints under `/scout/org-map/api/*` mirror the driver `/api/scout/*` paths and response shapes so the existing stores/chrome render org-wide unchanged (DEV-2116). See [real-time tracking](scout-real-time-tracking.md).

## Data architecture (at a glance)

Scout is read-heavy and analytical, so it leans on ClickHouse:

```
scanner GPS  →  clarity.sc_positions (ClickHouse)  →  ClickhouseQuery classes  →  /api/scout/* JSON  →  Vue / deck.gl
zones, kits, shifts, notifications              →  PostgreSQL (platform schema)  ↗
```

- **Real-time positions / live paths / scan paths / heatmaps** are read from **ClickHouse** (`clarity.sc_positions`, `clarity.kit_scan_paths`, scan/hit tables). See [real-time tracking](scout-real-time-tracking.md) and [ClickHouse query patterns](clickhouse-query-patterns.md).
- **Zones, kit↔user assignment, shifts, and notifications** are transactional **PostgreSQL** on the `platform` connection (`platform.zones`, `platform.zone_user`, `platform.kit_settings`, `platform.kit_shifts`). See [Data Architecture](data-architecture.md) before touching either side.

## Feature gating

Scout is a **Pro feature, but explicit-grant-only** — it is **not** bundled into the `CLARITY_PRO` / `PRO_PLUS` tier feature sets. An org gets Scout only through an explicit per-org Pro grant of `CLARITY_SCOUT` **and** the experimental config flag being on. The two flags:

| Flag | Slug | Definition (`app/Providers/PennantServiceProvider.php`) |
|------|------|---------------------------------------------------------|
| `FeatureEnum::CLARITY_SCOUT` | `platform_repo_pro_clarity_scout` | `config('app.experimental.clarity_scout', false)` **AND** (`$user->isInsightLpr()` **OR** `$user->isProEnabled(CLARITY_SCOUT)`) |
| `FeatureEnum::CLARITY_SCOUT_TRIPS` | `platform_repo_pro_clarity_scout_trips` | `config('app.experimental.clarity_scout_trips', false)` only — no per-user check (hidden during rollout) |

See [DTO & Enums](dto-and-enums.md) for `FeatureEnum`, and [Multi-Tenancy](multi-tenancy.md) for Pro gating / `isProEnabled()`.

### Gate points (keep in sync)

`CLARITY_SCOUT` is checked at five places. All admin-side checks are wrapped `Feature::active(...) || session()->has('admin_impersonator')`, so an InsightLPR staffer impersonating an org that lacks the grant still sees Scout:

1. `app/Filament/Resources/ScoutUsersResource.php` (`canAccess()`)
2. `app/Filament/Resources/ZoneResource.php` (`canAccess()`)
3. `app/Filament/Pages/ManageZones.php` (`canAccess()`)
4. The "Org Map" / Bird's Eye navigation entry
5. `app/Http/Middleware/ClarityScoutAdminMiddleware.php` (admin web routes)

The driver side is gated separately: the `routes/web.php` and `routes/api.php` Scout blocks are wrapped in `if (config('app.experimental.clarity_scout', false))`, and `ClarityScoutMiddleware` (Sanctum) enforces kit + `clarity-scout` permission + the feature per request. The broadcast channel `clarity.scout.user.{userId}` re-checks the same gate (see [real-time tracking](scout-real-time-tracking.md)).

## Where it lives in code

- Driver SPA: `resources/js/clarity-scout/` (`App.vue`, `stores/`, `composables/`)
- Driver API controllers: `app/Http/Controllers/Api/ClarityScout*Controller.php`
- Admin Bird's Eye controller: `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php`
- Middleware: `app/Http/Middleware/ClarityScoutMiddleware.php` (driver, Sanctum), `app/Http/Middleware/ClarityScoutAdminMiddleware.php` (admin, web)
- Feature flags: `app/Enums/FeatureEnum.php`, `app/Providers/PennantServiceProvider.php`
- Routes: `routes/api.php`, `routes/web.php`, `routes/channels.php`

## See also

- [Zone](zone.md) — patrol areas drivers are assigned to
- [Scout user assignment](scout-user-assignment.md) — kit ↔ user ↔ zone linkage and `clarity-scout` permission
- [Scout real-time tracking](scout-real-time-tracking.md) — GPS → ClickHouse → Vue, polling, geohash heatmaps, offline detection, broadcasting, Bird's Eye
- [Scout frontend architecture](scout-frontend.md) — the Vue PWA client: boot, routing, stores, composables, map layers
- [Scout PWA & offline](scout-pwa-and-offline.md) — service worker, install/update, PII purge
- [Scout API](scout-api.md) — full endpoint catalog
- [Scout Bird's Eye](scout-birds-eye.md) — the admin org-wide map surface
- [Scout ClickHouse queries](scout-clickhouse-queries.md) — full SQL for every query class
- [Scout shifts & trips](scout-shifts-and-trips.md) — shift lifecycle and end-of-shift summaries
- [Scout dashboard stats & map counting](scout-dashboard-stats.md) — how every Scout number is computed (dedup, precision, org scoping, current-vs-frozen status)
- [Kit](kit.md) — a kit is the user/login a driver scans under
- [Data Architecture](data-architecture.md) — PostgreSQL vs ClickHouse
