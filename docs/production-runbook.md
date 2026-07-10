# Production Runbook

Operations manual for the PLAZA PRO production stack. The *why* behind every choice is in
[`production-go-live-plan.md`](production-go-live-plan.md); this file is the *how*.

**Topology:** one office server runs the prod stack via `docker-compose.prod.yml`. The only public
entry point is a **Cloudflare Tunnel** (outbound-only — zero ports opened on the router). MySQL and
Redis live on an internal-only docker network: no published ports, no internet egress. TLS is free
and terminates at Cloudflare's edge for internet traffic; the **office LAN** talks straight to
nginx:443 with its own trusted Let's Encrypt certificate under the same hostname (§1.5), so office
traffic never hairpins through the internet and keeps working when the uplink is down.

---

## 1. First-time setup

### 1.1 Create the production checkout

Dev keeps living in `~/www`. Production is its own clone with its own env + volumes:

```bash
sudo mkdir -p /srv/plaza && sudo chown $USER:$USER /srv/plaza
git clone <repo-url> /srv/plaza
cd /srv/plaza
```

### 1.2 Generate secrets

```bash
./scripts/generate-prod-env.sh plaza.example.com     # your real domain
```

Writes `.env`, `backend/.env`, `frontend/.env.production` (mode 600) with 192-bit random
DB/Redis/Reverb secrets, all cross-referenced values in sync. The root `.env` also pins
`COMPOSE_FILE=docker-compose.prod.yml`, so **every `docker compose` command in this checkout
automatically targets the prod stack** — no `-f` juggling, no risk of booting the dev stack here.

### 1.3 Cloudflare Tunnel (the free HTTPS front door)

1. Buy/transfer a domain (~$10/yr — the only cost) and add it to a free Cloudflare account.
2. Zero Trust dashboard → **Networks → Tunnels → Create a tunnel** (connector: *Cloudflared*).
3. Copy the token into `/srv/plaza/.env` → `CLOUDFLARE_TUNNEL_TOKEN=...`
4. Add a **Public Hostname**: `plaza.example.com` → service `http://nginx:80`.
5. Websockets are on by default; SSL/TLS mode "Full" is fine (the hop to nginx stays inside docker).

Do **not** buy TLS certificates later — Cloudflare's (or Let's Encrypt's) are cryptographically
identical to paid ones; for an API/SPA there is nothing a paid cert adds.

### 1.4 First deploy

```bash
./scripts/deploy.sh --no-pull          # build SPA + image, composer --no-dev, APP_KEY, migrate, caches
docker compose --profile tunnel up -d  # start the tunnel
./scripts/install-backup-cron.sh       # 30-min day / hourly night DB backups + nightly media
```

Seed the first super-admin user, then verify §3.

### 1.5 LAN HTTPS (office devices reach the server directly, trusted cert)

Goal: office devices open the app over HTTPS with a real green padlock, hitting the server
**directly on the LAN** (fast, works even if the internet is down) instead of hairpinning out
through the tunnel. The cert is always a real Let's Encrypt one, issued/renewed via **DNS-01
through the Cloudflare API**, so no inbound port ever opens — the tunnel-only posture stands.

**Recommended method — a second hostname published to the private IP (no router/device config).**
This is what Plex/UniFi/Tailscale do and it works even behind a locked ISP router (the Algérie
Télécom **Nokia G-1425G-B** hides its LAN DNS/DHCP settings from the `userAdmin` account):

1. **Public DNS record**: in Cloudflare add `office.plaza-pro.com` → **A** `<server LAN IP>`,
   **DNS only (grey cloud — NOT proxied)**. Cloudflare warns "private IP"; save anyway. A private
   IP in public DNS only resolves to anything useful when you're actually on the LAN.
2. **Cert covers both names** (tunnel host + office host as SANs on one cert):
   ```bash
   ./scripts/setup-lan-tls.sh app.plaza-pro.com office.plaza-pro.com
   ```
   Issues/expands the cert (in the `letsencrypt` volume), points nginx at it, reloads, installs the
   twice-daily renewal cron. Idempotent.
3. **Trust the office origin** in Laravel — add it to `SANCTUM_STATEFUL_DOMAINS` in `backend/.env`
   (comma-separated) and `docker compose exec app php artisan optimize`. `SESSION_DOMAIN=null`
   keeps cookies per-host, so each URL gets its own valid session; the SPA builds API/websocket
   URLs from `window.location`, so nothing else changes.
4. **Result**: staff use `https://office.plaza-pro.com` at the office (direct, LAN speed);
   everyone else uses `https://app.plaza-pro.com` (tunnel). Both show a trusted cert.

Verify: `curl --resolve office.plaza-pro.com:443:<LAN IP> https://office.plaza-pro.com/up` → 200,
`ssl_verify_result 0`; and query the router (`nslookup office.plaza-pro.com <router IP>`) returns
the private IP — if it returns nothing, the router is doing **DNS-rebind protection** (see fallback).

**Fallback if the router strips private IPs (rebind protection) — split-horizon via local DNS.**
Run the bundled forwarder so LAN clients resolve the name locally instead of via public DNS:
set `LAN_DNS_IP=<server LAN IP>` + `LAN_TLS_DOMAIN=office.plaza-pro.com` in `.env`, then
`docker compose --profile lan-dns up -d` (dnsmasq answers the name → LAN IP, forwards the rest to
8.8.8.8). Point clients at it via the router's DHCP-DNS if available, else per-device DNS, else the
`lan-dhcp` profile (server runs DHCP; disable the router's first). All three need *some* client or
router reach — the recommended method above needs none, which is why it's preferred.

**Phones (the Android app) during an internet outage — the bare-IP door.** With the internet
down, phones can't resolve `office.plaza-pro.com` (their DNS goes router → ISP, and the locked
router can't hand out the local dnsmasq), so the APK carries a third, DNS-free origin:
`https://192.168.1.200`. Public CAs can't issue for a private IP, so it is served with a leaf
signed by our **private LAN CA** — trusted ONLY by the Android app (Network Security Config
pins it to that one IP; browsers keep their warning and the bare IP stays break-glass for them).

```bash
./scripts/setup-lan-ip-cert.sh              # in the PROD checkout; default IP 192.168.1.200
```

Creates the CA once in `secrets/lan-ip-ca/` (public half committed at
`frontend/android/app/src/main/res/raw/plaza_lan_ca.pem` — regenerating the CA means rebuilding
the APK; guard `ca.key` like the signing keystore), issues/renews the IP leaf, installs it as
`ip-fullchain.pem`/`ip-privkey.pem` in the letsencrypt volume, reloads nginx, and installs a
monthly renewal cron. nginx serves it from the `default_server` block (no-SNI clients =
bare-IP URLs); named hosts keep the Let's Encrypt cert. The app fails over between the three
origins by itself (`frontend/src/utils/serverFailover.js` + the shell's `PlazaWebViewClient`)
and shows a slim "office server" banner while parked on a LAN origin.

Optional but recommended: `SESSION_DOMAIN=.plaza-pro.com` in `backend/.env` (+
`php artisan optimize`) so a failover between `app.` and `office.` keeps the login session
(the bare-IP origin always needs a fresh login — cookies can't span host↔IP).

Notes:
- Port 80 stays published for the container healthcheck and the HTTP→HTTPS redirect; the tunnel
  path is exempt from the redirect (Cloudflare already terminated TLS), so nothing loops.
- nginx loads the certs from stable copies at `letsencrypt` volume path `/etc/letsencrypt/nginx/`;
  until the first successful issuance a self-signed placeholder sits there (deploy.sh seeds it,
  including the `ip-*.pem` pair) so nginx always boots — browsers warn, tunnel traffic is
  unaffected.
- Token rotation: edit `CLOUDFLARE_DNS_API_TOKEN` in `.env` — the renew script re-derives its
  credentials file from `.env` on every run.

## 2. Routine operations

| Task | Command (in `/srv/plaza`) |
|------|--------------------------|
| Deploy latest code | `./scripts/deploy.sh` |
| Stack status | `docker compose ps` |
| App logs | `docker compose logs -f app queue scheduler` |
| Manual DB snapshot | `./scripts/backup-db.sh manual` |
| Failed jobs check | `docker compose exec app php artisan queue:failed` |
| Tinker (careful!) | `docker compose exec app php artisan tinker` |

## 3. Post-deploy verification (2 minutes)

From a phone on **mobile data** (not office Wi-Fi):

1. `https://plaza.example.com` loads, login works, cookie shows `Secure` in devtools.
2. Send a chat message from another account → arrives **without refresh** (wss through the tunnel).
3. `POST https://plaza.example.com/api/v1/auth/token` with a device name returns a Bearer token,
   and an authorized API call with it succeeds (this is exactly what the mobile app will do).
4. `docker compose ps` — everything `Up`/`healthy`; `docker compose exec app php artisan about`
   shows Config/Routes/Views/Events **CACHED**.

## 4. Backups

Installed by `scripts/install-backup-cron.sh` (idempotent — safe to rerun after moving the checkout):

| What | When | Retention | Where |
|------|------|-----------|-------|
| DB (day) | every 30 min, 07:00–18:30 | last 25 | `~/backups/plaza/db/day/` |
| DB (night) | hourly, 19:00–06:00 | last 23 | `~/backups/plaza/db/night/` |
| DB (manual/pre-deploy) | each deploy + on demand | last 10 | `~/backups/plaza/db/manual/` |
| Media/documents/chat | nightly 21:30 | last 14 | `~/backups/plaza/media/` |

Every moment of the last 24 h is recoverable to ≤30 min (working hours) / ≤1 h (night). Dumps are
`zstd`-compressed (lossless — media archives restore **bit-identical**, photo/plan quality untouched).
Credentials never leave the mysql container; the log lives at `~/backups/plaza/backup.log`.

**Off-box copies:** a backup on the same disk as the database does not survive a dead disk. Sync
`~/backups/plaza` to a second machine/disk, e.g. hourly:
`rsync -a --delete ~/backups/plaza/ user@nas:/backups/plaza/`

### 4.1 Restore (drill this once BEFORE go-live)

```bash
cd /srv/plaza
docker compose exec app php artisan down
zstd -dc ~/backups/plaza/db/day/plaza-YYYYMMDD-HHMM.sql.zst | \
  docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose exec app php artisan up
```

Media restore: `zstd -dc ~/backups/plaza/media/plaza-media-....tar.zst | tar -x -C backend/storage/app`

## 5. Security model (what protects what)

- **No inbound ports**: cloudflared dials *out* to Cloudflare; the router forwards nothing.
- **DB cannot leak**: MySQL/Redis have no published ports and sit on an `internal: true` network —
  even a compromised container in there has **no route to the internet** to exfiltrate over.
- **Tunnel blast radius = nginx only**: cloudflared lives on its own `tunnel` network shared solely
  with nginx. Even someone controlling the Cloudflare account (rewiring the tunnel's service target)
  can reach nothing but nginx:80 — not php-fpm's FastCGI port, not Reverb, never mysql/redis. Keep
  **2FA on the Cloudflare account**; anyone in it can still repoint DNS at a phishing clone.
- **Secrets**: 192-bit random, mode-600 env files, never in git, never in images; mysqldump reads
  credentials from the container env.
- **Edge → app**: real client IPs recovered from `CF-Connecting-IP`; `trustProxies` makes Laravel
  see HTTPS + true IPs, so per-user/per-IP rate limits actually work. nginx overwrites
  `X-Forwarded-For` with the resolved client IP before php-fpm sees it — a forged header (from
  the internet *or* a LAN device) can't spoof throttle keys or audit-log IPs. nginx adds a
  10 req/min brute-force shield on `/auth/login` + `/auth/token` on top of Laravel's throttles.
- **Headers/CSP** on the SPA at nginx; API headers from Laravel middleware; HSTS on.
- **PHP**: `/index.php` marked `internal` (no direct probing), `expose_php off`, debug off,
  display_errors off; uploads stream to private disks served only via permission-gated endpoints.
- **Optional extra wall**: Cloudflare Access (free ≤50 users) can demand an email OTP before the
  web UI is even reachable. If enabled, **bypass** `/api/*` and `/broadcasting/*` (the mobile app
  and websockets authenticate with Sanctum, not a browser SSO).

### Known trade-offs

- `SESSION_SECURE_COOKIE=true` means login only works over HTTPS — satisfied everywhere: internet
  users via Cloudflare, office users via the LAN TLS listener (§1.5). Plain HTTP on the LAN
  redirects to HTTPS; nothing sessions over cleartext (by design).
- Cloudflare free caps request bodies at **100 MB** — from outside. On the LAN (§1.5 path) the
  full 200 MB media ceiling applies. Chunked uploads become a mobile-app work item if field
  agents ever need huge files from outside the office.
- `deploy.sh` takes a short maintenance window (`artisan down` → migrate → `up`). At 30 users,
  deploy off-hours; zero-downtime deploys are not worth their complexity here yet.

## 6. Rotating secrets

DB/Redis/Reverb secret rotation (e.g. staff departure):

1. `./scripts/backup-db.sh manual`
2. Edit `.env` + `backend/.env` (+ `frontend/.env.production` if the Reverb key changes) with new
   values from `openssl rand -hex 24`.
3. MySQL passwords live *inside* the volume, not the env: `docker compose exec mysql mysql -uroot -p
   → ALTER USER 'plaza'@'%' IDENTIFIED BY '<new>'; ALTER USER 'root'@'%'/'root'@'localhost' ...`
4. `docker compose up -d --force-recreate` then `./scripts/deploy.sh --no-pull` (rebuilds config
   cache; a Reverb key change requires the SPA rebuild the deploy performs anyway).

## 7. Monitoring

- **Uptime**: point a free checker (UptimeRobot or Cloudflare's) at `https://plaza.example.com/up`.
- **Queue**: `queue:failed` in §2; failed jobs also land in the `failed_jobs` table.
- **Slow queries**: enabled in prod MySQL (`>1 s` → `/var/lib/mysql/slow.log` in the `db-data` volume).
- **Slow requests**: php-fpm logs any request >5 s to `docker compose logs app`.
- **Disk**: backups + docker logs are rotation-capped, but check `df -h` monthly.
