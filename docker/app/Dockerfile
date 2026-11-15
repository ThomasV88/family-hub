# syntax=docker/dockerfile:1
# php-fpm image for Family Hub. Targets: dev (bind-mounted code, uid 1000) and prod (code baked in, www-data),
# plus ai-worker-dev / ai-worker, the only images that carry the pinned Codex CLI (never in dev or prod).
# Build context is the repository root: docker build -f docker/app/Dockerfile --target prod .

FROM php:8.4-fpm-alpine AS base

RUN set -eux; \
    apk add --no-cache icu-libs libpq libzip libjpeg-turbo libpng libwebp poppler-utils; \
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
    mkdir -p /var/www/html/storage/app/private/wish-images /var/www/html/storage/app/medical; \
    chmod 0700 /var/www/html/storage/app/medical; \
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
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private/wish-images storage/app/medical bootstrap/cache; \
    chmod 0700 storage/app/medical; \
    chown -R www-data:www-data storage bootstrap/cache
USER www-data

# ---- codex: build stage only. Installs the pinned CLI and hands over just the native directory ----
# Node and the JS wrapper never reach a runtime image. Select by `uname -m` (x86_64 and aarch64).
FROM node:22-alpine AS codex
WORKDIR /opt/codex-build
COPY docker/ai/package.json docker/ai/package-lock.json ./
RUN set -eux; \
    npm ci --ignore-scripts --omit=dev; \
    triple="$(uname -m)-unknown-linux-musl"; \
    d="$(find node_modules -type d -path "*vendor/${triple}" | head -1)"; \
    test -x "$d/bin/codex"; \
    mkdir /opt/codex; \
    cp -a "$d"/. /opt/codex/; \
    rm -rf /opt/codex/codex-resources/voice

# ---- copilot: build stage only. Pinned GitHub Copilot CLI, musl native binary, cache extracted once and baked read-only ----
# The binary is neither stripped nor repacked (licence). The --version run extracts its runtime cache into /opt/copilot/cache.
FROM node:22-alpine AS copilot
WORKDIR /opt/copilot-build
COPY docker/ai/copilot/package.json docker/ai/copilot/package-lock.json ./
RUN set -eux; \
    npm ci --ignore-scripts --omit=dev; \
    case "$(uname -m)" in x86_64) a=x64 ;; aarch64) a=arm64 ;; *) echo "unsupported arch" >&2; exit 1 ;; esac; \
    d="node_modules/@github/copilot-linuxmusl-${a}"; \
    test -x "$d/copilot"; \
    mkdir -p /opt/copilot/bin /opt/copilot/cache /tmp/ch; \
    cp "$d/copilot" /opt/copilot/bin/copilot; \
    cp node_modules/@github/copilot/LICENSE.md /opt/copilot/LICENSE.md; \
    env -i PATH=/usr/bin:/bin HOME=/tmp COPILOT_HOME=/tmp/ch COPILOT_CACHE_HOME=/opt/copilot/cache /opt/copilot/bin/copilot --version | grep -q "1.0.112"; \
    ls /opt/copilot/cache/pkg/*/*/.extraction-complete; \
    chmod -R a-w /opt/copilot/cache

# ---- ai-runtime: base + native Codex and Copilot (large layers sit below the per-commit code layer) ----
FROM base AS ai-runtime
COPY --from=codex /opt/codex /opt/codex
COPY --from=copilot /opt/copilot /opt/copilot
RUN set -eux; \
    ln -s /opt/codex/bin/codex /usr/local/bin/codex; \
    ln -s /opt/copilot/bin/copilot /usr/local/bin/copilot; \
    mkdir -p /codex /copilot /ai-work

# ---- ai-worker-dev: bind-mounted code, non-root `app` user ----
FROM ai-runtime AS ai-worker-dev
ARG UID=1000
ARG GID=1000
RUN set -eux; \
    addgroup -g "${GID}" app; \
    adduser -D -u "${UID}" -G app app; \
    mkdir -p /var/www/html/storage/app/private/wish-images /var/www/html/storage/app/medical; \
    chmod 0700 /var/www/html/storage/app/medical; \
    chown -R app:app /var/www/html/storage /codex /copilot /ai-work
USER app

# ---- ai-worker: prod flavour without the Vite assets, runs as www-data (same uid as app: medical files are 0600) ----
FROM ai-runtime AS ai-worker
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/app/php-prod.ini "$PHP_INI_DIR/conf.d/zz-prod.ini"
COPY docker/app/php-fpm-prod.conf /usr/local/etc/php-fpm.d/zz-prod.conf
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --no-interaction; \
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private/wish-images storage/app/medical bootstrap/cache; \
    chmod 0700 storage/app/medical; \
    chown -R www-data:www-data storage bootstrap/cache /codex /copilot /ai-work
USER www-data
