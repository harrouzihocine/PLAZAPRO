#!/usr/bin/env bash
# scripts/setup-lan-ip-cert.sh — private-CA certificate for the bare-IP LAN
# origin (https://192.168.1.200), the Android shell's DNS-free fallback.
#
#   Usage:  ./scripts/setup-lan-ip-cert.sh            (default IP 192.168.1.200)
#           ./scripts/setup-lan-ip-cert.sh 192.168.1.200
#
# Why this exists: when the office internet is down, phones cannot resolve
# office.plaza-pro.com (their DNS goes router → ISP), so the APK falls back to
# the server's bare LAN IP. Public CAs cannot issue for a private IP, so the
# IP origin is served with a leaf signed by our own offline CA. ONLY the
# Android app trusts that CA (network_security_config.xml pins it for the one
# IP); browsers keep their warning, exactly as before — bare IP stays
# break-glass for them.
#
# Steps (idempotent — safe to re-run; a monthly cron re-runs it to renew):
#   1. CA keypair in secrets/lan-ip-ca/ (created once; the PUBLIC cert must
#      match frontend/android/app/src/main/res/raw/plaza_lan_ca.pem baked
#      into the APK — regenerating the CA means rebuilding the APK)
#   2. leaf cert with SAN IP:<lan-ip>, renewed when <90 days remain
#   3. copy to the stable nginx path in the letsencrypt volume
#      (ip-fullchain.pem / ip-privkey.pem — served by the default no-SNI
#      server block in docker/nginx/prod.conf) + reload nginx
#   4. monthly renewal crontab block
#
# See docs/production-runbook.md §LAN HTTPS and docs/android-app.md.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

NGINX_CERT_DIR=/etc/letsencrypt/nginx
CA_DIR="$ROOT/secrets/lan-ip-ca"
LEAF_DAYS=1095
RENEW_UNDER_DAYS=90

grep -q '^COMPOSE_FILE=docker-compose.prod.yml' .env 2>/dev/null || {
    echo "This checkout's .env does not select docker-compose.prod.yml — run this in the PRODUCTION checkout." >&2
    exit 1
}

LAN_IP="${1:-192.168.1.200}"
[[ "$LAN_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
    echo "Pass a bare IPv4 address (got: $LAN_IP)." >&2
    exit 1
}

# --- 1. the CA (once) ----------------------------------------------------
mkdir -p "$CA_DIR"
chmod 700 "$CA_DIR"
if [[ ! -f "$CA_DIR/ca.pem" ]]; then
    echo "Generating the PLAZA PRO LAN CA (one-time)…"
    ( umask 077; openssl ecparam -name prime256v1 -genkey -noout -out "$CA_DIR/ca.key" )
    openssl req -x509 -new -key "$CA_DIR/ca.key" -sha256 -days 7300 \
        -subj "/O=PLAZA PRO/CN=PLAZA PRO LAN CA" \
        -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
        -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -out "$CA_DIR/ca.pem"
    chmod 644 "$CA_DIR/ca.pem"
    echo
    echo "  NEW CA CREATED. The Android app must embed this exact public cert:"
    echo "    cp $CA_DIR/ca.pem <repo>/frontend/android/app/src/main/res/raw/plaza_lan_ca.pem"
    echo "  then rebuild + republish the APK. Guard $CA_DIR/ca.key like the"
    echo "  Android keystore — whoever holds it can impersonate the LAN origin"
    echo "  to the app fleet."
    echo
fi

# Drift guard: the cert baked into the APK sources must be the same CA.
APK_CA="$ROOT/frontend/android/app/src/main/res/raw/plaza_lan_ca.pem"
if [[ -f "$APK_CA" ]] && ! cmp -s "$APK_CA" "$CA_DIR/ca.pem"; then
    echo "WARNING: $APK_CA differs from $CA_DIR/ca.pem —" >&2
    echo "the published APK will NOT trust leaves issued here. Align them before relying on the IP fallback." >&2
fi

# --- 2. the leaf (renewed when close to expiry or the IP changed) --------
need_leaf=0
if [[ ! -f "$CA_DIR/leaf.pem" ]]; then
    need_leaf=1
elif ! openssl x509 -in "$CA_DIR/leaf.pem" -noout -checkend $((RENEW_UNDER_DAYS * 24 * 3600)) >/dev/null; then
    echo "Leaf certificate expires within $RENEW_UNDER_DAYS days — renewing."
    need_leaf=1
elif ! openssl x509 -in "$CA_DIR/leaf.pem" -noout -ext subjectAltName 2>/dev/null | grep -q "IP Address:$LAN_IP"; then
    echo "Leaf certificate does not cover IP $LAN_IP — reissuing."
    need_leaf=1
fi

if [[ "$need_leaf" == 1 ]]; then
    ( umask 077; openssl ecparam -name prime256v1 -genkey -noout -out "$CA_DIR/leaf.key" )
    openssl req -new -key "$CA_DIR/leaf.key" -subj "/O=PLAZA PRO/CN=$LAN_IP" -out "$CA_DIR/leaf.csr"
    openssl x509 -req -in "$CA_DIR/leaf.csr" -CA "$CA_DIR/ca.pem" -CAkey "$CA_DIR/ca.key" \
        -CAcreateserial -sha256 -days "$LEAF_DAYS" \
        -extfile <(printf 'basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:%s\n' "$LAN_IP") \
        -out "$CA_DIR/leaf.pem"
    rm -f "$CA_DIR/leaf.csr"
    cat "$CA_DIR/leaf.pem" "$CA_DIR/ca.pem" > "$CA_DIR/leaf-fullchain.pem"
    echo "Issued leaf for IP:$LAN_IP ($(openssl x509 -in "$CA_DIR/leaf.pem" -noout -enddate))"
else
    echo "Leaf certificate still fresh ($(openssl x509 -in "$CA_DIR/leaf.pem" -noout -enddate)) — nothing to issue."
fi

# --- 3. publish where nginx reads + reload ------------------------------
# Same trick as setup-lan-tls.sh: the letsencrypt volume is only writable
# from a container; the certbot one is already wired to it.
docker compose run --rm --no-deps \
    -v "$CA_DIR:/lan-ip-ca:ro" \
    --entrypoint sh certbot -c "
        mkdir -p $NGINX_CERT_DIR
        cp -f /lan-ip-ca/leaf-fullchain.pem $NGINX_CERT_DIR/ip-fullchain.pem
        cp -f /lan-ip-ca/leaf.key $NGINX_CERT_DIR/ip-privkey.pem
        chmod 600 $NGINX_CERT_DIR/ip-privkey.pem
    "
if [[ -n "$(docker compose ps -q --status running nginx 2>/dev/null)" ]]; then
    docker compose exec -T nginx nginx -s reload
    echo "nginx reloaded with the IP certificate."
else
    echo "nginx not running — the certificate will be picked up on next start."
fi

# --- 4. renewal cron (idempotent block, same pattern as setup-lan-tls) ---
LOG_DIR="${PLAZA_BACKUP_DIR:-$HOME/backups/plaza}"
mkdir -p "$LOG_DIR"
MARK_BEGIN="# >>> PLAZA-LAN-IP-CERT ($ROOT) >>>"
MARK_END="# <<< PLAZA-LAN-IP-CERT ($ROOT) <<<"
block=$(cat <<CRON
$MARK_BEGIN
# Monthly is plenty: the script only reissues inside the ${RENEW_UNDER_DAYS}-day window.
26 4 3 * * $ROOT/scripts/setup-lan-ip-cert.sh $LAN_IP >> $LOG_DIR/cert-renew.log 2>&1
$MARK_END
CRON
)
current=$(crontab -l 2>/dev/null || true)
cleaned=$(printf '%s\n' "$current" \
    | awk -v b="$MARK_BEGIN" -v e="$MARK_END" '$0 == b {skip=1} !skip {print} $0 == e {skip=0}')
printf '%s\n%s\n' "$cleaned" "$block" | sed '/./,$!d' | crontab -

echo
echo "Done. https://$LAN_IP now serves the private-CA cert to no-SNI (bare-IP) clients."
echo
echo "Verify from any LAN machine:"
echo "  curl --cacert $CA_DIR/ca.pem https://$LAN_IP/up      # 200, chain valid against our CA"
echo "  curl --resolve app.plaza-pro.com:443:$LAN_IP https://app.plaza-pro.com/up   # hostname path untouched"
