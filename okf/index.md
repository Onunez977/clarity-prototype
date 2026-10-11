---
okf_version: "0.1"
title: Clarity Knowledge Wiki
description: Canonical source of truth for Clarity domain knowledge — business rules, domain concepts, and data architecture. OKF v0.1 bundle.
tags: [meta, index]
---

# Clarity Knowledge Wiki

This wiki is the **canonical source of truth for Clarity domain knowledge** — the business rules, domain concepts, and data architecture of the Clarity license plate recognition platform. It supersedes other knowledge bases in this repository for that purpose and is wired for discovery by **Codex, Cursor, and Claude Code** so all three land on it by default.

It follows the **Open Knowledge Format (OKF) v0.1**: a directory of Markdown files with YAML frontmatter, no SDK required, human- and agent-readable from the same file. The maintenance pattern is the **Karpathy LLM-Wiki**: the agent reads, updates, and cross-links these pages so the knowledge compounds over time instead of being re-derived on every question.

- **For executable behavior** (queries, models, migrations), the code under `app/`, `config/`, `database/`, `tests/` remains authoritative; on a code-vs-wiki conflict, code wins and the wiki gets updated.
- The legacy `.claude/docs/`, `.claude/skills/` knowledge skills, and `.serena/memories/` have been **migrated into this wiki and deleted** — this wiki is the single knowledge source. Only the workflow skills (`agent-qa`, `release-prep`) remain under `.claude/skills/`. See [CONTRIBUTING.md](CONTRIBUTING.md).
- The wiki can be **interacted with directly** via chat-triggered workflows (`lint the wiki`, `update wiki from <source>`, `evaluate wiki against code`, `wiki status`, `sync wiki from laravel-zod-migrations`) — see [CONTRIBUTING.md](CONTRIBUTING.md).
- It is **aware of sibling repositories** via [concepts/repositories.md](concepts/repositories.md); when a question spans more than this repo, the agent consults that registry and searches on demand.
- It **tracks upstream package drift** via [concepts/drift-watch.md](concepts/drift-watch.md), scoped to Clarity hotlists — so business-rule docs stay aligned when `laravel-zod-migrations` changes (e.g. `find_hits()`).

> **Start here:** Read [concepts/data-architecture.md](concepts/data-architecture.md) before any data-touching work. Clarity uses PostgreSQL for writes and ClickHouse for analytics; do not confuse the two.

## Page catalog

| Page | Summary | Last updated |
|------|---------|--------------|
| [CONTRIBUTING.md](CONTRIBUTING.md) | Schema layer — how to ingest, query, lint, and maintain this wiki (incl. sync workflow) | 2026-06-26 |
| [log.md](log.md) | Append-only chronological history of wiki changes | 2026-06-26 |
| [concepts/index.md](concepts/index.md) | Catalog of all concept pages, grouped by category | 2026-06-26 |
| [models/index.md](models/index.md) | **Data model reference** — one page per table/model (57 pages), live DB column types, relationships, PG↔CH mapping | 2026-07-06 |
| [concepts/data-architecture.md](concepts/data-architecture.md) | **Read first.** PostgreSQL vs ClickHouse disambiguator | 2026-06-26 |
| [concepts/multi-tenancy.md](concepts/multi-tenancy.md) | Org-based access control and sharing | 2026-06-26 |
| [concepts/feature-flags.md](concepts/feature-flags.md) | Pennant feature gating + Pro-tier resolution (`org_meta` tier store, `isProEnabled`, `FlushPennant`, impersonator bypass) | 2026-07-06 |
| [concepts/impersonation.md](concepts/impersonation.md) | Staff impersonation — Backyard-signed entry, `impersonator`/`admin_impersonator` keys, gate/analytics/cache effects, Scout force-token, 419 gotcha | 2026-07-06 |
| [concepts/scan.md](concepts/scan.md) | A license-plate read from a Kit | 2026-06-26 |
| [concepts/hit.md](concepts/hit.md) | A Scan ↔ Hotlist match | 2026-07-06 |
| [concepts/hit-address-distance.md](concepts/hit-address-distance.md) | Hits Distance from Given / Nearest Given Address — N/A contract, dual PG/CH sources, UNPROVEN DEV-2739 | 2026-09-25 |
| [concepts/hotlist.md](concepts/hotlist.md) | A wanted vehicle / repossession assignment | 2026-06-26 |
| [concepts/recovery.md](concepts/recovery.md) | A repossessed vehicle record | 2026-07-06 |
| [concepts/organization.md](concepts/organization.md) | The multi-tenant Org root | 2026-06-26 |
| [concepts/insight-lpr.md](concepts/insight-lpr.md) | Insight LPR is the company that owns Platform and Clarity. Public reference is https://insightlpr.com. Marketing on that site is not a product requirement. Otto quotes `resources/otto-knowledge/global/who-is-insight-lpr.md`. | 2026-09-23 |
| [concepts/kit.md](concepts/kit.md) | A **user** that signs in to a physical LPR camera (not the device); device is `scans.camera_name`, `kit_type=1` matters | 2026-07-06 |
| [concepts/missed-opportunity.md](concepts/missed-opportunity.md) | A scan matching a hotlist the org didn't hold — `M`/`F` hit types, `hotlist_org_id`, Pro feature | 2026-06-26 |
| [concepts/billing-events.md](concepts/billing-events.md) | **Billing events** — Backyard-owned per-API-call ledger, read only via the CH mirror `zod.public_billing_events`; `event_type` values, the `additional` JSON, nothing prunes (`ORDER BY id`), the claims funnel, and the **only 1-arg `ReplacingMergeTree`** so `FINAL` keeps its tombstones | 2026-09-09 |
| [concepts/lpr-assist.md](concepts/lpr-assist.md) | A recovery credited to LPR — plate scanned within ~0.1 mi of the repo; `clarity.lpr_assists` (no FINAL; best-effort) / Client Analytics | 2026-09-24 |
| [concepts/scout.md](concepts/scout.md) | **Scout** — driver PWA + admin surfaces for live tracking, zones, shifts; explicit-grant-only Pro feature gating | 2026-06-30 |
| [concepts/zone.md](concepts/zone.md) | A Scout patrol area — org-scoped PostGIS geometry (polygons/ZIPs), driver assignments, no-scan zones, and the save guard that refuses a zone whose ZIPs have no Census boundary | 2026-09-17 |
| [concepts/scout-user-assignment.md](concepts/scout-user-assignment.md) | Kit ↔ user ↔ zone linkage via `kit_settings`, the `clarity-scout` permission, `ScoutUsersResource` | 2026-07-06 |
| [concepts/scout-real-time-tracking.md](concepts/scout-real-time-tracking.md) | GPS → `clarity.sc_positions` → polled/broadcast → Vue; polling loop (10s, hits every 12th tick), cursor live-path, offline detection, zone broadcasts | 2026-07-09 |
| [concepts/scout-birds-eye.md](concepts/scout-birds-eye.md) | Admin org map — driver App.vue in adminMode, `ClarityScoutAdminMiddleware`, `OrgLive*` fan-out, `kit_ids` scope intersection, Kit(zod)/KitSettings(platform) enrollment lookup, driver-map differences | 2026-07-09 |
| [concepts/scout-clickhouse-queries.md](concepts/scout-clickhouse-queries.md) | Full SQL reference for every Scout ClickHouse query class (+ the non-Scout Kit User Path readers — scan-path and geohash only since DEV-2451) — tables, `FINAL`, SELECT/WHERE/GROUP BY, org scoping, geohash/dedup bucketing, CH-side route segmentation, gotchas | 2026-08-31 |
| [concepts/scout-dashboard-stats.md](concepts/scout-dashboard-stats.md) | How every Scout number is counted — stat tiles (repos = distinct repossessed orders), heatmap precision (forced to 7), current-vs-frozen status, `kit_org_id` vs `hotlist_org_id`; check-in tile reads `clarity.scout_open_addresses` | 2026-08-19 |
| [concepts/scout-frontend.md](concepts/scout-frontend.md) | Scout Vue PWA client — driver/Bird's Eye boot, query-param routing over one persistent map, Pinia stores/composables, deck.gl layers, `fetch`/`adminScope` libs | 2026-07-06 |
| [concepts/scout-pwa-and-offline.md](concepts/scout-pwa-and-offline.md) | Scout as an installable PWA — manifest/install, the 3-tier service-worker cache, byte-stamp update lifecycle, and the PII-purge-on-session-transition | 2026-07-06 |
| [concepts/scout-api.md](concepts/scout-api.md) | Scout HTTP surface — `/api/scout/*`, Bird's Eye `/scout/org-map/api/*`, auth/SPA, QA workflow | 2026-07-06 |
| [concepts/scout-shifts-and-trips.md](concepts/scout-shifts-and-trips.md) | Shift lifecycle in depth — lazy open from scan activity, confirmed/assumed close, the 30-min inactivity sweep, and the queued end-of-shift summary email (recipients, contents, Mapbox map); analytics vs audit; Trips parked behind a flag | 2026-07-09 |
| [concepts/scout-analytics-taxonomy.md](concepts/scout-analytics-taxonomy.md) | Scout event/page naming for `clarity.analytics`, tracked-vs-untracked matrix, analytics-vs-`activity_log` split, the `disposition-saved` cross-surface event + `from_scout` flag | 2026-07-22 |
| [concepts/check-in.md](concepts/check-in.md) | Domain definition of a Scout automated check-in — 0.1 mi, UTC hour, (kit, address, hour) identity, Direct-wins, what a check-in is NOT | 2026-08-18 |
| [concepts/scout-check-in-recorder.md](concepts/scout-check-in-recorder.md) | `scout:record-check-ins` — server-side CH match of `clarity.sc_positions` to `clarity.scout_open_addresses`, persist to PostgreSQL `platform.scout_check_ins`; schedule live (DEV-2386); book freshness is chlocal-owned | 2026-08-19 |
| [concepts/alert-settings.md](concepts/alert-settings.md) | Per-user hit-notification rules — `AlertSettingSettingsDto`, sms/email/slack/webhook, `HitAlertSettings` pipeline | 2026-06-26 |
| [concepts/rdn.md](concepts/rdn.md) | **RDN** — the SOAP upstream source of orders + org/agent/branch metadata; per-org rate-limited pull jobs; hourly RDN-metrics push over SQS | 2026-07-06 |
| [concepts/rdn-violent-debtor.md](concepts/rdn-violent-debtor.md) | **Violent debtor** — RDN webhook into PostgreSQL `zod.public` (table like `violent_vin`, not in the repo yet); VIN plaintext unique key, Laravel-encrypted first/last in separate columns, raw JSON blob of the full payload; flag-only display on hotlist/hit/scan | 2026-10-02 |
| [concepts/rdn-provider-integration.md](concepts/rdn-provider-integration.md) | **RDN metrics — provider spec** — the SQS message envelope, the 5 published hour-grain metric types + payload keys/examples, and `rdn_org_identifier` / `metric_range` semantics | 2026-07-21 |
| [concepts/clearplan.md](concepts/clearplan.md) | **ClearPlan** — outbound hit/flex-match replay to an SQS FIFO queue; manual triggers; `CLEARPLAN_RESEND`/`FLEX_SEARCH` gates | 2026-07-06 |
| [concepts/run-area.md](concepts/run-area.md) | **Run area** — an org's operating region; Backyard-authored inputs compiled to PostGIS in Clarity; clips Scout heatmaps; materialization refuses rather than stamping over an unresolved input | 2026-09-17 |
| [concepts/clickhouse-integration.md](concepts/clickhouse-integration.md) | ClickHouse analytical side — connections, PeerDB CDC, `clarity`/`zod` databases, tables + engines, pgsql test bridge | 2026-06-26 |
| [concepts/clickhouse-query-patterns.md](concepts/clickhouse-query-patterns.md) | `ClickhouseQuery` base, `FINAL`, `_peerdb_is_deleted`, driver-aware SQL, hot/cold, caching | 2026-06-26 |
| [concepts/client-analytics.md](concepts/client-analytics.md) | Client Analytics dashboard — metric definitions/formulas, status transitions, batch caching, widgets | 2026-06-26 |
| [concepts/company-metrics-dashboard.md](concepts/company-metrics-dashboard.md) | Company Metrics grid — `cards()` as the canonical key registry (twelve cards after DEV-2651), the merged-card pattern (one card, two figures; bars for counts, a line for a ratio on its own axis), cards as data adapters (why `mount()` never runs), per-viewer Pro entitlement plus the `canViewRatioFigure()` partial gate, the `getCacheTTL` ladder (1yr/10min/10s) + the `class_basename` key trap and the absorbed-leg namespace collision, `::query()` swallows vs `::handle()` propagates, one card's failure 500s all twelve, add-a-card checklist | 2026-09-22 |
| [concepts/filament-conventions.md](concepts/filament-conventions.md) | Clarity-specific Filament — panels, custom concerns/columns/filters, pro-feature gating | 2026-06-26 |
| [concepts/jobs-and-queues.md](concepts/jobs-and-queues.md) | Redis `{low}`/`{default}`/`{high}` via Horizon, `RdnJob` + rate limiting, `Bus::batch()`, retries | 2026-06-26 |
| [concepts/dto-and-enums.md](concepts/dto-and-enums.md) | DTO pattern + real enum set (`FeatureEnum`, `HitTypeEnum`, `HotlistStatusEnum`, …) with exact values | 2026-07-06 |
| [concepts/events-and-observers.md](concepts/events-and-observers.md) | `HitEvent`/`HitEventListener`, `InviteObserver`→`ShareOrgKits`, event-vs-observer-vs-job decision | 2026-06-26 |
| [concepts/testing-conventions.md](concepts/testing-conventions.md) | Sacred testing rules — `zod_testing`, `TestCase` helpers, `connectionsToTransact`, pgsql bridge | 2026-06-26 |
| [concepts/architecture-decisions.md](concepts/architecture-decisions.md) | Key architectural decisions (ADRs) — framework, dual-DB, multi-tenancy, schema split, dual panel, Redis, Black Adam, Pennant, CI | 2026-06-26 |
| [concepts/laravel-boost-guidelines.md](concepts/laravel-boost-guidelines.md) | **Generated** Laravel Boost coding standards — PHP, Laravel-way, Filament, Livewire, Tailwind, Pint, PHPUnit | 2026-08-13 |
| [concepts/platform-gotchas.md](concepts/platform-gotchas.md) | Recurring debugging pitfalls — `setPermissionsTeamId`, `FINAL`, org-scope leaks, Pro gating, cache staleness, bounding continuous-GPS route queries, `/broadcasting/auth` catch-all (PG-033), scheduled-command process pile-up, functions defeating ClickHouse PK pruning, unguarded loops, PHP round-trip joins, engine-dependent tombstone behaviour (PG-028), `join_use_nulls` type defaults (PG-030), `ST_Union` dropping NULL members (PG-031), the `spatial_ref_sys` test lock (PG-032), hot/cold split driven by a caller-supplied window (PG-034), releasing a completion signal too early (PG-035), drifting dual renderers (PG-036) | 2026-09-21 |
| [concepts/repositories.md](concepts/repositories.md) | Cross-repo awareness registry (sibling repos to search) | 2026-06-26 |
| [concepts/drift-watch.md](concepts/drift-watch.md) | Drift registry — package surfaces to re-verify on `laravel-zod-migrations` changes, scoped to Clarity hotlists | 2026-06-26 |
| [concepts/otto.md](concepts/otto.md) | Otto admin `/app` chat — laravel/ai+Bedrock, Soketi `ws://localhost:6001`, Horizon `{otto}`, `/broadcasting/auth` before catch-all, poll hang-net, hotlist assignment search, turn lifecycle + cache keys, page-action propose/confirm/apply, investigation hydration, Scout driver lookup | 2026-09-01 |

## How to use this wiki

See [CONTRIBUTING.md](CONTRIBUTING.md) for: the OKF format contract, the read-data-architecture-first rule, ingest/query/lint workflows, skill & planning integration, direct-maintenance chat workflows (including `sync wiki from laravel-zod-migrations`), the cross-repo search procedure, and the source-of-truth ordering.
