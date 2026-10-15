#!/usr/bin/env bash
# Entrypoint for the `app` (PHP/Laravel) container — local dev only.
# php:8.3-cli is Debian-based, so bash is available.
set -euo pipefail

cd /var/www/html

# 0. Stable machine identity for the licence gate (feature 018). The gate binds
#    an activation to a fingerprint derived from /etc/machine-id, and a fresh
#    container has none — so after ANY container recreate (image rebuild,
#    `docker compose up --force-recreate`) the app answered 423 "belum
#    diaktivasi" although the activation row was still in the database. Writing
#    the same well-known dev value that `php artisan license:dev-activate`
#    itself tells you to use makes the dev activation survive recreates. Only
#    written when missing/empty, so a value provided another way is never
#    overwritten. Dev image only — the store image bind-mounts the HOST's
#    /etc/machine-id instead (feature 016), which is what a real install needs.
if [ ! -s /etc/machine-id ]; then
    echo "1a2b3c4d5e6f789012345678901234567890" > /etc/machine-id
fi

# 1. Install PHP dependencies if the anonymous volume over vendor/ is
#    empty (fresh container, or a host with nothing but Docker installed —
#    see research.md R5 on why vendor/ is an anonymous volume, not part of
#    the bind mount).
if [ ! -d vendor ] || [ -z "$(ls -A vendor 2>/dev/null)" ]; then
    echo "[entrypoint] vendor/ is empty, running composer install..."
    composer install --no-interaction --prefer-dist
fi

# 2. Generate an application key if .env exists but APP_KEY is blank.
if [ -f .env ] && grep -q '^APP_KEY=$' .env; then
    echo "[entrypoint] APP_KEY is empty, generating..."
    php artisan key:generate --ansi
fi

# 3. Always run migrations. Idempotent — a no-op if already up to date
#    (research.md R7). Deliberately does NOT run db:seed / the demo
#    seeder here; that stays a manual, separately-documented step.
echo "[entrypoint] running migrations..."
php artisan migrate --force

# 4. Hand off to the dev server, reachable from outside the container.
exec php artisan serve --host=0.0.0.0 --port=8000
