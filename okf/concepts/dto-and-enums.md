---
type: Pattern
title: DTOs and Enums
description: Clarity's DTO pattern and the real enum set (FeatureEnum, HitTypeEnum, HotlistStatusEnum, InviteTypeEnum, NotificationTypeEnum, DispositionEnum, ProTierEnum, RdnMetricType) with exact values and the ArchTech/Black-Adam metadata machinery. Corrected from the legacy .claude/docs/dto-enum-patterns.md.
resource: app/Enums/FeatureEnum.php
tags: [domain, lpr, dto, enums, patterns, black-adam]
timestamp: 2026-06-26T20:00:00Z
---

# DTOs and Enums

Clarity's structured-data conventions: Data Transfer Objects in `app/Dto/` and PHP enums in `app/Enums/`. This page lists the **real** cases/values — the legacy `dto-enum-patterns.md` invented several (`AdvancedReporting`, `ClientPortal`, `missed-opportunity` as a value) and truncated `HitTypeEnum`. Use this page instead.

> Supersedes the legacy `.claude/docs/dto-enum-patterns.md`.

## Enum pattern

Most enums use **ArchTech metadata** + **Black Adam** concerns:

```php
use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Description;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class, Description::class)]
enum FeatureEnum: string
{
    use EnumHasValues, IsDataType, Metadata;

    #[Title('Shared Kits')]
    #[Description('…')]
    case SHARED_KITS = 'platform_repo_pro_shared_kits';
}
```

- `#[Meta(Title::class, Description::class)]` + `use Metadata;` exposes `->title()`, `->description()`.
- `EnumHasValues` (Black Adam) provides `::values()`, `::fromValue()`, etc.
- `IsDataType` (Black Adam) marks the enum as a data type for form/cast integration.
- Plain enums (`NotificationTypeEnum`, `ProTierEnum`, `RdnMetricType`) skip the metadata traits and define their own helpers.

## `FeatureEnum` (string) — Pennant pro-feature flags

The set of gated Pro features. **Values are prefixed `platform_repo_pro_*`** (these are the Pennant feature keys). `FeatureEnum::CLIENT_ANALYTICS->value === 'platform_repo_pro_client_dashboard'` — note the value differs from the case name.

| Case | Value | Title |
|------|-------|-------|
| `SHARED_KITS` | `platform_repo_pro_shared_kits` | Shared Kits |
| `INVITES` | `platform_repo_pro_invites` | Invites |
| `SCAN_CLUSTERING` | `platform_repo_pro_scan_clustering` | Scan Clustering |
| `MISSED_OPPORTUNITY` | `platform_repo_pro_missed_opportunity` | Missed Opportunity |
| `FLEX_SEARCH` | `platform_repo_pro_fuzzy_search` | Flex Search |
| `ALERT_SETTINGS` | `platform_repo_pro_alert_settings` | Alert Settings |
| `CLIENT_ANALYTICS` | `platform_repo_pro_client_dashboard` | Client Analytics |
| `REPO_HEATMAP` | `platform_repo_pro_repo_heatmap` | Repo Heatmap |
| `CLICKHOUSE_SCANS` | `platform_repo_pro_clickhouse_scans` | Clickhouse Scans |
| `CLICKHOUSE_HITS` | `platform_repo_pro_clickhouse_hits` | Clickhouse Hits |
| `AI_NOTIFICATIONS` | `platform_repo_pro_ai_notifications` | AI Notifications |
| `LPR_VISITS` | `platform_repo_pro_lpr_visits` | LPR Visits |
| `CLEARPLAN_RESEND` | `platform_repo_pro_clearplan` | Resend to Clearplan |
| `HIT_ADDRESS_DISTANCE` | `platform_repo_pro_hit_address_distance` | Distance from Given — see [Hit Address Distance](hit-address-distance.md) for N/A contract |
| `SCANNER_PORTAL` | `platform_repo_pro_scanner_portal` | Scanner Portal |
| `HIT_WEBHOOK` | `platform_repo_pro_hit_webhook` | Hit Webhooks |

Helpers: `slug()`, `slugs()`, `fromSlug()`, `minimumTier(): ?ProTierEnum` (which tier first unlocks it). Gate UI with `HasProFeature` (see [Filament Conventions](filament-conventions.md)).

## `HitTypeEnum` (string) — hit classification

Owned in **this repo** (`app/Enums/HitTypeEnum.php`), not in Black Adam. Created by PostgreSQL triggers — see [Hit](hit.md).

| Case | Value | Title |
|------|-------|-------|
| `Live` | `L` | Live |
| `Historical` | `H` | Historical |
| `Staging_Live` | `S` | Staging Live |
| `Staging_Historical` | `Z` | Staging Historical |
| `Missed_Opportunity` | `M` | Missed Opportunity |
| `Missed_Opportunity_Historical` | `F` | Missed Opportunity Spotted & Closed |
| `Probable_Match` | `P` | Probable Match |

`L`/`S` come from `find_hits()` (live); `H`/`Z` from `find_historicals()` (historical); `M`/`F` are missed-opportunity (see [Missed Opportunity](missed-opportunity.md)); `P` is a probable/OCR-variant match.

## `HotlistStatusEnum` (int) — hotlist lifecycle

| Case | Value | Title |
|------|-------|-------|
| `Other` | `0` | Other |
| `Open` | `1` | Open (accepted/active) |
| `Hold` | `2` | Hold |
| `Repossessed` | `3` | Repossessed |
| `ClosedPositive` | `4` | ClosedPositive (declined) |
| `Closed` | `5` | Closed |
| `NewFromClient` | `90` | NewFromClient |
| `Invalid` | `99` | Invalid |

`isTerminal(): bool` — true for `Repossessed`, `ClosedPositive`, `Closed`, `Invalid` (used to suppress alerts on done orders). Drives Client Analytics scopes (see [Client Analytics](client-analytics.md)) — note `declined = ClosedPositive (4)`, not `Closed (5)`.

## Other enums

- `InviteTypeEnum` (string) — `SHARE_SCANS = 'Share Scans'`, `CLIENT_SCANS = 'Client Scans'`. Drives `InviteObserver` behavior (see [Events and Observers](events-and-observers.md)).
- `NotificationTypeEnum` (string, plain) — `Email='email'`, `Sms='sms'`, `Slack='slack'`, `Webhook='webhook'`. `::values()`. Used by alert settings (see [Alert Settings](alert-settings.md)).
- `DispositionEnum` (string) — `NEW_HIT`, `ACCESSIBLE`, `MOVING`, `INACCESSIBLE`, `INVALID_SCAN`, `VALID`, `INVALID`, `REVIEWED` (values are the title strings). `formOptions(bool $missedOpportunity)` returns a filtered option set (missed-opportunity context uses only `VALID`/`REVIEWED`/`INVALID`).
- `ProTierEnum` (string, plain) — `BASIC='basic'` ($0), `PRO_LITE='pro_lite'` ($249), `CLARITY_PRO='clarity_pro'` ($599), `CLARITY_PRO_PLUS='clarity_pro_plus'` ($1199). `features(): array<FeatureEnum>`, `label()`, `price()`, `isSelfSubscribable()` (Pro Plus is not self-subscribable). `FeatureEnum::minimumTier()` resolves against this.
- `RdnMetricType` (string, plain) — `SCANS`, `HISTORICAL_HITS`, `STAGING_HISTORICAL`, `LIVE_HITS`, `STAGING_LIVE` (values are RDN metric column aliases like `scans_by_org_by_hour`). `isScan()`, `hitColumn()`, `hitCases()`. Used by the RDN metrics jobs (see [Jobs and Queues](jobs-and-queues.md)).

## DTOs (`app/Dto/`)

Plain PHP value objects for structured transfer (no base class mandate — they're typed bags). Real DTOs:

| DTO | Purpose |
|-----|---------|
| `AlertSettingSettingsDto` | Alert-rule settings payload (channels, hit-type filter) — see [Alert Settings](alert-settings.md) |
| `ScanMetaDataDto` | Scan metadata extracted from the scan `meta` JSON |
| `HitNotificationData` | Payload for hit notifications (email/sms/slack/webhook) |
| `HotlistJsonData` | The `hotlist_json` denormalized payload (case_number, vehicle_*, vin, …) |
| `AnalyticsDataDto` | Product-analytics event payload (`clarity.analytics`) |
| `ScanViewDto` | Scan view-layer data for the client/admin scan views |
| `SQSData` | Inbound SQS message envelope |
| `Rdn\AgentDto`, `Rdn\AgentInfoDto`, `Rdn\ClientDto` | RDN API response shapes (agents/clients) |

Conventions: DTOs hold the *shape* that crosses a boundary (API ↔ job ↔ notification); don't put behavior in them. Cast enums to their `->value` when serializing into a DTO/array (e.g. `HitTypeEnum::Live->value`), and `::from()` when hydrating.

## Where it lives in code

- Enums: `app/Enums/*.php`
- DTOs: `app/Dto/*.php`, `app/Dto/Rdn/*.php`
- Metadata machinery: `archtech/enums` (composer) + `InsightLPR\BlackAdam\Enum\Concern\{EnumHasValues,IsDataType}` + `InsightLPR\BlackAdam\Enum\MetaDataProperties\{Title,Description}` (see [Repositories](repositories.md))

## See also

- [Hit](hit.md) — `HitTypeEnum` and the trigger that sets it
- [Client Analytics](client-analytics.md) — `HotlistStatusEnum` scopes
- [Alert Settings](alert-settings.md) — `AlertSettingSettingsDto`, `NotificationTypeEnum`
- [Missed Opportunity](missed-opportunity.md) — `M`/`F` hit types, `MISSED_OPPORTUNITY` feature
- [Filament Conventions](filament-conventions.md) — `HasProFeature` uses `FeatureEnum`
