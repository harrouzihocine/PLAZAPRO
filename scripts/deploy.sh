#!/usr/bin/env bash
# scripts/deploy.sh — production deploy for the PLAZA PRO stack.
#
#   Usage:  ./scripts/deploy.sh            pull latest commit, build, migrate, restart
#           ./scripts/deploy.sh --no-pull  deploy the checkout as-is
#
# Run from the PRODUCTION checkout (its .env sets COMPOSE_FILE=docker-compose.prod.yml
# — created by scripts/generate-prod-env.sh). Order matters:
# backup → maintenance → migrate → cache → start → smoke test.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

[[ -f .env && -f backend/.env ]] || {
    echo "Missing prod env files — run ./scripts/generate-prod-env.sh <domain> first." >&2
    exit 1
}
grep -q '^COMPOSE_FILE=docker-compose.prod.yml' .env || {
    echo "This checkout's .env does not select docker-compose.prod.yml — refusing to deploy the dev stack." >&2
    exit 1
}

APP_TLS_PORT=$(grep -oP '^APP_TLS_PORT=\K.*' .env || echo 443)

if [[ "${1:-}" != "--no-pull" ]]; then
    git pull --ff-only
fi

echo "==> Building images and frontend"
docker compose build app
# Build the SPA with a throwaway node container (prod stack has no node service).
# npm_config_cache: a UID-mapped user has no writable $HOME in the stock image,
# and npm dies on its cache dir without it.
docker run --rm -u "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
    -v "$ROOT/frontend":/app -w /app \
    node:20-alpine sh -c "npm ci --no-audit --no-fund && npm run build"

echo "==> Starting datastores"
docker compose up -d mysql redis

echo "==> Installing backend dependencies (no dev packages in prod)"
docker compose run --rm --no-deps app composer install --no-dev --optimize-autoloader --no-interaction

# First deploy only: generate the app encryption key.
if ! grep -q '^APP_KEY=.\+' backend/.env; then
    docker compose run --rm --no-deps app php artisan key:generate --force
fi

echo "==> Backup before touching the schema"
# File the snapshot under THIS stack's backup root (~/backups/plaza vs
# ~/backups/plaza-prod) — without this, a pre-deploy prod snapshot lands in
# the dev folder and is hard to find when it matters.
project=$(grep -oP '^COMPOSE_PROJECT_NAME=\K.*' .env || echo plaza)
PLAZA_BACKUP_DIR="${PLAZA_BACKUP_DIR:-$HOME/backups/$project}" "$ROOT/scripts/backup-db.sh" manual \
    || echo "  (backup skipped — empty/first-run database)"

echo "==> Maintenance window: migrate + rebuild caches"
docker compose run --rm app php artisan down || true
docker compose run --rm app php artisan migrate --force
# config / routes / views / events all cached for prod speed.
docker compose run --rm app php artisan optimize

# nginx refuses to start when its cert files are missing; seed a self-signed
# placeholder if scripts/setup-lan-tls.sh hasn't issued the real one yet.
echo "==> Ensuring LAN TLS cert files exist"
"$ROOT/scripts/setup-lan-tls.sh" --bootstrap-only

echo "==> Starting the full stack"
docker compose up -d --remove-orphans
# Workers reload code/config on their next job.
docker compose exec -T app php artisan queue:restart
docker compose exec -T app php artisan up

echo "==> Smoke test"
sleep 3
# Through the LAN TLS listener (the path office users take). -k because the
# cert is for the domain (or still the bootstrap self-signed), not 127.0.0.1 —
# this probes the nginx→php chain, cert validity is verified in setup-lan-tls.
curl -fsSk "https://127.0.0.1:${APP_TLS_PORT}/up" > /dev/null && echo "  /up OK"
curl -fsSk "https://127.0.0.1:${APP_TLS_PORT}/api/v1/ping" > /dev/null && echo "  /api/v1/ping OK"

echo "Deploy complete. If the tunnel should run here: docker compose --profile tunnel up -d"
