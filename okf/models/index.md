---
type: Index
title: Data Models Catalog
description: One page per database table/Eloquent model — columns (live DB types), relationships, and PostgreSQL↔ClickHouse mapping. The AI-facing schema reference for the Clarity data layer.
tags: [meta, index, models, postgres, clickhouse, schema]
timestamp: 2026-07-06T00:00:00Z
---

# Data Models Catalog

This section documents **every Eloquent model and its backing table** in Clarity, one Markdown file per table (`type: DataModel`). Each page carries the table's columns with **real DB types pulled live** from the database, the model's casts/enums, its relationships, notable scopes, and the **PostgreSQL ↔ ClickHouse mapping**. It is the schema-reference companion to the conceptual pages under [`../concepts/`](../concepts/index.md) — read [Data Architecture](../concepts/data-architecture.md) first to understand which database a symbol lives in.

- **Filenames are table names** (schema/db prefixes stripped). PostgreSQL pages live in `okf/models/`; ClickHouse pages live in `okf/models/clickhouse/`.
- Column types are the **actual database types** (`character varying`, `int4`, `jsonb`, `geometry`, `LowCardinality(String)`, `Nullable(DateTime64(3))`, …), not PHP cast types. Casts/enums are listed separately per page.
- **Database rule:** every page states which database + schema/connection its table lives on. PostgreSQL is the transactional source of truth (`zod` database, `public` + `platform` schemas); ClickHouse (`clarity`/`zod`) is the read-only analytical side.

## PostgreSQL — `public` schema (core LPR, `zod` connection)

Owned upstream by the `insight-lpr/laravel-zod-migrations` package (see [Repositories](../concepts/repositories.md)).

| Table | Model | Purpose |
|-------|-------|---------|
| [scans](scans.md) | `Scan` | License-plate reads — the atomic unit of the system |
| [hits](hits.md) | `Hit` / `MissedOpportunity` | Scan↔Hotlist matches (and missed-opportunity variant) |
| [hotlist](hotlist.md) | `Hotlist` | Wanted vehicles / repossession assignments |
| [hotlist_json](hotlist_json.md) | `HotlistJson` | Raw JSON payload sidecar for a hotlist order |
| [hotlist_meta](hotlist_meta.md) | `HotlistMeta` | Hotlist metadata sidecar |
| [hotlist_address](hotlist_address.md) | `HotlistAddress` | Hotlist↔Address pivot |
| [hotlist_plate_log](hotlist_plate_log.md) | `PlateLog` | Per-hotlist plate change log |
| [address](address.md) | `Address` | Geocoded address records |
| [approval_list](approval_list.md) | `ApprovalList` | Approval/allow list entries |
| [integrations](integrations.md) | `Integrations` | Upstream LPR/RDN integration registry |
| [kits](kits.md) | `Kit` | The user that signs into a physical LPR camera |
| [orgs](orgs.md) | `Org` | Multi-tenant organization root |
| [org_meta](org_meta.md) | `OrgMeta` | Org metadata sidecar |
| [user_orgs](user_orgs.md) | `UserOrg` | User↔Org membership pivot |
| [scans_metadata](scans_metadata.md) | `ScanMetaData` | Per-scan metadata (trigger-managed) |
| [scan_validations](scan_validations.md) | `ScanValidation` | Scan validation records |
| [timezones](timezones.md) | `Timezone` | PostGIS timezone polygons (for scan local time) |

### PostgreSQL — `public` schema aggregates & views

| Table | Model | Purpose |
|-------|-------|---------|
| [aggregate_address_geohash6](aggregate_address_geohash6.md) | `AggregateAddressGeohash` | Address rollup by geohash-6 cell |
| [aggregate_address_heatmap](aggregate_address_heatmap.md) | `AggregateAddressHeatMap` | Address heatmap aggregate |
| [aggregate_kit_scan_path_h](aggregate_kit_scan_path_h.md) | `AggregateKitScanPathH` | Kit scan-path rollup by hour |
| [aggregate_scan_count_h](aggregate_scan_count_h.md) | `AggregateScanCountByHour` | Scan counts by hour |
| [aggregate_scan_count_unq_h](aggregate_scan_count_unq_h.md) | `AggregateScanCountUniqueByHour` | Unique scan counts by hour |
| [aggregate_recoveries_kituser_scans_report](aggregate_recoveries_kituser_scans_report.md) | `RecoveriesKitUserScansReport` | Recoveries × kit-user scan report |
| [staging_orgs_hotlist_count](staging_orgs_hotlist_count.md) | `StagingOrgsHotlistCount` | DB view — staged hotlist counts per org |

## PostgreSQL — `platform` schema (Clarity add-ons)

Created by this repo's migrations. Default `platform` connection unless noted.

| Table | Model | Purpose |
|-------|-------|---------|
| [recoveries](recoveries.md) | `Recoveries` | Repossessed-vehicle records |
| [alert_settings](alert_settings.md) | `AlertSetting` | Per-user hit-notification rules |
| [shared](shared.md) | `Shared` | Cross-org kit/data sharing grants |
| [invites](invites.md) | `Invite` | User invitations |
| [client_org_access](client_org_access.md) | `ClientOrgAccess` | Client↔org access grants (`zod` connection, `platform` schema) |
| [branches](branches.md) | `Branch` | Org branch/sub-unit records |
| [notification_logs](notification_logs.md) | `NotificationLog` | Sent-notification audit log |
| [org_run_areas](org_run_areas.md) | `OrgRunArea` | Org operating/run areas |
| [zones](zones.md) | `Zone` | Scout patrol areas (PostGIS geometry) |
| [kit_settings](kit_settings.md) | `KitSettings` | Kit↔user↔zone Scout linkage |
| [kit_shifts](kit_shifts.md) | `KitShift` | Scout driver shift records |
| [users](users.md) | `User` | Application users |
| [roles](roles.md) | `Role` | Spatie roles |
| [permissions](permissions.md) | `Permission` | Spatie permissions |
| [ai_usage_logs](ai_usage_logs.md) | `AiUsageLog` | AI feature usage log |
| [aggregate_rdn_hotlist_count_by_hour](aggregate_rdn_hotlist_count_by_hour.md) | `AggregateRdnHotlistCountByHour` | RDN hotlist counts by hour |

## PostgreSQL — `tiger_data` schema (PostGIS census reference)

| Table | Model | Purpose |
|-------|-------|---------|
| [zcta5_all](zcta5_all.md) | `Tiger\ZipCode5All` | TIGER ZCTA5 ZIP-code polygons |

## ClickHouse — `clarity` database (analytical, read-only)

| Table | Model | Engine | Purpose |
|-------|-------|--------|---------|
| [scans_cold](clickhouse/scans_cold.md) | `Clickhouse\Scan` | ReplacingMergeTree | Denormalized scans (cold storage) |
| [hits_cold](clickhouse/hits_cold.md) | `Clickhouse\Hit` | ReplacingMergeTree | Denormalized hits (cold storage) |
| [hotlist](clickhouse/hotlist.md) | `Clickhouse\Hotlist` | ReplacingMergeTree | Denormalized hotlist |
| [missed_opportunities](clickhouse/missed_opportunities.md) | `Clickhouse\MissedOpportunity` | ReplacingMergeTree | Missed-opportunity rows (`hotlist_org_id`-scoped) |
| [recoveries](clickhouse/recoveries.md) | `Clickhouse\Recovery` | ReplacingMergeTree | Denormalized recoveries |
| [analytics](clickhouse/analytics.md) | `Clickhouse\Analytics` | MergeTree | Product analytics events |
| [hotlist_status_aggregate](clickhouse/hotlist_status_aggregate.md) | `HotlistStatusAggregate` | AggregatingMergeTree | Hotlist status changes by hour |
| [client_historical_hits_hourly](clickhouse/client_historical_hits_hourly.md) | `ClientHistoricalHits` | AggregatingMergeTree | Historical hit counts by hour |
| [client_days_to_repo_all](clickhouse/client_days_to_repo_all.md) | `DaysToRepoAggregate` | View | Days-to-repo by recovery type |
| [hit_stats_daily](clickhouse/hit_stats_daily.md) | `HitStatsDaily` | ReplacingMergeTree | Daily hit stats |
| [affiliate_stats_hourly](clickhouse/affiliate_stats_hourly.md) | `AffiliateStatsHourly` | ReplacingMergeTree | Affiliate stats by hour |
| [kits](clickhouse/kits.md) | `Clickhouse\Kit` | ReplacingMergeTree | Denormalized kit dimension |
| [kit_trips](clickhouse/kit_trips.md) | `KitTrip` | ReplacingMergeTree | Kit trip aggregations |
| [lpr_assists](clickhouse/lpr_assists.md) | `LprAssist` | View | LPR-assist aggregate |
| [recovery_kit_scan_report](clickhouse/recovery_kit_scan_report.md) | `RecoveryKitScanReport` / `RecoveryKitLeaderboard` | ReplacingMergeTree | Recovery × kit scan-count join / leaderboard |

## ClickHouse — `zod` database (PeerDB-replicated `public` tables)

| Table | Model | Purpose |
|-------|-------|---------|
| [public_hotlist](clickhouse/public_hotlist.md) | `ZodPublicHotlist` | PeerDB replica of `public.hotlist` (`_peerdb_*` CDC columns) |

## PostgreSQL ↔ ClickHouse mapping (quick reference)

| Domain concept | PostgreSQL (source of truth) | ClickHouse replica (`zod`) | ClickHouse aggregate (`clarity`) |
|---|---|---|---|
| Scans | `public.scans` ([scans](scans.md)) | — | [scans_cold](clickhouse/scans_cold.md) (+`scans_hot`) |
| Hits | `public.hits` ([hits](hits.md)) | `zod.public_hits` | [hits_cold](clickhouse/hits_cold.md), [hotlist_status_aggregate](clickhouse/hotlist_status_aggregate.md), [client_historical_hits_hourly](clickhouse/client_historical_hits_hourly.md) |
| Hotlist | `public.hotlist` ([hotlist](hotlist.md)) + json/meta/address sidecars | [public_hotlist](clickhouse/public_hotlist.md) | [hotlist](clickhouse/hotlist.md) |
| Recoveries | `platform.recoveries` ([recoveries](recoveries.md)) | — | [recoveries](clickhouse/recoveries.md), [recovery_kit_scan_report](clickhouse/recovery_kit_scan_report.md) |
| Missed opportunities | `public.hits` (variant) | `zod.public_hits` | [missed_opportunities](clickhouse/missed_opportunities.md) |
| Kits | `public.kits` ([kits](kits.md)) | — | [kits](clickhouse/kits.md), [kit_trips](clickhouse/kit_trips.md) |

See [Data Architecture](../concepts/data-architecture.md) for the replication pipeline, CDC columns, and the dual-driver test bridge, and [ClickHouse Integration](../concepts/clickhouse-integration.md) for the full engine/connection detail.
