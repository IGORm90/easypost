# syntax=docker/dockerfile:1.7
#
# Production image for EasyPost. Build from the REPOSITORY ROOT:
#
#   docker build -f docker/production/Dockerfile --target app -t <repo>/easypost:<sha> .
#   docker build -f docker/production/Dockerfile --target web -t <repo>/easypost-web:<sha> .
#
# Two images from one build, same SHA: `app` runs php-fpm, Horizon, the
# scheduler and Pulse; `web` is nginx carrying the very same public/ tree, so
# the page and the assets it names can never come from different releases.
#
# No stage but `assets` knows Node, and the runtime knows neither Node nor
# Composer: there is nothing in production that can run `npm run dev`.

# ---------------------------------------------------------------------------
# vendor: PHP dependencies from composer.lock, without dev packages.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# The composer image has none of the PHP extensions the app needs, so platform
# requirements are ignored HERE and checked where they matter, in the final
# image (`composer check-platform-reqs`, stage `app`). Scripts are skipped
# because `package:discover` boots the application, which this stage cannot.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --ignore-platform-reqs

# The autoloader is generated after the source is in, so its classmap lists the
# application's own classes. Paths in it are relative to vendor/, so the result
# moves to /var/www/html unchanged.
COPY . .
RUN composer dump-autoload \
        --no-dev \
        --optimize \
        --no-interaction \
        --no-scripts

# ---------------------------------------------------------------------------
# assets: the Vue SPA, built by Vite into public/build.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

# Only what Vite and Tailwind read. Blade files are included because Tailwind
# scans them for class names.
COPY vite.config.ts tsconfig.json tailwind.config.js postcss.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# app: the runtime for php-fpm, Horizon, the scheduler and Pulse.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm AS app

# `procps` is for Pulse: it reads CPU from `top`, and without it the Servers
# card records 0% with no error anywhere but the container log. It also gives
# the healthchecks `pgrep`.
RUN apt-get update && apt-get install -y --no-install-recommends \
        procps \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_pgsql pgsql pcntl bcmath zip gd opcache \
    && rm -rf /var/lib/apt/lists/*

# Same upload ceilings as development (100M file / 108M body), then the
# production overrides on top. Loaded in file-name order, so 99-production wins.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-production.conf

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# These directories must exist BEFORE `package:discover` below: it boots the
# application, and the Blade compiler refuses to start without a view cache
# path ("Please provide a valid cache path"). They must also be writable by the
# php-fpm user, and the `easypost-logs` named volume (docker/compose.production.yaml) copies the
# owner and mode of storage/logs when it is first created: without this it is
# born root-only and web requests cannot write logs into it.
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod 775 storage/logs

# `package:discover` writes bootstrap/cache/{packages,services}.php, which
# lists the service providers of the packages that are actually installed.
# Without it a missing dev package could not be told from a missing provider.
#
# Composer is borrowed for one check and removed again: it answers whether THIS
# image, with THESE extensions, satisfies composer.lock, which the vendor stage
# could not know.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer check-platform-reqs --no-dev \
    && php artisan package:discover --ansi \
    && rm /usr/bin/composer \
    && chown -R www-data:www-data bootstrap/cache

COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# web: nginx in front of php-fpm, with the same public/ tree as `app`.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/production.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
