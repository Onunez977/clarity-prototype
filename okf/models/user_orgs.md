---
type: DataModel
title: user_orgs (UserOrg)
description: User-to-organization membership pivot — lives in the PostgreSQL zod database, public schema.
resource: app/Models/UserOrg.php
tags: [model, postgres, public, zod, access-control, multi-tenancy]
timestamp: 2026-07-06T00:00:00Z
---

# user_orgs

A `user_orgs` record is a many-to-many link between a [user](users.md) and an [organization](../concepts/organization.md). Where `users.org_id` records a user's single primary org, `user_orgs` is the pivot that lets a user be associated with multiple orgs — part of the org-scoping machinery behind Clarity's [multi-tenancy](../concepts/multi-tenancy.md).

This table lives in the **PostgreSQL `zod` database, `public` schema, `zod` connection** — the core LPR schema owned by the external `insight-lpr/laravel-zod-migrations` package, not the Clarity `platform` schema. The model extends Eloquent's `Pivot` base class and is timestamp-less.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `public`
- Connection: `zod`
- Table: `user_orgs`
- Model class: `App\Models\UserOrg` — `app/Models/UserOrg.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `integer` | no | Primary key |
| `user_id` | `integer` | no | FK → `platform.users.id` — the [user](users.md) side of the membership |
| `org_id` | `integer` | no | FK → `public.orgs.id` — the [organization](../concepts/organization.md) side of the membership |

## Relationships

The model is a bare `Pivot` and declares no relationship methods of its own; it is the join table sitting between the [User](users.md) and [Org](../concepts/organization.md) sides of a `belongsToMany` association. `$timestamps` is disabled and the connection is pinned to `zod`.

## Data mapping (PostgreSQL ↔ ClickHouse)

Core `public.*` tables replicate via PeerDB to `zod.public_<table>`, so a `zod.public_user_orgs` CDC mirror likely exists, but no denormalized `clarity.*` counterpart is established for this membership pivot. Mapping not established beyond the raw CDC replica.

## Notable scopes & behavior

- Extends `Illuminate\Database\Eloquent\Relations\Pivot`.
- `$timestamps = false` — no `created_at`/`updated_at`.
- `$connection = 'zod'` — explicitly bound to the core LPR connection/`public` schema.

## See also

- [users](users.md) — the user side of the membership
- [Organization](../concepts/organization.md) — the org side
- [Multi-Tenancy](../concepts/multi-tenancy.md) — org-scoped access control
