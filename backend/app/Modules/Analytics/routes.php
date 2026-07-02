<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group. Analytics is a
| read side: every route here is a GET that computes over existing tables and
| never mutates domain data.
*/

use App\Modules\Analytics\Http\Controllers\AuditController;
use App\Modules\Analytics\Http\Controllers\DashboardController;
use App\Modules\Analytics\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Role-aware dashboard — scoped server-side by the caller's role.
    Route::get('/dashboard', [DashboardController::class, 'show'])
        ->middleware('can:dashboard.view');

    // Manager/admin reports — gated behind an extra report permission.
    Route::middleware(['can:dashboard.view', 'can:reports.view'])->group(function () {
        Route::get('/analytics/source-roi', [ReportController::class, 'sourceRoi']);
        Route::get('/analytics/units', [ReportController::class, 'units']);
    });

    // Admin audit feed over the append-only activity log.
    Route::middleware('can:audit.view')->get('/audit', [AuditController::class, 'index']);
    Route::middleware('can:audit.export')->get('/audit/export', [AuditController::class, 'export']);
});
