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
use App\Modules\Pipeline\Http\Controllers\DispatchController;
use App\Modules\Pipeline\Http\Controllers\NextActionController;
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

    // Logging a call may leave a next action; one can also be planned later on
    // its own (store). Corrections (call + next action) also run under calls.log
    // — every edit is a cancel + new version, captured in history with a reason.
    Route::middleware('can:calls.log')->group(function () {
        Route::post('/clients/{client}/calls', [CallController::class, 'store']);
        Route::post('/clients/{client}/next-actions', [NextActionController::class, 'store']);
        Route::post('/calls/{call}/correct', [CallController::class, 'correct']);
        Route::post('/next-actions/{nextAction}/correct', [NextActionController::class, 'correct']);
    });

    // The dispatch board: pending in-site pool + agents × weekdays, drag & drop.
    Route::middleware('can:visits.dispatch')->group(function () {
        Route::get('/dispatch/board', [DispatchController::class, 'board']);
        Route::post('/dispatch/assign', [DispatchController::class, 'assign']);
    });

    // Scheduling / assigning a visit picks an agent (agent-only).
    Route::middleware('can:visits.assign')->group(function () {
        Route::post('/visits', [VisitController::class, 'store']);
        Route::post('/visits/{visit}/assign', [VisitController::class, 'assign']);
    });

    // Correcting a visit: visit admins (visits.assign) for any visit, or the
    // assigned field agent for their own in-site log — CorrectVisitRequest is
    // the gate, so no permission middleware here.
    Route::post('/visits/{visit}/correct', [VisitController::class, 'correct']);

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
