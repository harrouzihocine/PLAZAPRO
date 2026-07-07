<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Settings module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group.
*/

use App\Modules\Settings\Http\Controllers\AppSettingController;
use App\Modules\Settings\Http\Controllers\AuthController;
use App\Modules\Settings\Http\Controllers\CommuneController;
use App\Modules\Settings\Http\Controllers\DepartmentController;
use App\Modules\Settings\Http\Controllers\DynamicListController;
use App\Modules\Settings\Http\Controllers\DynamicListItemController;
use App\Modules\Settings\Http\Controllers\PermissionController;
use App\Modules\Settings\Http\Controllers\ProfileController;
use App\Modules\Settings\Http\Controllers\RoleController;
use App\Modules\Settings\Http\Controllers\UserController;
use App\Modules\Settings\Http\Controllers\UserDraftController;
use App\Modules\Settings\Http\Controllers\WilayaController;
use Illuminate\Support\Facades\Route;

// Authentication (Sanctum SPA cookie mode). Login is throttled.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Stateless Bearer-token login for mobile / external clients (same credentials
// and RBAC as the SPA). Throttled like login. See docs/phase-8-mobile-push.md.
Route::post('/auth/token', [AuthController::class, 'issueToken'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::delete('/auth/token', [AuthController::class, 'revokeToken']); // mobile logout

    // Self-service profile: the authenticated user editing their own account
    // (name/email/phone/password) and profile photo. Never touches username,
    // role or active-state — those are admin-only (see users.manage below).
    Route::put('/me/profile', [ProfileController::class, 'update']);
    Route::post('/me/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/me/avatar', [ProfileController::class, 'deleteAvatar']);

    // Stream a user's avatar (shown app-wide). Any authed user; the file itself
    // stays on the private disk and is only reachable through this endpoint.
    Route::get('/users/{user}/avatar', [UserController::class, 'avatar']);

    // The user's own unsaved-draft metadata (mirror of localStorage; own rows only).
    Route::get('/me/drafts', [UserDraftController::class, 'index']);
    Route::post('/me/drafts', [UserDraftController::class, 'upsert']);
    Route::delete('/me/drafts/{key}', [UserDraftController::class, 'destroy'])->where('key', '.*');

    /*
    | Dynamic lists — the reusable dropdown backbone.
    | The read endpoint feeds every dropdown app-wide, so it is available to any
    | authenticated user. Management endpoints require settings.manage.
    */
    Route::get('/dynamic-lists/{list}', [DynamicListController::class, 'show']);

    // Departments — reference data any authenticated user can read (feeds pickers).
    Route::get('/departments', [DepartmentController::class, 'index']);

    // Roles — readable by any authed user (role/agent pickers); managed under roles.manage.
    Route::get('/roles', [RoleController::class, 'index']);

    // Agents — active users whose role is_agent; feeds the assign-agent pickers
    // (clients, visits). Reference data, readable by any authenticated user.
    Route::get('/agents', [UserController::class, 'agents']);

    // Follow-up agents — active users who can log calls (sales agents / managers);
    // feeds the client "assigned agent" picker. Reference data, any authed user.
    Route::get('/follow-up-agents', [UserController::class, 'followUpAgents']);

    // Project handlers — active users who can open a client project (projects.create);
    // feeds the archive reactivation hand-off picker. Reference data, any authed user.
    Route::get('/project-handlers', [UserController::class, 'projectHandlers']);

    // Staff directory (id + name of active users) — feeds the "share a project
    // with a colleague" picker. Reference data, any authed user.
    Route::get('/staff', [UserController::class, 'staff']);

    // Scalar app settings (e.g. the interest hold duration). Read is open —
    // the UI surfaces the values; writes require settings.manage below.
    Route::get('/app-settings', [AppSettingController::class, 'index']);

    // Wilayas & communes — the geographic hierarchy. Reads feed the location /
    // desire dropdowns, so they are open to any authenticated user; writes below.
    Route::get('/wilayas', [WilayaController::class, 'index']);
    Route::get('/wilayas/{wilaya}/communes', [CommuneController::class, 'index']);

    Route::middleware('can:settings.manage')->group(function () {
        Route::post('/wilayas', [WilayaController::class, 'store']);
        Route::put('/wilayas/{wilaya}', [WilayaController::class, 'update']);
        Route::delete('/wilayas/{wilaya}', [WilayaController::class, 'destroy']);

        Route::post('/wilayas/{wilaya}/communes', [CommuneController::class, 'store']);
        Route::put('/communes/{commune}', [CommuneController::class, 'update']);
        Route::delete('/communes/{commune}', [CommuneController::class, 'destroy']);
    });

    Route::middleware('can:settings.manage')->group(function () {
        Route::get('/dynamic-lists', [DynamicListController::class, 'index']);
        Route::post('/dynamic-lists', [DynamicListController::class, 'store']);
        Route::put('/dynamic-lists/{list}', [DynamicListController::class, 'update']);
        Route::delete('/dynamic-lists/{list}', [DynamicListController::class, 'destroy']);

        Route::get('/dynamic-lists/{list}/items', [DynamicListItemController::class, 'index']);
        Route::post('/dynamic-lists/{list}/items', [DynamicListItemController::class, 'store']);
        Route::post('/dynamic-lists/{list}/items/reorder', [DynamicListItemController::class, 'reorder']);
        Route::put('/dynamic-lists/{list}/items/{item}', [DynamicListItemController::class, 'update']);
        Route::delete('/dynamic-lists/{list}/items/{item}', [DynamicListItemController::class, 'destroy']);

        Route::put('/app-settings', [AppSettingController::class, 'update']);

        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::put('/departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
    });

    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
    });

    // Clear a brute-force login lock. Its own permission (split from
    // users.manage) so unlocking can be delegated without full user admin.
    Route::put('/users/{user}/unlock', [UserController::class, 'unlock'])
        ->middleware('can:users.unlock');

    // Users — admin only. Created with exactly one role; deactivated or
    // cancelled, never deleted.
    Route::middleware('can:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::put('/users/{user}/active', [UserController::class, 'setActive']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
});
