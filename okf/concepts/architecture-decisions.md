---
type: ADR
title: Architecture Decisions
description: The key architectural decisions (ADRs) for Clarity — framework, dual-database, org-based multi-tenancy, schema split, dual panel, Redis, Black Adam, Pennant, CI — with rationale and consequences. Migrated from the legacy architecture-decisions skill decisions-log, drift corrected.
resource: composer.json
tags: [domain, lpr, architecture, adr, decisions]
timestamp: 2026-06-26T20:25:00Z
---

# Architecture Decisions

The settled architectural choices for Clarity, with rationale and consequences. Use these when evaluating a new technology, questioning why something is built a certain way, or proposing a change that might conflict with an existing decision. (Framework versions: see `composer.json` — the manifests are authoritative over older docs that say Filament v4.)

> Migrated from the legacy `.claude/skills/architecture-decisions/decisions-log.md` (now deleted). Drift corrected: the schema/connection split (ADR-004) and the dual-database detail (ADR-002) were imprecise in the original.

## ADR-001: Filament for the admin panel + client portal

- **Decision:** Filament (over Nova/Backpack/custom) for the admin panel, with a separate client portal panel.
- **Status:** Accepted.
- **Rationale:** Livewire integration gives reactive UI without JS complexity; highly customizable via concerns/traits/custom components; open-source; supports multiple panels from one codebase.
- **Consequences:** Livewire learning curve; must follow Filament conventions; upgrade-path attention required across major versions.
- **See:** [Filament Conventions](filament-conventions.md) (two panels).

## ADR-002: ClickHouse for analytics (dual-database)

- **Decision:** PostgreSQL (transactional) + ClickHouse (analytics).
- **Status:** Accepted.
- **Rationale:** ClickHouse excels at OLAP (aggregations, time-series) — 10–100x faster for analytical queries; PostgreSQL handles OLTP (transactional integrity, relationships); ClickHouse materialized views pre-compute common aggregations.
- **Consequences:** CDC sync complexity; dual-driver pattern needed for testing; ClickHouse-specific knowledge required; must use `FINAL` for `ReplacingMergeTree` accuracy.
- **Implementation:** PostgreSQL `zod` database — core LPR tables in the **`public` schema** (`zod` connection), Clarity add-on tables in the **`platform` schema** (`platform` connection). ClickHouse `clarity` database (analytics) + `zod` database (PeerDB-replicated `public_*` tables). See [Data Architecture](data-architecture.md) and [ClickHouse Integration](clickhouse-integration.md).

## ADR-003: Organization-based multi-tenancy

- **Decision:** All data scoped to organizations (not individual users).
- **Status:** Accepted.
- **Rationale:** Natural isolation; simplifies the permission model (org → team → user); enables per-org feature flags via Pennant; supports org-level billing.
- **Consequences:** **Every query must include an `org_id` filter** (the most common bug source); factories must chain org relationships; cross-org data sharing requires the explicit sharing/staging system.
- **Prevention:** `forOrg($orgId)` model scopes; `createUser()` test helper; code-review checklist includes org scoping.
- **See:** [Multi-Tenancy](multi-tenancy.md).

## ADR-004: PostgreSQL schema split (`public` core + `platform` add-on)

- **Decision:** Core LPR tables live in the `public` schema of the `zod` database (owned by `insight-lpr/laravel-zod-migrations`); Clarity's own add-on tables live in a separate `platform` schema.
- **Status:** Accepted.
- **Rationale:** Schema-level isolation between the upstream-owned core schema and Clarity's add-on tables; clear ownership boundary; cleaner backup/restore separation.
- **Consequences:** Core models declare `protected $connection = 'zod'` (search_path `public`); Clarity add-on models use the default `platform` connection (search_path `platform`). Migrations must target the correct schema. **Pitfall:** "platform" is overloaded — it's both the Clarity add-on schema *and* the default connection name; the core LPR tables are **not** in the `platform` schema. See [Data Architecture](data-architecture.md).

## ADR-005: Dual panel architecture (Admin + Client)

- **Decision:** Separate admin panel (`/app`) and client portal (`/client`), not a unified panel.
- **Status:** Accepted.
- **Rationale:** Prevents accidental exposure of admin features to clients; simpler authorization (panel-level); different UX per audience; easier to audit.
- **Consequences:** Resources must live in the correct panel directory (`app/Filament/Resources/` vs `app/Filament/Client/Resources/`); some intentional duplication between panels.
- **See:** [Filament Conventions](filament-conventions.md).

## ADR-006: Redis-primary caching/queue/session strategy

- **Decision:** Redis as primary cache, session, and queue driver.
- **Status:** Accepted.
- **Rationale:** One infra component for multiple concerns; sub-ms cached response; Horizon for queue monitoring; well-supported in Laravel.
- **Consequences:** Redis is a critical infrastructure dependency; monitor memory; cache-invalidation complexity for widgets.
- **See:** [Jobs and Queues](jobs-and-queues.md), [Client Analytics](client-analytics.md) (widget cache TTLs).

## ADR-007: Black Adam package extraction

- **Decision:** Extract shared LPR logic/enums/utilities into the reusable `insight-lpr/black-adam` composer package.
- **Status:** Accepted.
- **Rationale:** Encapsulates shared LPR concerns; independent versioning/testing; cleaner main app codebase; usable across Insight LPR products.
- **Consequences:** Package maintenance overhead; version coordination; clear API contract required. (`HitTypeEnum` lives in this repo, not Black Adam — see [DTO and Enums](dto-and-enums.md).)
- **See:** [Repositories](repositories.md).

## ADR-008: Feature flags via Laravel Pennant (org-scoped)

- **Decision:** Laravel Pennant for feature flags with per-org scoping.
- **Status:** Accepted.
- **Rationale:** Native Laravel integration; database-backed for org-specific flags; simple `Feature::active(...)` API; supports custom scopes.
- **Consequences:** Feature definitions in `org_meta.properties` JSON; gate Pro features with the `HasProFeature` concern; test helpers `makeOrgPro()` / `makeUserPro()` (which exclude `CLICKHOUSE_SCANS` by default). Most features are **org-scoped**, not user-scoped.
- **See:** [DTO and Enums](dto-and-enums.md) (`FeatureEnum`, `ProTierEnum`), [Filament Conventions](filament-conventions.md) (`HasProFeature`), [Testing Conventions](testing-conventions.md).

## ADR-009: GitHub Actions CI/CD

- **Decision:** GitHub Actions for CI/CD.
- **Status:** Accepted.
- **Rationale:** Integrated with the repo; matrix testing; easy parallelization; team familiarity.
- **Consequences:** Tests must pass before merge (CI-enforced — see [Testing Conventions](testing-conventions.md)); staging environment for validation; workflow YAML maintenance. **Do not run tests automatically from the agent** — CI runs them after commit.

## ADR template

```markdown
## ADR-XXX: [Title]
**Decision:** [one sentence]
**Status:** Proposed | Accepted | Deprecated | Superseded
**Context:** [why needed; constraints]
**Rationale:** [why chosen; alternatives]
**Consequences:** [implications; new requirements]
```

## See also

- [Data Architecture](data-architecture.md) — the dual-database + schema-split detail
- [Repositories](repositories.md) — Black Adam / laravel-zod-migrations / laravel-clickhouse-eloquent
- [Filament Conventions](filament-conventions.md), [Jobs and Queues](jobs-and-queues.md), [DTO and Enums](dto-and-enums.md)
