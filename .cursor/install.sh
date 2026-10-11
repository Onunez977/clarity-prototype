#!/usr/bin/env bash
# Idempotent Cloud Agent install: Composer + npm, plus a local .env if missing.
set -euo pipefail

command -v pandoc >/dev/null || { sudo apt-get update -qq && sudo apt-get install -y --no-install-recommends pandoc; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ -n "${COMPOSER_AUTH_GITHUB_PAT:-}" ]]; then
    composer config --global github-oauth.github.com "${COMPOSER_AUTH_GITHUB_PAT}"
fi

set_env() {
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=" "$file"; then
        sed -i -E "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

apply_ci_local_defaults() {
    local file="$1"
    local database="$2"

    set_env "$file" DB_CONNECTION platform
    set_env "$file" DB_HOST 127.0.0.1
    set_env "$file" DB_PORT 5432
    set_env "$file" DB_DATABASE "$database"
    set_env "$file" DB_USERNAME root
    set_env "$file" DB_PASSWORD postgres
    set_env "$file" DB_SEARCH_PATH platform

    set_env "$file" CLICKHOUSE_DRIVER clickhouse
    set_env "$file" CLICKHOUSE_HOST 127.0.0.1
    set_env "$file" CLICKHOUSE_PORT 8123
    set_env "$file" CLICKHOUSE_DATABASE_CLARITY clarity
    set_env "$file" CLICKHOUSE_DATABASE_ZOD zod
    set_env "$file" CLICKHOUSE_USERNAME default
    set_env "$file" CLICKHOUSE_PASSWORD ''
    set_env "$file" CLICKHOUSE_HTTPS false
    set_env "$file" CLICKHOUSE_WRITE_USERNAME default
    set_env "$file" CLICKHOUSE_WRITE_PASSWORD ''

    set_env "$file" REDIS_CLIENT phpredis
    set_env "$file" REDIS_HOST 127.0.0.1
    set_env "$file" REDIS_PORT 6379
    set_env "$file" REDIS_PASSWORD null
}

if [[ ! -f .env ]]; then
    cp .env.example .env
fi
if [[ ! -f .env.testing ]]; then
    cp .env.example .env.testing
fi

apply_ci_local_defaults .env zod
apply_ci_local_defaults .env.testing zod_testing
set_env .env APP_URL 'http://127.0.0.1:8000'
set_env .env.testing APP_ENV testing
set_env .env.testing APP_URL 'http://127.0.0.1:8000'

composer install --no-interaction --prefer-dist --no-progress
npm install

if ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

app_key="$(sed -n 's/^APP_KEY=//p' .env | head -n1)"
if [[ -n "$app_key" ]]; then
    set_env .env.testing APP_KEY "$app_key"
fi
