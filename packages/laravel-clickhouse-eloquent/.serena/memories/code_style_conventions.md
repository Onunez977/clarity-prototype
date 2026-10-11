# Code Style and Conventions

## PHP Code Style

### Namespace
- Root namespace: `LaravelClickhouseEloquent`
- PSR-4 autoloading: `src/` maps to `LaravelClickhouseEloquent\`

### Class Structure
- One class per file
- Class names match file names (PascalCase)
- Use `declare(strict_types=1);` in tests

### Method Naming
- Getters: `getTable()`, `getTableForInserts()`, `getCasts()`
- Static factory methods: `create()`, `make()`, `select()`, `where()`
- Fluent interface pattern for query building

### Properties
- Protected visibility by default
- Type hints used for class properties
- PHPDoc blocks for complex types

### Type Hints
- Return type declarations used (`: string`, `: array`, `: bool`)
- Nullable types with `?` prefix
- Use `mixed` or omit for dynamic types

### Documentation
- PHPDoc for public methods
- `@param` and `@return` annotations
- Brief descriptions for complex logic

### SQL Output Conventions
- SQL keywords are lowercase (`select`, `from`, `where`) per Laravel convention
- Identifiers wrapped in backticks (`` `table`.`column` ``)
- `toSql()` returns SQL with `?` placeholders
- `toRawSql()` returns SQL with values substituted

## Test Style

### Test Classes
- Extend `Tests\TestCase`
- One test class per feature area
- Method names: `testFeatureDescription()`

### Test Organization
- `tests/Unit/` - No database required
- `tests/Integration/` - Requires ClickHouse Cloud (`CLICKHOUSE_CLOUD_HOST`)
- Root `tests/` - Requires local ClickHouse

### Assertions
- Use PHPUnit assertions
- `assertEquals()`, `assertNotEmpty()`, `assertTrue()`

## Migration Style
- Extend `LaravelClickhouseEloquent\Migration`
- Use raw SQL via `static::write()`
- Include both `up()` and `down()` methods
