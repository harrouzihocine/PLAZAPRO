# docker/nginx/prod-common.inc — the shared interior of BOTH production server
# blocks in prod.conf (named-host block with the Let's Encrypt cert, and the
# default no-SNI/bare-IP block with the private-CA cert). Everything below is
# identical for the two: same SPA, same API, same policies — the blocks differ
# only in server_name and certificate. Mounted next to prod.conf by
# docker-compose.prod.yml; the .inc suffix keeps it out of conf.d's *.conf glob.

server_tokens off;

# Mozilla "intermediate" TLS. No OCSP stapling: Let's Encrypt retired its
# OCSP responders in 2025.
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
ssl_prefer_server_ciphers off;
ssl_session_cache shared:SSL:10m;
ssl_session_timeout 1d;
ssl_session_tickets off;

if ($lan_https_redirect) {
    return 301 https://$host$request_uri;
}

root /var/www/spa;             # built SPA (frontend/dist)
index index.html;

client_max_body_size 210M;     # keep >= PHP post_max_size (docker/php/uploads.ini)

gzip on;
gzip_vary on;
gzip_comp_level 6;
gzip_min_length 1024;
gzip_types text/css application/javascript application/json image/svg+xml
           application/manifest+json text/plain;

# Security headers for statically served SPA responses. API responses get
# theirs from Laravel's SecurityHeaders middleware. CSP origins: OSM tiles
# (LocationMap), Nominatim geocoding, wss for Reverb — everything else is
# bundled (fonts via @fontsource, no CDNs). connect-src also lists the three
# server origins so the SPA's failover probes (utils/serverFailover.js
# pinging /up cross-origin) pass CSP from whichever origin the page loaded on.
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options DENY always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
add_header Permissions-Policy "geolocation=(), microphone=(self)" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.tile.openstreetmap.org; connect-src 'self' wss: https://nominatim.openstreetmap.org https://app.plaza-pro.com https://office.plaza-pro.com https://192.168.1.200; font-src 'self' data:; worker-src 'self' blob:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;

# Credential endpoints first — regex locations match in file order, so this
# must precede the generic API block to get the limit_req applied.
location ~ ^/api/v1/auth/(login|token)$ {
    limit_req zone=auth burst=5 nodelay;
    root /var/www/html/public;
    try_files /nonexistent /index.php?$query_string;
}

# Laravel: everything API-shaped goes to the front controller.
location ~ ^/(api|sanctum|broadcasting|up)(/|$) {
    root /var/www/html/public;
    try_files /nonexistent /index.php?$query_string;
}

location ~ \.php$ {
    internal;                        # only reachable via try_files redirects — direct /index.php probing gets 404
    root /var/www/html/public;
    resolver 127.0.0.11 valid=10s;   # re-resolve so app container recreation doesn't wedge nginx at a stale IP
    set $upstream app:9000;
    fastcgi_pass $upstream;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
    # Direct-LAN TLS: tell PHP the request was secure (the tunnel path gets
    # this from Cloudflare's X-Forwarded-Proto instead).
    fastcgi_param HTTPS $https if_not_empty;
    # Laravel trusts proxy headers ('*'); pin X-Forwarded-For to the real
    # client IP (post-realip) so neither path can spoof its way past
    # throttles or falsify audit-log IPs with a forged header.
    fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;
    fastcgi_read_timeout 120s;       # matches queue --timeout; PDF/report requests never exceed this
    fastcgi_hide_header X-Powered-By;
}

# Reverb websocket (clients connect to wss://<domain>/app/<key>).
location /app/ {
    resolver 127.0.0.11 valid=10s;
    set $reverb reverb:8081;
    proxy_pass http://$reverb;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 180s;         # > Reverb's 60s ping interval, with margin
    proxy_send_timeout 180s;
}

# Hashed build assets are immutable — cache for a year. (`expires` instead
# of add_header so the security headers above still inherit.)
location /assets/ {
    expires 1y;
    try_files $uri =404;
}

# The SPA shell must always revalidate so deploys reach browsers.
location = /index.html {
    expires -1;
}

# The service worker is the PWA's update mechanism — a cached copy would
# pin every installed phone to an old app shell. Always revalidate.
location = /sw.js {
    expires -1;
}

# Android app download (signed APK + version.json, published out-of-band by
# scripts/build-android.sh into ./downloads — NOT under the SPA root, which
# is wiped on every frontend build). Scoped types{} so .apk always gets the
# installer content-type; always revalidate so phones see new versions.
location ^~ /downloads/ {
    root /var/www;
    types {
        application/vnd.android.package-archive apk;
        application/json json;
    }
    # no-store (not just no-cache): .apk is on Cloudflare's default cached-
    # extension list and a stale edge copy once served phones a 3-versions-old
    # build. The install page also links VERSIONED filenames (new URL per
    # release) as the primary defence.
    add_header Cache-Control "no-store" always;
    try_files $uri =404;
}

# SPA fallback: any non-file route is a Vue Router path.
location / {
    try_files $uri $uri/ /index.html;
}

location ~ /\.(?!well-known).* {     # block dotfiles
    deny all;
}
