---
type: DataModel
title: address (Address)
description: Geocoded street-address records linked to hotlist entries, with a PostGIS point, in the PostgreSQL zod database, public schema.
resource: app/Models/Address.php
tags: [model, postgres, public, zod, lpr, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# address

An `address` row is a geocoded street address associated with one or more [hotlist](../concepts/hotlist.md) entries (many-to-many through [hotlist_address](hotlist_address.md)). It stores the parsed components (house number, road, city, state, postcode), the original unparsed string (`orig`), and a PostGIS `Point` geometry (`geo`, SRID 4326) produced by geocoding. Addresses give recovery agents locations tied to a hotlisted account — for example lienholder or last-known addresses used to locate a vehicle.

It lives in **PostgreSQL**, the `zod` logical database, `public` schema, on the `zod` connection — one of the core LPR tables managed by the external `insight-lpr/laravel-zod-migrations` package. See [Data Architecture](../concepts/data-architecture.md).

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `address`
- Model class: `App\Models\Address` (`app/Models/Address.php`)
- Timestamps: disabled (`$timestamps = false`)
- PostGIS: uses `HasPostgisColumns`; `geo` is `geometry`, SRID 4326.

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key (sequence `address_id_seq`). |
| `house_number` | character varying(20) | yes | Parsed house/street number. |
| `road` | character varying(128) | yes | Parsed street/road name. |
| `city` | character varying(64) | yes | Parsed city. |
| `state` | character varying(32) | yes | Parsed state. |
| `postcode` | character varying(5) | yes | Parsed ZIP/postal code. |
| `geo` | geometry(Point) | yes | PostGIS point (SRID 4326) for the geocoded location. |
| `orig` | character varying | no | Original unparsed address string fed to the geocoder. |
| `type_id` | integer | yes | Address type/category discriminator. |

## Casts & enums

The model declares no `$casts`. `geo` is handled as a PostGIS column (`Clickbar\Magellan` `Point`, SRID 4326) via `HasPostgisColumns` / `$postgisColumns`, not via a standard cast.

## Relationships

- **hotlists()** — belongsToMany [hotlist](hotlist.md) through the [hotlist_address](hotlist_address.md) pivot (`address_id` ↔ `hotlist_id`).

## Notable scopes & behavior

- **`formattedAddress` accessor** — builds a single display string from the parsed components (`house_number road, city, state postcode`).
- **`geocode()` method** — calls the Geocodio API on `orig`, then populates `geo` (`ST_SetSRID(ST_MakePoint(lng, lat), 4326)`) and the parsed component columns and saves; logs and returns `false` on failure.
- **Fillable:** `house_number`, `road`, `city`, `state`, `postcode`, `geo`, `orig`.

## Data mapping (PostgreSQL ↔ ClickHouse)

Part of the hotlist family. Addresses join to hotlist entries via `hotlist_address`; hotlist data denormalizes into `clarity.hotlist`. A dedicated `address` ClickHouse replica is not established here — treat as transactional-only unless a `zod.public_address` replica is confirmed. See [Data Architecture](../concepts/data-architecture.md).

## See also

- [hotlist](hotlist.md) — the entries an address is linked to
- [hotlist_address](hotlist_address.md) — the pivot joining addresses to hotlist entries
- [Hotlist concept](../concepts/hotlist.md)
- [Data Architecture](../concepts/data-architecture.md)
