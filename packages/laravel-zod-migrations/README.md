# laravel-zod-migrations

Shared PostgreSQL **Zod** schema dumps and migration helpers for Insight LPR Laravel apps (Guardian/Overwatch, Clarity/Platform, Backyard).

## Contents

- `database/schema/zod-schema.sql` — canonical public + cross-app schema dump (sourced from Guardian)
- `database/schema/testing-schema.sql` — CI/testing dump
- `database/migrations/public/` — incremental migrations on the shared `public` schema
- `ZodSchemaMigrator` — orchestrates `migrate:fresh` / `migrate:refresh` across `public` + app schema connections

## Install

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-zod-migrations",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "insight-lpr/laravel-zod-migrations": "@dev"
    }
}
```

Publish config (optional — defaults work for Platform):

```bash
php artisan vendor:publish --tag=zod-migrations-config
```

## Per-app config

**Platform** (`config/zod-migrations.php`):

```php
'app_connection' => 'platform',
'app_schema' => 'platform',
```

**Guardian/Overwatch**:

```php
'app_connection' => 'overwatch',
'app_schema' => 'overwatch',
```

## Usage

```bash
# Fresh public + app schemas (default connection must be the app schema connection)
php artisan migrate:fresh --seed

# Dev helper (Platform)
php artisan dev:migrate --seed
```

## Updating the canonical schema

Regenerate from Guardian against pre-prod/prod, then copy into this package:

```bash
# In overwatch
docker compose run --rm schema-dump

# Sync into package
cp database/schema/overwatch-schema.sql ../laravel-zod-migrations/database/schema/zod-schema.sql
cp database/schema/testing-schema.sql ../laravel-zod-migrations/database/schema/testing-schema.sql
```

Commit the package, then `composer update insight-lpr/laravel-zod-migrations` in each app.
