<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments module routes
|--------------------------------------------------------------------------
| Versements (instalments), payment schedules, cancel-and-duplicate corrections
| and branded document generation. Required by routes/api.php inside /api/v1.
| See docs/phase-4-payments-documents.md and docs/database/04-payments.md.
*/

use App\Modules\Payments\Http\Controllers\DocumentController;
use App\Modules\Payments\Http\Controllers\PaymentScheduleController;
use App\Modules\Payments\Http\Controllers\PaymentsOverviewController;
use App\Modules\Payments\Http\Controllers\VersementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Reads: the instalment plan, recorded versements (+ running balance) and
    // generated documents. Open to anyone who can view payments.
    Route::middleware('can:versements.view')->group(function () {
        // Cross-project follow-up hub: reserved, interested, instalments due.
        Route::get('/payments/overview', [PaymentsOverviewController::class, 'index']);
        Route::get('/projects/{project}/schedule', [PaymentScheduleController::class, 'index']);
        Route::get('/projects/{project}/versements', [VersementController::class, 'index']);
        Route::get('/documents/{document}', [DocumentController::class, 'show']);
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
            ->name('documents.download');
    });

    // Create/replace the instalment plan and record instalment payments.
    Route::middleware('can:versements.record')->group(function () {
        Route::put('/projects/{project}/schedule', [PaymentScheduleController::class, 'save']);
        Route::post('/projects/{project}/versements', [VersementController::class, 'store']);
    });

    // Correct a recorded versement — cancel-and-duplicate, never an edit — or
    // refund it (the money went back; the row stays in history, flagged).
    Route::middleware('can:versements.cancel')->group(function () {
        Route::post('/versements/{versement}/correct', [VersementController::class, 'correct']);
        Route::post('/versements/{versement}/refund', [VersementController::class, 'refund']);
    });

    // Generate a branded receipt (rendered on the queue worker).
    Route::middleware('can:documents.generate')->group(function () {
        Route::post('/versements/{versement}/document', [VersementController::class, 'generateDocument']);
    });
});
