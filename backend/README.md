# backend/ — Laravel API

This folder holds the Laravel REST API. Some **framework‑agnostic foundation code is already staged
here** (it does not depend on a specific Laravel version):

```
app/Core/                         # BaseModel + Cancellable/LogsActivity/HasVersions traits, RecordStatus enum, exception
app/Modules/<7 modules>/          # module skeleton + routes.php stubs (+ Analytics/Models/ActivityLog.php)
app/Http/Middleware/SecurityHeaders.php
database/migrations/2026_01_01_000000_create_activity_log_table.php
pint.json
```

Because these files are already present, install Laravel with a **no‑clobber copy** so the framework
fills in everything else without overwriting the staged foundation.

## Install (run inside the `app` container, from repo root)

```bash
# 1) Bring the stack up (from the repo root)
cp ../.env.example ../.env 2>/dev/null || true
docker compose up -d

# 2) Create Laravel in a temp dir, then copy in WITHOUT clobbering the staged files
docker compose exec app sh -c '
  composer create-project laravel/laravel /tmp/laravel &&
  cp -rn /tmp/laravel/. /var/www/html/ &&      # -n = no-clobber: keep app/Core, app/Modules, pint.json, migration
  rm -rf /tmp/laravel
'

# 3) App key + env (edit backend/.env per docs/phase-0-foundations/03-laravel-install.md)
docker compose exec app php artisan key:generate

# 4) Sanctum (SPA cookie auth) + versioned API
docker compose exec app composer require laravel/sanctum
docker compose exec app php artisan install:api
```

Then follow the numbered guide from
[`../docs/phase-0-foundations/03-laravel-install.md`](../docs/phase-0-foundations/03-laravel-install.md)
onward — it covers `.env`, the `/api/v1` prefix wiring in `routes/api.php`, JSON error rendering,
registering `SecurityHeaders` middleware, RBAC, seeders, and tests.

## Wire‑up reminders (from the docs)

- `routes/api.php`: wrap a `Route::prefix('v1')` group and `require` each `app/Modules/*/routes.php`.
- `bootstrap/app.php`: render JSON for `api/*` exceptions; `append(SecurityHeaders::class)`; named
  rate limiters (`login`, `api`).
- `config/auth.php`: point the `users` provider at the module `User` model once it's created (Step 06).
- Every domain model **extends `App\Core\Models\BaseModel`** — never `Illuminate\...\Model` directly.

## Verify the foundation

```bash
docker compose exec app php artisan migrate       # creates activity_log (+ Laravel defaults)
docker compose exec app php artisan test          # add the no-delete + audit tests from the docs
docker compose exec app ./vendor/bin/pint --test  # style gate
```
