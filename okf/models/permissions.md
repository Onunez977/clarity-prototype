---
type: DataModel
title: permissions (Permission)
description: Spatie RBAC permissions — lives in the PostgreSQL zod database, platform schema.
resource: app/Models/Permission.php
tags: [model, postgres, platform, access-control, spatie]
timestamp: 2026-07-06T00:00:00Z
---

# permissions

A `permissions` record is a single named capability (e.g. `manage-hotlist`) in Clarity's Spatie RBAC system. Permissions are grouped into [roles](roles.md) and, together, gate what [users](users.md) can do. The model extends `Spatie\Permission\Models\Permission` and layers on presentation accessors (`title`, `group`, `description`) sourced from the Black Adam `PermissionEnum`.

This table lives in the **PostgreSQL `zod` database, `platform` schema, `platform` connection** (Clarity add-on tables). Unlike [roles](roles.md), permissions are **not** org-scoped — they are a global catalog of capabilities shared across all [organizations](../concepts/organization.md); org partitioning happens at the role level. See [multi-tenancy](../concepts/multi-tenancy.md).

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform`
- Table: `permissions`
- Model class: `App\Models\Permission` (extends `Spatie\Permission\Models\Permission`) — `app/Models/Permission.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key |
| `name` | `character varying(255)` | no | Permission name; matched against `PermissionEnum` for display title/group/description |
| `guard_name` | `character varying(255)` | no | Auth guard the permission applies to (Spatie) |
| `created_at` | `timestamp(0) without time zone` | yes | Row creation time |
| `updated_at` | `timestamp(0) without time zone` | yes | Row update time |

## Relationships

- **roles()** — belongsToMany [Role](roles.md) through the Spatie `role_has_permissions` pivot (inherited).
- **users()** — belongsToMany [User](users.md) through the Spatie `model_has_permissions` morph pivot (inherited, for direct permission grants).

Accessors (all resolved from `InsightLPR\BlackAdam\Enum\PermissionEnum` by `name`, with fallbacks):

- `title` — human-friendly label (falls back to a title-cased, dash-stripped `name`).
- `group` — grouping bucket for UI (falls back to `Other`).
- `description` — explanatory text (falls back to empty).

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only). RBAC tables are not part of the analytical CDC surface.

## Notable scopes & behavior

- **`scopeOrderByName()`** — orders results by `permissions.name`.
- Inherits Spatie's permission caching, guard resolution, and pivot wiring from the parent model.
- No org scope — the permission catalog is global (contrast [roles](roles.md), which are org-scoped via `forOrg`).

## See also

- [roles](roles.md) — how permissions are grouped and org-scoped
- [users](users.md) — permission holders
- [Multi-Tenancy](../concepts/multi-tenancy.md) — access control model
