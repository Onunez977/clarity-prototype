---
type: DomainEntity
title: Hotlist
description: A wanted vehicle / repossession assignment — the list of plates to watch for.
resource: app/Models/Hotlist.php
tags: [domain, lpr, postgres, clickhouse]
timestamp: 2026-06-26T16:48:00Z
---

# Hotlist

A **Hotlist** is a wanted vehicle / repossession assignment — a plate (and optional VIN/year-make-model) that an organization wants Clarity to watch for. When a [Scan](scan.md) matches a hotlist entry, a [Hit](hit.md) is created. Hotlists are transactional records in **PostgreSQL** — the `Hotlist` model uses the `zod` connection with `$table = 'hotlist'`, so the table is `zod.hotlist` (the `public` schema of the `zod` database; note the table name is singular) — and mirrored/denormalized into **ClickHouse** for analytics.

## Definition

A hotlist entry represents an assignment: a client/lienholder wants a vehicle found. It carries plate and vehicle identifiers, a status, an order type, references to the client and lienholder orgs, and a lookback window. Hotlists originating from other organizations appear as **Staging Hotlists** (flagged `is_staged`) rather than being shared through the polymorphic `Shared` pivot.

## Key attributes / columns

| Column | Type | Description |
|--------|------|-------------|
| `id` | — | Primary key |
| `plate` | string | Plate to watch |
| `us_state` | string | Plate US state |
| `vin` | string | Vehicle identification number (optional) |
| `vehicle_year` / `vehicle_make` / `vehicle_model` | — | Vehicle description |
| `status` | `HotlistStatusEnum` | Assignment status |
| `order_type` | enum | Order type |
| `org_id` | FK → `orgs.id` | Owning repo [Organization](organization.md) |
| `client_org_id` | FK → `orgs.id` | Client org |
| `lienholder_org_id` | FK → `orgs.id` | Lienholder org |
| `lookback_days` | int | How far back to match historical scans |
| `is_staged` | bool | Staged assignment flag |
| `integration_id` | FK | External integration source |
| `remote_order_id` | string | External order reference |

## Relationships

- A Hotlist belongs to an [Organization](organization.md) (`org_id`) and references a client org and a lienholder org.
- A Hotlist produces many [Hits](hit.md) when scanned plates match.
- A Hotlist may lead to a [Recovery](recovery.md) when a hit results in repossession.
- Sidecars: `HotlistMeta` (extra info, incl. `repo_date`), `HotlistJson` (raw JSON payload), `HotlistAddress` (pivot to `address`).
- `ApprovalList` links a staging org to an approved agent org (`staging_org_id`, `agent_org_id`) and gates Staging Hotlist visibility.
- Hotlists from other orgs surface as **Staging Hotlists** (`is_staged`), visible only to approved agent orgs via `ApprovalList` (see [Multi-Tenancy](multi-tenancy.md)). They are **not** shared via the `Shared` pivot — that pivot is used for Kits, not hotlists.

## Business rules

- **Staging Hotlists, not Shared hotlists:** hotlists originating from other organizations are surfaced as Staging Hotlists (`is_staged`), not via the polymorphic `Shared` model (which is used for Kits). Staging hotlists are only visible to approved agent orgs via `ApprovalList`. See [Multi-Tenancy](multi-tenancy.md) (Sharing System).
- **Staging hit types:** staging hotlists produce hits of type **`S` (Staging Live)** and **`Z` (Staging Historical)** — see `App\Enums\HitTypeEnum` and [Hit](hit.md).
- **Metadata:** client info, lienholder info, and notes are supported.
- **Plate history:** `PlateLog` records plate/state changes on a hotlist entry.
- **Status semantics (`HotlistStatusEnum`):** `1` = Open, `2` = Hold, `3` = Repossessed, `4` = ClosedPositive, `5` = Closed, `0` = Other, `90` = NewFromClient, `99` = Invalid. `isTerminal()` covers `3`/`4`/`5`/`99`. "Open" hotlists are `status = 1`; "repo'd" hotlists are `status = 3`.
- **Valid-plate filter:** open-count queries filter `length(plate) > 3` to exclude placeholder/junk plates.
- **Staging scope via `approval_list`:** to enumerate the Staging Hotlists an agent org (e.g. `1271`) can see, join `approval_list ON (agent_org_id = <agent> AND staging_org_id = hotlist.org_id)` plus `hotlist.is_staged`. Not every forwarder stages every order, so the `is_staged` flag is the discriminator.
- **Repo date lives on `hotlist_meta`:** `hotlist_meta.repo_date` (date) holds the repossession date for a hotlist; "repo'd in last N days" queries join `hotlist_meta` and filter `repo_date >= now() - interval 'N days'`.
- **Address sidecars:** `hotlist_address` is the pivot (`hotlist_id`, `address_id`) to `address`. `address.type_id` discriminates address kind — **`type_id = 2` is the repo/address-of-recovery** (confirmed by the package's recovery views, e.g. `WHERE a.type_id = 2`). `address.geo` is a PostGIS `geometry(Point)`; "geocoded" queries filter `geo IS NOT NULL` and exclude the `0,0` sentinel `0101000020E610000000000000000000000000000000000000`.

## Canonical PostgreSQL queries (open / staging / repo counts & addresses)

These are the production queries used to derive org-scoped hotlist metrics directly from PostgreSQL (`zod.public`). `<org>` is the org id of the logged-in account; `<agent>` is the agent org id for staging scope. All tables are in the `public` schema of the `zod` database (`zod` connection).

```sql
-- Direct hotlist OPENS (count) — the logged-in org's own open assignments
SELECT count(*) FROM hotlist
WHERE org_id = <org> AND status = 1 AND length(plate) > 3;

-- Staging hotlist OPENS (count) — orders forwarded TO the logged-in agent org
SELECT count(*) FROM hotlist
JOIN approval_list ON (agent_org_id = <agent> AND staging_org_id = hotlist.org_id)
WHERE is_staged AND status = 1 AND length(plate) > 3;

-- Direct ADDRESSES (distinct geocoded points) for open direct hotlists
SELECT geo FROM hotlist
JOIN hotlist_address ON (hotlist_id = hotlist.id)
JOIN address ON (address_id = address.id)
WHERE org_id = <org> AND status = 1 AND length(plate) > 3
  AND geo IS NOT NULL
  AND geo != '0101000020E610000000000000000000000000000000000000'
GROUP BY geo;

-- Staging ADDRESSES (distinct count of geocoded points) for open staging hotlists
SELECT count(DISTINCT geo) FROM hotlist
JOIN approval_list ON (agent_org_id = <agent> AND staging_org_id = hotlist.org_id)
JOIN hotlist_address ON (hotlist.id = hotlist_id)
JOIN address ON (address.id = address_id)
WHERE is_staged AND status = 1 AND length(plate) > 3
  AND geo IS NOT NULL
  AND geo != '0101000020E610000000000000000000000000000000000';

-- Direct REPOS (count) — the logged-in org's repossessed assignments
SELECT count(*) FROM hotlist WHERE org_id = <org> AND status = 3;

-- Direct REPO ADDRESSES in the last 7 days (repo address = address.type_id = 2)
SELECT * FROM hotlist
JOIN hotlist_address ON (hotlist_address.hotlist_id = hotlist.id)
JOIN address ON (address.id = address_id AND address.type_id = 2)
JOIN hotlist_meta ON (hotlist_meta.hotlist_id = hotlist.id)
WHERE org_id = <org> AND status = 3
  AND repo_date >= now() - interval '7 days';
```

Notes on the queries: direct queries scope by `hotlist.org_id = <org>`; staging queries scope by the `approval_list` join (agent ↔ staging org) plus `is_staged`. "Open" is `status = 1`; "repo'd" is `status = 3`. Address queries exclude the PostGIS `0,0` sentinel. Repo-address queries pin `address.type_id = 2` and read `repo_date` from `hotlist_meta`.

## Where it lives in code

- Model: `app/Models/Hotlist.php` (PostgreSQL `zod` connection; table `hotlist` singular)
- Sidecars: `app/Models/HotlistMeta.php`, `app/Models/HotlistJson.php`, `app/Models/HotlistAddress.php`, `app/Models/PlateLog.php`
- Policy: `app/Policies/HotlistPolicy.php`
- Filament resource: `app/Filament/Resources/HotlistResource.php` (app panel, "Search")
- ClickHouse mirror: `app/Clickhouse/Models/Hotlist.php` (table `hotlist`, read-only)
- ClickHouse aggregates: `app/Clickhouse/Models/HotlistStatusAggregate.php` (`clarity.hotlist_status_aggregate`)

## See also

- [Hit Address Distance](hit-address-distance.md) — Hits Distance from Given uses `hotlist_address` (PG path) vs `recovery_address` type_id=2 (CH fallback)

- [Hit](hit.md) — matches derived from hotlists
- [Recovery](recovery.md) — downstream outcome
- [Organization](organization.md) — ownership, client, lienholder
- [Data Architecture](data-architecture.md) — PostgreSQL `zod.hotlist` (public schema) vs ClickHouse `zod.public_hotlist` / `clarity.hotlist` / `clarity.hotlist_status_aggregate`
- [Multi-Tenancy](multi-tenancy.md) — sharing and staging visibility
