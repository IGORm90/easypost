FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_pgsql pgsql pcntl bcmath zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/99-app.ini

# The `easypost-logs` named volume (docker/compose.yaml) is mounted here. A
# fresh named volume copies the ownership and mode of this directory in the
# image, so it must exist and be writable by the php-fpm user (www-data) or
# the volume is born root-only and web requests cannot write logs into it.
RUN mkdir -p /var/www/html/storage/logs \
    && chown www-data:www-data /var/www/html/storage/logs \
    && chmod 775 /var/www/html/storage/logs

WORKDIR /var/www/html
