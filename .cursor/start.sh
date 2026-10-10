#!/usr/bin/env bash
# Per-boot Cloud Agent start: Docker daemon + PostGIS 17 / ClickHouse / Redis.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DOCKER=(docker)
if docker info >/dev/null 2>&1; then
    :
elif sudo docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
else
    sudo service docker start || true
    for _ in $(seq 1 30); do
        if docker info >/dev/null 2>&1; then
            break
        fi
        if sudo docker info >/dev/null 2>&1; then
            DOCKER=(sudo docker)
            break
        fi
        sleep 1
    done
fi
"${DOCKER[@]}" info >/dev/null 2>&1

COMPOSE=("${DOCKER[@]}" compose -f .cursor/docker-compose.cloud.yml)

"${COMPOSE[@]}" up -d --wait --remove-orphans

postgres_cid="$("${COMPOSE[@]}" ps -q postgres)"
clickhouse_cid="$("${COMPOSE[@]}" ps -q clickhouse)"

"${DOCKER[@]}" exec -i "$postgres_cid" psql -v ON_ERROR_STOP=1 -U root -d postgres <<'EOSQL'
SELECT 'CREATE DATABASE zod_testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'zod_testing')\gexec
EOSQL

for db in zod zod_testing; do
    "${DOCKER[@]}" exec "$postgres_cid" psql -v ON_ERROR_STOP=1 -U root -d "$db" -c 'CREATE SCHEMA IF NOT EXISTS platform;'
    "${DOCKER[@]}" exec "$postgres_cid" psql -v ON_ERROR_STOP=1 -U root -d "$db" -c 'CREATE EXTENSION IF NOT EXISTS postgis;'
done

if ! "${DOCKER[@]}" exec "$clickhouse_cid" clickhouse-client --query 'EXISTS TABLE clarity.scans_cold' 2>/dev/null | grep -qx 1; then
    "${DOCKER[@]}" exec -i "$clickhouse_cid" clickhouse-client --multiquery < database/schema/clickhouse-schema.sql
fi
