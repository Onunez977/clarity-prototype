# syntax=docker/dockerfile:1.7
# Prototype image. Private Insight packages are vendored under packages/, so
# this build does not need a GitHub token.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY packages ./packages
RUN composer install --no-interaction --prefer-dist --no-scripts

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY scripts ./scripts
COPY app ./app
COPY --from=vendor /app/vendor ./vendor
RUN npm ci --no-audit --no-fund \
    && npx vite build \
    && node scripts/version-sw.mjs

FROM php:8.3-cli-bookworm
WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu-dev libxml2-dev postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl soap zip gd pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY docker/prototype/entrypoint.sh /usr/local/bin/prototype-entrypoint

RUN chmod +x /usr/local/bin/prototype-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && php artisan filament:assets --ansi

EXPOSE 8080
ENTRYPOINT ["prototype-entrypoint"]
