#!/usr/bin/env bash
# scripts/setup-lan-tls.sh — one-time setup for trusted HTTPS on the office LAN.
#
#   Usage:  ./scripts/setup-lan-tls.sh plaza.example.com
#           ./scripts/setup-lan-tls.sh --bootstrap-only     (used by deploy.sh)
#
# The LAN reuses the PUBLIC hostname (split-horizon DNS: the office router
# answers with the server's LAN IP, the internet keeps resolving to the
# Cloudflare tunnel) so cookies/Sanctum/websockets need no changes, and the
# cert is a real Let's Encrypt one obtained via Cloudflare DNS-01 — no inbound
# port ever opens to the internet. See docs/production-runbook.md §LAN HTTPS.
#
# Steps (idempotent — safe to re-run; renewals happen via cron, not this):
#   1. secrets/cloudflare-dns.ini  ← CLOUDFLARE_DNS_API_TOKEN from .env
#   2. self-signed placeholder in the letsencrypt volume (nginx refuses to
#      start with no cert file at all; --bootstrap-only stops after this)
#   3. real cert via certbot (docker compose run certbot, DNS-01)
#   4. copy to the stable path nginx reads + reload nginx
#   5. renewal crontab block → scripts/renew-lan-cert.sh twice a day

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# nginx loads certs from this stable path inside the `letsencrypt` volume —
# never from certbot's live/ tree (symlinks + a bootstrap placeholder there
# would fight over ownership of the directory).
NGINX_CERT_DIR=/etc/letsencrypt/nginx
CERT_NAME=plaza

grep -q '^COMPOSE_FILE=docker-compose.prod.yml' .env 2>/dev/null || {
    echo "This checkout's .env does not select docker-compose.prod.yml — run this in the PRODUCTION checkout." >&2
    exit 1
}

# One cert may cover several hostnames (SAN): the first is the primary (the
# tunnel hostname), any extra are additional names on the same cert — e.g. the
# LAN-direct hostname that resolves to the server's private IP in public DNS:
#   ./scripts/setup-lan-tls.sh app.plaza-pro.com office.plaza-pro.com
BOOTSTRAP_ONLY=0
DOMAIN="${1:-}"
DOMAINS=()
if [[ "$DOMAIN" == "--bootstrap-only" ]]; then
    BOOTSTRAP_ONLY=1
    DOMAIN=""
elif [[ -z "$DOMAIN" ]]; then
    echo "Usage: $0 <primary-domain> [extra-domain ...]   e.g. $0 app.example.com office.example.com" >&2
    exit 1
else
    for d in "$@"; do
        [[ "$d" == *"://"* ]] && { echo "Pass bare hostnames (app.example.com), not URLs." >&2; exit 1; }
        DOMAINS+=(-d "$d")
    done
fi

# --- 1. credentials file (also a placeholder in bootstrap mode: compose
#        bind-mounts it, and a missing file would become a root-owned dir) ----
mkdir -p secrets
if [[ "$BOOTSTRAP_ONLY" == 0 ]]; then
    TOKEN="$(grep -E '^CLOUDFLARE_DNS_API_TOKEN=' .env | head -1 | cut -d= -f2- | tr -d '[:space:]')"
    if [[ -z "$TOKEN" ]]; then
        echo "CLOUDFLARE_DNS_API_TOKEN is empty in .env — create a token with Zone → DNS → Edit" >&2
        echo "on this site's zone only (dash.cloudflare.com → My Profile → API Tokens) and paste it in." >&2
        exit 1
    fi
    umask 077
    printf 'dns_cloudflare_api_token = %s\n' "$TOKEN" > secrets/cloudflare-dns.ini
    umask 022
elif [[ ! -f secrets/cloudflare-dns.ini ]]; then
    ( umask 077; : > secrets/cloudflare-dns.ini )
fi

# --- 2. self-signed placeholder so nginx can always start ---------------
docker compose run --rm --no-deps --entrypoint sh certbot -c "
    test -f $NGINX_CERT_DIR/fullchain.pem && exit 0
    mkdir -p $NGINX_CERT_DIR
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -subj '/CN=plaza-bootstrap-self-signed' \
        -keyout $NGINX_CERT_DIR/privkey.pem \
        -out $NGINX_CERT_DIR/fullchain.pem 2>/dev/null
    chmod 600 $NGINX_CERT_DIR/privkey.pem
    echo 'Seeded self-signed placeholder cert.'
"
if [[ "$BOOTSTRAP_ONLY" == 1 ]]; then
    exit 0
fi

# --- 3. the real certificate --------------------------------------------
# Let's Encrypt no longer sends expiry emails (discontinued 2025) — renewal
# health is the cron's job; set ACME_EMAIL in the environment if you want the
# account registered with one anyway.
EMAIL_ARGS=(--register-unsafely-without-email)
[[ -n "${ACME_EMAIL:-}" ]] && EMAIL_ARGS=(-m "$ACME_EMAIL" --no-eff-email)

docker compose run --rm certbot certonly \
    --dns-cloudflare \
    --dns-cloudflare-credentials /run/secrets/cloudflare-dns.ini \
    --dns-cloudflare-propagation-seconds 30 \
    --cert-name "$CERT_NAME" "${DOMAINS[@]}" \
    --keep-until-expiring --expand --non-interactive --agree-tos "${EMAIL_ARGS[@]}"

# --- 4. publish where nginx reads + reload ------------------------------
docker compose run --rm --no-deps --entrypoint sh certbot -c \
    "cp -fL /etc/letsencrypt/live/$CERT_NAME/fullchain.pem /etc/letsencrypt/live/$CERT_NAME/privkey.pem $NGINX_CERT_DIR/"
if [[ -n "$(docker compose ps -q --status running nginx 2>/dev/null)" ]]; then
    docker compose exec -T nginx nginx -s reload
    echo "nginx reloaded with the new certificate."
else
    echo "nginx not running — the certificate will be picked up on next start."
fi

# --- 5. renewal cron (idempotent block, same pattern as backups) ---------
LOG_DIR="${PLAZA_BACKUP_DIR:-$HOME/backups/plaza}"
mkdir -p "$LOG_DIR"
MARK_BEGIN="# >>> PLAZA-LAN-TLS ($ROOT) >>>"
MARK_END="# <<< PLAZA-LAN-TLS ($ROOT) <<<"
block=$(cat <<CRON
$MARK_BEGIN
# Twice daily like certbot recommends; renews only inside the 30-day window.
17 3,15 * * * $ROOT/scripts/renew-lan-cert.sh >> $LOG_DIR/cert-renew.log 2>&1
$MARK_END
CRON
)
current=$(crontab -l 2>/dev/null || true)
cleaned=$(printf '%s\n' "$current" \
    | awk -v b="$MARK_BEGIN" -v e="$MARK_END" '$0 == b {skip=1} !skip {print} $0 == e {skip=0}')
printf '%s\n%s\n' "$cleaned" "$block" | sed '/./,$!d' | crontab -

echo
echo "Done. https://$DOMAIN is now served with a trusted cert on the LAN port (443)."
echo
echo "Remaining manual step — split-horizon DNS on the OFFICE side:"
echo "  Point $DOMAIN at this server's LAN IP in the office router / local DNS"
echo "  (dnsmasq: address=/$DOMAIN/<LAN-IP>; most routers: 'DNS host mapping' /"
echo "  'local DNS record'). Internet users keep resolving to the Cloudflare tunnel."
echo
echo "Verify from any LAN machine:"
echo "  curl --resolve $DOMAIN:443:<LAN-IP> https://$DOMAIN/up     # 200, valid cert"
echo "  curl -I http://$DOMAIN/                                    # 301 → https"
