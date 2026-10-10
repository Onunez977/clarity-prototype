---
type: DomainEntity
title: Organization
description: The multi-tenant root — a Repo, Client, or LienHolder organization that owns data and gates access.
resource: app/Models/Org.php
tags: [domain, multi-tenancy, postgres]
timestamp: 2026-06-26T10:31:00Z
---

# Organization

An **Organization** (model `Org`) is the root of Clarity's multi-tenancy. Every user, kit, hotlist, hit, and recovery is scoped to an organization. An org has a type (Repo / Client / LienHolder), holds external integration credentials (RDN), and carries Pro-tier feature flags via `OrgMeta`.

## Definition

Organizations are the tenancy boundary. Data isolation is enforced by `org_id` on all major models and explicit `forOrg()` query scopes (there is no single global tenant scope). Cross-org access happens only through explicit sharing or access-grant tables.

## Org types

Defined in `OrgType`:

- **Repo** — a repossession agency that operates LPR [Kits](kit.md) and receives [Hits](hit.md).
- **Client** — a client organization (e.g. a lienholder) that consumes results via the client portal.
- **LienHolder** — an organization holding the lien on a vehicle referenced by a [Hotlist](hotlist.md).

## Key attributes / columns

| Column | Type | Description |
|--------|------|-------------|
| `id` | — | Primary key |
| `name` | string | Organization name |
| `type` | `OrgType` | Repo / Client / LienHolder |
| `rdn_id` / `rdn_api_key` | — | Recovery Database Network credentials |
| `enable_genetech_hotlist` | bool | Genetech hotlist integration toggle |
| `enable_matrix_hotlist` | bool | Matrix hotlist integration toggle |
| `silent_hit_generation` | bool | Suppress hit notifications on generation |

Pro-tier flags and portal toggles live in `OrgMeta.properties` JSON (e.g. `platform_repo_pro`, `platform_repo_pro_shared_kits`, client-portal enabled).

## Relationships

- An Org owns many [Kits](kit.md), [Hotlists](hotlist.md), [Hits](hit.md), [Recoveries](recovery.md), and [Users](#) (`User`).
- An Org may share its kits with other orgs via the `Shared` pivot.
- A Client org is granted access to a Repo org's data via `ClientOrgAccess`.
- An Org's Pro features are gated by `OrgMeta` + Laravel Pennant (`FeatureEnum`).

## Business rules

- **Pro tiers:** `Org::isProEnabled()`, `Org::proTier()`, and `FeatureEnum` drive feature gating. Use `makeOrgPro($org)` in tests to enable all features. See [DTO and Enums](dto-and-enums.md) (`FeatureEnum`, `ProTierEnum`).
- **Panel gating:** `User::canAccessPanel()` — the `app` panel requires a Repo org with `enable_genetech_hotlist`; the `client` panel requires a Client org with the portal enabled and repo access.
- **Client↔Repo access:** `Org::repoOrgsAccessible()`, `Org::clientOrgsWithAccess()`, and `User::accessibleRepoOrgIds()` drive client-portal data visibility (via `ClientOrgAccess`).
- **Staging visibility:** `Org::stagingOrgIds()` controls which staging orgs an agent org can see (via `ApprovalList`).
- **RDN credentials:** an org with RDN credentials can sync clients, agents, branches, and cases (see `app/Services/RDN.php` and `app/Jobs/Rdn/`).

## Where it lives in code

- Model: `app/Models/Org.php` (PostgreSQL `zod` connection)
- Meta: `app/Models/OrgMeta.php` (Pro flags, portal toggle, billing)
- Access: `app/Models/ClientOrgAccess.php`, `app/Models/ApprovalList.php`, `app/Models/Shared.php`, `app/Models/UserOrg.php`
- Branches: `app/Models/Branch.php`
- Pro features: `FeatureEnum` (Black Adam package), Laravel Pennant

## See also

- [Kit](kit.md) — LPR camera users owned by an org (kits are users, not devices)
- [Hotlist](hotlist.md) — assignments owned by an org
- [Multi-Tenancy](multi-tenancy.md) — the full scoping/sharing/access model
- [Repositories](repositories.md) — the Black Adam package providing `FeatureEnum`, `OrgType`, etc.
