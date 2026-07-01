<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group.
*/

use App\Modules\Analytics\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:audit.view'])->group(function () {
    Route::get('/audit', [AuditController::class, 'index']);
});
