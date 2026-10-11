---
type: DataModel
title: client_org_access (ClientOrgAccess)
description: Grants a repo org access to a client org's data (schema-prefixed table on the zod connection) in the zod PostgreSQL database.
resource: app/Models/ClientOrgAccess.php
tags: [model, postgres, platform, multi-tenancy]
timestamp: 2026-07-06T00:00:00Z
---

# client_org_access

A **client_org_access** row records that a repo [Organization](../concepts/organization.md) has been granted access to a client organization's data, who granted it, when, and whether it has since been revoked. It backs cross-org data-sharing in the [Multi-Tenancy](../concepts/multi-tenancy.md) model. It lives in the **PostgreSQL `zod` database, `platform` schema**, but is accessed on the **`zod` connection** using a schema-prefixed table name (`platform.client_org_access`) rather than the default `platform` connection.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `zod` (model sets `$connection = 'zod'` and `$table = 'platform.client_org_access'`)
- Table: `client_org_access` (referenced as `platform.client_org_access`)
- Model class: `App\Models\ClientOrgAccess` — `app/Models/ClientOrgAccess.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key |
| `client_org_id` | integer | no | FK → the client [Organization](../concepts/organization.md) whose data is shared |
| `repo_org_id` | integer | no | FK → the repo [Organization](../concepts/organization.md) receiving access |
| `granted_by_user_id` | bigint | yes | FK → `users.id` — the user who granted access |
| `granted_at` | timestamp(0) without time zone | no | When access was granted (default `CURRENT_TIMESTAMP`) |
| `revoked_at` | timestamp(0) without time zone | yes | When access was revoked (null = still active); drives the `active` global scope |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp |

## Casts & enums

- `granted_at` → `datetime`
- `revoked_at` → `datetime`

## Relationships

- **clientOrg()** — belongsTo [Organization](../concepts/organization.md) via `client_org_id`
- **repoOrg()** — belongsTo [Organization](../concepts/organization.md) via `repo_org_id`
- **grantedBy()** — belongsTo `User` via `granted_by_user_id`

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only access-control table).

## Notable scopes & behavior

- **`active` global scope** (registered in `boot()`) — only returns non-revoked grants (`revoked_at IS NULL`).
- **`withRevoked()`** query scope — removes the `active` scope to include revoked grants.

## See also

- [Multi-Tenancy](../concepts/multi-tenancy.md) — cross-org access grants
- [Organization](../concepts/organization.md) — client and repo orgs
- [shared](shared.md) — a more general polymorphic sharing pivot
