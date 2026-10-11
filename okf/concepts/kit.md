---
type: DomainEntity
title: Kit
description: A Kit is a USER that signs in to a physical LPR camera — not the device. The actual device is recorded per-scan as camera_name. kit_type=1 (GL… cameras) is the one that matters.
resource: app/Models/Kit.php
tags: [domain, lpr, postgres, clickhouse]
timestamp: 2026-06-26T16:51:00Z
---

# Kit

> **Read this carefully — this is one of the most commonly misunderstood concepts in Clarity, by both users and AI tools.**

A **Kit is NOT a physical camera.** A **Kit is a user account** that signs in to a physical LPR camera/scanner. The actual physical device is recorded **per scan** as `scans.camera_name` — it is **not** a property of the kit. A single kit (user) can log in to **multiple devices**, and a single device can be used by different kits over time.

## Definition

A Kit is the login identity used by a scanner operator / camera. It belongs to an [Organization](organization.md) and is the bridge between an org and its [Scans](scan.md): every scan row carries a `kit_id` (`scans.kit_id` → `kits.id`), and a scan's tenancy is resolved through its kit (`scans.kit_id` → `kits.org_id`).

What the kit is **not**:

- Not the device. The device is `scans.camera_name`.
- Not fixed hardware. A kit can produce scans from many different `camera_name` values over time.

## Kit vs. device vs. scan (the disambiguation)

| Concept | Where it lives | What it is |
|---------|----------------|------------|
| **Kit** | `zod.public.kits` (PostgreSQL) | A user/login that belongs to an org. Has `name` (the login name), `kit_type`, `org_id`, `kit_mapping`. |
| **Device (camera)** | `zod.public.scans.camera_name` (PostgreSQL, per-scan) | The actual physical camera that captured a given scan. Recorded on the scan, NOT on the kit. |
| **Scan** | `zod.public.scans` (PostgreSQL) → `clarity.scans_cold`/`scans_hot` (ClickHouse) | One LPR read. Carries both `kit_id` (which user) and `camera_name` (which device). |
| **Kit↔camera_name aggregate** | ClickHouse `clarity.scans_cold` / `clarity.scans_hot` | The analytical tables that carry `kit_id`, `kit_name`, AND `camera_name` on the same row — this is where you tie a kit id to the actual camera name(s) it has used. |

To answer "which device did kit X use?" or "which kit used device Y?", query the ClickHouse scan tables (`clarity.scans_cold`/`scans_hot`) grouping by `kit_id` / `camera_name`. The PostgreSQL `kits` table alone cannot answer that — it has no device column.

## Key attributes / columns (`zod.public.kits`)

| Column | Type | Description |
|--------|------|-------------|
| `id` | integer | Primary key |
| `org_id` | integer | Owning [Organization](organization.md) |
| `name` | varchar(64) | The kit's **login name** (the user identity). Not the device, and **not** reliably `GL`-prefixed — see the business rule below. |
| `kit_type` | integer | Kit type. **`kit_type = 1` is the meaningful type** for LPR cameras (the `GL…` cameras). Other types exist but are not the primary LPR device path. |
| `kit_mapping` | citext | Mapping configuration |
| `fixed_latitude` / `fixed_longitude` | double | Optional fixed location (if stationary) |
| `email` | citext | Optional email |
| `text_phone` | varchar(18) | Optional phone |
| `signing_public_key` | varchar(64) | Optional signing key |

The device a kit used is **not** on this table — see `scans.camera_name`.

## Relationships

- A Kit belongs to an [Organization](organization.md) (`org_id`).
- A Kit produces many [Scans](scan.md) (`scans.kit_id`).
- A Kit can be shared with other orgs via the `Shared` polymorphic pivot (`Kit::getSharedKits()`).
- In [Scout](scout.md), a Kit is bridged to the driver who operates it via `KitSettings` (`platform.kit_settings`); `Kit::assigned_user` reads through it. See [Scout user assignment](scout-user-assignment.md).
- The **device** relationship is many-to-many through scans: a kit → many scans → many `camera_name` values; a `camera_name` → many scans → potentially multiple kits over time.

## Business rules

- **A Kit is a user, not a device.** This is the #1 source of confusion. The kit (`kits.name`) is the login identity; the physical device is `scans.camera_name`. Do not treat `kits.name` as the camera, and do not look for a device column on `kits` — there isn't one.
- **Kits can log in to multiple devices.** One kit can produce scans with several different `camera_name` values. Any per-kit device list must be derived from scans (ClickHouse `clarity.scans_cold`/`scans_hot`), not assumed from the kit row.
- **`kit_type = 1` is the type that matters.** LPR camera kits are `kit_type = 1`
  (`KitType::GENETEC`, "Genetec Mobile", in `insight-lpr/black-adam`). Filter to `kit_type = 1`
  when you want real LPR cameras — **never** a `name LIKE 'GL%'` pattern. Measured on production
  2026-08-31: only **2 of 3,535** `kit_type = 1` kits have a `GL`-prefixed name, while 13
  `kit_type = 2` kits do. What "GL" actually names in this app is `kits.kit_mapping` — labelled
  "GL Number" in the UI (`ScoutUsersResource`) and the key `clarity.sc_positions` is keyed by.
  The strongest evidence for the filter is that `kit_type = 1` is the **only** kit type that
  appears in `clarity.sc_positions` at all (all 540 kits reporting GPS in a 14-day window).
- **Ownership is fixed in the app:** a kit belongs to one organization; the application offers
  no ownership transfer, only sharing. See [Multi-Tenancy](multi-tenancy.md) (Sharing System).
  **But do not rely on that rule when reading ClickHouse.** `zod.kit_last_scan` is keyed
  `(org_id, kit_id)` and retains historical org assignments: measured 2026-08-31, **275 of 4,755
  kits carry rows under more than one `org_id`** (worst case 3), and the stale row can be the
  newer one. Resolve a kit's owning org from PostgreSQL, never from a derived CH table.
- **Org inheritance:** scans produced by a kit inherit the kit's `org_id`.
- **Sharing is a Pro feature:** kit sharing is gated by `FeatureEnum::SHARED_KITS`; shared kits appear in the receiving organization's scan views via `withSharedKits` / `isCommercial`.
- **Commercial kits:** shared/commercial kits are handled distinctly in org scoping.

## Where it lives in code

- Model: `app/Models/Kit.php` (PostgreSQL `zod` connection, `zod.public.kits`)
- Sharing pivot: `app/Models/Shared.php`
- Filament resources (app panel): `app/Filament/Resources/ScoutUsersResource.php` (kits with an
  assigned Scout driver) and `app/Filament/Resources/GlKitsResource.php` (read-only `kit_type = 1`
  inventory with last-scan + 14-day GPS reporting status, DEV-2448). There is **no**
  `KitUsersResource.php` — the "Kit User" framing survives only in
  `app/Filament/Pages/KitUserPath.php`, `Pages/KitUserRecoveries.php`,
  `Widgets/Dashboard/KitUsers.php` and `Tables/Filters/KitUsersFilter.php`.
- Filament pages: `app/Filament/Pages/KitUserPath.php`, `app/Filament/Pages/KitUserRecoveries.php`
- ClickHouse (kit↔device aggregate): `app/Clickhouse/Models/Kit.php` (`clarity.kits`) and `app/Clickhouse/Models/Scan.php` (`clarity.scans_cold`/`scans_hot`) — the scan tables carry `kit_id` + `kit_name` + `camera_name` together.
- Jobs: `app/Jobs/ShareOrgKits.php`

## See also

- [Scout user assignment](scout-user-assignment.md) — `kit_settings` links a kit to the driver who scans under it
- [Scan](scan.md) — the reads a kit produces; `scans.camera_name` is the actual device
- [Organization](organization.md) — the kit's owner
- [Multi-Tenancy](multi-tenancy.md) — kit sharing and `forOrg()` scoping
- [Data Architecture](data-architecture.md) — `zod.public.kits` (PostgreSQL) vs `clarity.scans_cold`/`scans_hot` (ClickHouse, where kit↔camera_name is resolvable)
