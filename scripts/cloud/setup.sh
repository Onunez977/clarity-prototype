#!/usr/bin/env bash
# Setup script for the claude.ai/code "Clarity" cloud environment. Paste this
# file into the environment's Setup script box; this copy is the source of truth.
# It runs as root once per environment snapshot and installs toolchain + services
# only. Starting services, .env, migrating and seeding happen per session in
# .claude/hooks/cloud-session-start.sh.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

PG_VERSION=17
CLICKHOUSE_VERSION=26.2

apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg lsb-release software-properties-common jq unzip git

# PHP 8.3 — production's version (okf PG-021), even though CI runs 8.4.
add-apt-repository -y ppa:ondrej/php

install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
    >/etc/apt/sources.list.d/pgdg.list

curl -fsSL https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key | gpg --dearmor --yes -o /usr/share/keyrings/clickhouse-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg] https://packages.clickhouse.com/deb stable main" \
    >/etc/apt/sources.list.d/clickhouse.list

apt-get update -qq
apt-get install -y -qq \
    php8.3-cli php8.3-bcmath php8.3-curl php8.3-gd php8.3-igbinary php8.3-intl php8.3-mbstring \
    php8.3-pgsql php8.3-redis php8.3-soap php8.3-sqlite3 php8.3-xml php8.3-zip \
    "postgresql-${PG_VERSION}" "postgresql-${PG_VERSION}-postgis-3" "postgresql-${PG_VERSION}-postgis-3-scripts" \
    redis-server
apt-get install -y -qq \
    "clickhouse-common-static=${CLICKHOUSE_VERSION}.*" "clickhouse-server=${CLICKHOUSE_VERSION}.*" "clickhouse-client=${CLICKHOUSE_VERSION}.*"
update-alternatives --set php /usr/bin/php8.3

if ! command -v composer >/dev/null; then
    curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# The base image may ship an older Postgres cluster on 5432; keep only ours.
for cluster in $(pg_lsclusters -h | awk -v v="$PG_VERSION" '$1 != v {print $1"/"$2}'); do
    pg_dropcluster --stop "${cluster%/*}" "${cluster#*/}"
done
pg_lsclusters -h | grep -q "^${PG_VERSION} main" || pg_createcluster "$PG_VERSION" main
pg_conftool "$PG_VERSION" main set port 5432
service postgresql restart

su postgres -c psql <<'SQL'
DO $$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'root') THEN
        CREATE ROLE root SUPERUSER LOGIN PASSWORD 'postgres';
    END IF;
END $$;
SELECT 'CREATE DATABASE zod OWNER root' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'zod')\gexec
SELECT 'CREATE DATABASE zod_testing OWNER root' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'zod_testing')\gexec
SQL

for db in zod zod_testing; do
    su postgres -c "psql -d $db -c 'CREATE SCHEMA IF NOT EXISTS platform AUTHORIZATION root; CREATE EXTENSION IF NOT EXISTS postgis;'"
done

service postgresql stop
