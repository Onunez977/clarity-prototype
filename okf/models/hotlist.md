---
type: DataModel
title: hotlist (Hotlist)
description: A wanted-vehicle order that scans are matched against — the target list for LPR, in PostgreSQL zod database, public schema.
resource: app/Models/Hotlist.php
tags: [model, postgres, public, lpr, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# hotlist

A `hotlist` row is a wanted-vehicle order — a plate/VIN/vehicle description that incoming [scans](../concepts/scan.md) are matched against to generate [hits](../concepts/hit.md). Orders originate from external integrations (RDN, iRepo, etc.) and belong to a repo [organization](../concepts/organization.md), optionally with an associated client org and lienholder org. This is a transactional record living in **PostgreSQL**, database `zod`, schema `public`, on the `zod` connection, owned by the external `insight-lpr/laravel-zod-migrations` package. See the conceptual page [hotlist](../concepts/hotlist.md).

## Location

- Database: `zod` (PostgreSQL)
- Schema: `public`
- Connection: `zod`
- Table: `hotlist`
- Model class: `App\Models\Hotlist` (`app/Models/Hotlist.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key (sequence `hotlist_id_seq`) |
| `integration_id` | `integer` | no | Source integration (`IntegrationEnum`); powers `remote_name` / `remote_link` accessors |
| `remote_order_id` | `character varying(64)` | yes | Order ID in the source system (RDN/iRepo) |
| `status` | `integer` | no | `HotlistStatusEnum` (0=Other, 1=Open, 2=Hold, 3=Repossessed, 4=ClosedPositive, 5=Closed, 90=NewFromClient, 99=Invalid) |
| `org_id` | `integer` | no | FK → `public.orgs.id` — owning repo [org](orgs.md) |
| `client_org_id` | `integer` | no | FK → `public.orgs.id` — client org the order is for |
| `lienholder_org_id` | `integer` | yes | FK → `public.orgs.id` — lienholder org |
| `order_type` | `integer` | no | Order type discriminator |
| `is_staged` | `boolean` | no | Whether the order is a staging/assignment order. Default `false` |
| `plate` | `character varying(12)` | no | Target license plate |
| `us_state` | `character varying(2)` | no | Plate US state |
| `vin` | `character varying(17)` | no | Target VIN |
| `vehicle_year` | `character varying(8)` | no | Vehicle year (part of `vehicle`/`ymm` accessor) |
| `vehicle_make` | `character varying(25)` | no | Vehicle make |
| `vehicle_model` | `character varying(25)` | no | Vehicle model |
| `lookback_days` | `integer` | no | Historical scan lookback window for match generation. Default `270` |
| `created_at` | `timestamp without time zone` | no | Order creation time. Default `CURRENT_TIMESTAMP` |
| `updated_at` | `timestamp without time zone` | no | Last update. Default `CURRENT_TIMESTAMP` |

## Casts & enums

- `status` → `HotlistStatusEnum`
- `remote_order_id` → `string`

## Relationships

- **org()** — belongsTo [Org](orgs.md) via `org_id` (owning repo org).
- **client()** — belongsTo [Org](orgs.md) via `client_org_id`.
- **lienholder()** — belongsTo [Org](orgs.md) via `lienholder_org_id`.
- **integration()** — belongsTo `Integrations` via `integration_id`.
- **hits()** — hasMany [Hit](hits.md) via `hotlist_id`.
- **scans()** — hasManyThrough [Scan](scans.md) through `Hit` (`hotlist_id` → `hits.scan_id`).
- **plate_log()** — hasMany `PlateLog` via `hotlist_id`.
- **meta()** — hasOne `HotlistMeta` via `hotlist_id` (holds `additional_info`, surfaced as the `reason` accessor).
- **hotlistJson()** — hasOne `HotlistJson` via `hotlist_id` (raw payload; `public.hotlist_json`).
- **addresses()** — hasManyThrough `Address` through `HotlistAddress`.
- **addresspivot()** — hasMany `HotlistAddress` via `hotlist_id`.

## Data mapping (PostgreSQL ↔ ClickHouse)

- The `hotlist`, `hotlist_json`, `hotlist_meta`, and `hotlist_address` tables replicate via PeerDB CDC to `zod.public_hotlist*` (each adds `_peerdb_synced_at`, `_peerdb_is_deleted`, `_peerdb_version`).
- Denormalizes into `clarity.hotlist` and feeds status/analytics MVs such as `clarity.hotlist_status_aggregate`. Missed-opportunity analytics are scoped by `hotlist_org_id`. See [clickhouse-integration](../concepts/clickhouse-integration.md).

## Notable scopes & behavior

- `scopeForOrg($org_id)` — filters `org_id` directly (org ownership is native to the hotlist row). See [multi-tenancy](../concepts/multi-tenancy.md).
- `scopeOpen` — `status = Open`; `scopeStaged` — `is_staged = true`.
- `scopeStatus` / `scopeForStatus` — filter by `HotlistStatusEnum`, with `Other` meaning "not Open and not Repossessed".
- `scopeVin` / `scopeVl6` / `scopeForVin` — VIN and VIN-last-6 matching.
- `scopePlateNumber` / `scopeForPlate` — plate matching (LIKE + optional FlexSearch via `PlateFilter`).
- `scopeHasPlate` / `scopeMissingPlate` — presence/absence of `plate`.
- `scopeOrderIds` / `scopeForOrderId` — filter `remote_order_id`.
- `scopeForClients` — filter `client_org_id`.
- `scopeCreatedBetween` — `created_at` range.
- `$timestamps = true` (manages `created_at`/`updated_at`).
- Accessors: `vehicle`/`ymm` (year+make+model), `reason` (from `meta`), `remote_name`/`remote_link` (from `IntegrationEnum`); the `status` accessor coerces invalid values to `Other`.

## See also

- [hotlist](../concepts/hotlist.md) — conceptual background
- [hits](hits.md), [scans](scans.md), [orgs](orgs.md)
- [recovery](../concepts/recovery.md), [missed-opportunity](../concepts/missed-opportunity.md)
- [data-architecture](../concepts/data-architecture.md), [multi-tenancy](../concepts/multi-tenancy.md)
