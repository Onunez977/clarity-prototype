---
type: Registry
title: Repositories
description: Sibling repositories the wiki can search for cross-cutting context. Cross-repo awareness registry.
tags: [meta, cross-repo]
timestamp: 2026-06-26T10:31:00Z
---

# Repositories

This repository (Clarity) is the primary application. The repositories below are siblings the agent may search **on demand** when domain knowledge spans them. Each entry says what to search (its OKF bundle, docs, or code) and its relationship to Clarity.

## How the agent uses this registry

When a user question or planning session references behavior that plausibly lives outside this repo (shared package enums, a sibling service, cross-product conventions):

1. Read this file to identify candidate sibling repos.
2. Search **only** the repos and surfaces (`okf/`, `docs/`, or code) each entry permits.
3. Cite the source repo + path for any finding.
4. If a finding is durable and Clarity-relevant, offer to file it as a concept page with a `source_repo:` note in frontmatter. If a referenced repo isn't listed here, propose adding it — **never invent URLs.**

## Maintenance

The user adds/edits entries directly. The agent may propose new entries during lint if it discovers a referenced repo that isn't listed, but never invents repo URLs or paths.

---

## Registry entries

### Clarity (this repo)

- **Role:** self — the primary application.
- **Path:** repo root (current working directory).
- **Search:** `okf/` (this wiki), `docs/`, `app/`, `config/`, `database/`, `tests/`.
- **Entrypoints:** [okf/index.md](../index.md), `AGENTS.md`, `CLAUDE.md`.
- **Status:** canonical.

### insight-lpr/black-adam

- **Role:** custom LPR package — provides base models, enums (`FeatureEnum`, `HotlistStatusEnum`, `OrgType`, `RoleEnum`, `IntegrationEnum`), and utilities (`EnumHasValues`, `IsDataType` concerns, `Title`/`Description` meta properties) shared across Insight LPR products. Clarity depends on it; some domain enums originate here. Note: `HitTypeEnum` lives in this repo (`App\Enums`), not in Black Adam. See [DTO and Enums](dto-and-enums.md).
- **Path / URL:** https://www.github.com/insight-lpr/black-adam.git (composer: `insight-lpr/black-adam` `^3.4`).
- **Search:** code, docs (and its `okf/` bundle if/when it has one).
- **Status:** confirmed (declared in `composer.json`).

### insight-lpr/laravel-clickhouse-eloquent

- **Role:** the ClickHouse Eloquent ORM fork Clarity's ClickHouse models build on. `App\Clickhouse\Models\ClickhouseModel` binds its `LaravelClickhouseEloquent\Builder`/`Connection` when the connection is ClickHouse, and `scopeFinal()` delegates to the builder's `final()`. On the pgsql test driver it falls back to the standard Eloquent builder. See [ClickHouse Integration](clickhouse-integration.md) and [ClickHouse Query Patterns](clickhouse-query-patterns.md).
- **Path / URL:** composer: `insight-lpr/laravel-clickhouse-eloquent` `@dev` (repo URL TBD — confirm the `git` remote).
- **Search:** code (builder/connection/dialect handling) when ClickHouse query mechanics or `FINAL` behavior is in question.
- **Status:** confirmed (declared in `composer.json`).

### insight-lpr/laravel-zod-migrations

- **Role:** owns the core `zod` PostgreSQL schema — the `public`-schema tables (`scans`, `hits`, `hotlist`, `kits`, `orgs`, `scans_metadata`, …) and the **database triggers/functions that create hits**: `find_hits()` (trigger `AFTER INSERT ON public.scans` → live hits `L`/`S`) and `find_historicals()` (trigger `AFTER INSERT OR UPDATE ON public.hotlist` → historical hits `H`/`Z`). Clarity consumes the resulting data but does not own the hit-creation logic. See [Hit](hit.md) (How hits are created), [Data Architecture](data-architecture.md), and [Drift Watch](drift-watch.md).
- **Path / URL:** https://github.com/insight-lpr/laravel-zod-migrations (composer: `insight-lpr/laravel-zod-migrations` `@dev`).
- **Search:** code (migrations, SQL functions/triggers). When hit-creation behavior is in question, search this repo for `find_hits` / `find_historicals`.
- **Status:** confirmed (declared in `composer.json`).

---

## Notes

- Prefer searching a sibling's `okf/` bundle first (if it has one), then its `docs/`, then its code.
- When a finding comes from a sibling repo, record `source_repo: <name>` in the frontmatter of any concept page filed from it.
- Keep this list small and current; stale entries are worse than missing ones. Lint should flag entries whose `status: placeholder` persists beyond a reasonable time.
