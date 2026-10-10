# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel ClickHouse Eloquent is a PHP package that integrates ClickHouse with Laravel's database layer. It uses a built-in HTTP client (cURL) with no external ClickHouse libraries required.

Two ways to use it:
- **Eloquent Model** — Standard `Illuminate\Database\Eloquent\Model` with `$connection = 'clickhouse'`
- **BaseModel** — Lightweight ORM with ClickHouse-specific features (not extending Eloquent's Model)

## Running Tests

Tests require a ClickHouse Cloud connection (or local ClickHouse) configured via `.env`:

```bash
# Run all tests
php vendor/bin/phpunit

# Integration tests only (requires live ClickHouse)
php vendor/bin/phpunit tests/Integration/

# Unit tests only
php vendor/bin/phpunit tests/Unit/
```

## Architecture

### Key Components

**ClickhouseHttpClient** (`src/ClickhouseHttpClient.php`)
- Minimal cURL-based HTTP client for ClickHouse
- Methods: `select()` returns `array`, `write()` returns `bool`, `insert()`, `insertAssocBulk()`
- Client-side binding substitution: supports both `?` (standard) and `:N` (legacy) placeholders
- Value escaping: null→`NULL`, int/float→raw, string→single-quote escaped, bool→`1`/`0`
- Handles retries, timeouts, HTTPS, auth headers

**Connection** (`src/Connection.php`)
- Extends `Illuminate\Database\Connection` but uses `ClickhouseHttpClient` instead of PDO
- Registered via `ClickhouseServiceProvider` as the `clickhouse` driver
- `select()` returns arrays directly (no `->rows()` call needed)
- `statement()` returns `bool`

**Builder** (`src/Builder.php`)
- Extends `Illuminate\Database\Query\Builder` (Laravel's standard query builder)
- ClickHouse-specific methods: `final()`, `sample()`, `preWhere()`, `preWhereIn()`, `preWhereBetween()`, `preWhereRaw()`, `limitBy()`, `arrayJoin()`, `leftArrayJoin()`, `settings()`
- CTE support: `withCte()`, `withCteExpression()`
- `delete()` and `update()` compile to `ALTER TABLE ... DELETE/UPDATE`
- `get()` returns `Collection`, `getRows()` returns plain array

**QueryGrammar** (`src/QueryGrammar.php`)
- Extends `Illuminate\Database\Query\Grammars\Grammar`
- Uses backtick identifier wrapping (ClickHouse-compatible)
- Compiles ClickHouse-specific clauses: FINAL, SAMPLE, ARRAY JOIN, PREWHERE, LIMIT BY, SETTINGS, CTEs
- Standard `?` placeholder passthrough (no `:0` conversion)

**BaseModel** (`src/BaseModel.php`)
- Lightweight ORM base class (not extending Eloquent's Model)
- Uses Laravel traits: `HasAttributes`, `HidesAttributes`, `HasEvents`
- Supports: `create()`, `save()`, `insertBulk()`, `insertAssoc()`, `select()`, `where()`, `groupBy()`, `delete()`, `update()`, `optimize()`, `truncate()`

### Table Properties

Models support three table-related properties:
- `$table` - Table for SELECT queries
- `$tableForInserts` - Table for INSERT (e.g., buffer table)
- `$tableSources` - Table for DELETE/UPDATE/OPTIMIZE (the source table behind a buffer)

### Query Flow

**Eloquent path** (standard Laravel models):
```
Model::where()->get()
    → Illuminate\Database\Eloquent\Builder
    → LaravelClickhouseEloquent\Builder (query builder)
    → QueryGrammar compiles SQL with ? placeholders
    → Connection::select() passes SQL + bindings to ClickhouseHttpClient
    → Client substitutes bindings, sends HTTP request
    → Returns array of associative arrays
```

**BaseModel path**:
```
BaseModel::where()->get()
    → LaravelClickhouseEloquent\Builder
    → QueryGrammar compiles SQL
    → ClickhouseHttpClient executes
    → Returns Collection or array
```

## Development Notes

- Migrations extend `LaravelClickhouseEloquent\Migration` and use raw SQL via `static::write()`
- Column casting only supports `boolean` type currently
- Model events supported: `creating`, `created`, `saved`
- `RawColumn` extends `Illuminate\Database\Query\Expression` for raw SQL with aliasing
- `InsertArray` helper handles ClickHouse Array data type insertion
- `toSql()` returns SQL with `?` placeholders; `toRawSql()` returns SQL with values substituted
- SQL output uses lowercase keywords (`select`, `from`, `where`) per Laravel convention
- Identifiers are wrapped in backticks (`` `table`.`column` ``)
