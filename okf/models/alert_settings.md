---
type: DataModel
title: alert_settings (AlertSetting)
description: Per-entity alert/notification configuration (email, SMS, Slack, webhook) in the platform schema of the zod PostgreSQL database.
resource: app/Models/AlertSetting.php
tags: [model, postgres, platform, alert-settings, notifications]
timestamp: 2026-07-06T00:00:00Z
---

# alert_settings

An **alert_settings** row holds the notification configuration for one alertable entity (polymorphic — a hotlist, zone, org, etc.). It stores which channels fire and to whom (`settings` JSON: email addresses, SMS numbers, Slack targets, webhook URL) when the entity produces an alert. It lives in the **PostgreSQL `zod` database, `platform` schema**, on the default `platform` connection. The model is `Notifiable` and routes notifications per channel from the `settings` payload. See [Alert Settings](../concepts/alert-settings.md) for the domain treatment.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform` (default)
- Table: `alert_settings`
- Model class: `App\Models\AlertSetting` — `app/Models/AlertSetting.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key |
| `name` | character varying(255) | no | Human-readable name for the alert configuration |
| `alertable_type` | character varying(255) | no | Polymorphic morph type of the entity these settings belong to |
| `alertable_id` | bigint | no | Polymorphic morph id of the alertable entity |
| `org_id` | bigint | yes | FK → owning [Organization](../concepts/organization.md) |
| `settings` | jsonb | no | Channel configuration (notifications: emails/sms/slack, webhook_url); cast to `AlertSettingSettingsDto` via `AlertSettingsCast` |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp |
| `deleted_at` | timestamp(0) without time zone | yes | Soft-delete timestamp (`SoftDeletes`) |
| `webhook_secret` | character varying(255) | yes | Signing secret for outbound webhook notifications |

## Casts & enums

- `settings` → `App\Casts\AlertSettingsCast` (hydrates `App\Dto\AlertSettingSettingsDto`)

## Relationships

- **org()** — belongsTo [Organization](../concepts/organization.md) via `org_id`
- **alertable()** — morphTo (the entity the settings apply to, via `alertable_type` / `alertable_id`)

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only configuration table).

## Notable scopes & behavior

- **SoftDeletes** — rows are soft-deleted via `deleted_at`.
- **Notifiable** — routes notifications per channel from `settings`: `routeNotificationForMail`, `routeNotificationForSms`/`routeNotificationForTwilio`, `routeNotificationForSlack`, `routeNotificationForWebhook`.
- **LogsActivity** — activity-log records `name` and `settings` changes.
- Helper queries `alertsEmailIsSubscribedTo()` / `alertsSmsIsSubscribedTo()` find settings by subscribed contact via `whereJsonContains`; `removeEmail()` / `removeSms()` prune contacts and `saveQuietly()`.

## See also

- [Alert Settings](../concepts/alert-settings.md) — domain concept
- [notification_logs](notification_logs.md) — delivery log referencing `alert_setting_id`
- [Organization](../concepts/organization.md) — owning org
- [Multi-Tenancy](../concepts/multi-tenancy.md) — org scoping
