---
type: Pattern
title: Filament Conventions
description: Clarity-specific Filament patterns — the two panels, custom concerns (pro-feature gating, clickable filters, retry-query, filter analytics/logging), custom columns, custom filters, and panel switching. Excludes generic Filament how-to. Corrected from the legacy .claude/docs/filament-guide.md and form-patterns.md.
resource: app/Providers/Filament/App.php
tags: [domain, lpr, filament, ui, patterns, conventions]
timestamp: 2026-06-26T19:50:00Z
---

# Filament Conventions

Clarity-specific Filament patterns only. This is **not** a generic Filament tutorial — it covers the panels, custom concerns, columns, and filters that exist *in this repo* and the conventions new resources should follow. (Filament version: see `composer.json` — the manifests are authoritative over older docs that say v4.)

> Supersedes the legacy `.claude/docs/filament-guide.md` and the form-patterns how-to. Generic Filament usage is not re-documented here.

## Two panels

Defined in `app/Providers/Filament/`:

| Panel | ID | Path | Discover roots | Auth |
|-------|----|------|----------------|------|
| **Admin / Repo Company** | `app` | `/app` | `app/Filament/Resources`, `app/Filament/Pages`, `app/Filament/Clusters`, `app/Filament/Widgets` | `UserMiddleware` |
| **Client portal** | `client` | `/client` | `app/Filament/Client/Resources`, `app/Filament/Client/Pages`, `app/Filament/Client/Widgets` | `ClientAuthenticate` + `ClientMiddleware` |

Both use the shared `PlatformPanelProvider` trait (`app/Support/Filament/PlatformPanelProvider.php`) via `makePanel()`. The admin panel is `default`, registers `Pages\Dashboard`, exposes Profile + Subscription + Billing menu items, and groups nav into `Search`, `Geo-Spatial`, `Reports`, `Billing`, `Account`. The client panel is intentionally minimal (`pages([])`, `resources([])` — everything is discovered).

Key admin resources: `HitsResource`, `HotlistResource`, `ScansResource`, `ScanReportResource`, `ScoutUsersResource`, `GlKitsResource`, `UsersResource`, `InviteResource`, `ActivityResource`, `AffiliateStatsResource`, `MissedOpportunityResource`, `ZoneResource`. Client portal resource: `ClientSearchScansResource`.

## Custom concerns (`app/Filament/Concerns/`)

| Concern | Purpose | Notes |
|---------|---------|-------|
| `HasProFeature` | Redirects non-client users to the Subscription page when a Pennant feature is inactive. Implement `getFeatureName(): string\|array`. Skipped while an admin is impersonating. Pushes the feature to `session('upgrade_sources')`. | Use on any page/resource gated by a `FeatureEnum` value (see [DTO and Enums](dto-and-enums.md)). |
| `HasClickableFilter` | Lets widgets/JS dispatch a filter onto a List table programmatically (`addFilter($type, $value)`, `addQueryFilter(...)`). Handles the special `Hit` geo filter path (`scan.geo`) vs. plain `geo`. Dispatches `log-filter-activity`. | Powers click-to-filter on charts/maps. |
| `HasFilterActivityLogging` | Listens for `log-filter-activity` and dispatches `App\Jobs\LogFilterActivity` with the formatted filter state + session/user context (only when `$loggedFilters` is set and the table is filtered). | See [Jobs and Queues](jobs-and-queues.md). |
| `HasFilterAnalytics` | Tracks which filters were applied via the `Analytics` facade (`Analytics::track($featureName, $user, ['action' => 'filter-applied'], $page)`), iterating `$trackedFilters` (`statePath => featureName`). Override `trackConditionalFilterAnalytics()` for compound filters. | Pairs with the `clarity.analytics` ClickHouse table (see [ClickHouse Integration](clickhouse-integration.md)). |
| `HasRetryQuery` | Wraps `getTableRecords()` to retry broad/broken queries on `QueryException`: logs to the `db-error` channel + Datadog (`clarity.query_timeout`), sets an "Add additional search criteria" empty state, polls every 10s up to `$retryUntil = 2`, then returns an empty cursor paginator. Re-throws when `config('app.debug')`. | Use on list pages backed by heavy ClickHouse/PostgreSQL queries. |

## Custom columns (`app/Filament/Tables/Columns/`)

- `DateTimeColumn` (extends `TextColumn`) — renders dates in the authenticated user's timezone as `M j, Y` + `h:i A T` (wrapped, two-line, HTML) with a relative-time description below (`diffForHumans`). Uses `config('app.date_format')`. Falls back to `N/A` when no value.
- `RemoteOrderColumn` (extends `TextColumn`) — renders a `remote_order_id` as a clickable link to the originating integration (via `InsightLPR\BlackAdam\Datatype\RemoteOrderId` + `IntegrationEnum`), labeling it with the integration name. Returns `false` URL when no remote link exists.

## Custom filters (`app/Filament/Tables/Filters/`)

- `PlateFilter` — plate search with optional flex-search mode; exposes a static `modifyQuery(Builder, string $column, string $plate, bool $flexSearch)` used by ClickHouse scan scopes (`Scan::scopeForPlate` delegates here).
- `KitUsersFilter` — filter by kit/user.
- `DateTogglesFilter` — preset date-range toggles.
- `AddressFilter` — address/geo filtering.
- `FlexSearchToggle` — toggles flex (fuzzy) plate search; gated by `FeatureEnum::FLEX_SEARCH`.

## Pro-feature gating pattern

```php
class MyResource extends Resource
{
    use HasProFeature;

    public function getFeatureName(): string|array
    {
        return FeatureEnum::CLIENT_ANALYTICS->value;   // or an array of features
    }
}
```

When the feature is inactive (and the user isn't a `CLIENT` role or being impersonated), the user is redirected to `Pages\Subscription` and the feature is recorded as an upgrade source. Gate any Pro-only page/resource/widget this way rather than ad-hoc `Feature::active()` checks in views.

## Conventions for new resources

- Admin resources go in `app/Filament/Resources/`; client-portal resources in `app/Filament/Client/Resources/`. Both are auto-discovered.
- Reuse the concerns above instead of reinventing pro-gating, retry, filter analytics, or click-to-filter.
- Use `DateTimeColumn` for any datetime shown to users (timezone correctness is a recurring bug source).
- Use `RemoteOrderColumn` for `remote_order_id` fields so the integration link is consistent.
- Heavy list queries → `HasRetryQuery`. Tracked filters → `$trackedFilters` + `HasFilterAnalytics`. Click-to-filter UI → `HasClickableFilter` + dispatch `log-filter-activity`.

## Where it lives in code

- Panels: `app/Providers/Filament/App.php`, `app/Providers/Filament/Client.php`, `app/Support/Filament/PlatformPanelProvider.php`
- Concerns: `app/Filament/Concerns/*`
- Columns: `app/Filament/Tables/Columns/*`
- Filters: `app/Filament/Tables/Filters/*`
- Admin resources: `app/Filament/Resources/*`; client resources: `app/Filament/Client/Resources/*`
- Pages: `app/Filament/Pages/*` (incl. `Subscription`, `Profile`, `Dashboard`)

## See also

- [Client Analytics](client-analytics.md) — the widget hierarchy built on these concerns
- [DTO and Enums](dto-and-enums.md) — `FeatureEnum`, `RoleEnum`, `IntegrationEnum`
- [Jobs and Queues](jobs-and-queues.md) — `LogFilterActivity` and other dispatched jobs
- [Testing Conventions](testing-conventions.md) — Filament test helpers
