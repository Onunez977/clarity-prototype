# syntax=docker/dockerfile:1.7

FROM dunglas/frankenphp:1.7-php8.3-bookworm AS php-base

WORKDIR /app

ENV APP_ENV=production \
    PHP_RUNTIME_FRANKENPHP=true \
    SERVER_NAME=:80

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
        zip \
    && install-php-extensions \
        bcmath \
        exif \
        gd \
        igbinary \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        soap \
        sockets \
        zip \
    && apt-get purge -y --auto-remove \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Composer dependencies ────────────────────────────────────────────────────
FROM php-base AS vendor

COPY composer.json composer.lock ./

# composer_auth (build secret) carries github-oauth for the private VCS packages
# insight-lpr/black-adam and insight-lpr/laravel-clickhouse.
RUN --mount=type=secret,id=composer_auth,required=false \
    set -eux; \
    if [ -f /run/secrets/composer_auth ]; then \
        mkdir -p /tmp/composer; \
        cp /run/secrets/composer_auth /tmp/composer/auth.json; \
        export COMPOSER_HOME=/tmp/composer; \
    fi; \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts; \
    rm -rf /tmp/composer

# ── Front-end assets (Vite + Tailwind v4) ────────────────────────────────────
FROM node:22-bookworm-slim AS assets

WORKDIR /app

ARG VITE_GOOGLE_MAPS_API_KEY=
ARG VITE_APP_NAME=Platform
ARG VITE_APP_URL=https://pre-platform.internal.insightlpr.io
ARG VITE_PUSHER_APP_KEY=
ARG VITE_PUSHER_APP_CLUSTER=mt1
ARG VITE_PUSHER_HOST=wss.insightlpr.io
ARG VITE_PUSHER_PORT=443
ARG VITE_PUSHER_SCHEME=https

ENV VITE_GOOGLE_MAPS_API_KEY="${VITE_GOOGLE_MAPS_API_KEY}" \
    VITE_APP_NAME="${VITE_APP_NAME}" \
    VITE_APP_URL="${VITE_APP_URL}" \
    VITE_PUSHER_APP_KEY="${VITE_PUSHER_APP_KEY}" \
    VITE_PUSHER_APP_CLUSTER="${VITE_PUSHER_APP_CLUSTER}" \
    VITE_PUSHER_HOST="${VITE_PUSHER_HOST}" \
    VITE_PUSHER_PORT="${VITE_PUSHER_PORT}" \
    VITE_PUSHER_SCHEME="${VITE_PUSHER_SCHEME}"

COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY scripts ./scripts
# Tailwind v4 scans app/Filament/**, resources/views/** and vendor/filament/**
# (see resources/css/filament/app/{theme.css,tailwind.config.js}) — those sources
# must be present or their utility classes get purged from the compiled theme.
COPY app ./app
COPY --from=vendor /app/vendor ./vendor

# `npm run build` also runs `php artisan filament:assets`, which needs PHP; that
# step is deferred to the app stage. Here we only do the JS/CSS build + SW stamp.
RUN npm ci --no-audit --no-fund \
    && npx vite build \
    && node scripts/version-sw.mjs

# ── Runtime image ─────────────────────────────────────────────────────────────
FROM php-base AS app

COPY composer.json composer.lock ./
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/public/clarity-scout-sw.js ./public/clarity-scout-sw.js
COPY docker/php/Caddyfile /etc/caddy/Caddyfile
COPY docker/php/entrypoint.sh /usr/local/bin/platform-container

RUN composer dump-autoload --optimize --no-scripts \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi \
    && php artisan filament:assets --ansi \
    && mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod +x /usr/local/bin/platform-container

EXPOSE 80

ENTRYPOINT ["platform-container"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
