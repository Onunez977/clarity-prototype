---
type: DataModel
title: shared (Shared)
description: Polymorphic access-sharing pivot (share entity X with entity Y) in the platform schema of the zod PostgreSQL database.
resource: app/Models/Shared.php
tags: [model, postgres, platform, multi-tenancy]
timestamp: 2026-07-06T00:00:00Z
---

# shared

A **shared** row is a polymorphic pivot recording that one entity (`shared` — e.g. a kit or org resource) has been shared with another entity (`shared_with` — e.g. another org or user), optionally with an expiry. It underpins cross-org access grants in the [Multi-Tenancy](../concepts/multi-tenancy.md) model — for example, sharing kit/scan visibility with a partner org. It lives in the **PostgreSQL `zod` database, `platform` schema**, on the default `platform` connection, and extends Eloquent's `Pivot`.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform` (default)
- Table: `shared`
- Model class: `App\Models\Shared` (extends `Pivot`) — `app/Models/Shared.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key |
| `shared_type` | character varying(255) | no | Morph type of the entity being shared |
| `shared_id` | bigint | no | Morph id of the entity being shared |
| `shared_with_type` | character varying(255) | no | Morph type of the recipient entity |
| `shared_with_id` | bigint | no | Morph id of the recipient entity |
| `expires_at` | timestamp(0) without time zone | yes | When the share expires (null = never); enforced by a global scope |
| `status_id` | integer | no | Share status code (default `0`) |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp |
| `deleted_at` | timestamp(0) without time zone | yes | Soft-delete timestamp (`SoftDeletes`) |

## Casts & enums

- `expires_at` → `datetime`
- `deleted_at` → `datetime`

## Relationships

The model does not declare relationship methods in its class body; the PHPDoc documents read-only `shared` and `sharedWith` morph accessors resolved from the `shared_*` / `shared_with_*` morph pairs.

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only access-control table).

## Notable scopes & behavior

- **SoftDeletes** — rows are soft-deleted via `deleted_at`.
- **`expires` global scope** (registered in `boot()`) — automatically excludes shares whose `expires_at` is in the past (keeps rows where `expires_at` is null or greater than `now()`).
- **`$timestamps = false`** — timestamp columns exist but auto-timestamping is disabled on the pivot.

## See also

- [Multi-Tenancy](../concepts/multi-tenancy.md) — cross-org access sharing
- [Organization](../concepts/organization.md) — typical share participant
- [Kit](../concepts/kit.md) — kit/scan visibility can be shared across orgs
