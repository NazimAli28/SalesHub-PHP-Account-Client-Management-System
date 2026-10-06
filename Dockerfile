# syntax=docker/dockerfile:1
# SalesHub: one image, one origin. Laravel (FrankenPHP) serves /api, /sanctum, /docs/api and the
# built React app. See docs/deployment.md.

# ---- 1. Frontend build -------------------------------------------------------------------------
FROM node:24-slim AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
# true shows the quick-login buttons for the demo accounts; keep false for a real deployment.
ARG VITE_DEMO_MODE=false
ENV VITE_DEMO_MODE=${VITE_DEMO_MODE}
RUN npm run build

# ---- 2. PHP base (runtime + extensions) --------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base
RUN install-php-extensions pdo_sqlite pdo_mysql intl zip bcmath opcache \
    && apt-get update && apt-get install -y --no-install-recommends tini ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# ---- 3. Composer dependencies ------------------------------------------------------------------
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---- 4. Runtime --------------------------------------------------------------------------------
FROM base AS runtime
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080 \
    PORT=8080 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database/saleshub.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    XDG_CONFIG_HOME=/tmp/xdg-config \
    XDG_DATA_HOME=/tmp/xdg-data

RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=64'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'memory_limit=128M'; \
      echo 'expose_php=0'; \
    } > /usr/local/etc/php/conf.d/zz-saleshub.ini

WORKDIR /app
COPY --from=vendor --chown=www-data:www-data /app ./
# The SPA: static files straight into public/, index.html kept out of it so Laravel serves it
# (security headers, no-cache).
COPY --from=frontend --chown=www-data:www-data /build/dist/ ./public/
RUN mkdir -p resources/spa \
    && mv public/index.html resources/spa/index.html \
    && mkdir -p storage/app storage/database storage/framework/cache storage/framework/sessions \
       storage/framework/views storage/logs bootstrap/cache /tmp/xdg-config /tmp/xdg-data \
    && rm -f .env \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache /tmp/xdg-config /tmp/xdg-data \
    && setcap CAP_NET_BIND_SERVICE=+ep /usr/local/bin/frankenphp

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD php -r '$c=@file_get_contents("http://127.0.0.1:".(getenv("PORT")?:8080)."/up"); exit($c===false?1:0);'
ENTRYPOINT ["tini", "--", "/usr/local/bin/entrypoint.sh"]
