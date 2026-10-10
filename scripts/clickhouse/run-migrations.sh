#!/bin/sh
set -e  # Exit on error

# Configuration
CLICKHOUSE_DB="${CLICKHOUSE_MIGRATIONS_DB:-chlocal}"
CLICKHOUSE_HOST="${CLICKHOUSE_HOST:-localhost}"
CLICKHOUSE_PORT="${CLICKHOUSE_PORT:-8123}"
CLICKHOUSE_USERNAME="${CLICKHOUSE_USERNAME:-}"
CLICKHOUSE_PASSWORD="${CLICKHOUSE_PASSWORD:-}"
CLICKHOUSE_SKIP_DB_CREATE="${CLICKHOUSE_SKIP_DB_CREATE:-0}"
MIGRATIONS_DIR="${MIGRATIONS_DIR:-}"
MAX_WAIT=180
POLL_INTERVAL=5

# Build the base URL
CLICKHOUSE_URL="http://${CLICKHOUSE_HOST}:${CLICKHOUSE_PORT}"

# Helper to build curl auth args
curl_auth_args() {
  if [ -n "$CLICKHOUSE_USERNAME" ]; then
    echo "--user ${CLICKHOUSE_USERNAME}:${CLICKHOUSE_PASSWORD}"
  fi
}

# Helper function to run ClickHouse queries via HTTP API
run_query() {
  local query="$1"
  local response_file
  local http_code
  response_file=$(mktemp)

  # Run curl, capture response body and HTTP status code
  # Send raw SQL in POST body (not form-encoded)
  http_code=$(curl -s -w "%{http_code}" $(curl_auth_args) \
    -o "$response_file" \
    "${CLICKHOUSE_URL}/" -d "${query}" 2>&1)

  if [ "$http_code" -ge 200 ] && [ "$http_code" -lt 300 ]; then
    rm -f "$response_file"
    return 0
  else
    echo "Query failed (HTTP $http_code): $query" >&2
    if [ -s "$response_file" ]; then
      echo "ClickHouse error:" >&2
      cat "$response_file" >&2
    fi
    rm -f "$response_file"
    return 1
  fi
}

# Helper function to run ClickHouse queries and get result
run_query_result() {
  local query="$1"
  local response_file
  local http_code
  response_file=$(mktemp)

  # Send raw SQL in POST body (not form-encoded)
  http_code=$(curl -s -w "%{http_code}" $(curl_auth_args) \
    -o "$response_file" \
    "${CLICKHOUSE_URL}/" -d "${query}" 2>&1)

  if [ "$http_code" -ge 200 ] && [ "$http_code" -lt 300 ]; then
    cat "$response_file"
    rm -f "$response_file"
  else
    echo "Query failed (HTTP $http_code): $query" >&2
    if [ -s "$response_file" ]; then
      echo "ClickHouse error:" >&2
      cat "$response_file" >&2
    fi
    rm -f "$response_file"
    echo ""
  fi
}

# Helper function to run a SQL file (multiquery)
run_sql_file() {
  local file="$1"
  local response_file
  local http_code
  response_file=$(mktemp)

  http_code=$(curl -s -w "%{http_code}" $(curl_auth_args) \
    -o "$response_file" \
    "${CLICKHOUSE_URL}/?multiquery=1" --data-binary "@${file}" 2>&1)

  if [ "$http_code" -ge 200 ] && [ "$http_code" -lt 300 ]; then
    rm -f "$response_file"
    return 0
  else
    echo "SQL file execution failed (HTTP $http_code): $file" >&2
    if [ -s "$response_file" ]; then
      echo "ClickHouse error:" >&2
      cat "$response_file" >&2
    fi
    rm -f "$response_file"
    return 1
  fi
}

echo "================================================"
echo "ClickHouse Migration Runner"
echo "================================================"
echo "Connecting to: ${CLICKHOUSE_URL}"

# Resolve migrations directory with fallback priority:
# 1. Docker volume /migrations (CI/production)
# 2. Symlink database/clickhouse/migrations (local dev via sync-chlocal.sh)
# 3. Direct path database/clickhouse/migrations/core (legacy)
# 4. Bundled database/schema/clickhouse-schema.sql (CI fallback)
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
BUNDLED_SCHEMA="${SCRIPT_DIR}/../../database/schema/clickhouse-schema.sql"
USE_BUNDLED_SCHEMA=false

if [ -z "$MIGRATIONS_DIR" ]; then
  if [ -d "/migrations" ]; then
    # CI/production: Docker volume mount
    MIGRATIONS_DIR="/migrations"
    echo "Using Docker volume migrations"
  elif [ -L "${SCRIPT_DIR}/../../database/clickhouse/migrations" ] && [ -d "${SCRIPT_DIR}/../../database/clickhouse/migrations" ]; then
    # Local dev: Symlink to chlocal (preferred)
    MIGRATIONS_DIR="${SCRIPT_DIR}/../../database/clickhouse/migrations"
    echo "Using chlocal migrations via symlink"
  elif [ -d "${SCRIPT_DIR}/../../database/clickhouse/migrations/core" ]; then
    # Legacy: Direct path
    MIGRATIONS_DIR="${SCRIPT_DIR}/../../database/clickhouse/migrations/core"
    echo "Using legacy direct migrations path"
  elif [ -f "$BUNDLED_SCHEMA" ]; then
    # Fallback: Bundled schema file
    USE_BUNDLED_SCHEMA=true
    echo "Warning: No migrations directory found, using bundled schema"
  else
    echo "ERROR: No migrations found. Options:"
    echo "  1. Mount /migrations volume (CI/production)"
    echo "  2. Run scripts/clickhouse/sync-chlocal.sh (local dev)"
    echo "  3. Place migrations in database/clickhouse/migrations/core"
    echo "  4. Ensure database/schema/clickhouse-schema.sql exists"
    exit 1
  fi
fi

# Handle bundled schema fallback separately
if [ "$USE_BUNDLED_SCHEMA" = true ]; then
  echo "Schema file: ${BUNDLED_SCHEMA}"
else
  echo "Migrations directory: ${MIGRATIONS_DIR}"
fi

# Wait for ClickHouse to be ready
elapsed=0
while [ $elapsed -lt $MAX_WAIT ]; do
  if curl -sf $(curl_auth_args) "${CLICKHOUSE_URL}/?query=SELECT%201" > /dev/null 2>&1; then
    echo "✓ ClickHouse connection successful (${elapsed}s)"
    break
  fi
  echo "Waiting for ClickHouse... (${elapsed}s)"
  sleep $POLL_INTERVAL
  elapsed=$((elapsed + POLL_INTERVAL))
done

if [ $elapsed -ge $MAX_WAIT ]; then
  echo "ERROR: ClickHouse not ready after ${MAX_WAIT}s"
  exit 1
fi

echo ""
echo "Initializing migrations tracking..."

# Create database
if [ "$CLICKHOUSE_SKIP_DB_CREATE" = "1" ]; then
  DB_EXISTS=$(run_query_result "SELECT count() FROM system.databases WHERE name = '${CLICKHOUSE_DB}'" | tr -d '[:space:]')
  DB_EXISTS="${DB_EXISTS:-0}"
  if [ "$DB_EXISTS" = "0" ]; then
    echo "✗ Database '${CLICKHOUSE_DB}' does not exist (CLICKHOUSE_SKIP_DB_CREATE=1)"
    exit 1
  fi
  echo "↪ Skipping database creation (CLICKHOUSE_SKIP_DB_CREATE=1)"
else
  if ! run_query "CREATE DATABASE IF NOT EXISTS ${CLICKHOUSE_DB}"; then
    echo "✗ Failed to create database '${CLICKHOUSE_DB}' at ${CLICKHOUSE_URL}"
    exit 1
  fi
fi

# Create migrations table
if ! run_query "CREATE TABLE IF NOT EXISTS ${CLICKHOUSE_DB}.migrations (name String) ENGINE = MergeTree ORDER BY name"; then
  echo "✗ Failed to create migrations table in database '${CLICKHOUSE_DB}'"
  exit 1
fi

# Verify table exists
TABLE_EXISTS=$(run_query_result "SELECT count() FROM system.tables WHERE database = '${CLICKHOUSE_DB}' AND name = 'migrations'" | tr -d '[:space:]')
TABLE_EXISTS="${TABLE_EXISTS:-0}"

if [ "$TABLE_EXISTS" = "0" ]; then
  echo "✗ Migrations table verification failed"
  exit 1
fi

echo "✓ Migrations tracking initialized"

echo ""
echo "Running migrations..."
echo ""

# Handle bundled schema vs migration files
if [ "$USE_BUNDLED_SCHEMA" = true ]; then
  # Bundled schema fallback - run single schema file
  SCHEMA_NAME=$(basename "$BUNDLED_SCHEMA")

  echo "Checking bundled schema: ${SCHEMA_NAME}"

  # Check if schema has already been applied
  ALREADY_RUN=$(run_query_result "SELECT count() FROM ${CLICKHOUSE_DB}.migrations WHERE name = '${SCHEMA_NAME}'" | tr -d '[:space:]')
  ALREADY_RUN="${ALREADY_RUN:-0}"

  if [ "$ALREADY_RUN" != "0" ]; then
    echo "  ⊘ Skipping (already applied)"
  else
    echo "  → Running bundled schema..."

    if run_sql_file "$BUNDLED_SCHEMA"; then
      if run_query "INSERT INTO ${CLICKHOUSE_DB}.migrations (name) VALUES ('${SCHEMA_NAME}')"; then
        echo "  ✓ Bundled schema applied successfully"
      else
        echo "  ✗ Failed to record schema application"
        exit 1
      fi
    else
      echo "  ✗ Bundled schema execution failed!"
      exit 1
    fi
  fi
else
  # Standard migration files processing
  # Get list of migration files sorted by filename
  MIGRATION_FILES=$(ls -1 "${MIGRATIONS_DIR}"/*.sql 2>/dev/null | sort || true)

  if [ -z "$MIGRATION_FILES" ]; then
    echo "No migration files found in ${MIGRATIONS_DIR}"
    echo ""
    echo "================================================"
    echo "Migrations complete!"
    echo "================================================"
    exit 0
  fi

  # Process each migration file
  echo "$MIGRATION_FILES" | while IFS= read -r migration_file; do
    if [ -z "$migration_file" ]; then
      continue
    fi

    # Extract just the filename
    migration_name=$(basename "$migration_file")

    echo "Checking migration: ${migration_name}"

    # Check if migration has already been run
    ALREADY_RUN=$(run_query_result "SELECT count() FROM ${CLICKHOUSE_DB}.migrations WHERE name = '${migration_name}'" | tr -d '[:space:]')
    ALREADY_RUN="${ALREADY_RUN:-0}"

    if [ "$ALREADY_RUN" != "0" ]; then
      echo "  ⊘ Skipping (already applied)"
      continue
    fi

    echo "  → Running migration..."

    # Run the migration file via HTTP API
    if run_sql_file "$migration_file"; then
      # Record the migration as completed
      if run_query "INSERT INTO ${CLICKHOUSE_DB}.migrations (name) VALUES ('${migration_name}')"; then
        echo "  ✓ Migration applied successfully"
      else
        echo "  ✗ Failed to record migration"
        exit 1
      fi
    else
      echo "  ✗ Migration failed!"
      exit 1
    fi

    echo ""
  done
fi

echo "================================================"
echo "Migrations complete!"
echo "================================================"
echo ""

# Show applied migrations
echo "Applied migrations:"
APPLIED=$(run_query_result "SELECT name FROM ${CLICKHOUSE_DB}.migrations ORDER BY name FORMAT TSVRaw")

if [ -n "$APPLIED" ] && ! echo "$APPLIED" | grep -q "Exception"; then
  echo "$APPLIED" | while IFS= read -r migration; do
    if [ -n "$migration" ]; then
      echo "  - ${migration}"
    fi
  done
else
  echo "  (none)"
fi

echo ""
