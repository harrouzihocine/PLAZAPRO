# Phase 0 · Step 03 — Install & Configure Laravel

**Goal:** a pure REST API that speaks JSON, holds all business rules, owns the database, and exposes a
versioned `/api/v1` surface with Sanctum authentication.

Run all commands **inside the `app` container** so they use PHP 8.3, not the host.

---

## 1. Create the Laravel project into `backend/`

The `backend/` folder is bind‑mounted at `/var/www/html`. Create the app there:

```bash
docker compose exec app composer create-project laravel/laravel .
# (the trailing "." installs into the current dir /var/www/html)
```

If the folder must be empty first, install to a temp dir and move, or use:

```bash
docker compose exec app sh -c "composer create-project laravel/laravel /tmp/app && cp -a /tmp/app/. /var/www/html/ && rm -rf /tmp/app"
```

Generate the app key:

```bash
docker compose exec app php artisan key:generate
```

## 2. Configure `backend/.env`

Point Laravel at the container services (hostnames = compose service names):

```dotenv
APP_NAME="PLAZA PRO"
APP_ENV=local
APP_KEY=base64:...            # set by key:generate
APP_DEBUG=true
APP_TIMEZONE=UTC              # store UTC; format per-user in the UI
APP_URL=http://localhost:8080
FRONTEND_URL=http://localhost:5173

DB_CONNECTION=mysql
DB_HOST=mysql                 # compose service name
DB_PORT=3306
DB_DATABASE=plaza
DB_USERNAME=root
DB_PASSWORD=secret

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=redis

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS="no-reply@plaza.local"
MAIL_FROM_NAME="${APP_NAME}"

FILESYSTEM_DISK=local         # media policy defined in database/02-inventory.md

# SPA auth (Sanctum) — see Step 06
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8080
SESSION_DOMAIN=localhost
```

Keep `backend/.env.example` in sync (same keys, blanked secrets). Commit `.env.example`, **never** `.env`.

## 3. Install Sanctum (auth foundation)

```bash
docker compose exec app composer require laravel/sanctum
docker compose exec app php artisan install:api        # publishes Sanctum + api routes (Laravel 11+)
```

This creates `routes/api.php` and wires `EnsureFrontendRequestsAreStateful` for SPA cookie auth. Full
RBAC on top of this is [`06-auth-and-rbac.md`](06-auth-and-rbac.md).

## 4. Versioned API surface (`/api/v1`)

All routes are versioned and plural. Group them under a `v1` prefix. In `backend/bootstrap/app.php`
(Laravel 11+) the API routes file is already registered; structure `routes/api.php` like:

```php
// routes/api.php
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Each module contributes its own routes file (Step 07):
    require app_path('Modules/Settings/routes.php');
    require app_path('Modules/Inventory/routes.php');
    require app_path('Modules/Clients/routes.php');
    require app_path('Modules/Pipeline/routes.php');
    require app_path('Modules/Payments/routes.php');
    require app_path('Modules/Collaboration/routes.php');
    require app_path('Modules/Analytics/routes.php');
});
```

> Create these `routes.php` files as empty stubs now (Step 07 fills them) so the `require`s don't fail.

Confirm the version prefix:

```bash
docker compose exec app php artisan route:list --path=api/v1
```

## 5. Force JSON + consistent error shape

The API must always answer JSON (never an HTML error page). In `bootstrap/app.php`, in the
`withExceptions` closure, render API exceptions as JSON:

```php
->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
    $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
});
```

This yields a predictable `{ "message": ..., "errors": {...} }` envelope for 422/401/403/404, which the
frontend `useApi` interceptor relies on (Step 08).

## 6. Timezone & localisation notes

- **Store** all timestamps in **UTC** (`APP_TIMEZONE=UTC`); format per user in the UI.
- Money uses `decimal(12,2)` in the DB and integer‑cents or `bcmath` in PHP for arithmetic — never
  floats (the `bcmath` extension is installed in the PHP image). Rounding rules are documented with
  `versements` in [`../database/04-payments.md`](../database/04-payments.md).

## 7. Verify

```bash
docker compose exec app php artisan about        # environment summary
curl -s http://localhost:8080/api/v1/ping        # after you add a temporary ping route
```

Add a temporary sanity route inside the `v1` group:

```php
Route::get('/ping', fn () => response()->json(['pong' => true, 'ts' => now()]));
```

`curl http://localhost:8080/api/v1/ping` should return `{"pong":true,...}`. Remove it once real routes exist.

---

## Checklist / gate

- [ ] Laravel installed in `backend/`; `php artisan about` runs inside the container.
- [ ] `backend/.env` points at `mysql`, `redis`, `mailpit` by service name; `.env.example` committed.
- [ ] Sanctum installed; `install:api` run; `routes/api.php` uses a `v1` prefix.
- [ ] API returns JSON errors (not HTML) for `/api/*`.
- [ ] `GET /api/v1/ping` returns JSON through nginx on `:8080`.

**Next:** [`04-core-base-model.md`](04-core-base-model.md)
