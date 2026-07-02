<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Clients module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group. Clients, their
| deals (client_projects) and desire live here. See docs/phase-3-clients-pipeline.md.
*/

use App\Modules\Clients\Http\Controllers\ClientController;
use App\Modules\Clients\Http\Controllers\ClientProjectController;
use App\Modules\Clients\Http\Controllers\DesireController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Reads for anyone who can view clients.
    Route::middleware('can:clients.view')->group(function () {
        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/{client}', [ClientController::class, 'show']);

        // Deals (client_projects) on a client.
        Route::get('/clients/{client}/projects', [ClientProjectController::class, 'index']);

        // The client's desire (matching criteria).
        Route::get('/clients/{client}/desire', [DesireController::class, 'show']);

        // Desire → inventory matches also require the ability to view units.
        Route::middleware('can:units.view')
            ->get('/clients/{client}/matches', [DesireController::class, 'matches']);
    });

    // Creating a client (agents can capture leads).
    Route::middleware('can:clients.create')->group(function () {
        Route::post('/clients', [ClientController::class, 'store']);

        // Capturing/updating the desire is part of qualifying the lead.
        Route::put('/clients/{client}/desire', [DesireController::class, 'upsert']);
    });

    // Editing, reassigning and cancelling require the manage permission.
    Route::middleware('can:clients.manage')->group(function () {
        Route::put('/clients/{client}', [ClientController::class, 'update']);
        Route::delete('/clients/{client}', [ClientController::class, 'destroy']);

        // Deal lifecycle: open, edit particulars, advance stage, archive/reactivate, remove.
        Route::post('/clients/{client}/projects', [ClientProjectController::class, 'store']);
        Route::put('/projects/{project}', [ClientProjectController::class, 'update']);
        Route::post('/projects/{project}/advance', [ClientProjectController::class, 'advance']);
        Route::post('/projects/{project}/archive', [ClientProjectController::class, 'archive']);
        Route::post('/projects/{project}/reactivate', [ClientProjectController::class, 'reactivate']);
        Route::delete('/projects/{project}', [ClientProjectController::class, 'destroy']);
    });
});
