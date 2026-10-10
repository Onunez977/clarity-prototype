#!/bin/bash
# First-boot init for PostGIS 17. start.sh also applies these idempotently.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE SCHEMA IF NOT EXISTS platform;
    CREATE EXTENSION IF NOT EXISTS postgis;
EOSQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "CREATE DATABASE zod_testing;"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname zod_testing <<-EOSQL
    CREATE SCHEMA IF NOT EXISTS platform;
    CREATE EXTENSION IF NOT EXISTS postgis;
EOSQL
