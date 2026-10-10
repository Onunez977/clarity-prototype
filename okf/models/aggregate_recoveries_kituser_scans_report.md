---
type: DataModel
title: aggregate_recoveries_kituser_scans_report (RecoveriesKitUserScansReport)
description: Denormalized recovery + hotlist + address + scan-count rollup backing the recoveries report, in the PostgreSQL zod database, public schema.
resource: app/Models/RecoveriesKitUserScansReport.php
tags: [model, postgres, public, aggregate, recovery, hotlist, lpr]
timestamp: 2026-07-06T00:00:00Z
---

# aggregate_recoveries_kituser_scans_report

Each row is a **denormalized recovery/hotlist record enriched with vehicle, address, and scan-count fields** that backs the recoveries reporting UI. It flattens a [recovery](../concepts/recovery.md)/[hotlist](../concepts/hotlist.md) entry together with its plate/VIN/vehicle attributes, resolved address, owning/client/lienholder orgs, the [Kit](../concepts/kit.md) involved, and pre-computed hotlist scan counts and percentages — so the report can filter and rank recoveries without joining many source tables at query time.

The model lives in **PostgreSQL** — logical database `zod`, `public` schema, `zod` connection. It carries an `id` primary key and rich query scopes; treat the underlying table as a refreshed reporting rollup.

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `aggregate_recoveries_kituser_scans_report`
- Model class: `App\Models\RecoveriesKitUserScansReport` (`app/Models/RecoveriesKitUserScansReport.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `integer` | no | Primary key (sequence). |
| `recovery_id` | `bigint` | no | The recovery/hotlist this row summarizes. Also joins `HotlistMeta.hotlist_id`. |
| `integration_id` | `integer` | yes | Source integration; cast to `IntegrationEnum` (e.g. RDN and other recovery networks). |
| `remote_order_id` | `character varying(64)` | yes | External order id in the source integration. |
| `status` | `integer` | yes | Hotlist status; cast to `HotlistStatusEnum`. |
| `org_id` | `integer` | no | Owning [organization](../concepts/organization.md). FK → `public.orgs.id`. |
| `client_org_id` | `integer` | yes | Client org attribution. FK → `public.orgs.id`. |
| `lienholder_org_id` | `integer` | yes | Lienholder org. FK → `public.orgs.id`. |
| `order_type` | `integer` | yes | Recovery order type discriminator. |
| `is_staged` | `boolean` | yes | Whether the hotlist entry is staged (not yet active). |
| `plate` | `character varying(12)` | yes | License plate of the target vehicle. |
| `us_state` | `character varying(2)` | yes | Plate US state. |
| `vin` | `character varying(17)` | yes | Vehicle VIN. |
| `vehicle_year` | `character varying(8)` | yes | Vehicle model year. |
| `vehicle_make` | `character varying(25)` | yes | Vehicle make. |
| `vehicle_model` | `character varying(25)` | yes | Vehicle model. |
| `lookback_days` | `integer` | yes | Scan lookback window used for the scan-count aggregation. |
| `created_at` | `timestamp without time zone` | yes | Row/recovery creation time. |
| `updated_at` | `timestamp without time zone` | yes | Row/recovery update time. |
| `address_id` | `bigint` | yes | Resolved address. FK → `public.addresses.id`. |
| `house_number` | `character varying(20)` | yes | Denormalized address house number. |
| `road` | `character varying(128)` | yes | Denormalized address road. |
| `city` | `character varying(64)` | yes | Denormalized address city. |
| `state` | `character varying(32)` | yes | Denormalized address state. |
| `postcode` | `character varying(5)` | yes | Denormalized address ZIP. |
| `geo` | `geometry(Point)` | yes | PostGIS point of the address/recovery location. |
| `repo_date` | `date` | yes | Repossession/recovery date (filtered by `recoveryDateBetween`). |
| `kit_id` | `integer` | yes | The [Kit](../concepts/kit.md) involved. FK → `public.kits.id`. |
| `count` | `bigint` | yes | Aggregated scan count for this recovery within the lookback window. |
| `hotlist_scan_count` | `numeric` | yes | Total hotlist scan count (summed across the dataset in `reportAggregateQuery`). |
| `hotlist_scan_percentage` | `numeric` | yes | `count / hotlist_scan_count * 100` — this recovery's share of hotlist scans. |

## Casts & enums

- `status` → `App\Enums\HotlistStatusEnum`
- `integration_id` → `InsightLPR\BlackAdam\Enum\IntegrationEnum`
- `remote_order_id` → `string`

## Relationships

- **org()** — belongsTo [Org](../concepts/organization.md) via `org_id`
- **client()** — belongsTo [Org](../concepts/organization.md) via `client_org_id`
- **lienholder()** — belongsTo [Org](../concepts/organization.md) via `lienholder_org_id`
- **kit()** — belongsTo [Kit](../concepts/kit.md) via `kit_id`
- **hotlistMeta()** — belongsTo `HotlistMeta` via `recovery_id` → `hotlist_id`
- **plate_log()** — hasMany `PlateLog` via `hotlist_id` (local key `id`)
- **hits()** — hasMany [Hit](../concepts/hit.md) via `hotlist_id` (local key `id`)
- **scans()** — hasManyThrough [Scan](../concepts/scan.md) through [Hit](../concepts/hit.md) (`Hit.hotlist_id` ← `id`, `Scan.id` ← `Hit.scan_id`)

## Data mapping (PostgreSQL ↔ ClickHouse)

No direct ClickHouse replica of this exact reporting table. Its source concepts have CH counterparts: recoveries map to `clarity.recoveries` and `clarity.recovery_kit_scan_report`, and hits map to `clarity.hits_cold`/`hits_hot`. This aggregate is a PostgreSQL-side denormalized report rather than a PeerDB-replicated table.

## Notable scopes & behavior

- `forOrg($org_id)` — org-scopes by `<table>.org_id` (see [Multi-Tenancy](../concepts/multi-tenancy.md)).
- `vin($value)`, `plateNumber($value)`, `orderIds($ids)`, `kits(array)`, `hotlistId($value)`, `recoveryDateBetween($value)` — report filters (VIN, plate, `remote_order_id` list, `kit_id` list, single hotlist, `repo_date` range).
- Accessors: `remoteName` (integration name, underscores → spaces) and `remoteLink` (external order URL via `IntegrationEnum::remoteLink`).
- `reportAggregateQuery(Builder $query)` — wraps a base query in CTEs (`dataset`, `dataset2`) on the `zod` connection to sum `hotlist_scan_count` per id and compute `hotlist_scan_percentage`, producing the final report row shape.

## See also

- [Recovery](../concepts/recovery.md) · [Hotlist](../concepts/hotlist.md) · [Hit](../concepts/hit.md) · [Kit](../concepts/kit.md)
- [Organization](../concepts/organization.md) · [Multi-Tenancy](../concepts/multi-tenancy.md)
- [aggregate_rdn_hotlist_count_by_hour.md](aggregate_rdn_hotlist_count_by_hour.md)
