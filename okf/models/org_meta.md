---
type: DataModel
title: org_meta (OrgMeta)
description: Per-organization settings sidecar keyed 1:1 to orgs — lives in the PostgreSQL zod database, public schema.
resource: app/Models/OrgMeta.php
tags: [model, postgres, public, zod, organization, multi-tenancy]
timestamp: 2026-07-06T00:00:00Z
---

# org_meta

An `org_meta` record is a 1:1 settings/feature sidecar for an [organization](../concepts/organization.md). Its primary key **is** the org id (shared PK), and it carries a free-form `properties` JSON blob plus two data-sharing feature flags. This is where per-org configuration that does not warrant a dedicated column lives.

This table sits in the **PostgreSQL `zod` database, `public` schema, `zod` connection** — the core LPR schema owned by the external `insight-lpr/laravel-zod-migrations` package. The `OrgMeta` model pins `$connection = 'zod'`, disables timestamps, and casts `properties` to JSON. Feature flags here participate in Clarity's [multi-tenancy](../concepts/multi-tenancy.md) (e.g. whether an org's aggregated data may be shared).

## Location

- Database: `zod` (PostgreSQL)
- Schema: `public`
- Connection: `zod`
- Table: `org_meta`
- Model class: `App\Models\OrgMeta` — `app/Models/OrgMeta.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key AND FK → `public.orgs.id` (shared 1:1 key with the org) |
| `properties` | `jsonb` | no | Arbitrary per-org settings blob (default `{}`); updated key-by-key via `updateProperties()` |
| `commercial_data_feed_enabled` | `boolean` | yes | Feature flag: whether the org's commercial data feed is enabled |
| `aggregated_data_sharing_enabled` | `boolean` | yes | Feature flag: whether the org permits sharing of its aggregated data |

## Casts & enums

- `properties` → `json`

## Relationships

- **org()** — belongsTo [Org](../concepts/organization.md) via a shared key (`id` → `id`), i.e. the org this metadata row belongs to.

## Data mapping (PostgreSQL ↔ ClickHouse)

Core `public.*` tables replicate via PeerDB to `zod.public_<table>`, so a `zod.public_org_meta` CDC mirror likely exists. No dedicated denormalized `clarity.*` counterpart is established; org attributes are typically joined in at query time. Mapping not established beyond the raw CDC replica.

## Notable scopes & behavior

- `$timestamps = false` — no `created_at`/`updated_at`.
- `$connection = 'zod'` — bound to the core LPR connection/`public` schema.
- **`updateProperties(array $newProperties)`** — merges the given keys into the existing `properties` blob and persists (shallow, per-key merge).
- `$fillable` = `['id', 'properties']` — the two boolean feature flags are not mass-assignable.

## See also

- [Organization](../concepts/organization.md) — the org this metadata extends
- [org_run_areas](org_run_areas.md) — another per-org sidecar table
- [Multi-Tenancy](../concepts/multi-tenancy.md) — where org feature flags factor into access
