#!/usr/bin/env bash
# Entrypoint for the store-deployment `app` container
# (specs/016-docker-store-deployment/). Unlike feature 015's dev
# entrypoint (docker/php/entrypoint.sh), there is no bind mount and no
# `composer install`/`npm install` step here at all — vendor/ and
# public/build/ are already baked into the image (see Dockerfile's
# builder stage). Only two things can legitimately happen at runtime:
# key generation (first boot only) and migrations (every boot,
# idempotent) — mirrors research.md R4.
set -euo pipefail

cd /var/www/html

# BUG YANG DITEMUKAN & DIPERBAIKI (016-docker-store-deployment) —
# terverifikasi lewat `docker compose up` sungguhan: MySQL's official
# image me-restart dirinya SEKALI setelah fase "temporary server" saat
# inisialisasi pertama kali (lihat log resminya: "Starting temporary
# server" -> "Stopping temporary server" -> restart final). Compose
# healthcheck bisa saja melaporkan "healthy" TEPAT selama jendela
# temporary-server itu, sehingga `depends_on: condition: service_healthy`
# tidak cukup — `migrate` sempat jalan persis di celah restart itu dan
# gagal dengan "Connection refused". Loop tunggu di sini (memakai
# `mysqladmin`, sudah terpasang untuk app:backup/app:restore) menutup
# celah itu tanpa bergantung pada timing healthcheck compose.
echo "[entrypoint] waiting for MySQL to accept connections..."
# --ssl=0: Debian's default-mysql-client (MariaDB client under the hood)
# enables SSL by default and rejects MySQL 8's self-signed cert outright
# ("self-signed certificate in certificate chain") — found via a real
# `docker compose up`, not documentation. This is a local Compose
# network with no external exposure, so disabling SSL for this
# same-network ping is not a meaningful security tradeoff.
until mysqladmin ping -h "${DB_HOST:-mysql}" -u"${DB_USERNAME:-root}" -p"${DB_PASSWORD:-}" --ssl=0 --silent 2>/dev/null; do
    sleep 2
done
echo "[entrypoint] MySQL is accepting connections."

if [ -f .env ] && grep -q '^APP_KEY=$' .env; then
    echo "[entrypoint] APP_KEY is empty, generating..."
    php artisan key:generate --ansi
fi

echo "[entrypoint] running migrations..."
php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port=8000
