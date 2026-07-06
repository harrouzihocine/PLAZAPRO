---
name: verify
description: Build/launch/drive recipe for verifying PLAZA PRO changes end-to-end (Docker stack, cookie-auth API driving, seeded data)
---

# Verifying PLAZA PRO changes

Stack is already up via `docker compose up -d` (containers: app, nginx :8080, node :5173 vite dev, mysql, redis, queue, reverb, mailpit).

## Build / migrate / seed
- Dev-DB artisan **inside the container** (`.env` has `DB_HOST=mysql`):
  `docker compose exec -T app php artisan migrate --force` (or `migrate:fresh --seed --force` to reset the dev DB — it's demo data).
- Test suite: `docker compose exec -T app sh -c 'DB_HOST=mysql php artisan test --compact'`
  (phpunit.xml pins 127.0.0.1 for host runs; the override works in-container).
- Frontend build **in the node container** (host `dist/` is container-owned):
  `docker compose exec -T node sh -c 'cd /app && npm run -s build'`; lint on host: `cd frontend && npm run lint`.
- Vite dev server hot-reloads the mounted source at :5173; check `docker compose logs node --since 5m` for compile errors.

## Drive the surface (API, cookie auth)
The SPA meets the API at `http://localhost:8080/api/v1`. Sanctum SPA cookie mode — bare curl POSTs 500 by design. Recipe (python urllib works well):
1. `GET /sanctum/csrf-cookie` with `Origin: http://localhost:5173`, keep the cookie jar.
2. Every request: `Origin: http://localhost:5173`, `Accept: application/json`, `X-XSRF-TOKEN: <urldecoded XSRF-TOKEN cookie>`.
3. Login: `POST /api/v1/auth/login` `{"email":"admin@plaza.local","password":"password"}` (all seeded users use `password`; sarah/karim/amina@plaza.local are agents).
See a full worked example (deal workflow, 48 checks) in the session scratchpad pattern: create client → log calls with closures → drive deals/payments → assert via API + `docker compose exec -T mysql mysql -uplaza -pplaza plaza -N -e "…"`.

## Gotchas
- Seeders: `LocationSeeder`/`UnitSeeder`/`ClientSeeder` are idempotent (skip when data exists); `migrate:fresh --seed` is the clean reset.
- No Playwright in the repo — UI verification is API-drive + successful vite build.
- Two consecutive `php artisan test` runs back-to-back can race the test DB; re-run once before believing failures.
