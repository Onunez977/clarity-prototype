---
type: DataModel
title: notification_logs (NotificationLog)
description: Delivery log of outbound notifications (email/SMS/Slack/webhook) with provider status, in the platform schema of the zod PostgreSQL database.
resource: app/Models/NotificationLog.php
tags: [model, postgres, platform, alert-settings, notifications]
timestamp: 2026-07-06T00:00:00Z
---

# notification_logs

A **notification_logs** row records a single outbound notification dispatched by Clarity — the channel, recipient, message content, provider message id, and delivery lifecycle (sent/failed, plus opened/delivered/bounced/complaint timestamps for email). It ties back to the [alert_settings](alert_settings.md) configuration that triggered it. It lives in the **PostgreSQL `zod` database, `platform` schema**, on the default `platform` connection.

## Location

- Database: `zod` (PostgreSQL)
- Schema: `platform`
- Connection: `platform` (default)
- Table: `notification_logs`
- Model class: `App\Models\NotificationLog` — `app/Models/NotificationLog.php`

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | bigint | no | Primary key |
| `type` | character varying(255) | no | Notification channel; cast to `NotificationTypeEnum` (email, sms, slack, webhook); default `email` |
| `notifiable_type` | character varying(255) | yes | Polymorphic morph type of the notified entity |
| `notifiable_id` | bigint | yes | Polymorphic morph id of the notified entity |
| `alert_setting_id` | bigint | yes | FK → [alert_settings](alert_settings.md)`.id` — the config that triggered this notification |
| `recipient` | character varying(255) | yes | Logical recipient (address/number/channel target) |
| `from` | character varying(255) | yes | Sender address |
| `to` | character varying(255) | yes | To header |
| `cc` | character varying(255) | yes | CC header |
| `bcc` | character varying(255) | yes | BCC header |
| `subject` | character varying(255) | yes | Message subject |
| `body` | text | yes | Message body |
| `headers` | text | yes | Raw message headers |
| `attachments` | text | yes | Attachment metadata |
| `message_id` | character varying(255) | yes | Provider message identifier |
| `status` | character varying(255) | yes | Delivery status (e.g. `sent`, `failed`) |
| `error_message` | text | yes | Failure reason when status is failed |
| `data` | text | yes | Extra payload; cast to `array` |
| `opened` | timestamp(0) without time zone | yes | When the recipient opened the message |
| `delivered` | timestamp(0) without time zone | yes | Provider delivery confirmation timestamp |
| `complaint` | timestamp(0) without time zone | yes | Spam-complaint timestamp |
| `bounced` | timestamp(0) without time zone | yes | Bounce timestamp |
| `created_at` | timestamp(0) without time zone | yes | Row creation timestamp |
| `updated_at` | timestamp(0) without time zone | yes | Row update timestamp |

## Casts & enums

- `type` → `App\Enums\NotificationTypeEnum` (string-backed: email, sms, slack, webhook)
- `data` → `array`

## Relationships

- **notifiable()** — morphTo (the entity the notification concerns, via `notifiable_type` / `notifiable_id`)
- **alertSetting()** — belongsTo [alert_settings](alert_settings.md) via `alert_setting_id`

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (transactional-only delivery log).

## Notable scopes & behavior

- **`$guarded = []`** — fully mass-assignable.
- Static factories `logSuccess()` / `logFailure()` create rows with the appropriate `status` (`sent` / `failed`), resolving `notifiable_type`/`notifiable_id` from a given model and linking the triggering `alert_setting_id`.

## See also

- [alert_settings](alert_settings.md) — the configuration that triggers notifications
- [Alert Settings](../concepts/alert-settings.md) — domain concept
