#!/usr/bin/env bash
# Boot the Clarity concept prototype. Does not talk to Insight production.
set -euo pipefail

cd "$(dirname "$0")/.."
PORT="${APP_PORT:-8080}"

if [[ "${PROTOTYPE_DOCKER:-}" == "1" ]]; then
  exec docker compose up --build
fi

need() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing '$1'. Install it, or run: PROTOTYPE_DOCKER=1 ./scripts/prototype-up.sh"
    exit 1
  fi
}

need php
need composer
need npm

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

if ! grep -qE '^APP_KEY=base64:' .env; then
  php artisan key:generate --force --ansi
fi

composer install --no-interaction --prefer-dist
npm ci --no-audit --no-fund
npx vite build
node scripts/version-sw.mjs

if ! redis-cli ping >/dev/null 2>&1; then
  if command -v redis-server >/dev/null 2>&1; then
    redis-server --daemonize yes --bind 127.0.0.1 --port 6379
  else
    echo "Redis is not running. Start redis-server on 127.0.0.1:6379."
    exit 1
  fi
fi

if ! php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try { DB::connection("zod")->getPdo(); }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
'; then
  cat <<'MSG'
Cannot connect to Postgres. Create the local database, then re-run:

  sudo -u postgres psql -c "CREATE ROLE platform LOGIN SUPERUSER PASSWORD 'platform';"
  sudo -u postgres psql -c "CREATE DATABASE zod OWNER platform;"

Postgres 17 with PostGIS is required (the schema dump is from Postgres 17).
MSG
  exit 1
fi

SEEDED=$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\Schema::connection("platform")->hasTable("users") ? "yes" : "no";
')

if [[ "$SEEDED" != "yes" || "${PROTOTYPE_RESEED:-}" == "1" ]]; then
  echo "Migrating and seeding sample data (this replaces the local zod database)..."
  php -d memory_limit=1G artisan dev:migrate --seed --no-ansi
fi

echo "Clarity prototype: http://127.0.0.1:${PORT}/app/login"
echo "Demo email: test@tester.com  (Send OTP — local env skips the code)"
exec php -d memory_limit=512M artisan serve --host=127.0.0.1 --port="${PORT}"
