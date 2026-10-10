---
type: Concept
title: Feature Flags & Pro Tiers
description: How Clarity gates Pro features at runtime — Laravel Pennant definitions, the org Pro-tier resolution (org_meta tier store + per-feature overrides + isProEnabled), the enforcement patterns and admin-impersonator bypass, and the Pennant cache + FlushPennant invalidation.
resource: app/Providers/PennantServiceProvider.php
tags: [feature-flags, pennant, pro-tier, multi-tenancy, gating, org-meta]
timestamp: 2026-07-06T00:00:00Z
---

# Feature Flags & Pro Tiers

Clarity's paid capabilities are gated by a two-layer system: **Laravel Pennant** defines the feature flags, and an org's **Pro tier** (plus per-feature overrides stored on the org) drives whether each flag resolves active. This page is the runtime mechanics — for the enum *values* (the 17 `FeatureEnum` slugs and the 4 `ProTierEnum` tiers with prices) see [DTOs & Enums](dto-and-enums.md); for org scoping and panel gating see [Multi-Tenancy](multi-tenancy.md).

## How a feature resolves (`Org::isProEnabled`)

`app/Models/Org.php` is the source of truth. `isProEnabled(FeatureEnum|string|null $feature)` decides in three steps:

1. **Org-level Pro on/off** — reads `platform_repo_pro` from `org_meta` (bool), forced `false` if `platform_repo_pro_end` is in the past. Cached under `org_pro_enabled_{orgId}` for **30 min**. With no `$feature` argument, this boolean is the answer.
2. **Per-feature override first** — if `org_meta.properties[<feature slug>]` is set (not null), return `$pro && <that flag>`. This is the **explicit grant** path: a feature can be turned on for one org regardless of its tier (how [Scout](scout.md) is granted — no tier lists it).
3. **Tier fallback** — otherwise resolve `proTier()` and return whether the feature is in `$tier->features()`.

`proTier(): ProTierEnum` returns `BASIC` when not Pro-enabled; otherwise reads `platform_repo_pro_tier` (a missing value maps legacy Pro orgs to `CLARITY_PRO` for back-compat), then applies a **pending downgrade** — if `platform_repo_pro_downgrade_effective_at` is past, it returns `platform_repo_pro_downgrade_target_tier`.

## Pennant definitions (`PennantServiceProvider`)

`app/Providers/PennantServiceProvider.php` defines each flag. The standard resolver is per-user and delegates straight to the org:

```php
Feature::define($feature->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled($feature));
```

`isInsightLpr()` means internal staff always get the feature. A subset is additionally guarded by an **experimental config flag** (off in prod until the feature ships):

| Feature | Config guard | Resolver |
|---------|--------------|----------|
| `CLICKHOUSE_SCANS` | `app.experimental.clickhouse.scans` | config **AND** (`isInsightLpr` **OR** `isProEnabled`) |
| `CLICKHOUSE_HITS` | `app.experimental.clickhouse.hits` | config **AND** (…) |
| `CLEARPLAN_RESEND` | `app.experimental.clearplan` | config **AND** (…) — see [ClearPlan](clearplan.md) |
| `CLARITY_SCOUT` | `app.experimental.clarity_scout` | config **AND** (…) — see [Scout](scout.md) |
| `CLARITY_SCOUT_TRIPS` | `app.experimental.clarity_scout_trips` | **config only** (no per-user check) |

All other features use the plain resolver with no config guard.

## Pro tiers & the `org_meta` store

Tiers are `ProTierEnum` (`app/Enums/ProTierEnum.php`); each tier's `features(): array<FeatureEnum>` is the tier→feature mapping (`FeatureEnum::minimumTier()` inverts it). Current grants:

| Tier | Grants |
|------|--------|
| `BASIC` ($0) | — (nothing) |
| `PRO_LITE` ($249) | Flex Search, Scan Clustering, Repo Heatmap, Hit Address Distance, LPR Visits |
| `CLARITY_PRO` ($599) | Pro Lite + Client Analytics, Missed Opportunity, Alert Settings, Shared Kits, Invites, ClickHouse Scans/Hits, ClearPlan Resend |
| `CLARITY_PRO_PLUS` ($1199) | Clarity Pro + Hit Webhook |

**Tier is stored, not computed.** The values live in the shared `org_meta` table's `properties` JSON — **PostgreSQL `zod` database, `public` schema** (`zod.public.org_meta`, on the `zod` connection; see [Organization](organization.md)). A sibling staff app (**Backyard**) writes these; platform only reads them, so a tier change is a data flip with no platform deploy (see [Repositories](repositories.md)). The keys:

| `org_meta.properties` key | Meaning |
|---------------------------|---------|
| `platform_repo_pro` | Master Pro on/off (bool) |
| `platform_repo_pro_end` | ISO date — a past date revokes Pro |
| `platform_repo_pro_tier` | The `ProTierEnum` value |
| `platform_repo_pro_downgrade_effective_at` / `_downgrade_target_tier` | Scheduled downgrade |
| `<feature slug>` (e.g. `platform_repo_pro_clarity_scout`) | Per-feature explicit override (step 2 above) |

> **Two different databases.** The *tier/grant source* is `zod.public.org_meta` (Postgres). Pennant's *resolved-value cache* is a different table on a different schema — see below. Don't conflate them.

## Enforcement patterns

Check a flag with `Feature::active($slug)` (current user) or `Feature::for($user)->active($slug)`. Three recurring shapes:

- **Filament resource `canAccess()`** — often `config('app.env') === 'production' ? Feature::active(...) || session()->has('admin_impersonator') : canViewAny()`.
- **Column / nav visibility** — `->visible(fn () => Feature::active(FeatureEnum::X->slug()) || session()->has('admin_impersonator'))`.
- **Policies** — `(session()->has('admin_impersonator') || Feature::for($user)->active(...)) && $user->can(...) && $user->org_id === …`.

**The `admin_impersonator` bypass** (`session()->has('admin_impersonator')`) is paired with almost every admin-side gate so an InsightLPR staffer impersonating an org that lacks the grant still sees the feature. See [Impersonation](impersonation.md). Gate Blade/Filament UI via the `HasProFeature` concern (see [Filament Conventions](filament-conventions.md)).

## Caching & invalidation

- **Org Pro flag:** `org_pro_enabled_{orgId}`, 30 min (in `isProEnabled`).
- **Pennant resolved values:** the `features` table — **PostgreSQL `platform` schema** (`platform.features`, default `platform` connection; migration `2025_05_14_140126_create_features_table.php`), unique on `(name, scope)`.
- **`FlushPennant` job** (`app/Jobs/FlushPennant.php`) runs when a tier/subscription changes: `Feature::flushCache()` + `Feature::purge()`, then `cache()->forget('org_pro_enabled_'.$id)` (and `org_pro_features_'.$id`) for every active org — otherwise a stale 30-min cache or a stale Pennant row keeps serving the old entitlement.

## Where it lives in code

- Definitions: `app/Providers/PennantServiceProvider.php`
- Enums: `app/Enums/FeatureEnum.php`, `app/Enums/ProTierEnum.php` (values in [DTOs & Enums](dto-and-enums.md))
- Resolution: `app/Models/Org.php` (`isProEnabled`, `proTier`), `app/Models/User.php` (`isProEnabled`, `isInsightLpr`)
- Tier store: `app/Models/OrgMeta.php` → `zod.public.org_meta`
- Cache/invalidation: `app/Jobs/FlushPennant.php`, `platform.features` table
- UI gate concern: `app/Filament/Concerns/HasProFeature.php`

## See also

- [Hit Address Distance](hit-address-distance.md) — `HIT_ADDRESS_DISTANCE` Hits columns; N/A contract

- [DTOs & Enums](dto-and-enums.md) — the `FeatureEnum` slug table and `ProTierEnum` prices/helpers
- [Multi-Tenancy](multi-tenancy.md) — org scoping, roles/permissions, panel gating, the `org_meta.properties` note
- [Impersonation](impersonation.md) — the `admin_impersonator` bypass paired with every gate
- [Organization](organization.md) — `org_meta` and the Backyard-written tier
- [Scout](scout.md) / [ClearPlan](clearplan.md) — explicit-grant + experimental-config-guarded examples
- [Filament Conventions](filament-conventions.md) — `HasProFeature` UI gating
