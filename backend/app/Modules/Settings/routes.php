<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Settings module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group.
*/

use App\Modules\Settings\Http\Controllers\AuthController;
use App\Modules\Settings\Http\Controllers\DynamicListController;
use App\Modules\Settings\Http\Controllers\DynamicListItemController;
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
    });
});
