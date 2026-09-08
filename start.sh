#!/usr/bin/env bash
# Jalankan BoothPOS sepenuhnya lewat Docker Compose — local dev only
# (specs/015-dockerize-dev-environment). Bukan channel deployment toko.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f .env ]; then
    cp .env.docker.example .env
    echo "[start.sh] .env dibuat dari .env.docker.example"
fi

if ! grep -q '^APP_KEY=.\+' .env; then
    echo "[start.sh] APP_KEY kosong, generate dulu..."
    docker compose run --rm app php artisan key:generate
fi

docker compose up --build
