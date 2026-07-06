#!/usr/bin/env bash
# scripts/renew-lan-cert.sh — renew the office-LAN Let's Encrypt cert.
#
# Cadence run, installed in the crontab by scripts/setup-lan-tls.sh (twice a
# day; certbot only re-issues inside the 30-day expiry window). Can be run by
# hand to force a check. Logs land in $PLAZA_BACKUP_DIR/cert-renew.log.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

grep -q '^COMPOSE_FILE=docker-compose.prod.yml' .env 2>/dev/null || {
    echo "This checkout's .env does not select docker-compose.prod.yml — refusing." >&2
    exit 1
}

# Refresh the credentials file from .env each run: the token's single source
# of truth stays .env, so rotating it is edit-and-forget.
TOKEN="$(grep -E '^CLOUDFLARE_DNS_API_TOKEN=' .env | head -1 | cut -d= -f2- | tr -d '[:space:]')"
if [[ -z "$TOKEN" ]]; then
    echo "CLOUDFLARE_DNS_API_TOKEN is empty in .env — cannot renew." >&2
    exit 1
fi
umask 077
printf 'dns_cloudflare_api_token = %s\n' "$TOKEN" > secrets/cloudflare-dns.ini
umask 022

echo "[$(date '+%F %T')] certbot renew"
# The deploy hook only fires when a cert was actually renewed: it refreshes
# the stable copies nginx reads (live/ itself is certbot's symlink tree).
docker compose run --rm certbot renew \
    --dns-cloudflare-propagation-seconds 30 \
    --deploy-hook 'cp -fL "$RENEWED_LINEAGE/fullchain.pem" "$RENEWED_LINEAGE/privkey.pem" /etc/letsencrypt/nginx/'

# Reload is cheap and idempotent — do it unconditionally so a renewal that
# succeeded on a previous run whose reload failed still gets picked up.
if [[ -n "$(docker compose ps -q --status running nginx 2>/dev/null)" ]]; then
    docker compose exec -T nginx nginx -s reload
    echo "nginx reloaded."
fi
