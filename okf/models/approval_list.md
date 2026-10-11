---
type: DataModel
title: approval_list (ApprovalList)
description: Maps recovery-agent organizations to the staging organizations they are approved to work, in the PostgreSQL zod database, public schema.
resource: app/Models/ApprovalList.php
tags: [model, postgres, public, zod, lpr, hotlist, organization]
timestamp: 2026-07-06T00:00:00Z
---

# approval_list

An `approval_list` row records an approval relationship between two [organizations](../concepts/organization.md): an `agent_org_id` (a recovery-agent org) and a `staging_org_id` (an org whose hotlist/staging work the agent is authorized to act on). It is the access-control mapping that governs which agent orgs may service which staging orgs' plates.

It lives in **PostgreSQL**, the `zod` logical database, `public` schema, on the `zod` connection — one of the core LPR tables managed by the external `insight-lpr/laravel-zod-migrations` package. See [Multi-Tenancy](../concepts/multi-tenancy.md) and [Data Architecture](../concepts/data-architecture.md).

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `approval_list`
- Model class: `App\Models\ApprovalList` (`app/Models/ApprovalList.php`)
- Timestamps: disabled (`$timestamps = false`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key (sequence `approval_list_id_seq`). |
| `agent_org_id` | integer | yes | FK → `public.orgs.id`. The recovery-agent organization being granted access. |
| `staging_org_id` | integer | yes | FK → `public.orgs.id`. The staging organization whose work the agent org is approved for. |

## Relationships

- **org()** — belongsTo [organization](../concepts/organization.md) via `agent_org_id` (the agent org side of the approval).

Note: `staging_org_id` also references `public.orgs.id` but has no dedicated relationship method on the model.

## Data mapping (PostgreSQL ↔ ClickHouse)

Transactional access-control mapping. No established ClickHouse replica; treat as transactional-only. See [Data Architecture](../concepts/data-architecture.md).

## See also

- [Organization](../concepts/organization.md) — the agent and staging orgs referenced
- [Multi-Tenancy](../concepts/multi-tenancy.md) — org-scoped access control
- [Hotlist concept](../concepts/hotlist.md) — the staging/hotlist work these approvals govern
