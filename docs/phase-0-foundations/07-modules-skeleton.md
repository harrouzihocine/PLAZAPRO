# Phase 0 · Step 07 — The Module Skeleton

**Goal:** introduce the `app/Modules/` structure so each feature owns its own models, actions,
controllers, requests and resources. This is what makes the system **extensible** — a new module is a
new folder, not a change scattered across the app.

---

## 1. Create the module folders

```bash
docker compose exec app sh -c '
for m in Settings Inventory Clients Pipeline Payments Collaboration Analytics; do
  mkdir -p app/Modules/$m/{Models,Actions,Http/Controllers,Http/Requests,Http/Resources}
  touch app/Modules/$m/routes.php
done
mkdir -p app/Core/{Models,Concerns,Enums,Exceptions}
'
```

Resulting layout (guide §4.1):

```
backend/app/
├── Modules/
│   ├── Settings/        # roles, departments, users, dynamic lists
│   ├── Inventory/       # locations, units, boxes, media
│   ├── Clients/         # clients, desire
│   ├── Pipeline/        # calls, visits, tasks, next-action
│   ├── Payments/        # versements, schedules, documents
│   ├── Collaboration/   # chat, notifications
│   └── Analytics/       # dashboards, audit views
│       ├── Models/
│       ├── Actions/         # one job each (business logic)
│       ├── Http/
│       │   ├── Controllers/ # thin — call actions
│       │   ├── Requests/    # validation
│       │   └── Resources/   # JSON shaping
│       └── routes.php
├── Core/                # base model, traits, audit, RBAC
└── Providers/
```

## 2. PSR‑4 autoloading for the module namespace

`app/` already maps to `App\` in `composer.json`, so `app/Modules/Inventory/Models/Unit.php` is
`App\Modules\Inventory\Models\Unit` with **no extra config**. Confirm the default block reads:

```json
"autoload": { "psr-4": { "App\\": "app/" } }
```

If you add any non‑PSR‑4 paths later, run `docker compose exec app composer dump-autoload`.

## 3. Per‑module route registration

Each module's `routes.php` is required by the versioned group in `routes/api.php` (Step 03). Keep each
file focused on its own resources:

```php
// app/Modules/Inventory/routes.php   (already inside the /api/v1 prefix group)
use App\Modules\Inventory\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/units',                 [UnitController::class, 'index'])->middleware('can:units.view');
    Route::post('/units/{unit}/reserve', [UnitController::class, 'reserve'])->middleware('can:units.reserve');
    // ...
});
```

> Leaving each `routes.php` as an empty (but existing) stub during Phase 0 keeps the `require`s in
> `routes/api.php` from failing before the controllers exist.

## 4. Optional: a `ModuleServiceProvider` for view/config/lang per module

For Phase 0 you do **not** need per‑module service providers — routes are wired centrally and models
autoload by PSR‑4. Add a lightweight provider only if a module later needs its own config, migrations
path, translations or events:

```php
// app/Providers/ModuleServiceProvider.php (only if/when needed)
public function boot(): void
{
    foreach (glob(app_path('Modules/*/migrations'), GLOB_ONLYDIR) as $path) {
        $this->loadMigrationsFrom($path);
    }
}
```

By default, **keep migrations in `database/migrations/`** (standard Laravel) — simpler, and the schema
docs assume that location. Only move to per‑module migrations if the team explicitly wants it.

## 5. The anatomy of one module (reference)

Every module repeats the same shape. For `Inventory`:

| Folder | Holds | Example |
|--------|-------|---------|
| `Models/` | Eloquent models (extend `BaseModel`), enums | `Unit.php`, `Location.php`, `UnitStatus.php` |
| `Actions/` | one job each — the business rules | `ReserveUnit.php`, `ExpireReservationHolds.php` |
| `Http/Controllers/` | thin controllers, delegate to Actions | `UnitController.php` |
| `Http/Requests/` | FormRequest validation | `ReserveUnitRequest.php` |
| `Http/Resources/` | API Resources — shape JSON | `UnitResource.php` |
| `routes.php` | the module's routes | — |

This anatomy is the **vertical‑slice recipe** — see
[`../conventions/vertical-slice-recipe.md`](../conventions/vertical-slice-recipe.md).

---

## Checklist / gate

- [ ] All seven module folders exist with `Models/ Actions/ Http/{Controllers,Requests,Resources}/ routes.php`.
- [ ] `app/Core/` exists (from Step 04).
- [ ] A class placed in `app/Modules/Inventory/Models/` autoloads as `App\Modules\Inventory\Models\...`.
- [ ] `routes/api.php` requires each module's `routes.php` inside the `v1` prefix without error.
- [ ] `php artisan route:list` runs clean.

**Next:** [`08-vue-install.md`](08-vue-install.md)
