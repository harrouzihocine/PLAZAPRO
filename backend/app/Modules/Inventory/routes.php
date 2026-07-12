<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inventory module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group. Add this
| module's routes here, each guarded by auth:sanctum + a can:<permission>.
| See docs/phase-0-foundations/07-modules-skeleton.md and 06-auth-and-rbac.md.
*/

use App\Modules\Inventory\Http\Controllers\BoxController;
use App\Modules\Inventory\Http\Controllers\LocationController;
use App\Modules\Inventory\Http\Controllers\MediaController;
use App\Modules\Inventory\Http\Controllers\ReservationController;
use App\Modules\Inventory\Http\Controllers\StackingController;
use App\Modules\Inventory\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Reads are open to anyone who can view inventory.
    Route::middleware('can:units.view')->group(function () {
        Route::get('/locations', [LocationController::class, 'index']);
        Route::get('/locations/{location}', [LocationController::class, 'show']);
        Route::get('/locations/{location}/insights', [LocationController::class, 'insights']);

        Route::get('/units', [UnitController::class, 'index']);
        // Before /units/{unit} so "export"/"import-template" never hit the model binding.
        Route::get('/units/export', [UnitController::class, 'export']);
        Route::get('/units/import-template', [UnitController::class, 'template']);
        Route::get('/units/{unit}', [UnitController::class, 'show']);
        Route::get('/units/{unit}/insights', [UnitController::class, 'insights']);
        Route::get('/units/{unit}/project-logs', [UnitController::class, 'projectLogs']);

        Route::get('/locations/{location}/stacking', [StackingController::class, 'show']);

        Route::get('/boxes', [BoxController::class, 'index']);

        // Media: list + permission-gated streaming of the private files.
        Route::get('/{mediableType}/{mediableId}/media', [MediaController::class, 'index'])
            ->whereIn('mediableType', ['locations', 'units'])->whereNumber('mediableId');
        Route::get('/media/{media}/file', [MediaController::class, 'file'])->name('media.file');
        Route::get('/media/{media}/thumb', [MediaController::class, 'thumb'])->name('media.thumb');
        Route::get('/media/{media}/preview', [MediaController::class, 'preview'])->name('media.preview');
        Route::get('/media/{media}/slide/{page}', [MediaController::class, 'slide'])
            ->whereNumber('page')->name('media.slide');
        Route::get('/media/{media}/download', [MediaController::class, 'download'])->name('media.download');
    });

    // Location writes require the dedicated locations.manage permission.
    Route::middleware('can:locations.manage')->group(function () {
        Route::post('/locations', [LocationController::class, 'store']);
        Route::put('/locations/{location}', [LocationController::class, 'update']);
        Route::post('/locations/{location}/archive', [LocationController::class, 'archive']);
        Route::post('/locations/{location}/reactivate', [LocationController::class, 'reactivate']);
        Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
    });

    // Unit writes require units.manage. price/sale_status corrections go through
    // /correct (HasVersions: cancel-and-duplicate).
    Route::middleware('can:units.manage')->group(function () {
        Route::post('/locations/{location}/units', [UnitController::class, 'store']);
        Route::put('/units/{unit}', [UnitController::class, 'update']);
        Route::post('/units/{unit}/correct', [UnitController::class, 'correct']);
        Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
        // Fast bulk tools: multi-select cancel + the Excel edit round-trip.
        Route::post('/units/bulk-cancel', [UnitController::class, 'bulkCancel']);
        Route::post('/units/import', [UnitController::class, 'import']);

        Route::post('/locations/{location}/boxes', [BoxController::class, 'store']);
        Route::put('/boxes/{box}', [BoxController::class, 'update']);
        Route::delete('/boxes/{box}', [BoxController::class, 'destroy']);
    });

    // Media writes (upload / reorder / replace / rename / remove) require media.manage.
    Route::middleware('can:media.manage')->group(function () {
        Route::post('/{mediableType}/{mediableId}/media', [MediaController::class, 'store'])
            ->whereIn('mediableType', ['locations', 'units'])->whereNumber('mediableId');
        Route::post('/{mediableType}/{mediableId}/media/reorder', [MediaController::class, 'reorder'])
            ->whereIn('mediableType', ['locations', 'units'])->whereNumber('mediableId');
        Route::post('/media/{media}/replace', [MediaController::class, 'replace']);
        Route::patch('/media/{media}', [MediaController::class, 'rename']);
        Route::delete('/media/{media}', [MediaController::class, 'destroy']);
    });

    // The reservation follow-up board: reserved/held units + ordered queues.
    // Its own grant (reservations.view) — reservations.view_all, checked in the
    // action, widens the board from "my own book" to company-wide.
    Route::middleware('can:reservations.view')->group(function () {
        Route::get('/reservations/queues', [ReservationController::class, 'queues']);
    });

    // Interest-hold lifecycle (the 48h hold). Requires units.interest.
    Route::middleware('can:units.interest')->group(function () {
        Route::post('/units/{unit}/interest', [ReservationController::class, 'reserve']);
        Route::post('/reservations/{reservation}/release', [ReservationController::class, 'release']);
        Route::post('/reservations/{reservation}/convert', [ReservationController::class, 'convert']);
    });
});
