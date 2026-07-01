<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pipeline module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group. The interaction
| chain (calls, visits), the enforced next action, tasks and reminders live
| here. See docs/phase-3-clients-pipeline.md.
*/

use App\Modules\Pipeline\Http\Controllers\CallController;
use App\Modules\Pipeline\Http\Controllers\TaskController;
use App\Modules\Pipeline\Http\Controllers\TimelineController;
use App\Modules\Pipeline\Http\Controllers\VisitController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Reads — anyone who can view clients can see the interaction history.
    Route::middleware('can:clients.view')->group(function () {
        Route::get('/clients/{client}/calls', [CallController::class, 'index']);
        Route::get('/clients/{client}/timeline', [TimelineController::class, 'show']);
        Route::get('/visits', [VisitController::class, 'index']);
    });

    // Logging a call always leaves a next action (LogCallRequest enforces it).
    Route::middleware('can:calls.log')->group(function () {
        Route::post('/clients/{client}/calls', [CallController::class, 'store']);
    });

    // Scheduling / assigning a visit picks an agent (agent-only).
    Route::middleware('can:visits.assign')->group(function () {
        Route::post('/visits', [VisitController::class, 'store']);
        Route::post('/visits/{visit}/assign', [VisitController::class, 'assign']);
    });

    // Completing a visit (conducting it) always leaves a next action.
    Route::middleware('can:visits.conduct')->group(function () {
        Route::post('/visits/{visit}/complete', [VisitController::class, 'complete']);
    });

    // Tasks (to-dos). The dedicated tasks page is built in Phase 5.
    Route::middleware('can:tasks.manage')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    });
});
