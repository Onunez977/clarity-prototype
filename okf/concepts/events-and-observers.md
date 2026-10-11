---
type: Pattern
title: Events and Observers
description: Clarity's event + observer wiring — HitEvent (and its MissedOpportunity normalization) → HitEventListener, InviteObserver → ShareOrgKits/ClientOrgAccess, NotificationLogEventHandler, and the event-vs-observer-vs-job decision. Corrected from the legacy .claude/docs/event-observer-patterns.md.
resource: app/Events/HitEvent.php
tags: [domain, lpr, events, observers, notifications, patterns]
timestamp: 2026-06-26T20:05:00Z
---

# Events and Observers

How Clarity reacts to domain changes: Laravel **events** for cross-cutting fan-out, Eloquent **observers** for model-lifecycle side effects, and **jobs** for heavy/queued work. This page covers Clarity-specific wiring and the decision rule.

> Supersedes the legacy `.claude/docs/event-observer-patterns.md`. Generic Laravel event/observer how-to is not re-documented.

## `HitEvent` + `HitEventListener`

`app/Events/HitEvent.php` — fired when a hit (or missed-opportunity) needs to notify subscribers. Carries `Hit|MissedOpportunity $hit` and `array $scans`.

**Construction-time normalization:** if `$hit->hit_type === HitTypeEnum::Missed_Opportunity->value` (`M`) **but** the instance isn't already a `MissedOpportunity`, the constructor re-hydrates it as a `MissedOpportunity` model:

```php
if ($hit->hit_type === HitTypeEnum::Missed_Opportunity->value && get_class($hit) !== MissedOpportunity::class) {
    $this->hit = (new MissedOpportunity)->forceFill($hit->toArray());
}
```

So listeners downstream always get the correct model type for `M` hits. (See [Missed Opportunity](missed-opportunity.md).)

**Dispatch sites:**
- `app/Console/Commands/IngestSqsQueueCommand.php` — `event(new HitEvent($hit, $scans))` during SQS hit ingestion (the production hit path).
- `app/Http/Controllers/Api/Qa/HitController.php` — QA/test endpoint to re-fire notifications.

**Listener:** `app/Listeners/HitEventListener.php` implements `ShouldQueue` with `WithoutOverlapping('hit-notify:{hit_id}')->dontRelease()->expireAfter(60)` so duplicate notifications for the same hit are suppressed within a 60s window. It calls `$event->hit->notifySubscribers(scans: $event->scans)` — the notification fan-out (email/sms/slack/webhook per [Alert Settings](alert-settings.md)) lives on the model.

## `InviteObserver` → `ShareOrgKits` / `ClientOrgAccess`

`app/Observers/InviteObserver.php`, registered in `AppServiceProvider` via `Invite::observe(InviteObserver::class)`. Hooks the Invite **`updating`** event (so it fires when `accepted_at`/`expires_at` change, not on creation):

- **On `accepted_at` change** (invite accepted):
  - Queue `AcceptedInvite` mail to the original sender.
  - If `type === InviteTypeEnum::SHARE_SCANS` → dispatch `ShareOrgKits` job (shares the sender's Org Kits with the invitee) — see [Jobs and Queues](jobs-and-queues.md) and [Multi-Tenancy](multi-tenancy.md).
  - If `type === InviteTypeEnum::CLIENT_SCANS` → `ClientOrgAccess::withRevoked()->updateOrCreate(...)` granting the repo org access to the client org, and set the client Org's `enable_client_portal` meta flag so the invitee can reach the `/client` panel.
- **On `expires_at` change** (invite declined/expired) → queue `DeclinedInvite` mail to the original sender.

This is the canonical example of an **observer doing lightweight model-lifecycle side effects** (mail + a dispatch + a pivot upsert) rather than a raw event listener.

## `NotificationLogEventHandler`

`app/Events/NotificationLogEventHandler.php` — a subscriber (`subscribe(Dispatcher)`) that listens to Laravel's `MessageSending` event and writes a `NotificationLog` row (type/from/to/cc/bcc/subject/body/headers/attachments) for every outbound email. So **every email sent is logged** to `notification_logs` for audit. (Pairs with `NotificationTypeEnum::Email`.)

## Event vs. observer vs. job — decision guide

| Use a… | When | Clarity example |
|--------|------|-----------------|
| **Eloquent observer** | Side effects tightly bound to a single model's lifecycle (created/updated/deleted) that must always run with the save. Keep it light. | `InviteObserver` on `Invite::updating` |
| **Domain event + listener** | Fan-out to N decoupled, possibly-queued, possibly-overlapping-suppressed subscribers; the producer shouldn't know who listens. | `HitEvent` → `HitEventListener` |
| **Queued job** | Heavy, retryable, rate-limited, batchable, or external-API work. Dispatched from controllers, observers, listeners, or commands. | `ShareOrgKits`, `RdnJob` subclasses, `LogFilterActivity` |
| **Event subscriber** | One class wiring many framework events (e.g. logging all mail). | `NotificationLogEventHandler` |

Rules:

- **Don't put heavy work in an observer.** An observer runs synchronously in the request (unless its work is a dispatched job). `InviteObserver` stays light by *dispatching* `ShareOrgKits` instead of doing the share inline.
- **Use `ShouldQueue` + `WithoutOverlapping` for notification-style listeners** to avoid double-notify and to keep the request fast (`HitEventListener`).
- **Prefer passing IDs to jobs** dispatched from observers (see [Jobs and Queues](jobs-and-queues.md)).
- **Register observers in `AppServiceProvider`** (`Model::observe(...)`), not in a separate `EventServiceProvider` `$listen` array — that's the existing convention here.

## Where it lives in code

- Events: `app/Events/HitEvent.php`, `app/Events/NotificationLogEventHandler.php`
- Listeners: `app/Listeners/HitEventListener.php`
- Observers: `app/Observers/InviteObserver.php` (registered in `app/Providers/AppServiceProvider.php`)
- Dispatch sites: `app/Console/Commands/IngestSqsQueueCommand.php`, `app/Http/Controllers/Api/Qa/HitController.php`

## See also

- [Hit](hit.md) — what `HitEvent` represents
- [Missed Opportunity](missed-opportunity.md) — the `M` normalization in `HitEvent`
- [Jobs and Queues](jobs-and-queues.md) — `ShareOrgKits`, `LogFilterActivity`
- [Alert Settings](alert-settings.md) — `notifySubscribers()` channels
- [Multi-Tenancy](multi-tenancy.md) — sharing/invites and `ClientOrgAccess`
