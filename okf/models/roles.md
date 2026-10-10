---
type: DataModel
title: roles (Role)
description: Spatie RBAC roles, org-scoped — lives in the PostgreSQL zod database, platform schema.
resource: app/Models/Role.php
tags: [model, postgres, platform, access-control, multi-tenancy, spatie]
timestamp: 2026-07-06T00:00:00Z
---

# roles

A `roles` record is a named RBAC role (e.g. the `CLIENT` role) that groups [permissions](permissions.md) and is assigned to [users](users.md). Clarity extends Spatie's `Spatie\Permission\Models\Role` and adds **team/org scoping**: roles are partitioned by `org_id`, so each [organization](../concepts/organization.md) sees only its own roles plus the shared, org-less (global) roles. This is a core piece of Clarity's [multi-tenancy](../concepts/multi-tenancy.md).

This table lives in the **PostgreSQL `zod` database, `platform` schema, `platform` connection** (Clarity add-on tables). Spatie's "teams" feature is enabled, with the team id resolved by `getPermissionsTeamId()`.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform`
- Table: `roles`
- Model class: `App\Models\Role` (extends `Spatie\Permission\Models\Role`) — `app/Models/Role.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key |
| `org_id` | `bigint` | yes | Spatie team key → the [organization](../concepts/organization.md) that owns this role; `NULL` = global/shared role visible to all orgs |
| `name` | `character varying(255)` | no | Role name (e.g. `client`); unique per guard/team |
| `guard_name` | `character varying(255)` | no | Auth guard the role applies to (Spatie) |
| `created_at` | `timestamp(0) without time zone` | yes | Row creation time |
| `updated_at` | `timestamp(0) without time zone` | yes | Row update time |

## Relationships

- **permissions()** — belongsToMany [Permission](permissions.md) through the Spatie `role_has_permissions` pivot (inherited).
- **users()** — belongsToMany [User](users.md) through the Spatie `model_has_roles` morph pivot (inherited).

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only). RBAC tables are not part of the analytical CDC surface.

## Notable scopes & behavior

- **`forOrg` global scope** — applied in `boot()`: every query is constrained to `roles.org_id = getPermissionsTeamId()` OR `roles.org_id IS NULL`. This means an org only ever loads its own roles plus global (org-less) roles.
- Inherits Spatie's role/permission assignment, caching, and guard behavior from the parent model.

## See also

- [permissions](permissions.md) — the permissions a role groups
- [users](users.md) — role holders
- [Organization](../concepts/organization.md) — the team/tenant a role is scoped to
- [Multi-Tenancy](../concepts/multi-tenancy.md) — org-scoped access control
