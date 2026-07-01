<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Settings module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group.
*/

use App\Modules\Settings\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Authentication (Sanctum SPA cookie mode). Login is throttled.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});
