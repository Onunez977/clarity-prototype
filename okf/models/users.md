---
type: DataModel
title: users (User)
description: Application user accounts and authentication — lives in the PostgreSQL zod database, platform schema.
resource: app/Models/User.php
tags: [model, postgres, platform, access-control, multi-tenancy]
timestamp: 2026-07-06T00:00:00Z
---

# users

A `users` record is a person who authenticates into Clarity — either the admin/app panel or the client portal. Each user belongs to exactly one [organization](../concepts/organization.md) via `org_id`, and that org membership (plus Spatie [roles](roles.md)/[permissions](permissions.md)) drives what the user can see and do. Org-scoping is the backbone of Clarity's [multi-tenancy](../concepts/multi-tenancy.md): a user's `org` determines whether they are a repo/agent user or a client-portal user, and (for clients) which repo orgs' data they may read.

This table lives in the **PostgreSQL `zod` database, `platform` schema, `platform` connection** (Clarity add-on tables, not the core LPR `public` schema). The `User` model is soft-deletable, logs activity, and carries Sanctum API tokens, Pennant feature flags, and Spatie roles/permissions.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform`
- Table: `users`
- Model class: `App\Models\User` — `app/Models/User.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key |
| `name` | `character varying(255)` | yes | Display name |
| `email` | `character varying(255)` | yes | Login email; also the mail notification route |
| `org_id` | `bigint` | yes | FK → `public.orgs.id` — the [organization](../concepts/organization.md) the user belongs to (drives tenancy) |
| `email_verified_at` | `timestamp(0) without time zone` | yes | Email verification timestamp; cast to `datetime` |
| `password` | `character varying(255)` | no | Hashed password (`hashed` cast); hidden from serialization |
| `two_factor_enabled` | `boolean` | no | Whether 2FA is required (default `true`); tracked in the activity log |
| `dti` | `json` | yes | Device/tenant info blob; holds `timeZone`/`timezone` used to derive the `timezone` accessor |
| `remember_token` | `character varying(100)` | yes | Laravel "remember me" token; hidden from serialization |
| `deleted_at` | `timestamp(0) without time zone` | yes | Soft-delete timestamp (`SoftDeletes`) |
| `created_at` | `timestamp(0) without time zone` | yes | Row creation time |
| `updated_at` | `timestamp(0) without time zone` | yes | Row update time |
| `clarity_user_id` | `character varying(255)` | yes | External Clarity identity linkage |
| `mobile_phone` | `character varying(255)` | yes | Mobile number; normalized by the `formattedMobilePhone` accessor |

## Casts & enums

- `dti` → `array`
- `email_verified_at` → `datetime`
- `password` → `hashed`

## Relationships

- **org()** — belongsTo [Org](../concepts/organization.md) via `org_id` (eager-loaded by default through `$with`).
- **kitSettings()** — hasOne `KitSettings` via `user_id` (the bridge from a user to their [Kit](../concepts/kit.md)).
- **zones()** — belongsToMany [Zone](../concepts/zone.md) through the `zone_user` pivot (with timestamps).
- **alertable()** — morphMany [AlertSetting](../concepts/alert-settings.md) via `alertable` (polymorphic).
- **roles()** / **permissions()** — belongsToMany Spatie [Role](roles.md) / [Permission](permissions.md) (from the `HasRoles` trait).

Accessors: `kit` and `kitId` resolve through `kitSettings`; `timezone` derives from `dti`; `formattedMobilePhone` strips non-digits; `accessibleRepoOrgIds` returns the repo org IDs a client user may read (via [client_org_access](../concepts/multi-tenancy.md)).

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only). User accounts are not part of the PeerDB CDC surface; analytics resolve org context through kits and hits, not through `users`.

## Notable scopes & behavior

- **SoftDeletes** — `deleted_at` filters trashed users out of default queries.
- **LogsActivity** — logs changes to `username` and `two_factor_enabled` (Spatie activity log).
- **HasFeatures** (Pennant), **HasApiTokens** (Sanctum), **HasRoles** (Spatie permissions) traits are all mixed in.
- **`canAccessPanel()`** — gates the app vs. client Filament panels: the client panel requires an org that `isClient()`, has the client portal enabled, and has non-empty `accessibleRepoOrgIds`; the default (app) panel requires a repo org with `enable_genetech_hotlist`.
- **`isProEnabled()`** — resolves Pro-tier feature access through the org (with a 2026 Pro-trial window override), and always returns `false` for users holding the `CLIENT` role.
- **`getMorphClass()`** returns the literal `'user'` for polymorphic relations.

## See also

- [Organization](../concepts/organization.md) — the tenant a user belongs to
- [Multi-Tenancy](../concepts/multi-tenancy.md) — how `org_id` + client_org_access scope visibility
- [user_orgs](user_orgs.md) — user↔org membership pivot
- [roles](roles.md) / [permissions](permissions.md) — Spatie RBAC
- [Kit](../concepts/kit.md) — the device/session a user maps to via `kitSettings`
