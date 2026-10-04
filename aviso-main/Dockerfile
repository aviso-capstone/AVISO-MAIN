# AVISO admin + rider/IoT API — single container for DigitalOcean App Platform.
# nginx (8080) → php-fpm, plus Reverb (127.0.0.1:8081) and the queue worker,
# all supervised by supervisord.

# ---------- 1. PHP runtime with the extensions AVISO needs ----------
FROM php:8.2-fpm-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl unzip git \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libonig-dev libpq-dev libzip-dev \
        nginx supervisor procps \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------- 2. Composer dependencies (no dev packages) ----------
FROM php-base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---------- 3. Frontend build (Vite 8 needs Node >= 20.19) ----------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
# @vitejs/plugin-react 4.x doesn't list Vite 8 as a peer yet; it works with it.
RUN npm ci --legacy-peer-deps

COPY . .
# tsconfig maps "ziggy-js" to the Composer package.
COPY --from=vendor /var/www/html/vendor/tightenco/ziggy ./vendor/tightenco/ziggy

# VITE_* values are baked into the JS bundle, so they must exist at build time.
# On App Platform, set them with scope "Build time" (they arrive as --build-arg).
ARG VITE_APP_NAME=AVISO
ARG VITE_MAPBOX_TOKEN
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
ENV VITE_APP_NAME=$VITE_APP_NAME \
    VITE_MAPBOX_TOKEN=$VITE_MAPBOX_TOKEN \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

RUN npm run build

# ---------- 4. Final image ----------
FROM php-base

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-aviso.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
# Guard against CRLF line endings from a Windows checkout.
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan view:cache \
    && mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Production defaults; App Platform env vars override these.
# Laravel publishes broadcasts to Reverb inside the container. Browsers reach
# Reverb through nginx on the public domain (the VITE_REVERB_* build args).
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8081 \
    REVERB_SCHEME=http

EXPOSE 8080

CMD ["docker-entrypoint.sh"]
