---
type: DataModel
title: hits (Hit)
description: A match between a scan and a hotlist entry — the join record between an LPR read and a wanted vehicle, in PostgreSQL zod database, public schema.
resource: app/Models/Hit.php
tags: [model, postgres, public, lpr, hit, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# hits

A `hits` row records that a [scan](../concepts/scan.md)'s plate matched a [hotlist](../concepts/hotlist.md) entry — the join between an LPR read and a wanted vehicle, and the trigger for alerts and (ultimately) recoveries. Each hit carries a `hit_type` classifying the match (live, historical, staging, missed opportunity, probable match). This is a transactional record living in **PostgreSQL**, database `zod`, schema `public`, on the `zod` connection, owned by the external `insight-lpr/laravel-zod-migrations` package and mirrored to ClickHouse for analytics. See the conceptual page [hit](../concepts/hit.md).

## Location

- Database: `zod` (PostgreSQL)
- Schema: `public`
- Connection: `zod`
- Table: `hits`
- Model class: `App\Models\Hit` (`app/Models/Hit.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key (sequence `hits_id_seq`) |
| `hotlist_id` | `bigint` | no | FK → `public.hotlist.id` — the matched [hotlist](hotlist.md) entry |
| `scan_id` | `bigint` | no | FK → `public.scans.id` — the [scan](scans.md) that produced the match |
| `hit_type` | `character(1)` | no | Match classification; `HitTypeEnum` (L=Live, H=Historical, S=Staging Live, Z=Staging Historical, M=Missed Opportunity, F=Missed Opportunity Spotted & Closed, P=Probable Match). Exposed via the `type` accessor |
| `created_at` | `timestamp without time zone` | no | When the hit was generated. Default `CURRENT_TIMESTAMP`; accessor normalizes to UTC |
| `kit_id` | `integer` | no | FK → `public.kits.id` — capturing [kit](kits.md). Default `-1` |

## Casts & enums

- `type` → `HitTypeEnum` (note: reads the underlying `hit_type` char column; the `type()` accessor returns the enum name)
- `created_at` → `datetime`

## Relationships

- **kit()** — belongsTo [Kit](kits.md) via `kit_id`.
- **hotlist()** — belongsTo [Hotlist](hotlist.md) via `hotlist_id`.
- **scan()** — belongsTo [Scan](scans.md) via `scan_id`.
- **scans()** — belongsTo [Scan](scans.md) (deprecated alias of `scan()`).
- **activity()** — morphMany `Spatie\Activitylog\Models\Activity` (subject).

## Data mapping (PostgreSQL ↔ ClickHouse)

- Replicates via PeerDB CDC to `zod.public_hits` (adds `_peerdb_synced_at`, `_peerdb_is_deleted`, `_peerdb_version`).
- Denormalizes into `clarity.hits_cold` (plus `clarity.hits_hot` for recent data), and feeds `clarity.hotlist_status_aggregate` and `clarity.client_historical_hits_hourly` materialized views. See [clickhouse-integration](../concepts/clickhouse-integration.md).

## Notable scopes & behavior

- **Global scope `only_basic`** — restricts default queries to `hit_type` in (L, H, S, Z), excluding missed-opportunity (M/F) and probable-match (P) rows.
- `scopeForOrg($org_id)` — joins `hotlist` and filters `hotlist.org_id`; org ownership is resolved through the hotlist, not the scan/kit. See [multi-tenancy](../concepts/multi-tenancy.md).
- `scopeStatus` / `scopeForStatus` — filter by joined `hotlist.status` (`HotlistStatusEnum`), with `Other` meaning "not Open and not Repossessed".
- `scopeHitType` / `scopeForHitType` — filter by `hit_type`.
- `scopePlateNumber` / `scopeForPlate` — join `scans` and filter `scans.ocr`.
- `scopeVin` / `scopeVl6` / `scopeForVin` / `scopeTerm` / `scopeOrderIds` / `scopeForOrderId` — join `hotlist` and filter on VIN, VIN-last-6, plate/VIN term, or `remote_order_id`.
- `scopeCoordinates` — join `scans` and PostGIS `ST_DWithin` proximity on `scans.geo`.
- `scopeScannedGte/Lte` / `scopeForScannedBetween` — join `scans` and filter `scans.scanned_at`.
- `scopeCreatedGte/Lte` / `scopeCreatedBetween` / `scopeForCreatedBetween` — filter `hits.created_at`.
- `scopeRanked` — window functions `row_number` / `rank_total` partitioned by `hotlist_id`.
- `scopeForClients` / `scopeForLienholders` — filter joined `hotlist.client_org_id` / `hotlist.lienholder_org_id`.
- `scopeForDispositions` / `scopeExcludeDisposition` — JSON filters against a joined `scans_metadata.meta->disposition`.
- `getHits()` — static query builder that joins `scans`, `hotlist`, and three `orgs` aliases (hotlist/client/lienholder), masking staging (Z/S) client identity and excluding `silent_hit_generation` orgs; the source of the enriched hit listing.
- `$timestamps = false`.

## See also

- [hit](../concepts/hit.md) — conceptual background
- [scans](scans.md), [hotlist](hotlist.md), [kits](kits.md), [orgs](orgs.md)
- [missed-opportunity](../concepts/missed-opportunity.md), [alert-settings](../concepts/alert-settings.md)
- [data-architecture](../concepts/data-architecture.md), [multi-tenancy](../concepts/multi-tenancy.md)
