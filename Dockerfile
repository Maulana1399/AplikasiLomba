# syntax=docker/dockerfile:1

# Stage khusus: Composer binary (dari image official composer:2)
FROM composer:2 AS composer

# ------------------------------------------------------------------
# Tahap build: composer install (dev deps untuk base tools/tests).
# Menggunakan php:8.4-cli agar kompatibel dengan composer.lock.
# ------------------------------------------------------------------
FROM php:8.4-cli AS vendor

# Install dependency OS untuk Composer dan ext PHP
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
        libsqlite3-dev \
        libonig-dev \
        libzip-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libfreetype6-dev \
        libwebp-dev \
        libxml2-dev \
    ; \
    docker-php-ext-configure gd \
        --with-jpeg \
        --with-freetype \
        --with-webp; \
    docker-php-ext-configure zip; \
    docker-php-ext-install -j"$(nproc)" \
        mbstring \
        xml \
        zip \
        pdo_sqlite \
        gd \
    ; \
    rm -rf /var/lib/apt/lists/*

# Composer binary dari stage composer
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist

COPY . .

RUN composer install \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --prefer-dist

# ------------------------------------------------------------------
# Base runtime: PHP 8.4 FPM — digunakan untuk tools/test dan menjadi
# induk image produksi.
# ------------------------------------------------------------------
FROM php:8.4-fpm AS base

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libfcgi-bin \
        libsqlite3-dev \
        libonig-dev \
        libzip-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libfreetype6-dev \
        libwebp-dev \
    ; \
    docker-php-ext-configure gd \
        --with-jpeg \
        --with-freetype \
        --with-webp; \
    docker-php-ext-configure zip; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        mbstring \
        zip \
        opcache \
        gd \
    ; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

WORKDIR /var/www/match

COPY --from=vendor --chown=www-data:www-data /app /var/www/match

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]

# ------------------------------------------------------------------
# Produksi: hapus dev dependencies agar image ringan.
# ------------------------------------------------------------------
FROM base AS prod

RUN rm -rf bootstrap/cache/* \
    && composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && php artisan package:discover --ansi \
    && rm -f /usr/local/bin/composer

EXPOSE 9000
