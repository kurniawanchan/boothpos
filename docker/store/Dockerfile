# Production-shaped image for a real store deployment
# (specs/016-docker-store-deployment/). Distinct from docker/php/Dockerfile,
# which is feature 015's DEV-ONLY image (bind-mounted source, anonymous
# volumes over vendor/node_modules, hot-reload). This image bakes
# vendor/ and the built frontend assets in at BUILD time — no bind
# mount, no Node service at runtime (research.md R1).
#
# Multi-stage: a throwaway "builder" stage runs composer/npm, the final
# stage only carries the runtime PHP image + the build's output.

# --- Stage 1: build vendor/ + public/build/ -------------------------
FROM php:8.4-cli AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libxml2-dev libonig-dev curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl mbstring pdo_mysql zip xml \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader \
    && npm ci \
    && npm run build \
    && rm -rf node_modules

# --- Stage 2: runtime ------------------------------------------------
# Same PHP extension set as the builder (and as docker/php/Dockerfile's
# dev image), PLUS the MySQL client tools app:backup/app:restore shell
# out to (research.md R5) — the dev image doesn't need these because
# the native dev machine already has its own mysqldump path documented
# in RUNBOOK.md §7.
FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libxml2-dev libonig-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl mbstring pdo_mysql zip xml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Everything already built in the builder stage — vendor/, public/build/,
# application code, all baked in. No bind mount, no volume.
COPY --from=builder /var/www/html /var/www/html

COPY docker/store/entrypoint.sh /var/www/html/docker/store/entrypoint.sh
RUN chmod +x /var/www/html/docker/store/entrypoint.sh \
    && mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# BUG YANG DITEMUKAN & DIPERBAIKI (016-docker-store-deployment) —
# terverifikasi lewat `php artisan app:backup` sungguhan di dalam
# container: Debian's default-mysql-client (MariaDB client) mengaktifkan
# SSL secara default dan menolak sertifikat self-signed milik MySQL 8
# ("TLS/SSL error: self-signed certificate in certificate chain") —
# gagal persis di command `mysqldump` yang dibangun `BackupPos.php`
# (yang TIDAK BOLEH diubah, per FR-004: app:backup/app:restore harus
# tetap sama persis dengan instalasi native). Solusi di level image,
# bukan di kode aplikasi: config client MySQL global yang men-disable
# SSL untuk mysqldump/mysql/mysqladmin — aman karena ini jaringan
# Compose lokal tanpa paparan eksternal, bukan koneksi yang butuh TLS.
RUN printf '[client]\nssl=0\n' > /etc/mysql/conf.d/disable-ssl.cnf

ENTRYPOINT ["docker/store/entrypoint.sh"]
