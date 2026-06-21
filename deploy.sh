#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.production.yaml}"
SERVICE="${SERVICE:-app}"

cd "${PROJECT_DIR}"

if [[ ! -f "${COMPOSE_FILE}" ]]; then
    echo "Missing ${COMPOSE_FILE}" >&2
    exit 1
fi

run_app() {
    docker compose -f "${COMPOSE_FILE}" run --rm "${SERVICE}" "$@"
}

log() {
    printf '\n==> %s\n' "$1"
}

log "Building application image"
docker compose -f "${COMPOSE_FILE}" build "${SERVICE}"

log "Installing PHP dependencies"
run_app composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

log "Installing Node dependencies and building assets"
run_app npm ci
run_app npm run build

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    log "Generating application key"
    run_app php artisan key:generate --force --no-interaction
fi

log "Preparing Laravel caches and storage"
run_app php artisan optimize:clear
run_app php artisan storage:link --force || true

log "Running migrations"
run_app php artisan migrate --force --no-interaction

log "Caching production config"
run_app php artisan optimize

log "Restarting application container"
docker compose -f "${COMPOSE_FILE}" up -d --remove-orphans --force-recreate "${SERVICE}"

docker compose -f "${COMPOSE_FILE}" ps
