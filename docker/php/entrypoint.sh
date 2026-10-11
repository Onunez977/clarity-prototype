#!/usr/bin/env sh
set -e

mkdir -p \
    /app/bootstrap/cache \
    /app/storage/app/public \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data /app/bootstrap/cache /app/storage
fi

exec "$@"
