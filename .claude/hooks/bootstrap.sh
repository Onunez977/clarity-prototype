#!/usr/bin/env bash
# Claude Code SessionStart hook for the Clarity / platform project.
#
# Idempotently provisions a worktree on every session start:
#   - Only runs inside .claude/worktrees/<name> or .cursor/worktrees/<name>
#     (no-op in the main checkout, whose .env points at shared zod + CH Cloud)
#   - Copies seed env files if Desktop's native copier didn't already
#   - Writes branch-derived DB names + Reverb scaffolding into the worktree's .env
#   - createdb (dev + testing), composer install, npm install, migrate, seed
#   - herd link + secure
# Every step is guarded so re-runs are no-ops once the worktree is healthy.
#
# Output goes to stderr so SessionStart's stdout (shown to the user as
# additional context) stays brief.

set -uo pipefail

log() { echo "[bootstrap] $*" >&2; }

# ---------------------------------------------------------------------------
# 1. Guard: only run inside an isolated worktree checkout
# ---------------------------------------------------------------------------
PROJECT_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [ -z "$PROJECT_ROOT" ]; then
  exit 0
fi

case "$PROJECT_ROOT" in
  */.claude/worktrees/*) ;;
  */.cursor/worktrees/*) ;;
  *) exit 0 ;;
esac

# Belt-and-suspenders: confirm via porcelain this path is a registered worktree.
git worktree list --porcelain 2>/dev/null \
  | grep -qx "worktree $PROJECT_ROOT" || exit 0

cd "$PROJECT_ROOT"

# Locate the main worktree (first entry in porcelain output) for seed-file fallback copies.
MAIN_WORKTREE="$(git worktree list --porcelain | awk '/^worktree/ {print $2; exit}')"

# ---------------------------------------------------------------------------
# 2. Derive identifiers from the branch name (recoverable on teardown)
# ---------------------------------------------------------------------------
BRANCH="$(git branch --show-current 2>/dev/null || true)"
if [ -z "$BRANCH" ]; then
  log "no current branch; aborting"
  exit 0
fi

SAFE_BRANCH="$(printf '%s' "$BRANCH" | tr -c 'a-zA-Z0-9' '_' | tr '[:upper:]' '[:lower:]')"

# Length-safe per-worktree id. Postgres silently truncates identifiers at 63 chars,
# so a long branch prefixed with "zod_testing_" could overflow and two distinct long
# branches could collide on the same truncated name. Cap the slug and append a short
# deterministic hash of the full branch. The longest derived identifier is then
# "zod_testing_" (12) + WT_ID (<=40) = 52, comfortably under the limit. Short branches
# (the common case) are unchanged, so already-provisioned worktrees keep their names.
if [ "${#SAFE_BRANCH}" -le 40 ]; then
  WT_ID="$SAFE_BRANCH"
else
  WT_HASH="$(printf '%s' "$BRANCH" | shasum | cut -c1-6)"
  WT_ID="$(printf '%s' "$SAFE_BRANCH" | cut -c1-33)_${WT_HASH}"
fi

TEST_DB="zod_testing_${WT_ID}"
DEV_DB="zod_${WT_ID}"
HERD_SITE="clarity-${WT_ID}"

# ---------------------------------------------------------------------------
# 3. Seed env files if Desktop's copier didn't drop them in
# ---------------------------------------------------------------------------
if [ ! -f .env ] && [ -f "$MAIN_WORKTREE/.env" ]; then
  log "seeding .env from $MAIN_WORKTREE"
  cp "$MAIN_WORKTREE/.env" .env
fi

if [ ! -f .env.testing ] && [ -f "$MAIN_WORKTREE/.env.testing" ]; then
  log "seeding .env.testing from $MAIN_WORKTREE"
  cp "$MAIN_WORKTREE/.env.testing" .env.testing
fi

if [ ! -f auth.json ] && [ -f "$MAIN_WORKTREE/auth.json" ]; then
  cp "$MAIN_WORKTREE/auth.json" auth.json
fi

# ---------------------------------------------------------------------------
# 4. Branch-derived overrides written directly into the worktree's own .env.
#    .env IS the file Laravel loads; vanilla Laravel does NOT overlay .env.local
#    (its .env.{APP_ENV} feature only fires when APP_ENV is a real env var and it
#    *replaces* .env), so per-worktree isolation must live in .env itself. .env is
#    gitignored and a per-worktree copy, so editing it in place is safe.
# ---------------------------------------------------------------------------
set_env() { # key value file — replace KEY=... in place, or append if absent
  if grep -qE "^$1=" "$3"; then
    sed -i.bak -E "s|^$1=.*|$1=$2|" "$3" && rm -f "$3.bak"
  else
    printf '%s=%s\n' "$1" "$2" >> "$3"
  fi
}

if [ -f .env ]; then
  if ! grep -q "^DB_DATABASE=${DEV_DB}$" .env; then
    log "writing branch overrides into .env -> ${DEV_DB}"
    set_env DB_DATABASE                 "${DEV_DB}"                    .env
    set_env CLICKHOUSE_DATABASE_CLARITY "${DEV_DB}"                    .env
    set_env CLICKHOUSE_DATABASE_ZOD     "${DEV_DB}"                    .env
    set_env APP_URL                     "https://${HERD_SITE}.test"   .env
  fi

  # Reverb — shared Herd Reverb server (port 8080, single app). Per-worktree
  # isolation is by channel prefix, not app credentials. BROADCAST_CONNECTION
  # stays 'log' until a broadcasting driver is installed.
  if ! grep -q "^BROADCAST_CHANNEL_PREFIX=" .env; then
    log "adding Reverb scaffolding to .env"
    cat >> .env <<EOF

# Reverb — shared Herd Reverb server (port 8080, single app). Per-worktree
# isolation is by BROADCAST_CHANNEL_PREFIX, not app credentials.
REVERB_APP_ID=1001
REVERB_APP_KEY=laravel-herd
REVERB_APP_SECRET=secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
BROADCAST_CHANNEL_PREFIX=${WT_ID}
EOF
  fi
fi

# .env.local is NOT loaded by vanilla Laravel — remove any stale copy to avoid
# confusion (older bootstrap versions wrote one).
rm -f .env.local

# Rewrite .env.testing to the branch-derived testing DB (only if mismatched).
if [ -f .env.testing ] && ! grep -q "^DB_DATABASE=${TEST_DB}$" .env.testing; then
  log "rewriting .env.testing -> ${TEST_DB}"
  sed -i.bak -E "s/^DB_DATABASE=.*/DB_DATABASE=${TEST_DB}/"                                 .env.testing
  sed -i.bak -E "s/^CLICKHOUSE_DATABASE_CLARITY=.*/CLICKHOUSE_DATABASE_CLARITY=${TEST_DB}/" .env.testing
  sed -i.bak -E "s/^CLICKHOUSE_DATABASE_ZOD=.*/CLICKHOUSE_DATABASE_ZOD=${TEST_DB}/"         .env.testing
  rm -f .env.testing.bak
fi

# ---------------------------------------------------------------------------
# 5. Postgres — create only if absent
# ---------------------------------------------------------------------------
for db in "$DEV_DB" "$TEST_DB"; do
  if ! psql -h 127.0.0.1 -U root -lqt 2>/dev/null | cut -d'|' -f1 | tr -d ' ' | grep -qx "$db"; then
    log "createdb ${db}"
    createdb -h 127.0.0.1 -U root "$db" >&2 || log "  (createdb ${db} failed)"
  fi
done

# ---------------------------------------------------------------------------
# 6. composer install — guard on vendor/autoload.php
# ---------------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
  log "composer install"
  composer install --no-interaction --prefer-dist >&2
fi

# ---------------------------------------------------------------------------
# 7. npm install — guard on the lockfile inside node_modules (more reliable
#    than node_modules/ itself, which can linger after a partial install).
# ---------------------------------------------------------------------------
if [ ! -f node_modules/.package-lock.json ]; then
  log "npm install"
  npm install >&2
fi

# ---------------------------------------------------------------------------
# 8. Migrations + seed — only if the migrations table doesn't exist yet
# ---------------------------------------------------------------------------
if ! php artisan migrate:status --env=testing >/dev/null 2>&1; then
  log "dev:migrate --env=testing"
  php artisan dev:migrate --env=testing --no-interaction >&2
  php artisan db:seed --env=testing --force >&2 || log "  (db:seed --env=testing failed)"
  php artisan app:seed-permissions --env=testing >&2 || log "  (app:seed-permissions failed)"
fi

if ! php artisan migrate:status >/dev/null 2>&1; then
  log "dev:migrate (dev DB)"
  php artisan dev:migrate --no-interaction >&2
  php artisan db:seed --force >&2 || log "  (db:seed failed)"
fi

# ---------------------------------------------------------------------------
# 9. Herd link + secure — only if not already linked
# ---------------------------------------------------------------------------
if ! herd links 2>/dev/null | grep -qw "$HERD_SITE"; then
  log "herd link ${HERD_SITE}"
  herd link   "$HERD_SITE"      >&2 || true
  herd secure "${HERD_SITE}.test" >&2 || true
fi

log "ready: https://${HERD_SITE}.test"
exit 0
