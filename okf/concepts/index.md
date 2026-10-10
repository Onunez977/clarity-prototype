---
type: Index
title: Concept Catalog
description: Catalog of all concept pages in the Clarity knowledge wiki, grouped by category.
tags: [meta, index]
---

# Concept Catalog

## Cross-cutting Concerns

> These gate everything else. Read **Data Architecture** first whenever the work touches data.

| Page | Summary |
|------|---------|
| [data-architecture.md](data-architecture.md) | **Read first.** PostgreSQL vs ClickHouse disambiguator — which database a table/view/function lives in, and the parity traps that trip up LLMs. |
| [multi-tenancy.md](multi-tenancy.md) | Org-based access control: `org_id` scoping, kit sharing, client↔repo access, staging visibility, roles, feature flags, panel gating. |
| [feature-flags.md](feature-flags.md) | The runtime gating system — Pennant definitions, `Org::isProEnabled`/`proTier` resolution, the `org_meta` tier store (Backyard-written), enforcement patterns + `admin_impersonator` bypass, `FlushPennant` cache. |
| [impersonation.md](impersonation.md) | Staff impersonation — the Backyard Black-Adam-signed entry, the `impersonator` vs `admin_impersonator` session keys, the effects (Pro-gate bypass, analytics skip, cache-TTL drop), the Scout force-login token, the DEV-2089 419 gotcha. |

## Core Domain

| Page | Summary |
|------|---------|
| [insight-lpr.md](insight-lpr.md) | Insight LPR is the company that owns Platform and Clarity. Public reference is https://insightlpr.com. Marketing on that site is not a product requirement. Otto quotes the customer article `resources/otto-knowledge/global/who-is-insight-lpr.md`. |
| [scan.md](scan.md) | A license-plate read captured by a Kit — the atomic unit of the system. |
| [hit.md](hit.md) | A match between a Scan and a Hotlist entry. |
| [hit-address-distance.md](hit-address-distance.md) | Hits **Distance from Given** / **Nearest Given Address** — when N/A is correct vs bug-candidate; PG `hotlist_address` path vs CH `clarity.recovery_address` fallback; feature `HIT_ADDRESS_DISTANCE`; DEV-2739 motivating case left **UNPROVEN**. |
| [hotlist.md](hotlist.md) | A wanted vehicle / repossession assignment. |
| [check-in.md](check-in.md) | A Scout automated check-in — a Scout kit's GPS passed within 0.1 mi of an open Direct/Staging order address during a UTC hour; one immutable row per (kit, address, hour) in `platform.scout_check_ins`. Pure GPS coverage — no hit involved (the no-hit-within-30-min rule is DEV-1758's push filter, not the definition). |
| [recovery.md](recovery.md) | A repossessed vehicle record (materialized-view-backed). |
| [organization.md](organization.md) | The multi-tenant Org root — Repo / Client / LienHolder. |
| [kit.md](kit.md) | A **user** that signs in to a physical LPR camera — NOT the device. The device is `scans.camera_name`; `kit_type=1` (`GL…`) is the type that matters. |
| [missed-opportunity.md](missed-opportunity.md) | A scan matching a hotlist the org didn't hold — `M`/`F` hit types, `hotlist_org_id` scoping, Pro feature. |
| [billing-events.md](billing-events.md) | The Backyard-owned per-API-call billing ledger. PostgreSQL `zod.billing_events` is written by Backyard; Clarity reads **only** the CH mirror `zod.public_billing_events`. `event_type` values, the `additional` JSON (`claimed_by_org_id`, `vin`, `hotlist_id`), forwarder-vs-agent org, the all/successful/deduped claims funnel, why nothing prunes (`ORDER BY id` → a window filter is free and an org filter saves nothing), and the **only 1-arg `ReplacingMergeTree`** among the 25 `zod.public_*` mirrors — so `FINAL` does *not* drop its tombstones. |
| [lpr-assist.md](lpr-assist.md) | A recovery credited to LPR — plate scanned within ~0.1 mi of the repo site; `clarity.recovery_scans` → `clarity.lpr_assists` (no FINAL; best-effort); distinct-recovery Client Analytics metric. |
| [alert-settings.md](alert-settings.md) | Per-user hit-notification rules — `AlertSettingSettingsDto`, sms/email/slack/webhook channels, `HitAlertSettings` pipeline, Pro feature. |
| [run-area.md](run-area.md) | An org's operating region — Backyard-authored state/MSA/polygon inputs compiled to a PostGIS `MultiPolygon` in Clarity; clips the Scout order-density heatmaps; materialization throws rather than stamping `materialized_at` over an unresolved reference. |

## Integrations

> External systems Clarity pulls order data from or pushes activity to.

| Page | Summary |
|------|---------|
| [rdn.md](rdn.md) | **Recovery Database Network** — the SOAP upstream source of orders + org/agent/branch metadata; per-org rate-limited pull jobs (`RdnJob`); the hourly RDN-metrics push over SQS (`RdnMetricType`). |
| [rdn-violent-debtor.md](rdn-violent-debtor.md) | **Violent debtor** — RDN pushes a VIN-keyed list so Clarity can flag that VIN. Webhook auth is an env-matched GUID (or comparable string). PostgreSQL `zod.public` (table like `violent_vin`, not in the repo yet; platform writes it, ADR-004 exception): plaintext unique VIN, Laravel-encrypted first and last name in separate columns, raw JSON blob of the full payload. Display starts flag-only on hotlist/hit/scan. Implementation waits on the DEV-2742 push contract. |
| [clearplan.md](clearplan.md) | Outbound replay of hits (and synthetic flex-match probable-matches) to ClearPlan over an SQS FIFO queue; manual Filament/HTTP triggers; `CLEARPLAN_RESEND` / `FLEX_SEARCH` gating. |

## Scout

> The driver-facing PWA + admin surfaces for live scanner tracking, zones, and shifts. Read [scout.md](scout.md) first for the feature hub and gating.

| Page | Summary |
|------|---------|
| [scout.md](scout.md) | Feature hub — the three surfaces (driver PWA, admin panel, Bird's Eye), data architecture, and the explicit-grant-only `CLARITY_SCOUT` gating (5 gate points). |
| [zone.md](zone.md) | A patrol area — `platform.zones` PostGIS geometry (polygons + ZIP codes), `zone_user` assignments, no-scan zones, the geometry rebuild pipeline, and the two-part save guard for ZIPs with no Census boundary. |
| [scout-user-assignment.md](scout-user-assignment.md) | Kit ↔ user ↔ zone linkage via `platform.kit_settings`, the `clarity-scout` permission grant, `ScoutUsersResource`. `users.kit_id` is an accessor, not a column. |
| [scout-real-time-tracking.md](scout-real-time-tracking.md) | Scanner GPS → `clarity.sc_positions` → polled/broadcast → Vue/deck.gl; the 10s polling loop (hits every 12th tick), cursor-based live-path, offline detection, zone broadcasts, Bird's Eye fan-out. |
| [scout-birds-eye.md](scout-birds-eye.md) | Admin org-wide map — the driver `App.vue` in `adminMode` under `ClarityScoutAdminMiddleware`; every endpoint, org-scoping via `scopedKits()` intersection, the Kit(zod)/KitSettings(platform) cross-connection enrollment lookup, per-driver trail cursors, and how it differs from the driver map (run-area vs zone clip, org-wide heatmaps, `trip_count`=0). |
| [scout-clickhouse-queries.md](scout-clickhouse-queries.md) | Full SQL reference — the base class (silent degrade-to-`[]`, cache TTL, `DB::clickhouse()` macro) and every query class (position/path + density/count) with tables, `FINAL`, exact SELECT/WHERE/GROUP BY, org scoping, dedup/geohash bucketing, and the load-bearing gotchas. Which classes are Scout vs non-Scout, incl. `KitRangePathsQuery`'s ClickHouse-side route segmentation. |
| [scout-frontend.md](scout-frontend.md) | The Scout Vue PWA client — driver (`main.js`) vs Bird's Eye (`birdseye.js`) boot, `adminMode`, query-param routing over one persistent map, the 5 Pinia stores + 13 composables, the deck.gl layer stack, `lib/fetch` + `lib/adminScope`. |
| [scout-pwa-and-offline.md](scout-pwa-and-offline.md) | Scout as an installable PWA — `manifest.json` + install prompt, the 3-tier `clarity-scout-sw.js` cache, the `version-sw.mjs` byte-stamp update lifecycle, and the `CLEAR_API_CACHE` PII purge on logout/401/force-login. |
| [scout-api.md](scout-api.md) | Endpoint catalog — driver `/api/scout/*`, admin Bird's Eye `/scout/org-map/api/*`, broadcast auth, auth/SPA web routes, non-prod QA workflow. |
| [scout-shifts-and-trips.md](scout-shifts-and-trips.md) | Shift lifecycle in depth — lazily materialized from scan activity (`platform.kit_shifts`, one open per kit), confirmed (driver) vs assumed (30-min inactivity sweep) close, and the queued end-of-shift summary email: recipients (driver To / admin CC), contents (banner, stats, per-zone PostGIS breakdown, hotlinked Mapbox map), idempotency, and skip reasons; analytics-vs-audit under impersonation. Trips are parked behind `CLARITY_SCOUT_TRIPS`. |
| [scout-dashboard-stats.md](scout-dashboard-stats.md) | How every Scout number is counted — stat tiles (repos = distinct repossessed orders, un-deduped; direct/staging dedup by (plate,hour); scans `FINAL`), heatmaps (geohash precision forced to **7** in prod, live `count(DISTINCT)` not a stored column), current-vs-frozen hotlist status (DEV-2113/2117), `kit_org_id` vs `hotlist_org_id`. Check-in tile reads `clarity.scout_open_addresses`, not the zod CDC chain. |
| [scout-analytics-taxonomy.md](scout-analytics-taxonomy.md) | Canonical Scout event/page names (`ScoutAnalyticsEvent`/`ScoutAnalyticsPage`), tracked-vs-untracked matrix across Filament + PWA, the analytics-vs-`activity_log` responsibility split, and the `disposition-saved` cross-surface event disambiguated by `from_scout`. |
| [scout-check-in-recorder.md](scout-check-in-recorder.md) | The backend recorder (`scout:record-check-ins`, DEV-2313 / DEV-2342 / DEV-2386) that persists automated check-ins — GPS `clarity.sc_positions` matched **server-side** to `clarity.scout_open_addresses`, one row per (kit, address, hour) in PostgreSQL `platform.scout_check_ins`. Single CTE per kit (no PHP address list), N-slot concurrency ceiling, schedule live. Semantics: records every pass; the no-hit exclusion is DEV-1758's push filter. Distinct from the on-read `ScoutCheckInRollupQuery` dashboard tile. Book rebuild freshness is chlocal-owned. |

## Engineering Patterns

| Page | Summary |
|------|---------|
| [clickhouse-integration.md](clickhouse-integration.md) | ClickHouse analytical side — connections, PeerDB CDC, `clarity`/`zod` databases, tables + engines, the pgsql test bridge. |
| [clickhouse-query-patterns.md](clickhouse-query-patterns.md) | `ClickhouseQuery` base (`app/Clickhouse/Queries`), `FINAL`, `_peerdb_is_deleted`, driver-aware SQL, hot/cold tables, caching. |
| [client-analytics.md](client-analytics.md) | Client Analytics dashboard — metric definitions/formulas, `HotlistStatusEnum` transitions, batch caching, widgets. |
| [company-metrics-dashboard.md](company-metrics-dashboard.md) | The `/app` Company Metrics grid, **twelve cards** after DEV-2651. `CompanyMetricsOverview::cards()` is the canonical key registry (not `Dashboard::cardWidgets()`); slots are mockup-relative so a new card is *inserted*. DEV-2651 merged four pairs into their survivors — one card now carries two figures, counts as bars and a ratio as a line on its own right-hand axis, sharing a numerator via `RatioAgainstUniqueScans`. Cards are built as data adapters via `toStat()`, so `mount()` never runs and `canViewCardData()`/`lockedStat()` carry the Pro gate; `canViewRatioFigure()` gates a *figure* rather than a card. `getCacheTTL($index)` ladder = 1 year / 10 min / 10 s; `buildWidgetCacheKey` derives from `class_basename`, so a subclass addresses a different key — and an absorbed leg collides with its survivor's unless given a sub-namespace. `ClickhouseQuery::query()` swallows Throwable, `::handle()` does not, which is what decides blast radius. `getStats()` has no per-card try/catch — one card's ClickHouse failure 500s all twelve. |
| [filament-conventions.md](filament-conventions.md) | Clarity-specific Filament — two panels, custom concerns/columns/filters, pro-feature gating. |
| [jobs-and-queues.md](jobs-and-queues.md) | Redis `{low}`/`{default}`/`{high}`/`{otto}` via Horizon, `RdnJob` base + rate limiting, `Bus::batch()`, retries. |
| [dto-and-enums.md](dto-and-enums.md) | DTO pattern + real enum set (`FeatureEnum`, `HitTypeEnum`, `HotlistStatusEnum`, …) with exact values. |
| [events-and-observers.md](events-and-observers.md) | `HitEvent`/`HitEventListener`, `InviteObserver`→`ShareOrgKits`, event-vs-observer-vs-job decision. |
| [testing-conventions.md](testing-conventions.md) | Sacred testing rules — `zod_testing`, `TestCase` helpers, `connectionsToTransact`, pgsql bridge. |
| [architecture-decisions.md](architecture-decisions.md) | Key architectural decisions (ADRs) — framework, dual-DB, multi-tenancy, schema split, dual panel, Redis, Black Adam, Pennant, CI. |
| [laravel-boost-guidelines.md](laravel-boost-guidelines.md) | **Generated** Laravel Boost coding standards — PHP, Laravel-way, Laravel 12, Filament, Livewire, Tailwind, Pint, PHPUnit. Read before writing code; do not hand-edit. |
| [platform-gotchas.md](platform-gotchas.md) | Recurring debugging pitfalls — `setPermissionsTeamId`, `FINAL`, org-scope leaks, Pro gating, cache staleness, bounding continuous-GPS route queries, `/broadcasting/auth` catch-all, scheduled-command process pile-up, functions defeating ClickHouse primary-key pruning, unguarded loops, PHP round-trip joins, `join_use_nulls` type defaults, PostGIS `ST_Union` dropping NULL members, the `spatial_ref_sys` lock that hangs `ST_Transform` in tests, hot/cold split driven by a caller-supplied window, releasing a completion signal too early, drifting dual renderers. |
| [otto.md](otto.md) | Otto admin `/app` chat — laravel/ai + Bedrock IAM, Soketi `ws://localhost:6001` (no `/app/` proxy), Horizon `{otto}`, `/broadcasting/auth` before catch-all, poll hang-net, hotlist assignment search, turn lifecycle + cache keys, page-action propose/confirm/apply, investigation hydration, Scout driver lookup. |

## Meta / Cross-cutting

| Page | Summary |
|------|---------|
| [repositories.md](repositories.md) | Cross-repo awareness registry — sibling repositories the agent may search on demand. |
| [drift-watch.md](drift-watch.md) | Drift registry — package surfaces (e.g. `find_hits()`, `find_historicals()`, hotlist table columns) to re-verify when `laravel-zod-migrations` changes, scoped to Clarity hotlists. Drives the `sync wiki from laravel-zod-migrations` workflow. |
