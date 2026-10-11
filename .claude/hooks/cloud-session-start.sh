#!/usr/bin/env bash
# shellcheck disable=SC2329 # functions are invoked through step "$@"
# SessionStart hook for claude.ai/code cloud sessions. Exits immediately anywhere
# else, so local sessions are untouched. scripts/cloud/setup.sh installs the
# toolchain; the snapshot keeps files, not processes, so every session has to
# start services, write the env files, bootstrap once per VM, and serve the app.
# Every step is guarded, so a resume on a live VM is a fast no-op.
set -uo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "${BASH_SOURCE[0]}")/../..}" || exit 0

LOG="storage/logs/cloud-session-start.log"
mkdir -p storage/logs
: >"$LOG"

APP_HOST="clarity-cloud.test"
APP_PORT=8000
APP_URL="http://${APP_HOST}:${APP_PORT}"
CH_URL="http://127.0.0.1:8123"
DB_READY="/tmp/clarity-cloud-session-db-ready"
summary=()

step() {
    local name="$1"
    shift
    echo "==> ${name}" >>"$LOG"
    if "$@" >>"$LOG" 2>&1; then
        summary+=("ok    ${name}")
    else
        summary+=("FAIL  ${name} (see ${LOG})")
    fi
}

ch() {
    curl -sS --fail-with-body "$CH_URL/" --data-binary "$1"
}

set_env() {
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=" "$file"; then
        sed -i -E "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}

start_postgres() {
    pg_isready -q -h 127.0.0.1 || service postgresql start
    for _ in $(seq 1 30); do
        pg_isready -q -h 127.0.0.1 && return 0
        sleep 1
    done
    return 1
}

start_redis() {
    redis-cli ping >/dev/null 2>&1 || service redis-server start
}

start_clickhouse() {
    if ! ch 'SELECT 1' >/dev/null 2>&1; then
        mkdir -p /var/run/clickhouse-server
        chown clickhouse /var/run/clickhouse-server
        su clickhouse -s /bin/sh -c 'clickhouse-server --daemon --config-file=/etc/clickhouse-server/config.xml --pid-file=/var/run/clickhouse-server/clickhouse-server.pid'
    fi
    for _ in $(seq 1 60); do
        # Refreshable views restart enabled with the server and would append into seeded tables.
        ch 'SELECT 1' >/dev/null 2>&1 && ch 'SYSTEM STOP VIEWS' && return 0
        sleep 1
    done
    return 1
}

# /__qa/login only answers on a .test host.
add_host_alias() {
    grep -qE "[[:space:]]${APP_HOST}\$" /etc/hosts || echo "127.0.0.1 ${APP_HOST}" >>/etc/hosts
}

install_php_deps() {
    [ -f vendor/autoload.php ] && return 0
    composer install --no-interaction --no-progress --prefer-dist
}

# DB/ClickHouse settings live here, not in the claude.ai environment variables:
# real env vars override .env.testing, which would point tests at the dev DB.
write_env_files() {
    local file database
    for file in .env .env.testing; do
        [ -f "$file" ] || cp .env.example "$file"
        database=zod
        [ "$file" = .env.testing ] && database=zod_testing

        set_env "$file" APP_URL "$APP_URL"
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
        set_env "$file" CLICKHOUSE_HTTPS false
        set_env "$file" CLICKHOUSE_DATABASE_CLARITY clarity
        set_env "$file" CLICKHOUSE_DATABASE_ZOD zod
        set_env "$file" CLICKHOUSE_USERNAME default
        set_env "$file" CLICKHOUSE_PASSWORD "''"
        set_env "$file" CLICKHOUSE_WRITE_USERNAME default
        set_env "$file" CLICKHOUSE_WRITE_PASSWORD "''"
        set_env "$file" REDIS_CLIENT phpredis
        set_env "$file" REDIS_HOST 127.0.0.1
        set_env "$file" REDIS_PORT 6379
        set_env "$file" REDIS_PASSWORD null
        set_env "$file" QUEUE_CONNECTION sync
        set_env "$file" MAIL_MAILER log
        set_env "$file" FORCE_HTTPS false
    done

    set_env .env APP_ENV local
    set_env .env DEV_LOGIN_ENABLED true
    set_env .env.testing APP_ENV testing

    grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force --no-interaction
    set_env .env.testing APP_KEY "$(sed -n 's/^APP_KEY=//p' .env | head -n1)"
}

bootstrap_databases() {
    [ -f "$DB_READY" ] && return 0
    php artisan app:preview-bootstrap --no-interaction \
        && php artisan app:preview-bootstrap --env=testing --schema-only --skip-clickhouse --no-interaction \
        && touch "$DB_READY"
}

serve_app() {
    curl -sf -o /dev/null "http://127.0.0.1:${APP_PORT}/up" && return 0
    setsid nohup php artisan serve --host=127.0.0.1 --port="$APP_PORT" >storage/logs/artisan-serve.log 2>&1 </dev/null &
    for _ in $(seq 1 30); do
        curl -sf -o /dev/null "http://127.0.0.1:${APP_PORT}/up" && return 0
        sleep 1
    done
    return 1
}

step "Postgres" start_postgres
step "Redis" start_redis
step "ClickHouse (refreshable views stopped)" start_clickhouse
step "/etc/hosts ${APP_HOST}" add_host_alias
step "composer install" install_php_deps
step ".env + .env.testing + APP_KEY" write_env_files
step "databases (app:preview-bootstrap)" bootstrap_databases
step "app on ${APP_URL}" serve_app

echo "Clarity cloud session setup:"
printf '  %s\n' "${summary[@]}"
echo "  Log in: ${APP_URL}/__qa/login/1 (test@tester.com). After ClickHouse tests, re-run: php artisan app:preview-bootstrap"
exit 0
