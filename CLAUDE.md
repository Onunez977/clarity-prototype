# Clarity — Agent Operating Guide

**Clarity** is a Laravel 12 + Filament 5 application for license plate recognition operations — scans, hits, hotlists, and recoveries — on PostgreSQL + ClickHouse with a multi-tenant, organization-scoped architecture.

## The OKF wiki — READ before you build, WRITE only after approval

The OKF wiki at [`okf/`](okf/index.md) is the **canonical source for Clarity domain knowledge**: business rules, domain concepts, data architecture, engineering patterns, and the cross-repo registry.

### READ — required, before design or implementation

- **Before any data-touching work** — read [`okf/index.md`](okf/index.md) and [`okf/concepts/data-architecture.md`](okf/concepts/data-architecture.md).
- **Before modifying or extending an existing class** — read (a) that class's docblock and (b) the okf page that names it: `rg -l '<ClassName>' okf/`. Do this *before* proposing a design, not while reviewing one.
- **Before adding a query, command, or job that iterates production data** — read the concept pages for the tables involved and check [`okf/concepts/platform-gotchas.md`](okf/concepts/platform-gotchas.md) for the failure already catalogued there.
- **When a ticket names a table, command, or query class** — grep `okf/` for it first. If a page exists, it is part of the ticket's required reading.

This is not advisory.

### WRITE — only after the change is approved

**Do not write to `okf/` while work is in flight.** Update the wiki only once the change is merged or has passed final review/QA — whichever gate the task uses.

The wiki is a source of truth, not a scratchpad. During DEV-2342 it was updated mid-flight with a 90-minute lock value and a *"the concurrency is load-bearing"* conclusion. Both were reverted or withdrawn within a day, leaving the canonical page teaching a design that never shipped and reasoning that contributed to an outage.

If you learn something durable before that gate, hold it in the ticket or PR and land it in the wiki afterwards. The `okf-nudge` PostToolUse hook fires on edit — treat it as a reminder to **queue** the update, not to make it now.

Run `php scripts/okf-lint.php` before landing any wiki edit. See [`okf/CONTRIBUTING.md`](okf/CONTRIBUTING.md) for ingest/query/lint and cross-repo search procedures.

### Precedence

When guidance conflicts, trust in this order:

1. **`okf/`** — canonical for domain knowledge (business rules, concepts, data architecture, patterns).
2. **Manifests and code** — `composer.json`, `package.json`, and `app/`, `config/`, `database/`, `tests/`. Authoritative for **executable behavior**; **wins over the wiki on code conflicts**.
3. **`README.md`** and this file.

The legacy `.claude/docs/`, `.claude/skills/` knowledge companions, and `.serena/memories/` were migrated into the wiki and deleted.

## Essential Context

- **Framework**: Laravel 12 + Filament 5 (`composer.json` is authoritative for versions)
- **PHP**: production runs **8.3** — keep app code 8.3-compatible even though local/CI run newer (see `okf/concepts/platform-gotchas.md` PG-021)
- **Databases**:
  - PostgreSQL with custom `platform` schema in the `zod` database (transactional)
  - ClickHouse Cloud for analytics (`clarity` and `zod` databases) — treat ad-hoc queries as **read-only** by default
- **Multi-tenancy**: organization-based access control; be careful with role, permission, and org-bound data access
- **Panels**: two Filament panels — `app` at `/app` (admin) and `client` at `/client` (client portal)
- **Testing**: uses the `zod_testing` database (configured in `.env.testing`)
- **Key packages**: Black Adam (custom LPR package), Horizon, Pennant, Nightwatch

Current manifest versions — **do not trust version numbers in older docs without checking the manifests**: Laravel 12 · PHP 8.3 · Filament 5 · Livewire 4 · Tailwind 4 · Vite 6.

## Cursor Cloud specific instructions

Cloud Agents use the committed config in [`.cursor/environment.json`](.cursor/environment.json). The snapshot image is [`.cursor/Dockerfile`](.cursor/Dockerfile) (PHP 8.4, Node 22, Composer, Docker). That is **not** the production FrankenPHP `Dockerfile` at the repo root.

### Git identity (Grok / Cloud bots)

Cursor Cloud agents and Grok bots **must not commit as a human**. Author and committer are `Cursor Agent` `<cursoragent@cursor.com>` only — never Andrew Jorgenson (`git@jorgenson.dev`) or any other engineer's identity, even if that is what `git config user.*` currently says. Override per-commit with `git -c user.name="Cursor Agent" -c user.email="cursoragent@cursor.com"` when needed. See [`.cursor/rules/git-identity.mdc`](.cursor/rules/git-identity.mdc).

### Data services

`start` (`.cursor/start.sh`) starts Docker if needed, then:

```bash
docker compose -f .cursor/docker-compose.cloud.yml up -d --wait
```

| Service | Image | Ports | Defaults |
| --- | --- | --- | --- |
| PostgreSQL + PostGIS | `postgis/postgis:17-3.5` | 5432 | user `root`, password `postgres`, databases `zod` and `zod_testing`, schema `platform`, PostGIS enabled |
| ClickHouse | `clickhouse/clickhouse-server:26.2` | 8123 HTTP, 9000 native | user `default`, empty password, databases `clarity` and `zod` (CI dump loaded from `database/schema/clickhouse-schema.sql`) |
| Redis | `redis:7.4-alpine` | 6379 | no password; **standalone** (`redis-server --save "" --appendonly no`). phpunit uses array/sync — do not copy prod `REDIS_HOST_1`/`_2` cluster |

Postgres 17 is an intentional upgrade from CI (`postgis/postgis:16-3.5`). ClickHouse matches platform CI (`26.2`, not overwatch `24.8`). Redis matches Kamal’s image tag but **not** prod cluster mode.

If services are down: rerun `.cursor/start.sh` (idempotent).

### Env files

`.cursor/install.sh` copies `.env.example` to `.env` and `.env.testing` when missing, then overlays:

```
DB_CONNECTION=platform
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=zod          # .env.testing uses zod_testing
DB_USERNAME=root
DB_PASSWORD=postgres
DB_SEARCH_PATH=platform
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
CLICKHOUSE_DRIVER=clickhouse
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=8123
CLICKHOUSE_HTTPS=false
CLICKHOUSE_DATABASE_CLARITY=clarity
CLICKHOUSE_DATABASE_ZOD=zod
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
CLICKHOUSE_WRITE_USERNAME=default
CLICKHOUSE_WRITE_PASSWORD=
```

**Do not commit `.env` or `.env.testing`.** Official Postgres images reject an empty password, so `postgres` is the compose default (same as CI).

`.env.example` keeps `QUEUE_CONNECTION=sync` and `CACHE_STORE=file`. phpunit forces array/sync. Horizon’s prod Redis cluster is not part of this VM.

### Migrate, app, tests

After services are healthy:

```bash
php artisan key:generate          # install.sh does this when APP_KEY is empty
php artisan dev:migrate --seed    # zod (dev)
php artisan dev:migrate --env=testing   # zod_testing
php artisan serve --host=0.0.0.0 --port=8000
```

Tests use `zod_testing` via `.env.testing`. Never `--parallel`. Do not run the full suite unless asked; if you smoke-check, use `.env.testing` and a single file/filter.

ClickHouse schema is loaded by `start` (skip if `clarity.scans_cold` already exists).

### Required Cursor Secrets

Set these on the Cloud Agent environment (names only — never commit values):

| Secret | Required | Purpose |
| --- | --- | --- |
| `COMPOSER_AUTH_GITHUB_PAT` | **yes** | GitHub PAT with read access to private VCS packages `insight-lpr/laravel-zod-migrations`, `insight-lpr/black-adam`, and `insight-lpr/laravel-clickhouse`. Same role as GitHub Actions secret `COMPOSER_AUTH_GITHUB_PAT`. `install` runs `composer config --global github-oauth.github.com`. |

CI also defines `COMPOSER_AUTH_FILAMENT_FILTER_USERNAME` and `COMPOSER_AUTH_FILAMENT_FILTER_PASSWORD`; current `composer install` does not use them.

Optional product keys (`GOOGLE_MAPS_API_KEY`, Twilio, AWS, Pusher, etc.) live in `.env.example` and are not required to install, migrate, or run PHPUnit.

`repositoryDependencies` in `environment.json` asks Cursor to include the three private GitHub repos on the generated GitHub token. Composer still needs `COMPOSER_AUTH_GITHUB_PAT`.

## Repo Hotspots

Start with the nearest relevant area instead of scanning the whole app:

- `app/Filament/Resources/`, `app/Filament/Pages/`, `app/Filament/Widgets/` — main admin UI
- `app/Filament/Client/` — client portal UI
- `app/Providers/Filament/App.php` / `Client.php` — the `/app` and `/client` panels
- `app/Clickhouse/` — analytics models, queries, scopes, views
- `app/Models/`, `app/Services/`, `app/Jobs/`, `app/Policies/` — core domain behavior
- `tests/Filament/`, `tests/Feature/`, `tests/Unit/` — examples and regression coverage

## Working Rules

- Make the smallest coherent change that solves the request.
- Follow existing patterns before introducing new abstractions — and confirm the pattern exists by reading it, not by assuming.
- Prefer targeted reads with `rg` and nearby files over broad exploration.
- Do not silently "fix" unrelated drift.
- If a request touches both PostgreSQL and ClickHouse paths, verify which side is authoritative before editing.
- If you rely on a repo convention, cite the file that proves it in your notes or PR summary.
- Cursor Cloud / Grok bots never commit under a human git identity (see Git identity above).

## Execution Policy

- **Never run unit tests automatically.** CI (GitHub Actions) runs tests after commit; run them only when explicitly requested.
- When tests are requested: use the `.env.testing` configuration, and never point tests at development or production data.
- Prefer existing helpers from `tests/TestCase.php` — `createUser()`, `createClientPortalUser()`, `makeOrgPro()`, `makeUserPro()`.
- When assigning roles or permissions in tests, ensure the permissions team ID is set (the helpers already handle this).
- Materialized views are read-only in tests — create source records and let the test infrastructure refresh dependent views.

## Quick Commands

```bash
composer install
npm install
php artisan dev:migrate --seed   # setup
npm run dev                      # development
npm run build
composer format                  # or: pint
composer analyse
php scripts/okf-lint.php         # before landing wiki edits
```

Run `php artisan test` or `composer test` **only when explicitly requested**.

## Useful Internal Docs

Read only what the task needs — all in the OKF wiki (`okf/concepts/`):

- `data-architecture.md` — PostgreSQL vs ClickHouse (**read first for data work**)
- `multi-tenancy.md`, `organization.md`, `kit.md`, `scan.md`, `hit.md`, `hotlist.md`, `recovery.md`, `missed-opportunity.md`
- `clickhouse-integration.md`, `clickhouse-query-patterns.md`, `client-analytics.md`
- `filament-conventions.md`, `jobs-and-queues.md`, `dto-and-enums.md`, `events-and-observers.md`, `alert-settings.md`
- `testing-conventions.md`, `architecture-decisions.md`, `platform-gotchas.md`
- `repositories.md`, `drift-watch.md`

## Change Hygiene

- Keep docs and code aligned when you touch behavior that agent-facing docs describe — but land wiki edits **after** approval, per the WRITE rule above.
- If you find stale docs while changing behavior, note them in the ticket; correct them in the same post-approval pass.
- Changes to this file must be mirrored into the other (`CLAUDE.md` ↔ `AGENTS.md`).

---

## ⚠ Before you write Laravel, Filament, Livewire, or Tailwind code — READ THIS

**The coding standards for this repo are NOT in this file.** They live in:

### [`okf/concepts/laravel-boost-guidelines.md`](okf/concepts/laravel-boost-guidelines.md)

**You must read that page before writing or modifying any of the following.** It is not
background reading and it is not optional — it is the standard your code is reviewed against:

| If you are touching… | Read it for |
| --- | --- |
| **PHP** — any class, method, constructor, enum, PHPDoc | constructor property promotion, explicit return/param types, when comments are and aren't wanted, enum conventions |
| **Laravel** — models, controllers, validation, queues, config, auth | "Do Things the Laravel Way", Eloquent over raw queries, `config()` over `env()` outside config files, Form Requests, named routes, eager loading |
| **Laravel 12 specifics** — structure, migrations, models | the v12 skeleton (no `Kernel.php`, `bootstrap/app.php` wiring), `casts()` method over `$casts`, `php artisan make:*` for everything |
| **Filament** | panel conventions and the `make:filament-*` generators (also `okf/concepts/filament-conventions.md`) |
| **Livewire** | Livewire 4 patterns, state, wire directives, testing |
| **Tailwind** | Tailwind 4 syntax, replaced utilities, spacing, dark mode |
| **Tests** | test enforcement rules, PHPUnit conventions, factory usage |
| **Pint / formatting** | run `composer format` (Pint) before finishing — non-negotiable |

**Do not guess a convention from surrounding code.** Much of this repo predates the current
standards; matching a neighboring file can reproduce a pattern the guidelines now forbid.
If the guidelines and nearby code disagree, the guidelines win for new code.

That page is **generated** by `laravel/boost` — do not hand-edit it. If a
`<laravel-boost-guidelines>` block ever reappears in this file, it was re-added by
`boost:install`; move it back into the okf page and delete it here.
`php scripts/okf-lint.php` fails if it reappears.
