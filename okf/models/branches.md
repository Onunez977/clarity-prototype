---
type: DataModel
title: branches (Branch)
description: Physical branch/office locations belonging to an organization, in the platform schema of the zod PostgreSQL database.
resource: app/Models/Branch.php
tags: [model, postgres, platform, multi-tenancy]
timestamp: 2026-07-06T00:00:00Z
---

# branches

A **branches** row is a physical branch or office location belonging to an [Organization](../concepts/organization.md) — its name, address, contact details, and an external branch identifier (`branch_id` / `udbsaid`). Branches let orgs segment operations and data by location. It lives in the **PostgreSQL `zod` database, `platform` schema**, on the default `platform` connection.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform` (default)
- Table: `branches`
- Model class: `App\Models\Branch` — `app/Models/Branch.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key |
| `org_id` | integer | no | FK → owning [Organization](../concepts/organization.md) |
| `branch_id` | integer | yes | External/source branch identifier |
| `udbsaid` | character varying(255) | yes | External branch/system identifier (UDB SAID) |
| `zip_code` | character varying(255) | yes | Branch ZIP code |
| `branch_name` | character varying(255) | yes | Branch display name |
| `address` | character varying(255) | yes | Street address |
| `city` | character varying(255) | yes | City |
| `state` | character varying(255) | yes | State |
| `phone` | character varying(255) | yes | Phone number |
| `fax` | character varying(255) | yes | Fax number |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp |

## Casts & enums

- `org_id` → `integer`
- `branch_id` → `integer`

## Relationships

- **org()** — belongsTo [Organization](../concepts/organization.md) via `org_id`

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only reference table).

## Notable scopes & behavior

None beyond standard Eloquent behavior.

## See also

- [Organization](../concepts/organization.md) — owning org
- [Multi-Tenancy](../concepts/multi-tenancy.md) — org-scoped data
