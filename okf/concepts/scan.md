---
type: DomainEntity
title: Scan
description: A license-plate read captured by a Kit — the atomic unit of the Clarity system.
resource: app/Models/Scan.php
tags: [domain, lpr, postgres, clickhouse]
timestamp: 2026-06-26T10:31:00Z
---

# Scan

A **Scan** is a single license-plate read captured by a physical LPR camera. It is the atomic unit of the Clarity system — every hit, recovery, and analytic derives from scans. Scans are transactional records that live in **PostgreSQL** (`zod.scans` — the `Scan` model uses the `zod` connection, `public` schema) and are mirrored to **ClickHouse** (`scans_cold`) for analytics.

A scan carries both `kit_id` (the [Kit](kit.md) = the user that was logged in) and `camera_name` (the **actual physical device** that captured the read). See [Kit](kit.md) for why this distinction matters.

## Definition

A scan contains the OCR'd plate text, the US state of the plate, a PostGIS geographic point, the kit that captured it, the scan timestamp, and URLs to vehicle images. Related metadata (address, disposition, vehicle attributes, AI plate state) lives in a sidecar `ScanMetaData` JSON record.

## Key attributes / columns

| Column | Type | Description |
|--------|------|-------------|
| `id` | — | Primary key |
| `ocr` | string | OCR'd plate text |
| `us_state` | string | Plate US state |
| `geo` | PostGIS Point (SRID 4326) | Capture location (lat/long) |
| `kit_id` | FK → `kits.id` | The [Kit](kit.md) (user) that was logged in when the scan was captured |
| `scanned_at` | timestamp | When the scan was captured |
| `camera_name` | string | The **actual physical device** that produced the read (e.g. a `GL…` camera). Recorded per-scan, NOT on the kit. |
| `guid` | string | Globally unique scan identifier |
| `is_visible` | bool | Visibility flag (hidden scans excluded by global scope) |
| `meta` | json | Sidecar metadata |

## Relationships

- A Scan belongs to a [Kit](kit.md) (`kit_id`), which belongs to an [Organization](organization.md) (`org_id`) — the org↔scan bridge runs through the kit.
- A Scan may produce many [Hits](hit.md) when its plate matches a [Hotlist](hotlist.md) entry.
- A Scan has one `ScanMetaData` sidecar (1:1).
- Scans are scoped by [Multi-Tenancy](multi-tenancy.md) via `forOrg()` (resolved through the kit's org, optionally including shared kits).

## Business rules

- **Timezone-aware:** scans use a custom timezone global scope (`ScanTimezoneScope`) that joins the PostGIS `timezones` polygon table to compute local scan time. See [Data Architecture](data-architecture.md).
- **Visibility:** the `ShowVisibleScan` global scope hides scans where `is_visible = false`.
- **Org inheritance:** scans inherit their `org_id` from the kit that produced them; the app
  offers no kit ownership transfer (only shared access). See [Kit](kit.md) for the caveat about
  derived ClickHouse tables retaining historical org assignments.
- **Metadata via triggers:** `scan_metadata` is managed by database triggers, not application code.
- **"Unique scans" means period-distinct, not hourly-sum.** A plate is counted **once across the
  whole selected reporting window**, however long that window is — not once per hour, per day, or
  per kit. Confirmed by Oscar Nunez on DEV-2467 (2026-09-09): *"unique by reporting window/timeframe
  is right."* This matters because the two readings diverge materially: DEV-2466 §3 measured
  summing per-hour distinct-plate counts at **1.556x** true period-distinct for the same org and
  window (org 1271, Jul 2026: 11,070,941 vs 7,115,302), because a plate seen in two hours counts
  twice. Compute it as `uniqExact(ocr)` over the window; do **not** sum
  [`affiliate_stats_hourly.unique_plates`](../models/clickhouse/affiliate_stats_hourly.md), which
  answers a different question. Note the naming collision: that table's existing rollup already
  ships the hourly-sum reading under the label `unique_scans`.

## Where it lives in code

- Model: `app/Models/Scan.php` (PostgreSQL `zod` connection)
- Global scopes: `app/Models/Scopes/ShowVisibleScan.php`, `app/Models/Scopes/ScanTimezoneScope.php`
- Policy: `app/Policies/ScanPolicy.php`
- Filament resource: `app/Filament/Resources/ScansResource.php` (app panel, "Search")
- Metadata sidecar: `app/Models/ScanMetaData.php`
- ClickHouse mirror: `app/Clickhouse/Models/Scan.php` (table `scans_cold`, read-only analytical)
- ClickHouse queries: `app/Clickhouse/Queries/ScanStatsBatchQuery.php`

## See also

- [Kit](kit.md) — the user that was logged in (the device is `camera_name`, above)
- [Hit](hit.md) — matches derived from scans
- [Organization](organization.md) — ownership via the kit
- [Data Architecture](data-architecture.md) — PostgreSQL `zod.scans` (public schema) vs ClickHouse `scans_cold`
- [Multi-Tenancy](multi-tenancy.md) — `forOrg()` scoping
