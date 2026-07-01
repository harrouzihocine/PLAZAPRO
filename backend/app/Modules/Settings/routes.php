<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Settings module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group.
*/

use App\Modules\Settings\Http\Controllers\AuthController;
use App\Modules\Settings\Http\Controllers\DepartmentController;
use App\Modules\Settings\Http\Controllers\DynamicListController;
use App\Modules\Settings\Http\Controllers\DynamicListItemController;
use App\Modules\Settings\Http\Controllers\PermissionController;
use App\Modules\Settings\Http\Controllers\RoleController;
use App\Modules\Settings\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Authentication (Sanctum SPA cookie mode). Login is throttled.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

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
