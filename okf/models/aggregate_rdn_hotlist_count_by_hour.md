---
type: DataModel
title: aggregate_rdn_hotlist_count_by_hour (AggregateRdnHotlistCountByHour)
description: Hourly rollup of RDN hotlist counts per org / client / branch / order type, in the PostgreSQL zod database, platform schema.
resource: app/Models/AggregateRdnHotlistCountByHour.php
tags: [model, postgres, platform, aggregate, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# aggregate_rdn_hotlist_count_by_hour

Each row is an **hourly count of RDN (Recovery Database Network) hotlist entries** grouped by owning [organization](../concepts/organization.md), client org, branch, and order type. It is a pre-aggregated rollup that lets dashboards report hotlist volume over time without re-counting raw [hotlist](../concepts/hotlist.md) rows. RDN is an external recovery-order integration whose orders drive hotlist entries.

The model lives in **PostgreSQL** — logical database `zod`, `platform` schema (the Clarity add-on schema), `platform` connection (the default). It has no single primary key, no auto-increment, and timestamps disabled; treat it as a refreshed aggregate table.

## Location

- Database: `zod`
- Schema: `platform`
- Connection: `platform`
- Table: `aggregate_rdn_hotlist_count_by_hour`
- Model class: `App\Models\AggregateRdnHotlistCountByHour` (`app/Models/AggregateRdnHotlistCountByHour.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `org_id` | `integer` | no | Owning [organization](../concepts/organization.md). FK → `public.orgs.id`. |
| `client_org_id` | `integer` | yes | Client organization the count is attributed to. FK → `public.orgs.id`. |
| `hour_timestamp` | `timestamp(0) without time zone` | no | The hour bucket the count applies to (cast to datetime). |
| `branch_id` | `character varying(255)` | yes | Branch the count is grouped by. FK → `platform.branches.id`. |
| `order_type` | `integer` | yes | RDN order type discriminator for the grouping. |
| `hotlist_count` | `integer` | no | Number of hotlist entries in this org/client/branch/order-type/hour bucket. Defaults to `0`. |

## Casts & enums

- `hour_timestamp` → `datetime`
- `org_id` → `integer`
- `client_org_id` → `integer`
- `order_type` → `integer`
- `hotlist_count` → `integer`

## Relationships

- **org()** — belongsTo [Org](../concepts/organization.md) via `org_id`
- **branch()** — belongsTo `Branch` via `branch_id`
- **clientOrg()** — belongsTo [Org](../concepts/organization.md) via `client_org_id`

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (a `platform`-schema aggregate; the PeerDB CDC path replicates `public.*`, not `platform.*`). Mapping not established beyond the transactional rollup.

## Notable scopes & behavior

- `public $incrementing = false;`, `public $timestamps = false;`, and `$primaryKey = null` — the model is a keyless hourly rollup, not an application-written entity.
- `$fillable` lists all six columns, allowing the rollup process to mass-assign rows.
- Org-scoped reporting is expressed through the `org` / `clientOrg` relationships rather than a `forOrg()` scope.

## See also

- [Hotlist](../concepts/hotlist.md) — the entity being counted
- [Organization](../concepts/organization.md) — ownership and client attribution
- [staging_orgs_hotlist_count.md](staging_orgs_hotlist_count.md) — a related hotlist-count view for staging orgs
- [Data Architecture](../concepts/data-architecture.md)
