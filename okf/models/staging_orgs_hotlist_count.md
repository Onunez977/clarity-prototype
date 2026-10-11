---
type: DataModel
title: staging_orgs_hotlist_count (StagingOrgsHotlistCount)
description: Database view exposing hotlist totals per staging org, in the PostgreSQL zod database, public schema.
resource: app/Models/Views/StagingOrgsHotlistCount.php
tags: [model, postgres, public, aggregate, hotlist, view]
timestamp: 2026-07-06T00:00:00Z
---

# staging_orgs_hotlist_count

Each row exposes the **total number of [hotlist](../concepts/hotlist.md) entries for a "staging" organization** — orgs whose hotlist data is being staged rather than fully active. It is a read-only database view (namespaced under `App\Models\Views`) used to quickly sum staged hotlist volume across a set of staging orgs, e.g. for capacity/monitoring displays.

The model lives in **PostgreSQL** — logical database `zod`, `public` schema, `zod` connection. It is a view, so it has no primary key, no timestamps, and no relationships; it is queried, cached, and summed.

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table (view): `staging_orgs_hotlist_count`
- Model class: `App\Models\Views\StagingOrgsHotlistCount` (`app/Models/Views/StagingOrgsHotlistCount.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `org_id` | `integer` | yes | The staging [organization](../concepts/organization.md) the count belongs to. FK → `public.orgs.id`. |
| `total` | `bigint` | yes | Total hotlist entries for that staging org. |

## Casts & enums

None declared.

## Relationships

None. This is a read-only view with no Eloquent relationships.

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica — this is a PostgreSQL view, not a base table, and is not part of the PeerDB `public.*` → `clarity.*` replication path.

## Notable scopes & behavior

- Backed by a **database view** (updates automatically when the underlying hotlist data changes); the application never writes to it.
- `public $timestamps = false;`.
- `getCount(array $stagingOrgIds = [])` is a static helper that sums `total` over the given staging org ids, memoized in the cache until the end of the current hour (`staging-org-hotlist-count:<ids>`), returning `0` on any exception.

## See also

- [Hotlist](../concepts/hotlist.md) — the entity being counted
- [Organization](../concepts/organization.md) · [Multi-Tenancy](../concepts/multi-tenancy.md)
- [aggregate_rdn_hotlist_count_by_hour.md](aggregate_rdn_hotlist_count_by_hour.md) — hourly RDN hotlist-count rollup
