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
use App\Modules\Clients\Http\Controllers\DuplicateRequestController;
use App\Modules\Clients\Http\Controllers\ShortlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // The supervised duplicate-client queue (deny / share a project). Registered
    // BEFORE /clients/{client} so the literal path is not captured as a client id.
    Route::middleware('can:clients.duplicates.resolve')->group(function () {
        Route::get('/clients/duplicate-requests', [DuplicateRequestController::class, 'index']);
        // Inspect one of the existing client's projects before deciding.
        Route::get('/clients/duplicate-requests/{duplicateRequest}/projects/{project}', [DuplicateRequestController::class, 'previewProject']);
        Route::post('/clients/duplicate-requests/{duplicateRequest}/resolve', [DuplicateRequestController::class, 'resolve']);
    });

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

        // Who worked the project (credit pickers on a sale).
        Route::get('/projects/{project}/participants', [DealController::class, 'participants']);

        // The client's desire (matching criteria).
        Route::get('/clients/{client}/desire', [DesireController::class, 'show']);

        // Desire → inventory matches also require the ability to view units.
        Route::middleware('can:units.view')->group(function () {
            Route::get('/clients/{client}/matches', [DesireController::class, 'matches']);
        });
    });

    // The "Desire matches" board is a company-wide oversight monitor (waiting
    // clients whose wishlist now fits available inventory) — gated by its own
    // oversight permission, not the per-client "view clients / units" reads above.
    Route::middleware('can:oversight.matches')->group(function () {
        Route::get('/desires/matches', [DesireMatchController::class, 'index']);
    });

    // Creating a client (agents can capture leads).
    Route::middleware('can:clients.create')->group(function () {
        Route::post('/clients', [ClientController::class, 'store']);

        // Capturing/updating the desire is part of qualifying the lead.
        Route::put('/clients/{client}/desire', [DesireController::class, 'upsert']);
    });

    // Editing, reassigning and cancelling a CLIENT require its manage permission.
    Route::middleware('can:clients.manage')->group(function () {
        Route::put('/clients/{client}', [ClientController::class, 'update']);
        Route::delete('/clients/{client}', [ClientController::class, 'destroy']);
        // Delegate a waiting client (a desire match) to the sales agent who will
        // reconnect — the manager triages, the agent does the calling.
        Route::post('/clients/{client}/assign-agent', [ClientController::class, 'assignAgent']);
    });

    // Opening a NEW project is part of the agent's lead workflow (the "New
    // project" flow starts with its opening call) — its own grant, held by
    // agents. Editing an existing project's particulars needs manage (below).
    Route::middleware('can:projects.create')->group(function () {
        Route::post('/clients/{client}/projects', [ClientProjectController::class, 'store']);
    });

    // Project lifecycle: edit particulars, archive/reactivate, shift back to
    // desire, remove an empty project.
    Route::middleware('can:projects.manage')->group(function () {
        Route::put('/projects/{project}', [ClientProjectController::class, 'update']);
        Route::post('/projects/{project}/archive', [ClientProjectController::class, 'archive']);
        Route::post('/projects/{project}/reactivate', [ClientProjectController::class, 'reactivate']);
        // Who to show before reactivating: opener + current contributors + in-site agents.
        Route::get('/projects/{project}/handoff-preview', [ClientProjectController::class, 'handoffPreview']);
        Route::post('/projects/{project}/shift-to-desire', [ClientProjectController::class, 'shiftToDesire']);
        Route::delete('/projects/{project}', [ClientProjectController::class, 'destroy']);
    });

    // Moving the project's workflow step is day-to-day file work, not lifecycle.
    Route::middleware('can:projects.advance')->group(function () {
        Route::post('/projects/{project}/advance', [ClientProjectController::class, 'advance']);
    });

    // Phase-6 per-property closure (won/lost) on an interested shortlisted
    // property — the closure desk (deals.manage), same as closing a deal.
    Route::middleware('can:deals.manage')->group(function () {
        Route::post('/shortlist-items/{item}/outcome', [ShortlistController::class, 'outcome']);
    });

    // Freeze / unfreeze a project — a deliberate close-down to new activity
    // (payments still flow). Its own permission: who may pull this lever.
    Route::middleware('can:projects.freeze')->group(function () {
        Route::post('/projects/{project}/freeze', [ClientProjectController::class, 'freeze']);
        Route::post('/projects/{project}/unfreeze', [ClientProjectController::class, 'unfreeze']);
    });

    // Sharing a project (add / hide viewers) has its own permission.
    Route::middleware('can:projects.contributors')->group(function () {
        Route::post('/projects/{project}/viewers', [ClientProjectViewerController::class, 'store']);
        Route::post('/projects/{project}/viewers/{user}/hide', [ClientProjectViewerController::class, 'hide']);
    });

    // Curating the standalone property shortlist needs shortlist.manage (agents
    // add properties via "Add unit to visit" instead). The office-visit picker
    // is gated the same way in the completion form.
    Route::middleware('can:shortlist.manage')->group(function () {
        Route::put('/projects/{project}/shortlist', [ShortlistController::class, 'sync']);
    });

    // Deals: opened from an interaction log — visit or call — (visits.conduct;
    // a direct deal additionally needs deals.direct — both enforced in
    // StoreDealRequest/CreateDeal). Each apartment closes won/lost on its own
    // (the closure desk; Close*Request gates deals.manage), the whole-deal
    // close is the bulk face of the same flow. Boxes are edited per apartment.
    Route::post('/projects/{project}/deals', [DealController::class, 'store']);
    Route::post('/deals/{deal}/close', [DealController::class, 'close']);
    Route::post('/deals/{deal}/items/{item}/close', [DealController::class, 'closeItem']);
    // A WON apartment can be released after the fact (the sale fell through) —
    // it returns to the market; recorded payments stay as refundable history.
    Route::post('/deals/{deal}/items/{item}/release', [DealController::class, 'releaseItem']);
    Route::put('/deals/{deal}/items/{item}/boxes', [DealController::class, 'syncUnitBoxes']);
    // …and it can take MORE boxes later (the client comes back for a parking
    // box): sold + linked right onto the won apartment, agreed price grows.
    Route::post('/deals/{deal}/items/{item}/boxes/add', [DealController::class, 'addBoxes']);
});
