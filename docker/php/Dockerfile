# Local-development-only image for BoothPOS's PHP/Laravel app.
# Not a production/store deployment artifact — see
# specs/015-dockerize-dev-environment/research.md R1-R3.
#
# BUG YANG DITEMUKAN & DIPERBAIKI (015-dockerize-dev-environment) —
# research.md R2 semula memilih php:8.3-cli mengikuti floor yang
# DIDEKLARASIKAN composer.json ("php": "^8.3"). Ternyata composer.lock
# yang benar-benar ada di repo ini sudah meng-resolve symfony/* ke versi
# 8.1.x yang masing-masing punya requirement riil "php": ">=8.4.1" —
# artinya composer.json's ^8.3 sudah basi/menyesatkan, composer.lock
# sungguhan HANYA bisa `composer install` di PHP 8.4+. Ditemukan lewat
# `docker compose up` sungguhan (Composer gagal dengan belasan conflict
# "requires php >=8.4.1"), bukan dari membaca composer.json semata.
# php:8.4-cli dipakai di sini supaya cocok dengan composer.lock yang
# nyata, sama seperti mesin dev native ini yang juga sudah PHP 8.4.5.
FROM php:8.4-cli

# Extensions required by this app:
#   - bcmath, gd, intl, mbstring, pdo_mysql, zip: declared requirements of
#     maatwebsite/excel (confirmed via `composer show maatwebsite/excel`,
#     research.md R3), used for master-data import/export and report exports.
#   - pdo, xml, curl: Laravel 13 baseline. curl and openssl support are
#     already compiled into the php:8.3-cli base image; xml/dom come via
#     the libxml-backed extensions installed below.
# BUG YANG DITEMUKAN & DIPERBAIKI (015-dockerize-dev-environment) —
# build awal gagal di `docker-php-ext-install mbstring` dengan galat
# pkg-config "ONIG_CFLAGS/ONIG_LIBS not found": mbstring butuh library
# oniguruma (libonig-dev) untuk dikompilasi dari source, dan paket itu
# tidak ada di daftar apt-get semula. Ditemukan lewat build sungguhan
# (docker compose build), bukan dari membaca dokumentasi ekstensi PHP.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libxml2-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        pdo_mysql \
        zip \
        xml \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer, copied straight from the official image rather than
# curl|bash-installed, matching the well-known multi-stage pattern.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /var/www/html/docker/php/entrypoint.sh
RUN chmod +x /var/www/html/docker/php/entrypoint.sh

ENTRYPOINT ["docker/php/entrypoint.sh"]
