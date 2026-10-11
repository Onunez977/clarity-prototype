# Codebase Structure

```
laravel-clickhouse-eloquent/
├── src/                              # Main source code
│   ├── ClickhouseHttpClient.php      # cURL HTTP client (core - replaces external libs)
│   ├── Connection.php                # Database connection handler
│   ├── Builder.php                   # Query builder with ClickHouse extensions
│   ├── QueryGrammar.php              # SQL grammar (backticks, PREWHERE, FINAL, etc.)
│   ├── SchemaGrammar.php             # CREATE TABLE compilation
│   ├── SchemaBuilder.php             # Schema operations (hasTable via system.tables)
│   ├── BaseModel.php                 # Lightweight ORM base class
│   ├── Migration.php                 # Migration base class
│   ├── RawColumn.php                 # Raw SQL expression with aliasing
│   ├── WithClient.php                # Client access trait
│   ├── ClickhouseServiceProvider.php # Laravel service provider
│   ├── Exceptions/
│   │   ├── ClickhouseException.php   # Base RuntimeException
│   │   └── QueryException.php        # Query error handling
│   └── Expressions/
│       ├── InsertExpression.php       # Interface for insert values
│       └── InsertArray.php            # Array type helper for INSERT
│
├── tests/
│   ├── TestCase.php                  # Base test class
│   ├── CreatesApplication.php        # Laravel app bootstrap
│   ├── Unit/
│   │   └── ConnectionEscapeTest.php  # Unit test (no DB required)
│   ├── Integration/
│   │   ├── ClickHouseCloudTest.php   # Cloud integration tests
│   │   └── OverwatchQueryTest.php    # Real-world query pattern tests
│   ├── BaseTest.php                  # Core functionality tests (local CH)
│   ├── UpdateTest.php                # ALTER TABLE UPDATE tests
│   ├── CastsTest.php                 # Boolean casting tests
│   ├── CteTest.php                   # CTE SQL generation + execution
│   ├── MultiInstancesTest.php        # Multi-connection tests
│   ├── Models/
│   │   ├── Example.php               # BaseModel, default connection
│   │   ├── Example2.php              # BaseModel, clickhouse2 connection
│   │   ├── Example3.php              # BaseModel, boolean casts
│   │   ├── Camera.php                # BaseModel, cloud connection
│   │   ├── HitHot.php                # Full Eloquent Model, cloud
│   │   └── ScanHot.php               # Full Eloquent Model, cloud
│   ├── migrations/                   # Test table migrations
│   ├── config/                       # Test config files
│   └── docker/                       # Docker build files
│
├── composer.json                     # PHP dependencies (no external CH libs)
├── phpunit.xml                       # PHPUnit configuration
├── docker-compose.test.yaml          # Test environment
├── README.md                         # User documentation
└── CLAUDE.md                         # AI assistant guidance
```

## Key Files by Purpose

### HTTP & Connection
- `ClickhouseHttpClient.php` - All HTTP communication with ClickHouse
- `Connection.php` - Laravel database connection integration

### Query Building & Compilation
- `Builder.php` - Query building with ClickHouse extensions
- `QueryGrammar.php` - SQL compilation (FINAL, PREWHERE, SAMPLE, LIMIT BY, SETTINGS, CTEs)

### ORM
- `BaseModel.php` - Lightweight ORM (non-Eloquent)
- Full Eloquent Model also supported via `$connection = 'clickhouse'`

### Schema & Migrations
- `SchemaBuilder.php` - Schema operations
- `SchemaGrammar.php` - DDL compilation
- `Migration.php` - Migration base class

### Infrastructure
- `ClickhouseServiceProvider.php` - Laravel driver registration
- `WithClient.php` - Client access trait
