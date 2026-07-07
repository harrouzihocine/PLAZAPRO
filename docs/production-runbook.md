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

### 1.5 LAN HTTPS (split-horizon — office devices talk to the server directly)

Same hostname on both sides: the internet resolves `plaza.example.com` to the Cloudflare tunnel,
the **office LAN resolves it to the server's LAN IP** and gets nginx:443 with a real Let's Encrypt
certificate. Because the hostname never changes, cookies, Sanctum, CSP and websockets all just
work; nothing in the app config knows the difference. The cert is issued/renewed via **DNS-01
through the Cloudflare API**, so no inbound port opens — the tunnel-only posture stands.

1. Cloudflare dashboard → **My Profile → API Tokens → Create token**: one permission only,
   **Zone → DNS → Edit**, scoped to this site's zone. Paste into `/srv/plaza/.env` →
   `CLOUDFLARE_DNS_API_TOKEN=...`
2. ```bash
   ./scripts/setup-lan-tls.sh plaza.example.com
   ```
   Issues the cert (stored in the `letsencrypt` docker volume), points nginx at it, reloads, and
   installs a twice-daily renewal cron (`scripts/renew-lan-cert.sh`, logs to
   `~/backups/plaza/cert-renew.log`). Idempotent — rerun any time.
3. **Office DNS override** (the split-horizon half): make the office answer
   `plaza.example.com` with the server's LAN IP. If the router supports "DNS host mapping" /
   "local DNS records", use that. If not (e.g. the stock Nokia GPON gateway), run the bundled
   forwarder: set `LAN_DNS_IP=<server LAN IP>` in `.env`, then
   `docker compose --profile lan-dns up -d` — and in the router's **LAN/DHCP settings** set
   primary DNS = the server's LAN IP (secondary 8.8.8.8: if the server is down, clients fall
   back and simply reach the app via the tunnel instead). Give the server a static LAN IP.
4. Verify from a LAN machine: `curl -v https://plaza.example.com/up` → 200 with a **Let's
   Encrypt** cert (not Cloudflare's), and `nslookup plaza.example.com` returns the LAN IP.
   Plain `http://` on the LAN answers 301 → https.

Notes:
- If the router does DNS-rebind protection, whitelist the domain (the override itself is fine on
  most firmwares; only *upstream* answers with private IPs get filtered).
- Port 80 stays published for the container healthcheck and the HTTP→HTTPS redirect; the tunnel
  path is exempt from the redirect (Cloudflare already terminated TLS), so nothing loops.
- nginx loads the cert from stable copies at `letsencrypt` volume path `/etc/letsencrypt/nginx/`;
  until the first successful issuance a self-signed placeholder sits there (deploy.sh seeds it)
  so nginx always boots — browsers warn, tunnel traffic is unaffected.
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
