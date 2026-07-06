# Phase 0 · Step 06 — Authentication & RBAC

**Goal:** login, and **route‑level permissions**. Each user has **exactly one role**; each role grants
a set of permissions; middleware checks the permission **before** the controller runs. An **`is_agent`
flag** on the role decides who appears in the visit‑assignment list, exactly as the product specifies.

> Access is controlled at the route level — a route is protected by a *permission*, not just "logged in".

---

## 1. Data model (see [`../database/01-settings.md`](../database/01-settings.md))

- `roles` — `id, name, slug, description, is_agent (bool), status, cancellation_reason, timestamps`
- `permissions` — `id, name, slug, group, description, timestamps`
- `permission_role` — pivot `(permission_id, role_id)`
- `users` — one `role_id` (FK), `department_id`, auth fields, `is_active`, `last_login_at`, base‑model columns

**One role per user** is a hard rule: `users.role_id` is a single nullable‑false FK, *not* a
many‑to‑many. Do not introduce a `role_user` pivot.

## 2. Choose the mechanism: native Gate vs. a package

Two valid paths — pick one and document it:

- **Native (recommended for full control):** define a Gate that resolves a permission slug against the
  user's role. No extra dependency; permissions live in your own tables.
- **Package (`spatie/laravel-permission`):** mature and convenient, but its default model allows
  *multiple* roles per user. If you use it, **enforce one‑role‑per‑user** in your `StoreUserRequest`
  and assignment Action, and keep the `is_agent` flag on your own `roles` table.

This guide shows the **native** approach.

## 3. Wire permissions into Laravel's Gate

Register a Gate that lets `can:units.reserve` middleware work against your tables:

```php
// app/Providers/AuthServiceProvider.php  (or a dedicated RbacServiceProvider)
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    // A single Gate::before resolves ANY permission slug (e.g. "units.reserve")
    // against the user's role — no need to define a gate per permission.
    Gate::before(function ($user, string $ability) {
        if ($user->role?->slug === 'super-admin') {
            return true;                                 // super-admin shortcut
        }
        // true  => allowed; null => fall through to any real policy/gate (then denied if none).
        // Using ?: null (not false) so explicit policies can still run.
        return $user->hasPermission($ability) ?: null;
    });
}
```

> **Why `Gate::before`, not `Gate::define('*')`:** Laravel has no wildcard ability name — defining
> `'*'` would register an ability literally called `*`. `Gate::before` runs ahead of every check, so
> it's the correct place to resolve dotted permission slugs dynamically from the user's role.

```php
// app/Modules/Settings/Models/User.php  (extends Authenticatable + uses HasApiTokens)
public function role() { return $this->belongsTo(Role::class); }

public function hasPermission(string $slug): bool
{
    return $this->relationLoaded('role')
        ? $this->role?->permissions->contains('slug', $slug)
        : $this->role?->permissions()->where('slug', $slug)->exists();
}

public function isAgent(): bool
{
    return (bool) $this->role?->is_agent;
}
```

> **Cache** the user's permissions per request (eager‑load `role.permissions`) so RBAC checks don't
> issue a query per route.

## 4. Protect routes with `can:`

Every write route names the permission it requires — this is the pattern the whole API follows:

```php
// A route is protected by a permission, not just "logged in"
Route::middleware(['auth:sanctum', 'can:units.reserve'])
    ->post('/units/{unit}/reserve', [UnitController::class, 'reserve']);
```

Permission slugs use `resource.action` dotted form: `units.view`, `units.reserve`, `clients.create`,
`versements.cancel`, `audit.view`, `users.manage`, …

## 5. SPA authentication with Sanctum

Use Sanctum **SPA (cookie) mode** since the Vue app is first‑party on `localhost:5173`.

- `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN` set in `backend/.env` (Step 03).
- The frontend first calls `GET /sanctum/csrf-cookie`, then `POST /api/v1/auth/login`.

```php
// app/Modules/Settings/routes.php  (auth block, inside v1 group)
Route::post('/auth/login',  [AuthController::class, 'login']);        // throttled (see Step 10)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);       // current user + role + permissions
});
```

```php
// AuthController@login (thin — delegates validation to LoginRequest)
public function login(LoginRequest $request)
{
    if (! Auth::attempt($request->validated(), remember: true)) {
        throw ValidationException::withMessages(['email' => __('auth.failed')]);
    }
    $request->session()->regenerate();
    $user = Auth::user()->load('role.permissions');
    ActivityLog::record('login', $user);                  // audit the login (Step 05)
    return new UserResource($user);
}
```

> Log the `login` action to the activity log. Rate‑limit the login route (Step 10) to blunt brute force.

## 6. Seed roles, permissions and the first admin

```php
// database/seeders/RbacSeeder.php  (run in DatabaseSeeder)
$permissions = collect([
    'users.manage','roles.manage','settings.manage','audit.view','audit.export',
    'units.view','units.reserve','units.manage','media.manage',
    'clients.view','clients.create','clients.manage',
    // Client projects & their details are separate groups from the client
    // record: create/manage the project, plus advance the stage and manage
    // deals inside it. See phase-3 §2.
    'projects.create','projects.manage','projects.view_all','projects.contributors','projects.freeze',
    'projects.advance','deals.direct','deals.manage',
    'calls.log','visits.assign','visits.conduct','tasks.manage',
    'versements.view','versements.record','versements.cancel','documents.generate',
    'chat.use','notifications.view','dashboard.view',
])->map(fn ($slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug)]));

$admin = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'is_agent' => false]);
$admin->permissions()->sync($permissions->pluck('id'));

$agent = Role::firstOrCreate(['slug' => 'agent'], ['name' => 'Agent', 'is_agent' => true]);
$agent->permissions()->sync(
    Permission::whereIn('slug', ['units.view','clients.view','clients.create','projects.create','calls.log',
        'visits.conduct','tasks.manage','chat.use','notifications.view','dashboard.view'])->pluck('id')
);

User::firstOrCreate(['email' => 'admin@plaza.local'], [
    'name' => 'Administrator', 'password' => Hash::make('change-me-now'), 'role_id' => $admin->id, 'is_active' => true,
]);
```

## 7. The `is_agent` flag drives visit assignment

Anywhere the UI or API lists "who can be assigned a visit", the source is **users whose role
`is_agent = true`**:

```php
// used by the visit-assignment picker (Phase 3)
User::query()->active()->whereHas('role', fn ($q) => $q->where('is_agent', true))->get();
```

Enforce it server‑side too: `AssignVisit` Action rejects assignees who are not agents.

## 8. Test RBAC

```php
it('blocks a route when the role lacks the permission', function () {
    $user = User::factory()->for(Role::factory()->create())->create();   // role has no perms
    $this->actingAs($user)->postJson('/api/v1/units/1/reserve')->assertForbidden();  // 403
});

it('only lists agent-flagged users for visit assignment', function () {
    $agentRole = Role::factory()->create(['is_agent' => true]);
    $deskRole  = Role::factory()->create(['is_agent' => false]);
    User::factory()->for($agentRole)->create();
    User::factory()->for($deskRole)->create();
    expect(User::whereHas('role', fn ($q) => $q->where('is_agent', true))->count())->toBe(1);
});
```

---

## Checklist / gate

- [ ] `roles`, `permissions`, `permission_role`, `users` created; **one `role_id` per user**.
- [ ] `can:<permission>` middleware guards write routes; a missing permission returns **403**.
- [ ] Sanctum SPA auth works: csrf‑cookie → login → authenticated `/auth/me`.
- [ ] `login` is logged to the activity log; login route is throttled.
- [ ] `is_agent` flag drives the visit‑assignment list, enforced both in queries and in the Action.
- [ ] RbacSeeder creates super‑admin + agent roles and a first admin user.

**Next:** [`07-modules-skeleton.md`](07-modules-skeleton.md)
