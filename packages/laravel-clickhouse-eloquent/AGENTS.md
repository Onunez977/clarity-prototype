# AGENTS Instructions

## Purpose
- This repo builds a native Laravel ClickHouse package using a built-in HTTP client (cURL) — no external ClickHouse libraries. It provides both a standard Eloquent connection and a lightweight BaseModel ORM with ClickHouse-specific query features.
- Document any architectural or UX decisions (design docs, usage notes) in `docs/` with clear dates and owner initials.

## Workflows
- Before designing or implementing new functionality, run the `brainstorming` skill: define intent, surface alternatives, and validate via small sections.
- Use `systematic-debugging` for reproducing or isolating bugs before proposing fixes.
- Prefer short, focused plans via `update_plan` when work spans multiple files or logical steps.
- Run tests after changes that touch query building, HTTP client, or grammar logic:
  ```bash
  php vendor/bin/phpunit
  ```

## Repo norms
- Keep PHP style consistent with the existing Laravel conventions (PSR-12 style, short helper functions).
- Prefer adding tests in `tests/Unit/` for isolated logic and `tests/Integration/` for live ClickHouse queries.
- Don't duplicate logic elsewhere; extract shared helpers when needed.
- The Builder extends Laravel's `Illuminate\Database\Query\Builder` — maintain compatibility with Laravel's query builder API.
- The QueryGrammar extends Laravel's base Grammar — ClickHouse-specific clauses (FINAL, SAMPLE, PREWHERE, LIMIT BY, SETTINGS, CTEs) are injected via `compileSelect()` override.
- The ClickhouseHttpClient handles binding substitution (both `?` and `:N` placeholders) and value escaping — keep this logic centralized there.
