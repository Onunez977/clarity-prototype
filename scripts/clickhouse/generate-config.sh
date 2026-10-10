#!/bin/sh
# Generate config file from template by replacing environment variables

cat /config.template.yml | \
  sed "s|\${PG_DATABASE_HOSTNAME}|${PG_DATABASE_HOSTNAME}|g" | \
  sed "s|\${PG_DATABASE_PORT}|${PG_DATABASE_PORT}|g" | \
  sed "s|\${PG_DATABASE_USER}|${PG_DATABASE_USER}|g" | \
  sed "s|\${PG_DATABASE_PASSWORD}|${PG_DATABASE_PASSWORD}|g" | \
  sed "s|\${PG_DATABASE_NAME}|${PG_DATABASE_NAME}|g" | \
  sed "s|\${CLICKHOUSE_SERVER_URL}|${CLICKHOUSE_SERVER_URL}|g" | \
  sed "s|\${CLICKHOUSE_SERVER_PORT}|${CLICKHOUSE_SERVER_PORT}|g" | \
  sed "s|\${CLICKHOUSE_SERVER_USER}|${CLICKHOUSE_SERVER_USER}|g" | \
  sed "s|\${CLICKHOUSE_SERVER_PASSWORD}|${CLICKHOUSE_SERVER_PASSWORD}|g" \
  > /config.yml

echo "Config file generated successfully"
cat /config.yml | head -n 50
