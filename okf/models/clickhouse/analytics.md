---
type: DataModel
title: analytics (App\Clickhouse\Models\Analytics)
description: ClickHouse product-analytics event table capturing feature/page usage events by user and org.
resource: app/Clickhouse/Models/Analytics.php
tags: [model, clickhouse, clarity, analytics, aggregate]
timestamp: 2026-07-06T00:00:00Z
---

# analytics

`clarity.analytics` is a ClickHouse product-analytics event table — one row per tracked user interaction (a feature used on a page, by a user in an org, with an arbitrary JSON `data` payload). Unlike the other analytical tables it is **not** a CDC replica of a PostgreSQL table; it is a native product-telemetry store written directly by the application. It lives in the **ClickHouse `clarity` database**, `SharedMergeTree` engine.

Because these are append-only events rather than replicated rows, the model extends the ClickHouse ORM `BaseModel` directly (not the CDC-aware `ClickhouseModel` base) and carries no `_peerdb_*` columns. It is written through the dedicated write connection `clarity_clickhouse_write`. See [ClickHouse Integration](../../concepts/clickhouse-integration.md) and [Data Architecture](../../concepts/data-architecture.md).

## Location

- Database: `clarity` (ClickHouse)
- Connection: `clarity_clickhouse_write` (the write-path connection; separate write credentials, async/dedup insert path)
- Table: `analytics`
- Engine: `SharedMergeTree`
- Model class: `App\Clickhouse\Models\Analytics` — `app/Clickhouse/Models/Analytics.php` (extends `LaravelClickhouseEloquent\BaseModel`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `UUID` | no | Unique event id. |
| `feature` | `LowCardinality(String)` | no | The feature/action that generated the event. |
| `page` | `LowCardinality(String)` | no | The page/surface where the event occurred. |
| `user_id` | `Int32` | no | The user who triggered the event. |
| `org_id` | `Int32` | no | The org the user belongs to (tenant dimension). |
| `data` | `String` | no | Arbitrary JSON payload with event-specific detail. |
| `created_at` | `DateTime` | no | Event timestamp. |
| `date` | `Date` | no | Event date (partition/grouping dimension). |

## Casts & enums

The model declares no `casts()` and no enum-backed columns. `data` is stored as a raw JSON string.

## Relationships

No Eloquent relationship methods. `user_id` and `org_id` are analytical dimensions referencing their PostgreSQL counterparts by id, not eager-loaded relations.

## Data mapping (PostgreSQL ↔ ClickHouse)

- **No PostgreSQL source table / no CDC replica.** `clarity.analytics` is a native ClickHouse event store written directly by the app via `clarity_clickhouse_write`; it does not originate from PeerDB replication and has no `_peerdb_*` columns.

## Notable scopes & behavior

- Extends `LaravelClickhouseEloquent\BaseModel` directly (not the app's `ClickhouseModel`), so it does **not** get the `FiltersCdcDeletes` global scope, `scopeFinal()`, or the hot/cold `forWindow()` helper.
- Uses the `clarity_clickhouse_write` connection: writes go through the async, deduplicated, exactly-once write path with a longer connect budget (see [ClickHouse Integration](../../concepts/clickhouse-integration.md)).
- No custom query scopes defined on the model.

## See also

- [ClickHouse Integration](../../concepts/clickhouse-integration.md) — connections, write path, engines
- [Data Architecture](../../concepts/data-architecture.md) — PostgreSQL vs ClickHouse split
