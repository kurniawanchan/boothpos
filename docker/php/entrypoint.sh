#!/usr/bin/env bash
# Entrypoint for the `app` (PHP/Laravel) container — local dev only.
# php:8.3-cli is Debian-based, so bash is available.
set -euo pipefail

cd /var/www/html

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
