#!/usr/bin/env bash
# scripts/generate-prod-env.sh — create the three production env files with
# strong random secrets, all cross-referenced values kept in sync.
#
#   Usage:  ./scripts/generate-prod-env.sh plaza.example.com
#
# Writes (in this checkout — run it in the PRODUCTION checkout, not dev):
#   .env                      compose vars (DB/Redis passwords, prod compose file)
#   backend/.env              Laravel prod config
#   frontend/.env.production  Vite build config (Reverb key matches backend)
#
# Refuses to overwrite existing files: rotating secrets on a live install needs
# a coordinated deploy (see docs/production-runbook.md § Rotating secrets).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOMAIN="${1:-}"

if [[ -z "$DOMAIN" ]]; then
    echo "Usage: $0 <public-domain>   e.g. $0 plaza.example.com" >&2
    exit 1
fi
if [[ "$DOMAIN" == *"://"* ]]; then
    echo "Pass a bare hostname (plaza.example.com), not a URL." >&2
    exit 1
fi

for f in "$ROOT/.env" "$ROOT/backend/.env" "$ROOT/frontend/.env.production"; do
    if [[ -e "$f" ]]; then
        echo "Refusing to overwrite existing $f — move it away first if you really mean to regenerate." >&2
        exit 1
    fi
done

# Hex secrets: alphanumeric only, safe in .env files, shells, and MySQL env vars.
gen() { openssl rand -hex 24; }   # 48 chars, 192 bits

MYSQL_PASSWORD="$(gen)"
MYSQL_ROOT_PASSWORD="$(gen)"
REDIS_PASSWORD="$(gen)"
REVERB_APP_KEY="$(gen)"
REVERB_APP_SECRET="$(gen)"

sed -e "s/^MYSQL_PASSWORD=__GENERATE__/MYSQL_PASSWORD=$MYSQL_PASSWORD/" \
    -e "s/^MYSQL_ROOT_PASSWORD=__GENERATE__/MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD/" \
    -e "s/^REDIS_PASSWORD=__GENERATE__/REDIS_PASSWORD=$REDIS_PASSWORD/" \
    "$ROOT/.env.production.example" > "$ROOT/.env"

sed -e "s/__DOMAIN__/$DOMAIN/g" \
    -e "s/^DB_PASSWORD=__GENERATE_DB__/DB_PASSWORD=$MYSQL_PASSWORD/" \
    -e "s/^REDIS_PASSWORD=__GENERATE_REDIS__/REDIS_PASSWORD=$REDIS_PASSWORD/" \
    -e "s/^REVERB_APP_KEY=__GENERATE_REVERB_KEY__/REVERB_APP_KEY=$REVERB_APP_KEY/" \
    -e "s/^REVERB_APP_SECRET=__GENERATE__/REVERB_APP_SECRET=$REVERB_APP_SECRET/" \
    "$ROOT/backend/.env.production.example" > "$ROOT/backend/.env"

sed -e "s/^VITE_REVERB_APP_KEY=__GENERATE_REVERB_KEY__/VITE_REVERB_APP_KEY=$REVERB_APP_KEY/" \
    "$ROOT/frontend/.env.production.example" > "$ROOT/frontend/.env.production"

chmod 600 "$ROOT/.env" "$ROOT/backend/.env" "$ROOT/frontend/.env.production"

# Nothing left unfilled?
if grep -q "__GENERATE\|__DOMAIN__" "$ROOT/.env" "$ROOT/backend/.env" "$ROOT/frontend/.env.production"; then
    echo "ERROR: unreplaced placeholders remain — check the templates." >&2
    exit 1
fi

echo "Wrote .env, backend/.env, frontend/.env.production for https://$DOMAIN (mode 600)."
echo
echo "Next steps:"
echo "  1. Paste your Cloudflare tunnel token into .env (CLOUDFLARE_TUNNEL_TOKEN=...)"
echo "  2. ./scripts/deploy.sh            (first deploy generates APP_KEY)"
echo "  3. docker compose --profile tunnel up -d"
