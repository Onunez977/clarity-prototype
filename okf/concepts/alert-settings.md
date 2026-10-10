---
type: DomainEntity
title: Alert Settings
description: Clarity's alert/subscription system — the AlertSetting model + polymorphic alertable, AlertSettingSettingsDto (hit-type + client filters, sms/email/slack/webhook channels), the AlertSettingsCast, the HitAlertSettings subscriber pipeline, and the ALERT_SETTINGS pro feature. Extracted from the legacy .claude/docs/form-patterns.md alert example.
resource: app/Models/AlertSetting.php
tags: [domain, lpr, alerts, notifications, pro-feature, filament, patterns]
timestamp: 2026-06-26T20:15:00Z
---

# Alert Settings

Alert Settings are Clarity's per-user subscription rules for **hit notifications**: which hit types to be notified about, for which clients, and through which channels (sms / email / slack / webhook). It is a **Pro feature** gated by `FeatureEnum::ALERT_SETTINGS`. This page documents the domain model and the notification pipeline; the Filament form itself is just CRUD over this model.

> Extracted from the legacy `.claude/docs/form-patterns.md` (the alert-settings example). Generic form how-to is not re-documented.

## The `AlertSetting` model (`app/Models/AlertSetting.php`)

| Field | Type | Purpose |
|-------|------|---------|
| `id` | int | PK |
| `name` | string | Rule label |
| `alertable_type` / `alertable_id` | morph | Polymorphic owner — currently `user` (`alertable_type = 'user'`). The alert belongs to a **user**, not an org. |
| `org_id` | int? | Denormalized org for scoping |
| `settings` | json → `AlertSettingSettingsDto` | The rule payload (cast below) |
| `webhook_secret` | string? | Shared secret for outbound webhooks |

Uses `Notifiable` (so it can receive Laravel notifications), `SoftDeletes`, `LogsActivity` (activity log). Relations: `org()` BelongsTo, `alertable()` MorphTo.

## `AlertSettingSettingsDto` (`app/Dto/AlertSettingSettingsDto.php`)

The typed rule payload:

```php
public array $types = [];          // hit types to match, e.g. ['L','H','S','Z','M','F','P'] (HitTypeEnum values)
public array $clients = [];        // client_org_ids to scope (empty = all clients)
public array $notifications = [    // channels + their targets
    'sms' => [],                   // phone numbers
    'emails' => [],                // email addresses
    'slack' => [],                 // slack webhook URLs
];
public string $webhook_url = '';   // custom HTTP webhook endpoint
```

- `fromArray(array)` hydrates from the stored JSON; `toJson()` serializes.
- `types` holds `HitTypeEnum` **values** (`L`/`H`/`S`/`Z`/`M`/`F`/`P`) — see [DTO and Enums](dto-and-enums.md). Stored as a JSON array; matched with `whereJsonContains('settings->types', $hit_type)`.
- `clients` holds `client_org_id` ints to restrict the rule to specific lender clients.
- Channels: **sms** (Twilio), **emails** (Laravel mail), **slack** (incoming webhooks), plus a single **webhook_url** (custom HTTP POST). See `NotificationTypeEnum` (`email`/`sms`/`slack`/`webhook`).

## The cast (`app/Casts/AlertSettingsCast.php`)

`AlertSetting::$casts['settings'] = AlertSettingsCast::class` — `get()` returns `AlertSettingSettingsDto::fromArray(json_decode(...))`, `set()` serializes the DTO (or raw array) back to JSON. So anywhere you read `$alertSetting->settings` you get a typed DTO.

## The notification pipeline (`HitAlertSettings` concern)

`app/Models/Concerns/HitAlertSettings.php` — used by `Hit` (and `MissedOpportunity`). The flow, triggered by `HitEventListener` calling `$hit->notifySubscribers(scans:)` (see [Events and Observers](events-and-observers.md)):

1. **`kitAlertSettings()`** — cached flexibly (`cache()->flexible` 10/30 min, key `alert_settings:t:{hit_type}:k:{kit_id}`) — loads all `AlertSetting` rows where `alertable_type='user'`, the user belongs to the kit's org, `settings->types` contains the hit's `hit_type`, **and** at least one channel is configured (`notifications->sms`/`emails`/`slack` non-empty, or `webhook_url` non-empty).
2. **`getSubscribers()`** — requires a kit (no kit → empty). Runs each candidate `AlertSetting` through a Laravel `Pipeline` of filters on a `HitNotificationData` payload:
   - `Pipelines\HitNotifications\ProFilterPipeline` — Pro/feature gating (the `ALERT_SETTINGS` feature, via Pennant).
   - `Pipelines\HitNotifications\ClientFilterPipeline` — applies the `clients` allow-list.
   - `Pipelines\HitNotifications\HotlistStatusFilterPipeline` — suppresses alerts on terminal hotlist statuses (`HotlistStatusEnum::isTerminal()`).
   - A setting survives only if the pipeline returns `HitNotificationData` (not null).
3. **`notifySubscribers()`** — dispatches the survivors to their channels via the custom channel classes (`App\Channels\Twilio\TwilioChannel`, `App\Channels\Slack\SlackChannel`, `App\Channels\Webhook\WebhookChannel`) + Laravel mail, using `HitNotification` (or `MissedOpportunityNotification` for `M`/`F`). Also dispatches `TrackAnalytics` for product analytics.

## Rules

- **Alerts belong to a user** (`alertable_type='user'`), not an org — even though they're org-scoped via the user. The kit→org→users join is how subscribers are resolved per hit.
- **A rule with no channel configured is never matched** — `kitAlertSettings()` requires at least one of sms/emails/slack/webhook to be non-empty.
- **Hit-type matching is by enum value** (`L`/`H`/`S`/`Z`/`M`/`F`/`P`), stored in `settings->types` and queried with `whereJsonContains`.
- **Terminal hotlist statuses suppress alerts** via `HotlistStatusFilterPipeline` (see [DTO and Enums](dto-and-enums.md) `isTerminal()`).
- **Gated by `FeatureEnum::ALERT_SETTINGS`** — non-Pro orgs can't use alert settings; `HasProFeature` redirects to Subscription (see [Filament Conventions](filament-conventions.md)).
- **Subscriber resolution is cached** (10/30 min flexible cache) keyed by hit_type + kit_id — edit an `AlertSetting` and the cache must expire/flush for the change to take effect immediately.

## Where it lives in code

- Model: `app/Models/AlertSetting.php`
- DTO: `app/Dto/AlertSettingSettingsDto.php`
- Cast: `app/Casts/AlertSettingsCast.php`
- Subscriber pipeline: `app/Models/Concerns/HitAlertSettings.php`, `app/Pipelines/HitNotifications/*`
- Channels: `app/Channels/Twilio/TwilioChannel.php`, `app/Channels/Slack/SlackChannel.php`, `app/Channels/Webhook/WebhookChannel.php`
- Notifications: `app/Notifications/Hits/HitNotification.php`, `MissedOpportunityNotification.php`
- Notification log: `app/Events/NotificationLogEventHandler.php` (logs every sent email)

## See also

- [Events and Observers](events-and-observers.md) — `HitEvent` → `HitEventListener` → `notifySubscribers()`
- [DTO and Enums](dto-and-enums.md) — `HitTypeEnum`, `HotlistStatusEnum::isTerminal()`, `NotificationTypeEnum`
- [Hit](hit.md) — the thing being alerted on
- [Missed Opportunity](missed-opportunity.md) — the `M`/`F` notification path
- [Filament Conventions](filament-conventions.md) — `HasProFeature` gating
