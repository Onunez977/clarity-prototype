---
type: Concept
title: Hit Address Distance (Distance from Given)
description: When Hits columns Distance from Given and Nearest Given Address show a value vs N/A — feature gate, dual PG/CH sources, geocode guards, and what remains unproven (DEV-2739).
resource: app/Filament/Resources/HitsResource.php
tags: [domain, hits, address, pennant, postgres, clickhouse]
timestamp: 2026-09-25T15:26:00Z
---

# Hit Address Distance (Distance from Given)

Hits list columns **Distance from Given** and **Nearest Given Address** estimate how far the hit's scan was from the nearest address tied to the matched order. Product copy: *"See how far each hit was from the nearest given address on the order"* (`FeatureEnum::HIT_ADDRESS_DISTANCE` Description). Original story [DEV-1611](https://insightlpr-team.atlassian.net/browse/DEV-1611) (Done). Motivating follow-up [DEV-2739](https://insightlpr-team.atlassian.net/browse/DEV-2739) (Brad Webb company all-N/A in demo) — **do not treat that ticket as a proven root cause**; it only motivates documenting this contract.

## Feature gate

| Item | Value | Source |
|------|-------|--------|
| Enum | `FeatureEnum::HIT_ADDRESS_DISTANCE` | `app/Enums/FeatureEnum.php` |
| Pennant value | `platform_repo_pro_hit_address_distance` | same |
| UI title | Distance from Given | `#[Title]` on the enum case |
| Pennant definition | Insight LPR user **or** `User::isProEnabled(HIT_ADDRESS_DISTANCE)` | `app/Providers/PennantServiceProvider.php` |
| Tier grants | `PRO_LITE`, `CLARITY_PRO`, `CLARITY_PRO_PLUS` | `ProTierEnum::features()` |
| Column visibility | columns visible when feature active **or** `session()->has('admin_impersonator')` | `HitsResource` `->visible(...)` |
| Analytics | `distance-from-given` track on Hits page mount when gate passes | `ListHits::mount()` |

When the feature is inactive and the viewer is **not** an admin impersonator, **Nearest Given Address** renders the Pro teaser blade instead of a value (`filament.tables.columns.pro-feature-teaser`). Distance column is not visible in that case.

## Two computation paths (code)

The Hits table owns one pair of columns, but the **numeric/text source depends on whether the list query already selected the columns**.

### Path A — PostgreSQL list query (`ListHits::buildPostgresQuery`)

Used when `ListHits::shouldUseClickhouse()` is **false**.

When the feature (or admin impersonator) is active, the query adds correlated subqueries:

- Join: `zod.public.hotlist_address` → `zod.public.address` (`ha.hotlist_id = hotlist.id`, `a.id = ha.address_id`) on the **`zod`** connection / `public` schema.
- Distance: `ST_DistanceSphere(scans.geo::geometry, a.geo::geometry) * 0.000621371`, rounded to 2 decimals → alias `distance_from_given_miles`.
- Address text: `CONCAT_WS` of house/road, city, state+postcode → alias `nearest_given_address`.
- Ordering: nearest address first (`ORDER BY ST_DistanceSphere(...) ASC LIMIT 1`).
- Geo predicate on this path: `a.geo IS NOT NULL AND scans.geo IS NOT NULL` only.

**Not filtered on this path (as of the cited code):** `address.type_id`, and the PostGIS `(0,0)` sentinel that [hotlist.md](hotlist.md) canonical open-address queries exclude (`geo != '0101000020E610000000000000000000000000000000000000'`).

Sources: `app/Filament/Resources/HitsResource/Pages/ListHits.php` (`buildPostgresQuery`).

### Path B — async ClickHouse fallback (blades → `NearestGivenAddressController`)

Used when column state from the list query is null/`'-'` **and** the record has truthy `hotlist_id`, `lat`, and `lng` — `HitsResource` then returns Alpine blades that `POST /nearest-given-address`.

`ListHits::buildClickhouseQuery()` does **not** select `distance_from_given_miles` or `nearest_given_address`. `shouldUseClickhouse()` is true when `Feature::active('clickhouse:hits')` **or** `session()->has('admin_impersonator')`. Under admin impersonation the list therefore always takes the ClickHouse hit rows and relies on Path B for these two columns.

Controller rules (`app/Http/Controllers/NearestGivenAddressController.php`):

- Same feature / admin-impersonator gate; otherwise empty JSON.
- Rejects request scan coords with `lat == 0 || lng == 0` → empty JSON.
- Reads **`clarity.recovery_address` `FINAL`** on connection `clarity_clickhouse`, filtered `ra.id = {hotlist_id}` and `ra.geo.1 != 0 AND ra.geo.2 != 0`.
- Picks nearest row in PHP via Haversine (earth radius 3958.8 mi); returns `{ address, distance_miles }` or empty JSON.

Blades (`resources/views/filament/tables/columns/distance-from-given.blade.php`, `nearest-given-address.blade.php`) show **N/A** when loading finishes without a distance/address; they also skip the fetch when `hotlistId` / `lat` / `lng` are falsy (including numeric `0`). Client helper: `resources/js/app.js` → `fetchNearestGivenAddress` → route `nearest-given-address` in `routes/web.php`.

## What `clarity.recovery_address` is (provenance)

PostgreSQL `public.recovery_address` is a **materialized view** (package `insight-lpr/laravel-zod-migrations`), not an open-order address book:

- Built from `hotlist_address` ⋈ `address` where **`a.type_id = 2`**, one row per hotlist (`row_number = 1`), with `hotlist_address.hotlist_id AS id`.
- Wiki convention: `address.type_id = 2` is the **repo / address-of-recovery** ([hotlist.md](hotlist.md)).
- Mirrored analytically as ClickHouse `clarity.recovery_address` (`ReplacingMergeTree`; see [clickhouse-integration.md](clickhouse-integration.md)).

Path A (PG) uses **all** linked `hotlist_address` rows with non-null `geo`. Path B (CH controller) uses **only** the type_id=2 recovery-address MV row(s) keyed by hotlist id. That disagreement is **in the code**; whether it explains any particular org's all-N/A display is **unproven** without reproduce evidence (see below).

Sibling note (do not conflate): Scout check-in / open-order address paths were deliberately moved **off** `recovery_address` onto `public_hotlist_address` → `public_address` / `clarity.scout_open_addresses` (DEV-2163 / DEV-2386). DEV-2163 plan text explicitly left `NearestGivenAddressController` on `recovery_address`.

## When N/A is correct (contract)

Treat **N/A** as correct when any of the following hold (cited behavior):

1. **No usable scan coordinates for the fallback UI** — missing/`0` lat/lng on the record so blades/formatters short-circuit to N/A (`HitsResource` formatters; blade `init()`; controller `lat == 0 \|\| lng == 0`).
2. **No qualifying given address for the active path** — Path A: no `hotlist_address`→`address` row with non-null `geo` for that hotlist (and non-null scan geo). Path B: no `clarity.recovery_address` row for that hotlist id with non-zero geo → empty JSON → blade N/A.
3. **Feature off for a non-impersonating user** — Distance column hidden; Nearest Given shows Pro teaser (not the miles/address).

**N/A is a bug candidate** only after confirming the active path, that scan geo is present and non-zero, and that the path's address source has at least one qualifying geocoded row — and still the UI shows N/A. Classification belongs to investigation tickets (e.g. DEV-2739 AC), not this page.

## Databases / schemas touched

| Symbol | Database | Notes |
|--------|----------|-------|
| `hotlist_address`, `address`, `scans.geo` (Path A) | PostgreSQL `zod` / `public` | Core LPR; `zod` connection |
| `clarity.recovery_address` (Path B) | ClickHouse `clarity` | Analytical mirror of PG MV; `clarity_clickhouse` |
| Hit list rows when CH path | ClickHouse `hits_hot` / `hits_cold` | `buildClickhouseQuery`; no distance columns selected |

## UNPROVEN (do not promote to rules)

- **Brad Webb / DEV-2739 all-N/A root cause** — ticket asks to reproduce and classify data vs query vs UI; First Glance hard-stop: do not invent root cause before reproduce. Demo context may include admin impersonation (which forces Path B), but that is **not** proven as the failure mode for that company.
- **Whether Path A vs Path B address-source disagreement is wrong for product intent** — code differs (all geocoded `hotlist_address` vs `type_id = 2` recovery MV); no cited product AC in this ingest settles which is canonical for "given address on the order."
- **Whether Path A's lack of `(0,0)` exclusion is intentional** — Path B and hotlist open-address SQL exclude zero geo; Path A does not. Effect on live rows not measured here.
- **Whether `Feature::active('clickhouse:hits')` matches the Pennant slug for `CLICKHOUSE_HITS`** — ListHits uses the string `'clickhouse:hits'`; Pennant defines `FeatureEnum::CLICKHOUSE_HITS->slug()` (`clickhouse-hits` from title "Clickhouse Hits"). Documented as written; resolution not proven in this ingest. Admin-impersonator bypass does not depend on that string.

## Where it lives in code

- Columns / formatters: `app/Filament/Resources/HitsResource.php`
- List query (PG + CH switch): `app/Filament/Resources/HitsResource/Pages/ListHits.php`
- CH nearest endpoint: `app/Http/Controllers/NearestGivenAddressController.php`
- Blades: `resources/views/filament/tables/columns/distance-from-given.blade.php`, `nearest-given-address.blade.php`
- Client: `resources/js/app.js` (`fetchNearestGivenAddress`)
- Route: `POST /nearest-given-address` (`routes/web.php`)
- Feature: `app/Enums/FeatureEnum.php`, `PennantServiceProvider`, `ProTierEnum`
- PG MV definition: `vendor/insight-lpr/laravel-zod-migrations` schema (`CREATE MATERIALIZED VIEW public.recovery_address`)

## See also

- [Hit](hit.md) — Hits domain entity / Filament resource
- [Hotlist](hotlist.md) — `hotlist_address` / `address.type_id = 2` / `(0,0)` sentinel
- [Recovery](recovery.md) — recovery MVs including `recovery_address`
- [Feature flags](feature-flags.md) — Pennant + admin impersonator bypass
- [DTOs and Enums](dto-and-enums.md) — `HIT_ADDRESS_DISTANCE` row
- [models/hotlist_address.md](../models/hotlist_address.md), [models/address.md](../models/address.md)
- [Data Architecture](data-architecture.md)
