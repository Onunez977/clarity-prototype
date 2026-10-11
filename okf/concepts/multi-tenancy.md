---
type: Concept
title: Multi-Tenancy
description: Org-based access control — org_id scoping, kit sharing, client↔repo access, staging visibility, roles, feature flags, panel gating.
resource: app/Models/Org.php
tags: [domain, multi-tenancy, security, postgres]
timestamp: 2026-06-26T10:31:00Z
---

# Multi-Tenancy

Clarity is multi-tenant and organization-scoped. **All data is scoped to organizations (`org_id`)**, and there is no single global tenant scope — scoping is applied explicitly via `forOrg()` query scopes and access tables. This page is the hub for the access model; entity pages describe their own scoping.

## Core principle

- Every user belongs to exactly one [Organization](organization.md).
- All major models ([Scan](scan.md), [Hit](hit.md), [Hotlist](hotlist.md), [Kit](kit.md), [Recovery](recovery.md)) have an `org_id` (directly or via the kit).
- Users can only access data belonging to their organization, with explicit exceptions for sharing and client↔repo access.
- Queries are scoped by `forOrg()`; middleware enforces organization boundaries.

## Ownership chain

```
Scan → Kit (kit_id) → Org (org_id)
Hit  → Org (org_id, direct)
Hotlist → Org (org_id, direct) + client_org_id + lienholder_org_id
User → Org (org_id)
```

## Sharing system

**Kits** can be shared between organizations using a polymorphic `Shared` pivot:

```
Shared {
    shared_type       // e.g. Kit::class
    shared_id         // The ID of the shared resource
    shared_with_type  // e.g. Org::class
    shared_with_id    // The organization receiving access
    expires_at        // Optional expiration date
    status_id         // Status of the share
}
```

- Sharing is a **Pro feature** (`FeatureEnum::SHARED_KITS`).
- A global scope automatically filters expired shares.
- Shared kits appear in the receiving organization's scan views; the original org retains ownership.
- Common pattern: **Kit sharing** (let another org see scans from your camera).

> **Hotlists are not shared via `Shared`.** Hotlists originating from other organizations surface as **Staging Hotlists** (`is_staged`), visible only to approved agent orgs via `ApprovalList`. Staging hotlists produce hits of type `S` (Staging Live) and `Z` (Staging Historical). See [Hotlist](hotlist.md).

## Client ↔ Repo access

The client portal gates data visibility through `ClientOrgAccess` (table `platform.client_org_access`):

- A client org is granted access to a repo org's data (`client_org_id`, `repo_org_id`, `granted_by_user_id`, `granted_at`, `revoked_at`).
- `Org::repoOrgsAccessible()` / `Org::clientOrgsWithAccess()` and `User::accessibleRepoOrgIds()` drive client-portal visibility.
- The `client` panel requires a Client org with the portal enabled (`isClientPortalEnabled()`) + repo access.

## Staging visibility

Staged [Hotlists](hotlist.md)/[Hits](hit.md) are only visible to approved agent orgs via `ApprovalList`:

- `Org::stagingOrgIds()` returns the staging orgs an agent org can see.
- `ApprovalList` maps an agent org ↔ staging orgs for staged-hit visibility.

## Roles, permissions, and feature flags

- **Roles/permissions:** Spatie Laravel Permission with team scoping. **Always call `setPermissionsTeamId($user->org_id)` before assigning roles/permissions.** Common roles: Admin, User, Client.
- **Feature flags:** Laravel Pennant. Pro features are stored in `org_meta.properties` JSON and defined in `FeatureEnum` (Shared Kits, Invites, Scan Clustering, Missed Opportunity, Fuzzy Search, Alert Settings). Check with `Feature::active()`; state is cached. Use `makeOrgPro($org)` / `makeUserPro($user)` in tests. See [Feature Flags & Pro Tiers](feature-flags.md) for the resolver + tier mechanics, and [Impersonation](impersonation.md) for the `admin_impersonator` gate bypass.
- **Panel gating:** `User::canAccessPanel()` — `app` panel requires a Repo org with `enable_genetech_hotlist`; `client` panel requires a Client org with portal enabled + repo access.

## Global scopes (always-on, non-tenant)

- `ShowVisibleScan` — hides `is_visible = false` scans.
- `ScanTimezoneScope` — joins `timezones` for local scan time.
- `only_basic` hit-type scope on [Hit](hit.md).

## Business rules

- Multi-Tenancy System, Sharing System, Feature Flag System, User Management, Kit Ownership, Data Privacy — documented across this wiki (see [Kit](kit.md), [DTO and Enums](dto-and-enums.md), [Alert Settings](alert-settings.md)).
- **Users belong to one organization** (no cross-org users); client users have limited access via the client panel; invitations create pending relationships.
- **Kits cannot transfer ownership** in the app (only share access). Derived ClickHouse tables
  may still hold historical org assignments — see [Kit](kit.md) business rules.
- **Organizations cannot see other orgs' data unless explicitly shared**; sharing is bidirectional-aware; audit logs track cross-org access.

## Where it lives in code

- `app/Models/Org.php`, `app/Models/User.php`, `app/Models/UserOrg.php`
- Scopes: `forOrg()` on `Scan`, `Hit`, `Hotlist`, `Kit`, `Recoveries`
- Sharing: `app/Models/Shared.php`, `Kit::getSharedKits()`
- Client access: `app/Models/ClientOrgAccess.php`
- Staging: `app/Models/ApprovalList.php`
- Roles: `app/Models/Role.php`, `app/Models/Permission.php` (Spatie, team-scoped)
- Features: `FeatureEnum` (Black Adam), Laravel Pennant, `app/Models/OrgMeta.php`
- Panel gating: `app/Providers/Filament/App.php`, `app/Providers/Filament/Client.php`, `User::canAccessPanel()`

## See also

- [Organization](organization.md) — the tenancy root
- [Kit](kit.md) — sharable LPR camera users (a kit is a user, not a device; see kit.md)
- [Hotlist](hotlist.md) — assignments (surfaced as Staging Hotlists from other orgs)
- [Scan](scan.md) / [Hit](hit.md) — `forOrg()` scoping through kits
- [Repositories](repositories.md) — Black Adam provides `FeatureEnum` and shared enums
