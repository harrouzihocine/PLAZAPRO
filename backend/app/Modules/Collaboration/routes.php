<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Collaboration module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group. Notifications,
| the tasks page (tasks table lives in Pipeline) and chat live here. Each route
| is guarded by auth:sanctum + a can:<permission>.
| See docs/phase-5-collaboration.md and phase-0-foundations/06-auth-and-rbac.md.
*/

use App\Modules\Collaboration\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // In-app notification feed. Each user reads only their own notifications.
    Route::middleware('can:notifications.view')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);
    });
});
