# syntax=docker/dockerfile:1
# php-fpm image for Family Hub. Targets: dev (bind-mounted code, uid 1000) and prod (code baked in, www-data).
# Build context is the repository root: docker build -f docker/app/Dockerfile --target prod .

FROM php:8.4-fpm-alpine AS base

RUN set -eux; \
    apk add --no-cache icu-libs libpq libzip libjpeg-turbo libpng libwebp; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev libjpeg-turbo-dev libpng-dev libwebp-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache pcntl zip bcmath gd exif; \
    apk del .build-deps; \
    # php-fpm is run unprivileged in every target, so the pool user/group directives are not used
    sed -i 's/^user = /;user = /; s/^group = /;group = /' /usr/local/etc/php-fpm.d/www.conf

# upload limits for wish images (app caps at 5 MiB; nginx allows 8m on the upload route only)
COPY docker/app/php-uploads.ini "$PHP_INI_DIR/conf.d/zz-uploads.ini"

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# ---- dev: non-root `app` user, code is bind-mounted by compose ----
FROM base AS dev
ARG UID=1000
ARG GID=1000
RUN set -eux; \
    addgroup -g "${GID}" app; \
    adduser -D -u "${UID}" -G app app; \
    mkdir -p /var/www/html/storage/app/private/wish-images; \
    chown -R app:app /var/www/html/storage
USER app

# ---- assets: build the Vite output so PHP can read public/build/manifest.json ----
# This is the single build of the front-end assets: the nginx image copies public/ out of the prod image
# (docker/nginx/Dockerfile), so HTML and static files can never come from two differently hashed builds.
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js vitest.config.js ./
COPY resources ./resources
COPY lang ./lang
COPY scripts/check-sw-precache.mjs ./scripts/check-sw-precache.mjs
COPY public ./public
RUN npm run build

# ---- vendor: production composer dependencies ----
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

# ---- prod: code baked in, runs as www-data ----
FROM base AS prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/app/php-prod.ini "$PHP_INI_DIR/conf.d/zz-prod.ini"
COPY docker/app/php-fpm-prod.conf /usr/local/etc/php-fpm.d/zz-prod.conf
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
# whole public/: build/, sw.js, manifest, icons (nginx serves them from a copy of this directory)
COPY --from=assets /build/public ./public
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --no-interaction; \
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private/wish-images bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache
USER www-data
