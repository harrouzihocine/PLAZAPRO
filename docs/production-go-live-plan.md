# Production Go-Live Plan

**Date:** 2026-07-06 · **Audit basis:** full-stack scan of Docker, nginx, Laravel, Vue, queue/scheduler/Reverb,
security posture, and code quality. Companion to [`phase-7-hardening-launch.md`](phase-7-hardening-launch.md) —
this file makes Phase 7 concrete for our actual deployment: **one office server (12 cores / 30 GB RAM),
~30 internal users, external access for the future mobile app via an HTTPS tunnel.**

---

## 1. Audit verdict (what's already right)

The app itself is in good shape; the gaps are almost entirely **infrastructure/config**, not code:

- **Tests:** 500/500 passing (1710 assertions, ~27 s). Run from the host, or inside the container with
  `docker compose exec -e DB_HOST=mysql app php artisan test` (phpunit.xml pins `127.0.0.1`).
- **Dependency audits clean:** `composer audit` and `npm audit` report zero vulnerabilities.
- **AuthZ:** every module routes file sits behind `auth:sanctum` plus per-permission `can:` middleware;
  a single `Gate::before` resolves RBAC slugs; login and API rate limiters exist.
- **Mobile-ready auth already built:** `POST /auth/token` issues Sanctum Bearer tokens (one per device,
  throttled) alongside the SPA cookie flow.
- **Files:** media/documents/chat live on **private** disks and are only served through permission-gated
  streaming controllers — never public URLs.
- **Security headers** middleware in place, with HSTS wired to turn on in production.
- **Architecture:** clean module structure (7 backend modules with Actions/Http/Models, frontend feature
  folders), route-level code splitting, one axios instance with CSRF retry, eager-loading discipline
  (`with([...])`/`whenLoaded` used consistently), no `dd()`/`console.log` leftovers, `.env` never committed.
- **Prod build:** `vite build` succeeds; largest gzipped chunk 78 kB — healthy.

## 2. Findings to fix (ranked)

### Blockers — must be done before exposing the app

| # | Finding | Where |
|---|---------|-------|
| B1 | `APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug` — stack traces and SQL leak to users | `backend/.env` |
| B2 | Dev-grade secrets: DB password 5 chars, MySQL root `secret`, short Reverb key/secret | root + backend `.env` |
| B3 | MySQL published on `0.0.0.0:3306` — reachable from the whole LAN (and the internet if the box is ever port-forwarded) | `docker-compose.yml` |
| B4 | No `trustProxies` config. Behind a tunnel/TLS proxy Laravel won't see HTTPS (breaks secure cookies + signed URLs) **and the API rate limiter keys unauthenticated requests by the proxy IP — all external users would share one 120 req/min bucket** | `bootstrap/app.php` |
| B5 | No HTTPS story yet; `SESSION_SECURE_COOKIE` unset | infra + `.env` |
| B6 | Frontend is served by the Vite **dev server**; prod must serve `dist/` from nginx (same origin as the API — also makes CORS a non-issue) | compose + nginx |
| B7 | Reverb `allowed_origins => ['*']` — any site may connect to the websocket | `config/reverb.php` |
| B8 | **No backups** — no mysqldump schedule, no media backup, no tested restore | infra |

### High — should land with go-live

| # | Finding | Where |
|---|---------|-------|
| H1 | php-fpm at image defaults: `pm.max_children = 5` — five concurrent requests total, then queueing | php image |
| H2 | No prod opcache tuning (`validate_timestamps=On`, 128 MB) | php image |
| H3 | No deploy-time optimization: `config:cache route:cache view:cache event:cache`, `composer install --no-dev` | deploy script |
| H4 | Queue: `database` driver, single worker, no `--max-time` (slow PHP memory creep never recycled) | compose + `.env` |
| H5 | Logging: unbounded single `laravel.log`; Docker json-file logs also unbounded | `.env` + compose `logging:` |
| H6 | nginx: no gzip, no static-asset cache headers, no websocket proxy for Reverb, no fastcgi timeouts | `docker/nginx` |
| H7 | `MAIL_MAILER=log` — fine while notifications are in-app only; decide SMTP before any email feature matters | `.env` |
| H8 | Dev-only services (`node`, `mailpit`) and dev ports must not exist in the prod stack | compose |

### Medium — quality cleanups (not launch-blocking)

| # | Finding | Where |
|---|---------|-------|
| M1 | Dead code: `POST /shortlist-items/{item}/outcome` + `ShortlistController::outcome` + FE `shortlistApi.outcome()` have no callers | Clients module + `features/clients/api.js` |
| M2 | Only 4 endpoints paginate; most lists return full collections. Fine at 30 users / current data volume — add pagination to the heaviest lists (units, clients, team-logs) as data grows | modules |
| M3 | `Model::preventLazyLoading(! app()->isProduction())` not enabled — cheap permanent N+1 tripwire for dev/CI | `AppServiceProvider` |
| M4 | `Password::defaults()` = min 8; consider `->uncompromised()` + min 10 in prod | `AppServiceProvider` |
| M5 | CI runs `composer audit \|\| true` — phase 7 says audits become **blocking** | `.github/workflows/ci.yml` |
| M6 | `robots.txt` allows all crawling; internal CRM should be `Disallow: /` | `backend/public` |
| M7 | `DealPanel.vue` at 832 lines — split next time it's touched | frontend |
| M8 | Redis has no password — acceptable on the internal compose network, add `requirepass` if anything else ever joins that network | compose |

---

## 3. Target production architecture

```
Internet (mobile app + remote users)
        │ https://plaza.<your-domain>  (TLS terminates at Cloudflare edge — free)
        ▼
cloudflared container  (outbound-only tunnel — NO router ports opened)
        ▼
nginx (prod vhost)
  ├─ /            → frontend/dist  (built SPA, static, cached, gzipped)
  ├─ /api, /sanctum, /broadcasting, /up → php-fpm (app)
  └─ /app         → reverb:8081   (websocket upgrade proxy → wss for clients)
app (php-fpm, tuned) ── mysql (internal only) ── redis (internal only)
queue ×2 (redis driver) · scheduler · reverb    [all restart: unless-stopped]
```

Key properties: one public origin for SPA + API + websockets (no CORS, cookies "just work");
DB/Redis unreachable from outside the compose network; office LAN users can use the same domain
(or an internal nginx port if you want LAN traffic to skip the tunnel).

### External access decision (B5)

**Recommended: Cloudflare Tunnel.**
- Free TLS, free unlimited tunnel, hides the office IP, no router port-forwarding, DDoS protection,
  websockets supported (Reverb works over it).
- Only cost: a registered domain (~$10/yr) added to Cloudflare's free plan.
- Later "paid certificates" are unnecessary — for an API/SPA, Let's Encrypt/Cloudflare certs are
  cryptographically identical to paid ones; don't budget for them.
- Optional extra wall: Cloudflare Access (Zero Trust) is free for up to 50 users — can require a login
  at the edge for the web UI. Skip it for `/api/*` (the mobile app authenticates with Sanctum tokens).

**Fallback (no domain purchase):** DuckDNS subdomain + port-forward 443 + certbot/Let's Encrypt.
Free, but exposes the office IP and requires open router ports. Only if buying a domain is refused.

---

## 4. Sizing for 30 users (12 cores / 30 GB RAM)

30 concurrent office users ≈ a few requests/second with bursts. These numbers leave ~10× headroom:

| Component | Setting |
|-----------|---------|
| php-fpm | `pm = dynamic`, `pm.max_children = 20`, `pm.start_servers = 6`, `pm.min_spare_servers = 4`, `pm.max_spare_servers = 10`, `pm.max_requests = 500` (~70–120 MB/worker → worst case ≈ 2.5 GB) |
| opcache (prod ini) | `opcache.memory_consumption = 192`, `opcache.max_accelerated_files = 20000`, `opcache.validate_timestamps = 1`, `opcache.revalidate_freq = 60` (code changes picked up ≤ 60 s after deploy; no fpm restart dance) |
| queue | `QUEUE_CONNECTION=redis`, **2 workers**, `--tries=3 --timeout=120 --max-time=3600` (worker exits hourly, compose restarts it fresh) |
| Reverb | single process — fine for hundreds of connections |
| MySQL | add `--innodb-buffer-pool-size=2G`; keep the rest default |
| Sessions | keep `database` driver (survives Redis restarts) |
| Cache | keep `redis` |

---

## 5. Work plan

### Phase A — prod environment & secrets (B1, B2, B5-env)
1. Create the prod deployment directory (e.g. `/srv/plaza`) as a **separate checkout** with its own
   `.env` files and volumes; dev in `~/www` keeps running independently (different
   `COMPOSE_PROJECT_NAME`, different ports).
2. New `backend/.env` for prod: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=info`,
   `LOG_STACK=daily` (+ `LOG_DAILY_DAYS=30`), fresh `php artisan key:generate`,
   `APP_URL=https://plaza.<domain>`, `SESSION_SECURE_COOKIE=true`,
   `SANCTUM_STATEFUL_DOMAINS=plaza.<domain>`, `SESSION_DOMAIN=plaza.<domain>`,
   `QUEUE_CONNECTION=redis`.
3. Rotate every secret: 32-char random DB password + MySQL root password, 32-char
   `REVERB_APP_KEY`/`REVERB_APP_SECRET` (`openssl rand -hex 32`). MySQL app user stays least-privilege
   (`plaza`, single schema).
4. Frontend prod env: `VITE_REVERB_HOST=plaza.<domain>`, `VITE_REVERB_PORT=443`,
   `VITE_REVERB_SCHEME=https`.

### Phase B — production compose + nginx (B3, B6, B7, H1, H2, H4, H5, H6, H8)
1. Add `docker-compose.prod.yml`: drop `node` + `mailpit`; **no published ports** for mysql/reverb;
   nginx as the only entry point; `restart: unless-stopped` everywhere;
   `logging: { driver: json-file, options: { max-size: 10m, max-file: "5" } }` on every service.
2. Prod nginx vhost: serve `frontend/dist` at `/` with `try_files ... /index.html` SPA fallback and
   1-year immutable cache on `/assets/*`; proxy `/api`, `/sanctum`, `/broadcasting`, `/up` to php-fpm;
   proxy `/app` to `reverb:8081` with `Upgrade`/`Connection` headers; enable gzip; keep
   `client_max_body_size 210M`; add `fastcgi_read_timeout 120s`.
3. php image: add `production.ini` (opcache table above) + a `www.prod.conf` fpm pool with the §4 numbers
   (mounted only by the prod compose file, dev stays as-is).
4. Queue service: `command: php artisan queue:work redis --tries=3 --timeout=120 --max-time=3600`,
   `deploy: { replicas: 2 }` (or duplicate the service block).
5. `config/reverb.php`: `allowed_origins => [env('REVERB_ALLOWED_ORIGIN', '*')]` → set to the prod domain.
6. `robots.txt` → `Disallow: /` (M6).

### Phase C — tunnel, proxies, HTTPS (B4, B5)
1. Register/attach domain on Cloudflare (free plan) → create a **Tunnel** → run the `cloudflared`
   container on `plaza-net` pointing at `http://nginx:80`; map `plaza.<domain>` to it. Enable
   websockets (on by default) and set SSL mode "Full".
2. `bootstrap/app.php`: `$middleware->trustProxies(at: '*')` — required so Laravel honours
   `X-Forwarded-Proto/For` from cloudflared+nginx (secure cookies, correct client IPs for the
   login/API rate limiters).
3. Verify end-to-end from a phone on mobile data: login (cookie flow), an API call, a live chat
   message (wss through the tunnel), a 200 MB media upload (Cloudflare free caps request bodies at
   100 MB — document that big uploads happen on the LAN, or split uploads later if the mobile app
   needs them).

### Phase D — deploy procedure & backups (B8, H3)
1. `deploy.sh` in the prod checkout:
   `git pull` → `composer install --no-dev --optimize-autoloader` → `npm ci && npm run build` →
   **db backup** → `php artisan down` → `migrate --force` →
   `config:cache && route:cache && view:cache && event:cache` → `queue:restart` → `php artisan up`.
2. Backups: nightly `mysqldump --single-transaction` + `tar` of `storage/app` (media/documents/chat),
   7 daily + 4 weekly retained, **copied off the box** (second machine, external disk, or object
   storage). Do one full **restore drill** before go-live — a backup that's never been restored
   doesn't count.
3. Add the backup job to the host crontab (not the app scheduler — it must run even if the app is down).

### Phase E — monitoring (phase-7 §6)
1. Free uptime monitor (e.g. UptimeRobot) on `https://plaza.<domain>/up`.
2. Daily check of `failed_jobs` — simplest: a scheduled command that notifies admins in-app/by mail
   when `failed_jobs > 0`.
3. Enable MySQL slow-query log (>1 s) in the prod compose; review weekly.
4. Optional: Sentry free tier for exception tracking (Laravel + Vue SDKs).

### Phase F — code cleanups (M1–M5, M7)
1. Remove the dead shortlist-outcome endpoint, controller method, FormRequest (if dedicated), and
   `shortlistApi.outcome()` (M1).
2. `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider::boot` (M3) — run the
   test suite after; fix anything it flushes out.
3. `Password::defaults()` hardened in prod (M4).
4. CI: make `composer audit` blocking; add `npm audit --omit=dev` blocking (M5).
5. Paginate the heaviest list endpoints as data grows (M2); split `DealPanel.vue` opportunistically (M7).

---

## 6. Go-live checklist (run top to bottom on launch day)

- [ ] Prod `.env`s in place, all secrets rotated, `APP_DEBUG=false` confirmed via `/api/v1/ping` error test
- [ ] `docker compose -f docker-compose.prod.yml up -d` — all services healthy, no published DB/Reverb ports (`docker compose ps`)
- [ ] SPA loads over `https://plaza.<domain>`, login works, cookie marked `Secure`
- [ ] Chat/notification arrives live over `wss` (no refresh) from outside the LAN
- [ ] `php artisan config:cache route:cache view:cache event:cache` ran; `php artisan about` shows caches ✔
- [ ] Queue: 2 workers up; a test notification job processes; `failed_jobs` empty
- [ ] Scheduler ticking (holds expire, digest at 08:00)
- [ ] Backup ran, **restore drill performed once**
- [ ] Uptime monitor green; slow-query log on
- [ ] `composer audit` / `npm audit` clean; 500/500 tests green on the deployed commit
- [ ] Mobile-app smoke test: `POST /api/v1/auth/token` from outside → authorized API call with the Bearer token
