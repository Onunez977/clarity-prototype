---
type: Registry
title: Scout Analytics Taxonomy
description: The canonical Scout event/page naming scheme for clarity.analytics, the action-only tracking philosophy (no polling/auto-fetch rows), and how the two observability systems (ClickHouse analytics vs Spatie activity_log) divide responsibility.
resource: app/Enums/ScoutAnalyticsEvent.php
tags: [scout, analytics, clickhouse, activity-log, audit, taxonomy, registry]
timestamp: 2026-07-22T12:00:00Z
---

# Scout Analytics Taxonomy

Scout observes through **two distinct systems** — keep them separate:

| System | Store | Purpose | Gate |
|--------|-------|---------|------|
| **Analytics** | ClickHouse `clarity.analytics` | Usage telemetry — deliberate user actions | `database.track_analytics` + `database.use_clickhouse`; **skips while impersonating** |
| **Audit** | Postgres `activity_log` (Spatie) | Accountability for *mutating* admin/driver actions | `ACTIVITY_LOGGER_ENABLED` |

## Guiding principle: track actions, not heartbeats

**One analytics row per deliberate user action.** We do **not** track polled or auto-refresh
data fetches — `kit-position` (10s), `live-path` (20s), `shift/status`, Bird's-Eye polls (10s),
`map/hits` (~2 min), `recent-hits`, `stats`, `heatmap` data, `me`, zone fetches, etc. Tracking
those would write hundreds of identical heartbeat rows per session and make the table useless as
a usage signal. Each action is recorded **exactly once**, either server-side (when it has a
dedicated endpoint) or client-side (pure UI), never both.

`shift-start` is the model: it fires once when `EnsureOpenShift` materializes a shift
(`wasRecentlyCreated`), not on every `status` poll.

## Naming scheme

- **Feature name** (`feature` column): `clarity-scout-<thing>`, registered in
  [`app/Enums/ScoutAnalyticsEvent.php`](../../app/Enums/ScoutAnalyticsEvent.php) — **not free-form**.
  Client mirror: `resources/js/clarity-scout/lib/analyticsEvents.js`; the `/api/scout/track`
  endpoint validates client-supplied names against the enum. The one deliberate exception is
  `disposition-saved` — a shared cross-surface event, not in the enum (see below).
- **Page tag** (`page` column): the surface, from
  [`app/Enums/ScoutAnalyticsPage.php`](../../app/Enums/ScoutAnalyticsPage.php).
- **`action`** + **`meta`**: the verb (`open`, `toggle`, `select`, `mark-read`, `confirm-end`)
  and any extra keys (range, layer, id). `data` is JSON, so meta keys need no schema change.

## What IS tracked (deliberate actions)

### Server-side — once per action

| Event | Where | Trigger |
|-------|-------|---------|
| `clarity-scout-login` / `-logout` | `ClarityScoutAuthController` | login / logout |
| `clarity-scout-shift-start` | `ClarityScoutShiftController::status` (`wasRecentlyCreated`) | a shift opens |
| `clarity-scout-shift-end` | `ClarityScoutShiftController::end` | driver taps End Shift |
| `clarity-scout-day-detail` | `ClarityScoutTripsController::day` | driver opens a day |
| `clarity-scout-trip-detail` | `ClarityScoutTripsController::show` | driver opens a trip |
| `clarity-scout-notification-read` / `-notifications-read-all` | `ClarityScoutNotificationsController` | mark read / mark all read |

### Client-side (PWA) — pure UI interactions, via `lib/track.js` → `POST /api/scout/track`

`clarity-scout-panel` (open/close, `meta.panel`), `-back-nav`, `-live-hits-toggle`, `-hit-focus`
(`meta.source` card|map), `-map-layer-toggle` (`meta.layer`/`visible`), `-map-zone-tap`,
`-map-recenter`, `-date-range` (the range *click*). The util **no-ops under impersonation /
Bird's-Eye admin mode** and posts fire-and-forget. (The web `/analytics/track` route is
session-only, so the driver's Sanctum bearer needs this dedicated endpoint.)

### Filament admin — mutating management actions

`clarity-scout-zone-create|edit|delete|restore|reassign`, `-user-web-access`, `-user-force-login`,
`-user-assign`. **Tracked once on the execution path** — explicit `Analytics::track()` inside the
action closure / save hook (`ManageZones::save`, `ZoneReassignAction`, `ZoneQuickEditAction`,
`ScoutUsersResource` toggle, `EditScoutUser::afterSave`), **not** via `data-track`. A `data-track`
attribute fires on button click — including a cancelled confirmation modal — and would double-count
against the closure, so it was removed. The lone exception is `-user-force-login`, a `<a href>`
new-tab action with no server-side hook: it keeps its single `data-track` (one click = one row).

### Cross-surface shared event — `disposition-saved`

The one deliberate break from the `clarity-scout-*` namespace. A scan disposition is tracked under
the **shared** `disposition-saved` feature name — *not* a Scout-specific name and *not* in
`ScoutAnalyticsEvent` — because a disposition is the same business action whether a driver saves it
in the Scout PWA or an analyst saves it in Filament, and consumers want both to aggregate as one funnel.

| Surface | Emitted by | `meta.from_scout` | `page` |
|---------|-----------|-------------------|--------|
| Scout driver PWA | `ClarityScoutScanDispositionController` | `true` | — |
| Filament / web | `DispositionController` | `false` | — |
| Admin Bird's Eye | `ClarityScoutBirdsEyeDispositionController` | `true` | `clarity-scout-birds-eye` (DEV-2235) |

Source is disambiguated by the **`from_scout`** boolean in `meta` (added 3.8.0). Filter
`from_scout = true` for Scout-side, `= false` for web-side. **Rows predating 3.8.0 have no
`from_scout` key**, so the correct segmentation is Scout ⇔ `from_scout = true` and web/Filament ⇔
*absent-or-`false`* (never strictly `= false`). `meta` also carries `scan_id`, `disposition`,
`reason`. The admin Bird's Eye surface is a third source but was deliberately **not** given its
own `from_scout` value — widening that boolean would break the existing driver/web segmentation —
so it reuses `from_scout: true` and disambiguates itself from the driver PWA via the `page` tag
instead (`clarity-scout-birds-eye`, the only one of the three that sets `page` at all). Any
consumer that has been treating `from_scout: true` as "driver PWA" must now also read `page` to
tell the two apart. This is the only place a Scout action funnels into an existing web event name
— every other Scout event is self-identifying via its `clarity-scout-` prefix.

## What is NOT tracked (deliberate exclusions)

- **Polled / auto-refresh GETs**: `me`, `shift/status`, `dashboard/{stats,recent-hits,hotlist-stats,check-in-counts}`,
  `map/{scan-paths,hits,heatmap/*,kit-position,live-path,zones}`, `trips/{current,browse}`, the
  `notifications` list view, and **all** Bird's-Eye `/scout/org-map/api/*` endpoints. These are
  data fetches, not actions. (Removed in DEV-2130 — they previously emitted per request.)
- **Raw map pan/zoom** gestures.
- **Admin Bird's-Eye** has no client analytics (the PWA util no-ops in admin mode); its polling
  endpoints are intentionally untracked. If admin usage analytics is wanted later, that's a
  separate, action-based effort — not per-fetch tracking.

## Audit (`activity_log`) coverage

| Mutating action | Mechanism | Status |
|-----------------|-----------|--------|
| Zone create / update / delete / restore | `Zone` model `LogsActivity` | pre-existing |
| Zone driver reassignment | explicit `activity('zone')` in `ZoneAssignmentSyncer` | pre-existing |
| Scout web-access grant/revoke | explicit `activity('scout-user')` in `ScoutUsersResource` | DEV-2130 |
| Kit↔user assignment + vehicle type | explicit `activity('scout-user')` in `EditScoutUser` | DEV-2130 |
| Shift start / end | explicit `activity('scout-shift')` in `ClarityScoutShiftController` | DEV-2130 |
| Force-login into a kit | explicit `activity('clarity-scout-force-login')` in `ClarityScoutForceLoginController` | pre-existing (Filament action adds analytics only) |

Audit rows capture actor + impersonator + IP/UA via the same shape `ActivityFactory` uses
(`_impersonator`, `_request.{clientIp,userAgent}`). Audit is intentionally broader than analytics:
it records *every* mutation for accountability, where analytics records *interactions* for usage.

See also: [scout-api.md](scout-api.md), [scout.md](scout.md),
[clickhouse-integration.md](clickhouse-integration.md).
