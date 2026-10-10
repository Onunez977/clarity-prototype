---
type: DataModel
title: kits (Kit, ClickHouse)
description: ClickHouse ReplacingMergeTree mirror of the PostgreSQL kits table, CDC-replicated for analytical joins (kit name, location, org).
resource: app/Clickhouse/Models/Kit.php
tags: [model, clickhouse, clarity, kit, cdc]
timestamp: 2026-07-06T00:00:00Z
---

# kits

`kits` (ClickHouse) is the analytical mirror of the PostgreSQL [kit](../../concepts/kit.md) table. A kit is the logged-in user/account that captures [scans](../../concepts/scan.md) (distinct from `camera_name`, the physical device — see [Kit](../../concepts/kit.md)). This `clarity.kits` copy exists so analytics queries can join kit metadata (name, org, fixed location) to scan/hit/trip aggregates without crossing back to PostgreSQL. It is CDC-replicated, so rows carry the full `_peerdb_*` column set and must be read with `FINAL` and `_peerdb_is_deleted = 0`. See [ClickHouse Integration](../../concepts/clickhouse-integration.md). The model sets `$table = 'clarity.kits'` with the database prefix baked in. The transactional source model is documented at [../kits.md](../kits.md).

## Location

- Database: `clarity`
- Connection: `clarity_clickhouse`
- Table: `clarity.kits` (set explicitly on the model, prefix included)
- Engine: `SharedReplacingMergeTree`
- Model class: `App\Clickhouse\Models\Kit`
- Source: `app/Clickhouse/Models/Kit.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `Int32` | no | Kit primary key. FK → `public.kits.id`. |
| `org_id` | `Int32` | no | Owning organization. FK → `public.orgs.id`. Filtered by `forOrg()`. |
| `kit_mapping` | `String` | no | External mapping identifier for the kit. |
| `kit_type` | `Int32` | no | Kit type discriminator (integer code from the source `kits` table). |
| `name` | `String` | no | Human-readable kit name (joined into affiliate/scan analytics). |
| `text_phone` | `Nullable(String)` | yes | Phone number for text alerts, if configured. |
| `fixed_latitude` | `Nullable(Float64)` | yes | Fixed-location latitude for stationary kits (null for mobile kits). |
| `fixed_longitude` | `Nullable(Float64)` | yes | Fixed-location longitude for stationary kits (null for mobile kits). |
| `_peerdb_synced_at` | `DateTime64(9)` | no | PeerDB CDC sync timestamp. |
| `_peerdb_is_deleted` | `UInt8` | no | CDC soft-delete flag (0 = active, 1 = deleted). **Always filter `= 0`** (the model does this via `FiltersCdcDeletes`). |
| `_peerdb_version` | `UInt64` | no | CDC version for `ReplacingMergeTree` dedup (keep latest). |

## Casts & enums

None declared. The model sets `$guarded = []` (fully mass-assignable, supporting the test factory).

## Relationships

No Eloquent relationships are defined on the ClickHouse model; joins to scans/hits/trips are done by `id`/`org_id` inside query classes.

## Data mapping (PostgreSQL ↔ ClickHouse)

- Direct CDC mirror of PostgreSQL `public.kits`: `public.kits` → `zod.public_kits` → denormalized into `clarity.kits`. Sibling kit-derived aggregates include `clarity.kit_scan_paths` and `clarity.kit_trips` ([kit_trips](kit_trips.md)). See the mapping table in [ClickHouse Integration](../../concepts/clickhouse-integration.md).
- The `_peerdb_synced_at` / `_peerdb_is_deleted` / `_peerdb_version` columns are the standard PeerDB CDC triple.

## Notable scopes & behavior

- **`forOrg($orgId)`** — filters `org_id` (primary tenant scope; see [Multi-Tenancy](../../concepts/multi-tenancy.md)).
- **`FiltersCdcDeletes` trait** — adds a global `FilterDeletedRecords` scope so every query filters `_peerdb_is_deleted = 0` by default; use `withDeleted()` to bypass it.
- **Factory** — `HasFactory` wired to `Database\Factories\Clickhouse\KitFactory` for seeding under the pgsql test bridge.
- **`ReplacingMergeTree` reads** — combine with `FINAL` (via the base model's `scopeFinal()`) so only the latest `_peerdb_version` per id is returned.

## See also

- [../kits.md](../kits.md) — the transactional PostgreSQL kit model (source of truth)
- [Kit](../../concepts/kit.md) — kit vs `camera_name` distinction
- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — engines and PeerDB CDC
- [kit_trips](kit_trips.md) — kit-derived trip aggregation
- [affiliate_stats_hourly](affiliate_stats_hourly.md) — joins `clarity.kits` for the kit name
