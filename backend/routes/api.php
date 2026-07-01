<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — versioned and modular
|--------------------------------------------------------------------------
| Every route lives under /api/v1. Each feature module contributes its own
| routes file. See docs/phase-0-foundations/03-laravel-install.md.
*/

Route::prefix('v1')->group(function () {
    Route::get('/ping', fn () => response()->json(['pong' => true, 'ts' => now()]));

    require app_path('Modules/Settings/routes.php');
    require app_path('Modules/Inventory/routes.php');
    require app_path('Modules/Clients/routes.php');
    require app_path('Modules/Pipeline/routes.php');
    require app_path('Modules/Payments/routes.php');
    require app_path('Modules/Collaboration/routes.php');
    require app_path('Modules/Analytics/routes.php');
});
