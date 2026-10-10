---
type: DataModel
title: hotlist_address (HotlistAddress)
description: Pivot table linking hotlist entries to address records (many-to-many), in the PostgreSQL zod database, public schema.
resource: app/Models/HotlistAddress.php
tags: [model, postgres, public, zod, lpr, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# hotlist_address

A `hotlist_address` row joins a [hotlist](../concepts/hotlist.md) entry to an [address](address.md) record — the pivot backing the many-to-many relationship between hotlisted plates and the addresses associated with the underlying account (e.g. lienholder or last-known addresses used to locate a vehicle). The `HotlistAddress` model extends Eloquent's `Pivot` class.

It lives in **PostgreSQL**, the `zod` logical database, `public` schema, on the `zod` connection — one of the core LPR tables managed by the external `insight-lpr/laravel-zod-migrations` package. See [Data Architecture](../concepts/data-architecture.md).

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `hotlist_address`
- Model class: `App\Models\HotlistAddress` (`app/Models/HotlistAddress.php`), extends `Illuminate\Database\Eloquent\Relations\Pivot`
- Timestamps: disabled (`$timestamps = false`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | integer | no | Primary key (sequence `hotlist_address_id_seq`). |
| `hotlist_id` | bigint | no | FK → `public.hotlist.id`. The hotlist entry side of the join. |
| `address_id` | bigint | no | FK → `public.address.id`. The address side of the join. |

## Relationships

This is the pivot itself; it defines no relationship methods. The many-to-many it backs is declared on the related models:

- `App\Models\Address::hotlists()` — belongsToMany [hotlist](hotlist.md) through `hotlist_address` (`address_id` ↔ `hotlist_id`).
- The corresponding `hotlist`-side belongsToMany to [address](address.md) uses this same pivot.

## Data mapping (PostgreSQL ↔ ClickHouse)

Part of the hotlist family that replicates via PeerDB CDC into ClickHouse. `public.hotlist` (plus `hotlist_json`, `hotlist_meta`, `hotlist_address`) → `zod.public_hotlist*` and denormalizes into `clarity.hotlist`. See [ClickHouse Integration](../concepts/clickhouse-integration.md).

## See also

- [hotlist](hotlist.md) — one side of the join
- [address](address.md) — the other side of the join
- [Hotlist concept](../concepts/hotlist.md)
- [Data Architecture](../concepts/data-architecture.md)
