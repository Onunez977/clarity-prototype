# Laravel ClickHouse Eloquent - Project Overview

## Purpose
A PHP package that integrates ClickHouse with Laravel's database layer using a built-in cURL HTTP client. No external ClickHouse libraries required.

## Two Usage Paths

### 1. Eloquent Model (standard Laravel)
- Use `Illuminate\Database\Eloquent\Model` with `$connection = 'clickhouse'`
- Full Laravel Eloquent API: relationships, scopes, etc.
- Query flows through: Eloquent Builder -> LaravelClickhouseEloquent\Builder -> QueryGrammar -> Connection -> ClickhouseHttpClient

### 2. BaseModel (lightweight ORM)
- Does NOT extend Eloquent's Model
- Uses Laravel traits: `HasAttributes`, `HidesAttributes`, `HasEvents`
- Key methods: `create()`, `save()`, `insertBulk()`, `insertAssoc()`, `select()`, `where()`, `groupBy()`, `delete()`, `update()`, `optimize()`, `truncate()`

## Core Components

### ClickhouseHttpClient (`src/ClickhouseHttpClient.php`)
- Minimal cURL-based HTTP client (replaces smi2/phpclickhouse + the-tinderbox/clickhouse-builder)
- `select()` returns `array[]`, `write()` returns `bool`, `insert()`, `insertAssocBulk()`
- Client-side binding substitution: supports both `?` and `:N` placeholders
- Handles retries, timeouts, HTTPS, auth headers

### Connection (`src/Connection.php`)
- Extends `Illuminate\Database\Connection` using `ClickhouseHttpClient` instead of PDO
- `select()` returns arrays directly (no `->rows()` call)
- `statement()` returns `bool`

### Builder (`src/Builder.php`)
- Extends `Illuminate\Database\Query\Builder` (Laravel's standard builder)
- ClickHouse-specific: `final()`, `sample()`, `preWhere()`, `limitBy()`, `arrayJoin()`, `settings()`, CTEs
- `delete()`/`update()` compile to `ALTER TABLE ... DELETE/UPDATE`

### QueryGrammar (`src/QueryGrammar.php`)
- Extends `Illuminate\Database\Query\Grammars\Grammar`
- Backtick identifier wrapping
- Compiles: FINAL, SAMPLE, ARRAY JOIN, PREWHERE, LIMIT BY, SETTINGS, CTEs
- Standard `?` placeholder passthrough

## Table Properties
- `$table` - Table for SELECT queries
- `$tableForInserts` - Table for INSERT (e.g., buffer table)
- `$tableSources` - Table for DELETE/UPDATE/OPTIMIZE

## Dependencies
- `php: >=8.1`
- `ext-curl`, `ext-json`
- `illuminate/support: >=9`, `illuminate/database: >=9`
- Zero external ClickHouse libraries
