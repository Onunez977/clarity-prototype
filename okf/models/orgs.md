---
type: DataModel
title: orgs (Org)
description: An organization (repo company, client, lienholder, etc.) — the multi-tenancy root, in PostgreSQL zod database, public schema.
resource: app/Models/Org.php
tags: [model, postgres, public, lpr, organization, multi-tenancy]
timestamp: 2026-07-06T00:00:00Z
---

# orgs

An `orgs` row is an organization — a repo company, repo client, lienholder, insurer, law-enforcement agency, retailer, or Insight itself — and is the root of Clarity's [multi-tenancy](../concepts/multi-tenancy.md) model. Kits, hotlists, users, and (transitively) scans and hits are all scoped to an org. This is a transactional record living in **PostgreSQL**, database `zod`, schema `public`, on the `zod` connection, owned by the external `insight-lpr/laravel-zod-migrations` package. Cross-org data sharing (repo ↔ client) is managed through the `platform.client_org_access` pivot in the Clarity add-on schema. See the conceptual page [organization](../concepts/organization.md).

## Location

- Database: `zod` (PostgreSQL)
- Schema: `public`
- Connection: `zod`
- Table: `orgs`
- Model class: `App\Models\Org` (`app/Models/Org.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `integer` | no | Primary key (sequence `orgs_id_seq`) |
| `name` | `character varying(64)` | no | Organization name |
| `address_id` | `bigint` | yes | FK → address record (likely `addresses.id`) |
| `type` | `integer` | no | `OrgType` (1=Repo, 2=Client, 3=LienHolder, 4=LawEnforcement, 5=Insurance, 6=RealEstate, 7=Retailer, 99=Insight/Other). Default `1` |
| `rdn_id` | `character varying(64)` | yes | RDN account identifier; presence drives `hasRdnAccount()` |
| `rdn_api_key` | `character varying(64)` | yes | RDN API key for client-list lookups |
| `irepo_id` | `character varying(64)` | yes | iRepo account identifier |
| `enable_genetech_hotlist` | `boolean` | no | Whether the Genetech hotlist integration is enabled. Default `false` |
| `enable_matrix_hotlist` | `boolean` | no | Whether the Matrix hotlist integration is enabled. Default `false` |
| `silent_hit_generation` | `boolean` | no | When true, this org's hits are suppressed from the enriched hit listing (`Hit::getHits`). Default `false` |
| `ls_id` | `character varying(64)` | yes | External "LS" system identifier |

## Casts & enums

- `silent_hit_generation` → `bool`
- `enable_genetech_hotlist` → `bool`
- `enable_matrix_hotlist` → `bool`
- `type` is a plain integer compared against `InsightLPR\BlackAdam\Enum\OrgType` in helpers (`isClient()`, `isRepo()`, `isLienHolder()`).

## Relationships

- **users()** — hasMany `User`.
- **hotlists()** — hasMany [Hotlist](hotlist.md).
- **kits()** — hasMany [Kit](kits.md).
- **scans()** — hasManyThrough [Scan](scans.md) through `Kit` (`orgs.id` → `kits.org_id` → `scans.kit_id`).
- **meta()** — hasOne `OrgMeta` (shares `id`; holds `properties` JSON for pro-tier/feature/client-portal flags).
- **branches()** — hasMany `Branch`.
- **zones()** — hasMany [Zone](../concepts/zone.md).
- **runArea()** — hasOne `OrgRunArea`.
- **repoOrgsAccessible()** — belongsToMany self via `platform.client_org_access` (client → repos it can access; `revoked_at IS NULL`).
- **clientOrgsWithAccess()** — belongsToMany self via `platform.client_org_access` (repo → clients with access; `revoked_at IS NULL`).

## Data mapping (PostgreSQL ↔ ClickHouse)

- Replicates via PeerDB CDC to `zod.public_orgs` (adds `_peerdb_synced_at`, `_peerdb_is_deleted`, `_peerdb_version`) and org name/type are denormalized onto `clarity.*` fact/aggregate tables (hits, hotlist, recoveries) rather than exposed as a standalone `clarity` fact table. See [clickhouse-integration](../concepts/clickhouse-integration.md).

## Notable scopes & behavior

- `scopeIsHotlistEnabled` — orgs with either Genetech or Matrix hotlist enabled.
- `scopeActive` — repo-type orgs with Genetech hotlist enabled.
- Pro-tier / feature resolution: `isProEnabled()`, `proTier()` (`ProTierEnum`), `proBillingCycle()` read the `OrgMeta.properties` JSON (cached), including pending-downgrade handling.
- `isClientPortalEnabled()` — reads `meta.properties['enable_client_portal']`.
- `getOrgKits()`, `getHotlistClients()`, `getHotlistLienholders()`, `getRdnClients()`, `stagingOrgIds()` — cached lookups deriving related orgs/kits/clients.
- Type predicates: `isClient()`, `isRepo()`, `isLienHolder()`, `hasRdnAccount()`.
- `$timestamps = false`.

## See also

- [organization](../concepts/organization.md) — conceptual background
- [multi-tenancy](../concepts/multi-tenancy.md) — org-scoping model and `platform.client_org_access`
- [kits](kits.md), [hotlist](hotlist.md), [scans](scans.md), [hits](hits.md)
- [data-architecture](../concepts/data-architecture.md)
