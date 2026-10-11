#!/usr/bin/env bash
set -euo pipefail
cd /app

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

if ! grep -qE '^APP_KEY=base64:' .env; then
  php artisan key:generate --force --ansi
fi

echo "Waiting for Postgres..."
for _ in $(seq 1 60); do
  if php -r '
    try {
      new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
      exit(0);
    } catch (Throwable $e) { exit(1); }
  '; then
    break
  fi
  sleep 2
done

SEEDED=$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo Illuminate\Support\Facades\Schema::connection("platform")->hasTable("users") ? "yes" : "no";
' || echo no)

if [[ "$SEEDED" != "yes" ]]; then
  php -d memory_limit=1G artisan dev:migrate --seed --no-ansi
fi

exec php -d memory_limit=512M artisan serve --host=0.0.0.0 --port=8080
