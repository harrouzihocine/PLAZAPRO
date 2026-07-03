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
use App\Modules\Clients\Http\Controllers\ClientProjectViewerController;
use App\Modules\Clients\Http\Controllers\DealController;
use App\Modules\Clients\Http\Controllers\DesireController;
use App\Modules\Clients\Http\Controllers\DesireMatchController;
use App\Modules\Clients\Http\Controllers\ShortlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Reads for anyone who can view clients.
    Route::middleware('can:clients.view')->group(function () {
        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/{client}', [ClientController::class, 'show']);

        // Deals (client_projects) on a client.
        Route::get('/clients/{client}/projects', [ClientProjectController::class, 'index']);

        // The deal's property shortlist (read).
        Route::get('/projects/{project}/shortlist', [ShortlistController::class, 'index']);

        // Who can see this project (creator + shared-with users).
        Route::get('/projects/{project}/viewers', [ClientProjectViewerController::class, 'index']);

        // The project's deals (read).
        Route::get('/projects/{project}/deals', [DealController::class, 'index']);

        // The client's desire (matching criteria).
        Route::get('/clients/{client}/desire', [DesireController::class, 'show']);

        // Desire → inventory matches also require the ability to view units.
        Route::middleware('can:units.view')->group(function () {
            Route::get('/clients/{client}/matches', [DesireController::class, 'matches']);
            // The dedicated "Desire matches" board (agent-scoped waiting clients).
            Route::get('/desires/matches', [DesireMatchController::class, 'index']);
        });
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
        Route::post('/projects/{project}/shift-to-desire', [ClientProjectController::class, 'shiftToDesire']);
        Route::delete('/projects/{project}', [ClientProjectController::class, 'destroy']);

        // Phase-6 per-property closure (won/lost) on an interested shortlisted property.
        Route::post('/shortlist-items/{item}/outcome', [ShortlistController::class, 'outcome']);
    });

    // Sharing a project (add / hide viewers) has its own permission.
    Route::middleware('can:projects.contributors')->group(function () {
        Route::post('/projects/{project}/viewers', [ClientProjectViewerController::class, 'store']);
        Route::post('/projects/{project}/viewers/{user}/hide', [ClientProjectViewerController::class, 'hide']);
    });

    // The visiting agent curates the deal's property shortlist at the office visit.
    Route::middleware('can:visits.conduct')->group(function () {
        Route::put('/projects/{project}/shortlist', [ShortlistController::class, 'sync']);
    });

    // Deals: opened from a visit log (visits.conduct; a direct deal additionally
    // needs deals.direct — both enforced in StoreDealRequest/CreateDeal); closed
    // won/lost by back-office (CloseDealRequest gates clients.manage).
    Route::post('/projects/{project}/deals', [DealController::class, 'store']);
    Route::post('/deals/{deal}/close', [DealController::class, 'close']);
    Route::put('/deals/{deal}/boxes', [DealController::class, 'syncBoxes']);
});
