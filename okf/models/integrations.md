---
type: DataModel
title: integrations (Integrations)
description: Defines an external data-feed integration that supplies hotlist plates, with a polymorphic mapping target, in the PostgreSQL zod database, public schema.
resource: app/Models/Integrations.php
tags: [model, postgres, public, zod, lpr, hotlist]
timestamp: 2026-07-06T00:00:00Z
---

# integrations

An `integrations` row describes an external data-feed integration — a named source (with a run status and batch reference) that supplies plates onto the [hotlist](../concepts/hotlist.md). Each integration points at a polymorphic `mapping` target via `mapping_type`/`mapping_id`, and owns the hotlist entries it produced through `integration_id`. It is how third-party feeds are ingested and attributed to a hotlist.

It lives in **PostgreSQL**, the `zod` logical database, `public` schema, on the `zod` connection — one of the core LPR tables managed by the external `insight-lpr/laravel-zod-migrations` package. See [Data Architecture](../concepts/data-architecture.md).

## Location

- Database: `zod`
- Schema: `public`
- Connection: `zod`
- Table: `integrations`
- Model class: `App\Models\Integrations` (`app/Models/Integrations.php`)
- Timestamps: disabled (`$timestamps = false`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | integer | no | Primary key (sequence `integrations_id_seq`). |
| `name` | character varying(64) | no | Human-readable integration name. |
| `status` | character varying(16) | no | Run status of the integration. Defaults to `'run'`. |
| `batch_id` | bigint | yes | Reference to the current/last ingest batch. |
| `mapping_id` | integer | no | Polymorphic target id (paired with `mapping_type`). Defaults to `1`. |
| `mapping_type` | character varying(64) | yes | Polymorphic target type — the model class the mapping resolves to. |

## Relationships

- **hotlists()** — hasMany [hotlist](hotlist.md) via `integration_id` (references `integrations.id`). The plates this integration produced. (Method is `hotlists()`; the `@property` alias `plates` refers to the same set.)
- **mapping()** — morphTo the polymorphic target resolved from `mapping_type` (class) and `mapping_id` (key).

## Notable scopes & behavior

- **Fillable:** `name`, `status`, `mapping_id`, `mapping_type`.
- Annotated `@codeCoverageIgnore`.

## Data mapping (PostgreSQL ↔ ClickHouse)

Transactional integration/config record. No established ClickHouse replica; treat as transactional-only. The hotlist entries it produces flow through the hotlist family into `clarity.hotlist`. See [Data Architecture](../concepts/data-architecture.md).

## See also

- [hotlist](hotlist.md) — the plates an integration produces
- [Hotlist concept](../concepts/hotlist.md)
- [Data Architecture](../concepts/data-architecture.md)
