---
type: Concept
title: ClearPlan Integration
description: The outbound ClearPlan integration — replaying hit records (and synthetic flex-match probable-matches) to ClearPlan over an AWS SQS FIFO queue, the manual Filament/HTTP triggers, and the CLEARPLAN_RESEND / FLEX_SEARCH feature gates.
resource: app/Services/ClearPlan.php
tags: [clearplan, integration, sqs, hit, feature-flag, outbound]
timestamp: 2026-07-06T00:00:00Z
---

# ClearPlan Integration

**ClearPlan** is an **outbound** integration: Clarity replays [Hit](hit.md) records to ClearPlan (an external recovery-workflow service) over an **AWS SQS FIFO** queue so they can be re-ingested/reprocessed. It is a manual, replay-only path — nothing sends to ClearPlan automatically on hit creation. It has two modes: **resend** existing hits, and **flex-match**, which sends *synthetic* probable-match hits for plate variations.

## The service (`app/Services/ClearPlan.php`)

`sendHit(Hit $hit): bool` is the single entry point. It eager-loads the hit's `hotlist.org` / `scan.scanMetaData` / `kit.org`, builds the payload via `HitPayload::build($hit)` (tagged `CAME_FROM_CLARITY_REQUEUE = true`), and publishes through `app/Services/Aws/Sqs::sendClearplanMessage()` to `config('services.clearplan.queue_url')` (a `.fifo` queue). FIFO fields:

- **Message group id:** the hit id — or `flex-{scanId}-{hotlistId}` for a flex match.
- **Deduplication id:** `{identifier}-{timestamp}`.
- **Attribute:** `HitType` = the hit's `hit_type` value.

`HitPayload::build()` is the shared shape (also used by hit notifications): vehicle fields (`vin`/`plate`/`us_state`/`make`/`model`/`year`), `udbsaid` = the scanning org's `rdn_id` (see [RDN](rdn.md)), the scanning/hotlist org ids + types, `insight_*` ids (hit/scan/kit/hotlist), and a `scans[]` array with lat/lng, plate/car image URLs, and timestamps.

## Two modes / two jobs

Both jobs are `ShouldQueue` and notify the initiating user with success/failure counts.

| Job | Input | What it sends |
|-----|-------|---------------|
| `ResendHitsToClearPlanJob` | `array $hitIds`, `User` | Loads each real `Hit`, calls `sendHit()`. Logs `activity('clearplan-resend')`. |
| `SendFlexMatchToClearPlanJob` | `int $scanId`, `array $hotlistIds`, `User` | Builds an **in-memory (unsaved) `Hit`** per hotlist with `hit_type = HitTypeEnum::Probable_Match` (`P`), relations set via `setRelation()`, then `sendHit()`. Logs `activity('flex-match-alert')`. |

Flex-match is the ClearPlan side of **Flex Search** — generating plate variations to catch OCR errors — sending each candidate hotlist as a probable (`P`) match rather than a confirmed hit (see `HitTypeEnum` in [DTOs & Enums](dto-and-enums.md)).

## Triggers

- **Filament** — `app/Filament/Actions/ResendToClearPlanAction.php`, a bulk action on the Hits resource. It filters the selection to `HotlistStatusEnum::Open` hits, requires the `ADMIN` role, tracks `Analytics::track('resend-to-clearplan', …)`, and dispatches `ResendHitsToClearPlanJob`.
- **HTTP** — `POST /scan/flex-match-alert` → `FlexMatchAlertController` dispatches `SendFlexMatchToClearPlanJob`.

## Feature gating

| Feature | Slug | Resolver | Notes |
|---------|------|----------|-------|
| `CLEARPLAN_RESEND` | `platform_repo_pro_clearplan` | `config('app.experimental.clearplan')` **AND** (`isInsightLpr` **OR** `isProEnabled`) | Guarded by the experimental config flag; also `|| session()->has('admin_impersonator')` on the action. In `CLARITY_PRO`+. |
| `FLEX_SEARCH` | `platform_repo_pro_fuzzy_search` | `isInsightLpr` **OR** `isProEnabled` | The OCR plate-variation search; from `PRO_LITE` up. |

See [Feature Flags & Pro Tiers](feature-flags.md) for the resolver mechanics and the `admin_impersonator` bypass.

## Where it lives in code

- Service: `app/Services/ClearPlan.php`, `app/Services/Aws/Sqs.php`, `app/Services/HitPayload.php`
- Jobs: `app/Jobs/ResendHitsToClearPlanJob.php`, `app/Jobs/SendFlexMatchToClearPlanJob.php`
- Triggers: `app/Filament/Actions/ResendToClearPlanAction.php`, `app/Http/Controllers/FlexMatchAlertController.php` (route `scan.flex-match-alert`)
- Config: `config/services.php` (`clearplan.queue_url`), `config('app.experimental.clearplan')`
- Features: `app/Enums/FeatureEnum.php` (`CLEARPLAN_RESEND`, `FLEX_SEARCH`), `app/Providers/PennantServiceProvider.php`

## See also

- [Hit](hit.md) — the records replayed; `HitPayload` shape
- [Feature Flags & Pro Tiers](feature-flags.md) — `CLEARPLAN_RESEND` / `FLEX_SEARCH` gating
- [DTOs & Enums](dto-and-enums.md) — `HitTypeEnum` (`P` = Probable Match)
- [RDN](rdn.md) — `udbsaid` / `rdn_id` carried in the payload
- [Jobs & Queues](jobs-and-queues.md) — how the resend/flex jobs are queued
