---
type: Schema
title: Contributing to the Clarity Knowledge Wiki
description: How agents and humans ingest, query, lint, and maintain this OKF wiki. The schema layer that makes a generic agent a disciplined wiki maintainer.
tags: [meta, contributing]
---

# Contributing to the Clarity Knowledge Wiki

This is the **schema layer** — the document that turns a generic LLM agent into a disciplined wiki maintainer. It tells the agent how this wiki is structured, what conventions to follow, and the workflows for ingesting sources, answering queries, and linting pages. It is the OKF equivalent of Karpathy's `CLAUDE.md`/`AGENTS.md` "schema" file.

## Format contract (OKF v0.1)

This bundle conforms to the Open Knowledge Format v0.1:

- Each concept is one Markdown file; the file path is the concept's identity.
- Each concept file has YAML frontmatter with a non-empty `type` field — the **only** required field.
- Recommended frontmatter (priority order): `type`, `title`, `description`, `resource`, `tags`, `timestamp` (ISO 8601). Unknown keys are allowed; consumers must preserve them and must not reject them (permissive consumption).
- Reserved filenames: `index.md` (content catalog) and `log.md` (append-only chronological history). These are tolerated without a `type`.
- Cross-links are normal Markdown links, e.g. `[Hotlist](concepts/hotlist.md)` from the bundle root or `[Hotlist](hotlist.md)` from within `concepts/`.
- The bundle-root `index.md` carries `okf_version: "0.1"`.

## Conventions

- Concept files live in `concepts/` and use lowercase slug filenames (`hotlist.md`, `data-architecture.md`).
- Frontmatter field order: `type`, `title`, `description`, `resource`, `tags`, `timestamp`. Add `migrated_from` or `source_repo` notes when applicable.
- `type` controlled vocabulary — pick the one that fits so agents can route/filter on it (OKF leaves the taxonomy to the producer; this is ours):
  - `DomainEntity` — a thing in the domain that maps to a model/record (scan, hit, hotlist, kit, recovery, organization, missed-opportunity, alert-settings).
  - `Pattern` — a Clarity-specific engineering convention or how-to (clickhouse-query-patterns, dto-and-enums, events-and-observers, filament-conventions, jobs-and-queues, testing-conventions).
  - `Concept` — a foundational/cross-cutting idea that isn't a single record (data-architecture, multi-tenancy, clickhouse-integration, client-analytics).
  - `ADR` — an architecture decision record (architecture-decisions).
  - `Playbook` — recurring pitfalls / diagnostic guidance (platform-gotchas).
  - `Registry` — a lookup list the agent consults (repositories, drift-watch).
  - `Index` / `Schema` — reserved for `concepts/index.md` and this contributing doc.

  Adding a new `type` value is fine — just add it here so the vocabulary stays discoverable.
- Timestamps are ISO 8601 UTC, e.g. `2026-06-26T10:31:00Z`.
- Cite code paths as repo-relative strings (`app/Models/Scan.php`). Cite the source of each business rule (code path or OKF page) so a future lint pass can verify against code. The legacy `.claude/docs/`, `.claude/skills/` companions, and `.serena/memories/` have been migrated into this wiki and deleted — do not cite them as sources.
- Every page that mentions a database table or view **must** state which database/schema it lives in (PostgreSQL `zod.<table>` for the `public` schema, `platform.<table>` for the `platform` schema, vs ClickHouse `clarity`/`zod`). See the data-architecture-first rule below.
- The legacy `.claude/docs/`, `.claude/skills/` knowledge companions, and `.serena/memories/` have been migrated into this wiki and deleted. This wiki is the single knowledge store. If Serena (or any other agent memory bank) is re-enabled, it must write into `okf/`, not `.serena/memories/` or `.claude/docs/`.

## CRITICAL — Read data-architecture first

Before writing or editing any query, model, or widget, the agent **MUST** read [concepts/data-architecture.md](concepts/data-architecture.md). Clarity has two databases — PostgreSQL (the `zod` database, accessed via `zod` and `platform` connections/schemas) and ClickHouse (`clarity`/`zod`, analytical read side) — and LLMs have repeatedly confused PostgreSQL functions/tables with ClickHouse materialized views/tables, and the core LPR tables with the `platform`-schema add-on tables.

Before proposing a change, confirm which connection a symbol lives on:

- `app/Models/*` (no `Clickhouse\` namespace) → PostgreSQL. Which schema depends on `$connection`: `zod` connection → core LPR tables in `public` (`zod.hotlist`, `zod.scans`, `zod.hits`, `zod.kits`, `zod.orgs`); default `platform` connection → Clarity add-on tables in `platform` (`platform.recoveries`, `platform.shared`, …). Writes allowed, transactional, FK-enforced.
- `app/Clickhouse/Models/*` (extend `ClickhouseModel`, `$connection = 'clarity_clickhouse'`) → ClickHouse. Read-only analytical.
- `app/Models/Views/` and `app/Models/Aggregate*` → PostgreSQL-backed DB views (read-only, refresh automatically).

When editing a concept page that touches data, record the connection explicitly in the page.

## Source of truth order

When guidance conflicts, trust sources in this order (mirrors `AGENTS.md`):

1. **`okf/` (this wiki)** — canonical for **domain knowledge** (business rules, domain concepts, data architecture, cross-repo registry).
2. **Code** under `app/`, `config/`, `database/`, `tests/` + `composer.json`/`package.json` — authoritative for **executable behavior**; wins over the wiki on code conflicts.
3. `README.md` and `CLAUDE.md`.

When the wiki conflicts with code on behavior, code wins and the wiki gets updated. The legacy `.claude/docs/`, `.claude/skills/` knowledge companions, and `.serena/memories/` have been migrated into this wiki and deleted — this wiki is the single knowledge source.

## Ingest workflow

When a new source arrives (a code path, an investigation under `docs/investigations/`, or a sibling repo from the registry):

1. Read the source.
2. Confirm key takeaways with the user.
3. Create or update the relevant concept page(s) — a single source may touch multiple pages.
4. Update the `index.md` catalog (and `concepts/index.md`) if a page is added.
5. Append a `log.md` entry: `## [YYYY-MM-DD] ingest | <subject>`.

## Query workflow

When answering a question against the wiki:

1. Read `index.md` first to find relevant pages.
2. Drill into the relevant concept pages.
3. Synthesize an answer with citations to the wiki pages (and the code they reference).
4. If the answer is durable and reusable, **file it back** as a new concept page or a section — don't let it disappear into chat history. Append a `log.md` entry.

## Lint workflow

Periodically health-check the wiki. Look for:

- Broken cross-links (links to pages that don't exist).
- Orphan pages (no inbound links).
- Missing concept pages for entities mentioned but not yet authored.
- Stale claims superseded by newer code.
- Contradictions between pages.
- The data-architecture rule: every table/view mention states which database it lives in.

Surface findings as **warnings, never errors** (matches OKF's permissive consumption model). Do not auto-fix unless asked; propose fixes per page.

### Automated lint (`scripts/okf-lint.php`)

The first three mechanical checks (broken internal `.md` links, `resource:` frontmatter paths that no longer exist in the repo, and orphan pages) are automated by `scripts/okf-lint.php`. Run it locally:

```bash
php scripts/okf-lint.php
```

It exits non-zero on hard errors (broken links, missing `resource:` paths) and reports orphans as warnings. The data-architecture rule and stale-claim/contradiction checks remain human/agent lint items — too fuzzy to automate without false positives. The linter is the source of truth for the mechanical checks; `lint the wiki` (below) layers the fuzzy checks on top.

### CI enforcement (`.github/workflows/okf-lint.yml`)

The linter runs in GitHub Actions on every PR that touches `okf/**` (or the linter/workflow itself) and on a weekly schedule (Sunday 18:00 UTC) as a drift sweep. PRs that introduce broken links or missing `resource:` paths fail CI. This is the **tool-agnostic safety net** — it catches drift regardless of which agent or human edited, and it is the primary mechanism that keeps the wiki honest across Cursor / Claude Code / Codex / humans.

## Upkeep hooks

Two mechanisms keep the wiki from silently drifting. The CI linter (above) is the durable, tool-agnostic one. Additionally:

- **Claude Code `PostToolUse` nudge** (`.claude/hooks/okf-nudge.php`, registered in `.claude/settings.json`): after an `Edit`/`Write`/`MultiEdit` lands on a drift-causing surface (`app/`, `config/`, `database/`, `tests/`), emits a one-line, **non-blocking** reminder to run `update wiki from <path>`. One nudge per file per 10 minutes (so a flurry of edits doesn't spam). Claude-Code-specific — it does not fire for Cursor's agent or Codex, so the CI linter is what covers those.

No agent is expected to run tests or the linter automatically from a session (per the repo's CI-first execution policy); the linter runs in CI, and the nudge only *suggests* a wiki sync.

## Skill & planning integration (the wiki as a context source)

This wiki's primary job is to store **domain knowledge about the company and this application** so that design/planning work product is grounded in accurate context. When running inside a skill (e.g. "superpowers" brainstorming, design-review, planning routines) or producing planning/implementation documents:

- **Read the wiki before planning.** Before generating a brainstorm, design doc, or implementation plan, read `index.md` then the relevant concept pages (always [data-architecture](concepts/data-architecture.md) if the work touches data; plus whichever domain concepts are in scope). Cite the wiki pages used.
- **Feed the wiki into artifacts.** Design and implementation docs in `docs/investigations/` (and equivalent planning locations) should link to the wiki concept pages they depend on, e.g. "See [okf/concepts/hotlist.md](../okf/concepts/hotlist.md) for the hotlist domain model." This keeps plans anchored to the shared domain model instead of re-deriving it.
- **Surface gaps, don't silently fill them.** If a planning session reveals domain knowledge that isn't in the wiki, note the gap in the artifact and offer to ingest it into the wiki after the session — do not block the plan on a wiki update.
- **Wiki vs. work product.** The wiki holds durable domain knowledge; `docs/investigations/` and planning docs hold instance-specific design/decisions. Don't copy a whole investigation into the wiki — extract only the durable domain facts and cross-link.

## Direct-maintenance & evaluation workflows (chat-triggered, no code change)

These trigger phrases let an engineer or user update or evaluate the wiki as a whole, outside any code change. The agent follows these routines when invoked. All write only inside `okf/` (plus `docs/investigations/` cross-links where relevant) and append a `log.md` entry.

- **`lint the wiki` / `evaluate wiki health`** — Run `php scripts/okf-lint.php` first for the mechanical checks (broken links, `resource:` paths, orphans), then layer the fuzzy checks (stale claims, contradictions, data-architecture rule) on top. Output a health report grouped by severity (warnings only). Do not auto-fix unless asked; propose fixes per page.
- **`update wiki from <source>`** — Ingest a named source (a code path, an investigation under `docs/investigations/`, or a sibling repo from the registry) into the relevant concept pages. Confirm scope with the user first.
- **`evaluate wiki against code`** — Spot-check a sample of concept pages against the code they cite; flag where the wiki has drifted from `app/`, `config/`, or `database/`. Output a drift report; offer to reconcile.
- **`wiki status`** — Read `log.md` (last ~10 entries via `grep "^## \[" log.md | tail -n 10`) and `index.md`; summarize what was last ingested/updated, page count, and any known gaps. Lightweight orientation, no writes.
- **`sync wiki from laravel-zod-migrations`** — Reconcile the wiki with upstream package drift. See the **Sync workflow (upstream package drift)** below.

## Sync workflow (upstream package drift)

The `insight-lpr/laravel-zod-migrations` package owns the core `zod.public` schema and the **hit-creation triggers/functions** (`find_hits()`, `find_historicals()`) that the wiki documents as business rules. When the package changes, the wiki can drift. This workflow keeps it current — **scoped to Clarity hotlists only.**

Run it when invoked via `sync wiki from laravel-zod-migrations`, or when `composer update insight-lpr/laravel-zod-migrations` / a lock-file change touches the package.

1. **Read the drift registry.** Open [concepts/drift-watch.md](concepts/drift-watch.md) — it enumerates the exact package surfaces the wiki depends on, the wiki pages each affects, and `last_verified` (the package ref the wiki was last verified against).
2. **Diff the package.** Compare the installed package in `vendor/insight-lpr/laravel-zod-migrations` (and its migrations / SQL functions) against the `last_verified` ref recorded in drift-watch. Identify which tracked surfaces changed. Use `git` in the package dir or the upstream repo (https://github.com/insight-lpr/laravel-zod-migrations) to see the diff.
3. **Apply the relevance gate.** A package change only triggers a wiki edit if it touches a **tracked surface in [drift-watch.md](concepts/drift-watch.md)** AND pertains to **Clarity hotlists**. Changes to non-hotlist `zod.public` tables, unrelated functions, or non-hotlist columns are **out of scope** — do not edit the wiki for them.
4. **Re-verify only the listed pages.** For each changed, in-scope surface, open the wiki pages drift-watch lists for that surface and reconcile them against the new package behavior (function signature, trigger events, produced `hit_type` values, table columns). Update only what's wrong; do not rewrite whole pages. Cite the package path/function as the source.
5. **Stamp `last_verified`.** In [drift-watch.md](concepts/drift-watch.md), update `last_verified` for each re-verified surface with the new package ref + ISO-8601 timestamp. If a surface did not change, leave its stamp.
6. **No-op case.** If no tracked surface changed (or changes failed the relevance gate), do NOT edit any concept page. Still append a `log.md` entry: `## [YYYY-MM-DD] sync | laravel-zod-migrations: no tracked surfaces changed (ref <x> → <y>)` so there is a record the sync was run.
7. **Log it.** Append a `log.md` entry: `## [YYYY-MM-DD] sync | laravel-zod-migrations <from> → <to>: <summary of in-scope wiki edits, or no-op>`. List the wiki pages edited and the surfaces re-verified.

This workflow edits only inside `okf/`. It never edits `vendor/` or the package itself, and it never changes the package's behavior — it only keeps the wiki's description of hotlist-relevant behavior aligned with the package.

## Cross-repo search procedure

When a question or plan references behavior that may live outside this repo (shared package enums, a sibling service, cross-product conventions):

1. Read [concepts/repositories.md](concepts/repositories.md) to identify candidate sibling repos.
2. Search only the repos and surfaces (`okf/`, `docs/`, or code) the registry entry permits.
3. Cite the source repo + path for any finding.
4. If a finding is durable and Clarity-relevant, offer to file it as a concept page with a `source_repo:` note in frontmatter; if a referenced repo isn't in the registry, propose adding it (never invent URLs).

## Editing boundary

Do not edit files outside `okf/` when maintaining the wiki. The one exception is that planning/investigation artifacts created by skills may cross-link into `okf/` (and the chat workflows may write cross-links into `docs/investigations/`).
