---
type: Pattern
title: Testing Conventions
description: Clarity's sacred testing rules — the zod_testing DB, TestCase helpers (createUser, createClientPortalUser, makeOrgPro, makeUserPro), connectionsToTransact/Truncate, materialized-view refresh, the ClickHouse pgsql bridge, Filament test patterns, and anti-patterns. Corrected from the legacy .claude/docs/testing-best-practices.md.
resource: tests/TestCase.php
tags: [domain, lpr, testing, patterns, conventions]
timestamp: 2026-06-26T20:10:00Z
---

# Testing Conventions

The non-negotiable testing rules for Clarity. **Do not run tests automatically** (per `CLAUDE.md`/`AGENTS.md`) — only when explicitly asked. When you do run them, use the testing DB and the helpers below.

> Supersedes the legacy `.claude/docs/testing-best-practices.md`. The `$connectionsToTransact` list there was wrong (it included `clarity_clickhouse`); the real list is `['zod', 'platform']`.

## Test DB + env

- Tests run against the **`zod_testing`** database (configured in `.env.testing`, never dev/production). PostgreSQL connections `zod` and `platform` point at it.
- `phpunit.xml` forces: `APP_ENV=testing`, `QUEUE_CONNECTION=sync`, `PENNANT_STORE=array`, `CACHE_STORE=array`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`, `PULSE_ENABLED=false`, `TELESCOPE_ENABLED=false`. So jobs run inline, mail is captured, Pennant features are in-memory.
- **ClickHouse pgsql bridge**: set `CLICKHOUSE_DRIVER=pgsql` (in `.env.testing`) so the `clarity_clickhouse`/`public_clickhouse` connections run against a PostgreSQL `clarity_testing` DB. `scopeFinal()` is a no-op on pgsql; query classes branch on the driver. See [ClickHouse Integration](clickhouse-integration.md) and [ClickHouse Query Patterns](clickhouse-query-patterns.md). ClickHouse-gated widgets **skip** when no ClickHouse is configured.

## `tests/TestCase.php` — the contract

`TestCase` uses `RefreshDatabase` + `HasZodDatabase` (`DatabaseTruncation`). Per-test `setUp()`:

- Sets default TZ to `UTC`.
- Runs `app:seed-permissions` (seeds the permission tree — required before assigning roles).
- `Model::unguard()`.
- `Feature::flushCache()` (Pennant).
- `$this->withoutVite()`.

### Transaction scope (corrected)

```php
protected function connectionsToTransact(): array { return ['zod', 'platform']; }
```

Only **`zod` and `platform`** are wrapped in the test transaction. `HasZodDatabase::$connectionsToTruncate = ['zod']` (the `zod` connection is truncated between tests). `clarity_clickhouse` is **not** in the transaction list — ClickHouse-side test data is handled via the pgsql bridge / factories, not transactional rollback.

### Materialized views are read-only in tests

`HasZodDatabase::setupHasZodDatabase()` refreshes the PostgreSQL materialized views the tests read from: `aggregate_kit_scan_path_h`, `aggregate_address_heatmap`, `aggregate_address_geohash`, `recovery_address`, `recoveries` (on the `zod` connection). **Create source records and let the infrastructure refresh the views** — do not insert into materialized views directly.

### Helpers (use these, don't reinvent)

| Helper | What it does |
|--------|--------------|
| `createUser(array $attrs = [], $role = null, $perms = null, bool $pro = false): User` | Creates an Org + User for that org, calls `setPermissionsTeamId($user->org_id)` (required before role/permission assignment), optionally assigns a role/permissions, and if `$pro=true` calls `makeOrgPro($org)`. |
| `createClientPortalUser(array $attrs = [], $role = null, bool $portalEnabled = true, bool $withAccessGrant = true): User` | Creates a **client** Org (`Org::factory()->client()`), optionally disables `enable_client_portal`, optionally creates a `ClientOrgAccess` grant to a repo org, then the user. Sets permissions team id. |
| `makeOrgPro(Org $org, ?string $feature = null): void` | Enables Pro features on the org via `org_meta.properties` (`platform_repo_pro => true` + each `FeatureEnum` value => true). **Excludes `CLICKHOUSE_SCANS`** by default (ClickHouse is opt-in). Pass a single `$feature` to enable just one. |
| `makeUserPro(User $user, ?string $feature = null): void` | Like `makeOrgPro` but on the user's org, also removes the `CLIENT` role and clears the `org_pro_enabled_*` / `org_pro_features_*` cache keys, then reloads `org.meta`. |

> **`setPermissionsTeamId` is mandatory** before `assignRole`/`syncPermissions` — both helpers do this for you. If you create users manually, call `setPermissionsTeamId($user->org_id)` yourself or role checks will misbehave (permission team scoping).

## Factories

ClickHouse models have factories under `Database/Factories/Clickhouse/` (e.g. `ScanFactory`, referenced by `Scan::newFactory()`). Use them for the pgsql-bridge path. PostgreSQL models use standard `Database/Factories/*`. Prefer factories over raw inserts; respect guarded/fillable since `Model::unguard()` is on in tests.

## Filament testing

Suites live in `tests/Filament/` (and `tests/Roles/` for role-gated behavior). Patterns in use:

- `$this->actingAs($user)` then `livewire(...)` / `FilamentResource` page assertions.
- Use `createUser($role=...)` / `createClientPortalUser(...)` to set up the panel viewer (admin `/app` vs client `/client`).
- For Pro-gated pages, `makeOrgPro($org, FeatureEnum::SOME_FEATURE->value)` to enable just that feature (avoids accidentally turning on ClickHouse).
- Assert table filters / actions via the standard Filament testing helpers; pair with `HasProFeature` redirect assertions (see [Filament Conventions](filament-conventions.md)).

## Anti-patterns (don't)

- ❌ Don't run tests against dev/production — always `zod_testing` via `.env.testing`.
- ❌ Don't insert into materialized views — create source rows and let `HasZodDatabase` refresh.
- ❌ Don't assign roles/permissions without `setPermissionsTeamId` (use the helpers, which handle it).
- ❌ Don't enable `CLICKHOUSE_SCANS` via `makeOrgPro` and assume a ClickHouse server is present — it's excluded by default for a reason; opt in explicitly.
- ❌ Don't mock the ClickHouse connection when the pgsql bridge exists — use `CLICKHOUSE_DRIVER=pgsql` for real query coverage.
- ❌ Don't run tests automatically; only on explicit request.

## Where it lives in code

- Base: `tests/TestCase.php`
- DB trait: `tests/Support/HasZodDatabase.php`
- Config: `phpunit.xml`, `.env.testing`
- Suites: `tests/Unit/`, `tests/Feature/`, `tests/Filament/`, `tests/Roles/`
- Factories: `database/factories/`, `database/factories/Clickhouse/`

## See also

- [ClickHouse Integration](clickhouse-integration.md) — the pgsql test bridge
- [ClickHouse Query Patterns](clickhouse-query-patterns.md) — driver-aware SQL
- [Client Analytics](client-analytics.md) — skipping ClickHouse-gated widgets
- [Filament Conventions](filament-conventions.md) — Pro-feature gating under test
- [DTO and Enums](dto-and-enums.md) — `FeatureEnum` values used by `makeOrgPro`
