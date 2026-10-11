---
type: DataModel
title: invites (Invite)
description: User invitations to a polymorphic invitable resource (org, team, etc.) in the platform schema of the zod PostgreSQL database.
resource: app/Models/Invite.php
tags: [model, postgres, platform, multi-tenancy]
timestamp: 2026-07-06T00:00:00Z
---

# invites

An **invites** row represents an invitation extended to a user to join or access a polymorphic invitable resource (for example an [Organization](../concepts/organization.md) or team). It tracks the invite lifecycle — sent, accepted, approved, expired — and carries arbitrary `meta`. It lives in the **PostgreSQL `zod` database, `platform` schema**, on the default `platform` connection.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform` (default)
- Table: `invites`
- Model class: `App\Models\Invite` — `app/Models/Invite.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key |
| `type` | character varying(255) | no | Invite type discriminator |
| `user_id` | bigint | no | FK → `users.id` — the invited user |
| `invitable_type` | character varying(255) | yes | Polymorphic morph type of the resource being invited to |
| `invitable_id` | bigint | yes | Polymorphic morph id of the invitable resource |
| `meta` | jsonb | yes | Arbitrary invite metadata; cast to `array` |
| `accepted_at` | timestamp(0) without time zone | yes | When the invitee accepted |
| `expires_at` | timestamp(0) without time zone | yes | When the invite expires; enforced by a global scope |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp |
| `approved_at` | timestamp(0) without time zone | yes | When the invite was approved |
| `notification_sent_at` | timestamp(0) without time zone | yes | When the invite notification was sent |

## Casts & enums

- `meta` → `array`
- `accepted_at` → `datetime`
- `approved_at` → `datetime`
- `notification_sent_at` → `datetime`
- `expires_at` → `datetime`

## Relationships

- **user()** — belongsTo `User` via `user_id` (the invited user)
- **invitable()** — morphTo (the resource being invited to, via `invitable_type` / `invitable_id`)

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only workflow table).

## Notable scopes & behavior

- **`expires` global scope** (registered in `boot()`) — excludes invites whose `expires_at` is in the past (keeps rows where `expires_at` is null or greater than `now()`).
- **`accept()`** — sets `accepted_at = now()` and raises a success Filament notification.
- **`decline()`** — sets `expires_at = now()` (immediately expiring the invite) and raises a warning notification.

## See also

- [Organization](../concepts/organization.md) — a common invitable resource
- [Multi-Tenancy](../concepts/multi-tenancy.md) — org membership and access
