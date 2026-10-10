---
type: DataModel
title: recoveries (Recoveries)
description: Repossessed-vehicle records — the successful outcome of a hit — in the platform schema of the zod PostgreSQL database.
resource: app/Models/Recoveries.php
tags: [model, postgres, platform, lpr, recovery, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# recoveries

A **recoveries** row is a repossessed-vehicle record — the successful outcome of a [Hit](../concepts/hit.md) that led to a vehicle being recovered. Each record mirrors the relevant [Hotlist](../concepts/hotlist.md) attributes (plate, VIN, vehicle year/make/model, owning/client/lienholder orgs) plus a geocoded recovery address and a `repo_date`. Recoveries drive recovery-rate analytics, days-to-repo metrics, and leaderboard widgets. It lives in the **PostgreSQL `zod` database, `platform` schema**, on the default `platform` connection, and is denormalized into ClickHouse for analytics. See [Recovery](../concepts/recovery.md) for the full domain treatment.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform` (default)
- Table: `recoveries`
- Model class: `App\Models\Recoveries` — `app/Models/Recoveries.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key |
| `integration_id` | integer | yes | Source integration; cast to `IntegrationEnum` (1=RDN, 2=Location_Services, 3=iRepo, 5=NICB, 6=Primeritus, 7=Manual_Upload) |
| `remote_order_id` | character varying(64) | yes | External order identifier from the source integration |
| `status` | integer | yes | Recovery/order status; cast to `HotlistStatusEnum` (0=Other, 1=Open, 2=Hold, 3=Repossessed, 4=ClosedPositive, 5=Closed, 90=NewFromClient, 99=Invalid) |
| `org_id` | integer | yes | Owning [Organization](../concepts/organization.md) (the repo org) |
| `client_org_id` | integer | yes | Client organization the vehicle belongs to |
| `lienholder_org_id` | integer | yes | Lienholder organization on the loan |
| `order_type` | integer | yes | Order type discriminator (integer code) |
| `is_staged` | boolean | yes | Whether the record is staged (not yet live) |
| `plate` | character varying(12) | yes | License plate of the recovered vehicle |
| `us_state` | character varying(2) | yes | Plate's US state |
| `vin` | character varying(17) | yes | Vehicle Identification Number |
| `vehicle_year` | character varying(8) | yes | Vehicle model year |
| `vehicle_make` | character varying(25) | yes | Vehicle make |
| `vehicle_model` | character varying(25) | yes | Vehicle model |
| `lookback_days` | integer | yes | Copied from the hotlist — historical-hit lookback for `find_historicals()` (often 270). **Not** the LPR-assist scan window (that is hardcoded 90 days in the Cloud kit-scan MV; see [LPR Assist](../concepts/lpr-assist.md#time-window-exact)). |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp |
| `address_id` | bigint | yes | Reference to the geocoded recovery address |
| `house_number` | character varying(20) | yes | Recovery address house number |
| `road` | character varying(128) | yes | Recovery address street |
| `city` | character varying(64) | yes | Recovery address city |
| `state` | character varying(32) | yes | Recovery address state |
| `postcode` | character varying(5) | yes | Recovery address ZIP code |
| `geo` | character varying(255) | yes | Recovery location; property cast to a Magellan `Point` in the model |
| `repo_date` | date | yes | Date the vehicle was repossessed; cast to `datetime:Y-m-d` |

## Casts & enums

- `status` → `App\Enums\HotlistStatusEnum` (int-backed)
- `integration_id` → `InsightLPR\BlackAdam\Enum\IntegrationEnum` (int-backed)
- `remote_order_id` → `string`
- `repo_date` → `datetime:Y-m-d`
- `geo` — typed as `Point|string|null` via the class PHPDoc (Magellan geometry)

## Relationships

The `Recoveries` model declares no Eloquent relationship methods in its class body; org association is carried by the `org_id` / `client_org_id` / `lienholder_org_id` foreign keys rather than declared relations. Its PHPDoc advertises query scopes (`forOrg`, `orderIds`, `plateNumber`, `recoveryDateBetween`, `vin`) that filter recoveries for reporting.

## Data mapping (PostgreSQL ↔ ClickHouse)

`platform.recoveries` denormalizes into ClickHouse as `clarity.recoveries` (analytical model `app/Clickhouse/Models/Recovery.php`) and feeds `clarity.recovery_kit_scan_report` (model `app/Clickhouse/Models/RecoveryKitScanReport.php`) for LPR-assist scoring. Recovery-kit scoring is ClickHouse-native (materialized views), not a Postgres procedure.

## Notable scopes & behavior

- No global scopes on the Eloquent model.
- Reporting scopes exposed via PHPDoc: `forOrg($org_id)` (multi-tenant filter — see [Multi-Tenancy](../concepts/multi-tenancy.md)), `orderIds`, `plateNumber`, `recoveryDateBetween`, `vin`.

## See also

- [Recovery](../concepts/recovery.md) — domain concept and analytics
- [Hit](../concepts/hit.md) — the match that precedes a recovery
- [Hotlist](../concepts/hotlist.md) — source attributes mirrored here
- [Organization](../concepts/organization.md) — owning/client/lienholder orgs
- [Multi-Tenancy](../concepts/multi-tenancy.md) — `forOrg()` scoping
- [ClickHouse recoveries](clickhouse/recoveries.md) · [ClickHouse recovery_kit_scan_report](clickhouse/recovery_kit_scan_report.md) — analytical replicas
