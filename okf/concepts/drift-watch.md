---
type: Registry
title: Drift Watch
description: External surfaces the wiki depends on. Lists the exact package-owned schemas/functions/tables to re-verify when the upstream package changes, scoped to Clarity-hotlist relevance. Maintained by the sync workflow.
tags: [meta, drift, sync, laravel-zod-migrations]
timestamp: 2026-06-26T16:33:00Z
---

# Drift Watch

This page is the **drift registry** — the explicit list of surfaces the wiki depends on that are owned **outside this repo** (primarily the `insight-lpr/laravel-zod-migrations` composer package). When the package changes, the [sync workflow](../CONTRIBUTING.md) consults this page to decide which wiki pages need re-verification, and stamps `last_verified` here.

It is **scope-limited by design**: only surfaces that affect **Clarity hotlist behavior** are tracked. Package changes unrelated to Clarity hotlists (e.g. unrelated `zod.public` tables Clarity never reads) are explicitly out of scope and must NOT trigger wiki edits.

## How the agent uses this page

- **On `sync wiki from laravel-zod-migrations`** (see [CONTRIBUTING.md](../CONTRIBUTING.md)): diff the installed package against the last-verified ref; for each changed surface below, check the listed wiki pages; update only the pages in scope; stamp `last_verified` here; append a `log.md` entry.
- **On `lint the wiki`**: flag any surface whose `last_verified` is older than the installed package version, or missing.
- **Relevance gate:** if a package change does not touch any tracked surface, the sync workflow records a no-op `log.md` entry (`sync | laravel-zod-migrations: no tracked surfaces changed`) and edits nothing.

## Tracked surfaces

Each surface lists: the package symbol, the wiki pages that depend on it, and `last_verified` (package ref + date). The ref is the composer installed version / commit the wiki was last verified against.

### `find_hits()` — PostgreSQL function (live hit creation)

- **Owner:** `insight-lpr/laravel-zod-migrations`, applied to `zod.public`.
- **What it does:** trigger `AFTER INSERT ON public.scans` (INSERT only — not UPDATE) that matches the new scan against hotlist entries and inserts live hit rows: `L` for direct hotlists on Repo Company orgs (`orgs.type=1`), `S` for Staging Hotlists (`hotlist.is_staged`, gated by `approval_list` / `orgs.silent_hit_generation`).
- **Clarity-hotlist relevance:** YES — this is the live hit-creation path; it defines what a scan↔hotlist match means, including staging-hotlist matches.
- **Wiki pages to re-verify:** [hit.md](hit.md) (How hits are created, Business rules, Where it lives in code), [hotlist.md](hotlist.md) (how hotlists produce live hits), [data-architecture.md](data-architecture.md) (trigger-driven flow).
- **Last verified:** `2026-06-26T16:33:00Z` against `composer.lock` ref `e29792387b31536539f4a50d3abc234aa4fce42d` (`dev-main`) — trigger events and `L`/`S` production confirmed against `database/schema/zod-schema.sql`.

### `find_historicals()` — PostgreSQL function (historical hit creation)

- **Owner:** `insight-lpr/laravel-zod-migrations`, applied to `zod.public`.
- **What it does:** trigger `AFTER INSERT OR UPDATE ON public.hotlist` (on **hotlist**, not on `hits`) that looks back through already-captured scans (within `hotlist.lookback_days`) for matches against the new/changed hotlist entry and inserts historical hit rows: `H` for direct (non-staged) hotlists, `Z` for Staging Hotlists (same `approval_list` / `silent_hit_generation` gating as `S`).
- **Clarity-hotlist relevance:** YES — defines historical hit creation, including staging-hotlist historical matches.
- **Wiki pages to re-verify:** [hit.md](hit.md) (How hits are created, Hit types `H`/`Z`, Business rules), [hotlist.md](hotlist.md) (staging hotlist → `Z`).
- **Last verified:** `2026-06-26T16:33:00Z` against `composer.lock` ref `e29792387b31536539f4a50d3abc234aa4fce42d` (`dev-main`) — trigger table/events and `H`/`Z` production confirmed against `database/schema/zod-schema.sql`.

### `zod.public.hotlist` — table columns / constraints

- **Owner:** `insight-lpr/laravel-zod-migrations` (schema); read by Clarity's `Hotlist` model.
- **What it does:** the hotlist table Clarity reads/writes from. Column changes (e.g. `is_staged`, plate fields, status) directly affect the [Hotlist](hotlist.md) domain model and Staging Hotlist behavior.
- **Clarity-hotlist relevance:** YES — this is the hotlist table.
- **Wiki pages to re-verify:** [hotlist.md](hotlist.md) (Key attributes/columns, Staging Hotlist), [multi-tenancy.md](multi-tenancy.md) (staging visibility if columns change), [data-architecture.md](data-architecture.md) (table→replica mapping).
- **Last verified:** `2026-06-26T16:33:00Z` against `composer.lock` ref `e29792387b31536539f4a50d3abc234aa4fce42d` (`dev-main`).

### `zod.public.scans` — table columns / constraints

- **Owner:** `insight-lpr/laravel-zod-migrations`; read by Clarity's `Scan` model; the trigger target for `find_hits()`.
- **What it does:** the scans table. Column changes affect the [Scan](scan.md) domain model and what `find_hits()` can match on.
- **Clarity-hotlist relevance:** PARTIAL — only columns that feed hotlist matching (plate, kit, geohash, timestamp) are in scope. Non-hotlist scan columns are out of scope.
- **Wiki pages to re-verify:** [scan.md](scan.md) (Key attributes), [hit.md](hit.md) (only if matching-relevant columns change).
- **Last verified:** `2026-06-26T16:33:00Z` against `composer.lock` ref `e29792387b31536539f4a50d3abc234aa4fce42d` (`dev-main`).

### `zod.public.hits` — table columns / constraints

- **Owner:** `insight-lpr/laravel-zod-migrations`; read by Clarity's `Hit` model; the trigger target for `find_historicals()`.
- **What it does:** the hits table. Column changes affect the [Hit](hit.md) domain model. **Note:** `hit_type` values themselves are defined in this repo's `App\Enums\HitTypeEnum`, NOT in the package — so hit_type enum drift is tracked via [repositories.md](repositories.md), not here.
- **Clarity-hotlist relevance:** PARTIAL — columns that link hits to hotlists (`hotlist_id`, `org_id`, `scan_id`, `kit_id`) are in scope; unrelated columns are out of scope.
- **Wiki pages to re-verify:** [hit.md](hit.md) (Key attributes/columns), [data-architecture.md](data-architecture.md) (table→replica mapping).
- **Last verified:** `2026-06-26T16:33:00Z` against `composer.lock` ref `e29792387b31536539f4a50d3abc234aa4fce42d` (`dev-main`).

## Out of scope (explicit)

These are NOT tracked here, even though they live in the same package, because they do not pertain to Clarity hotlists:

- `zod.public.kits`, `zod.public.orgs`, `zod.public.scans_metadata` — Clarity reads these, but their drift does not change hotlist business rules. (If a Kit/Org change later affects hotlist staging/sharing, add a surface here at that time.)
- `HitTypeEnum` values — owned in this repo (`app/Enums/HitTypeEnum.php`); drift is caught by `evaluate wiki against code`, not this package sync.
- Black Adam enums (`FeatureEnum`, `HotlistStatusEnum`, `OrgType`) — owned by `insight-lpr/black-adam`; tracked via [repositories.md](repositories.md), not here. (Add a separate drift-watch section only if Black Adam begins driving hotlist business rules the wiki must mirror.)

## Maintenance

- The sync workflow updates `last_verified` per surface and appends a [log.md](../log.md) entry. Do not edit `last_verified` outside a sync run.
- If a new hotlist-relevant surface appears in the package (e.g. a new `find_*` function or a new hotlist table), add it here during a sync run with a `last_verified` stamp — and add the wiki pages it affects.
- If a tracked surface is removed from the package, mark it `**Status:** removed (date)` and reconcile the wiki pages that referenced it.
