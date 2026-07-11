<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web module routes — the public showcase + its staff inbox
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group.
|
| /public/* is UNAUTHENTICATED by design: read-only project/media data that
| goes through Location::published() and the field-by-field Public* resources
| (an unpublished project 404s; internal fields never leave). Throttled per
| IP; the lead POST gets its own, much tighter limiter.
*/

use App\Modules\Web\Http\Controllers\PublicConfigController;
use App\Modules\Web\Http\Controllers\PublicLeadController;
use App\Modules\Web\Http\Controllers\PublicMediaController;
use App\Modules\Web\Http\Controllers\PublicProjectController;
use App\Modules\Web\Http\Controllers\WebLeadController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->middleware('throttle:public')->group(function () {
    Route::get('/config', PublicConfigController::class);
    Route::get('/projects', [PublicProjectController::class, 'index']);
    Route::get('/projects/{id}', [PublicProjectController::class, 'show'])->whereNumber('id');
    Route::get('/media/{media}/file', [PublicMediaController::class, 'file'])->name('public.media.file');
    Route::get('/media/{media}/thumb', [PublicMediaController::class, 'thumb'])->name('public.media.thumb');
});

Route::prefix('public')->middleware('throttle:public-leads')->group(function () {
    Route::post('/leads', PublicLeadController::class);
});

// The staff inbox: triage + one-click convert (convert also checks
// clients.create inside the controller).
Route::middleware(['auth:sanctum', 'can:web.leads'])->group(function () {
    Route::get('/web-leads', [WebLeadController::class, 'index']);
    Route::post('/web-leads/{webLead}/handle', [WebLeadController::class, 'markHandled']);
    Route::post('/web-leads/{webLead}/spam', [WebLeadController::class, 'markSpam']);
    Route::post('/web-leads/{webLead}/convert', [WebLeadController::class, 'convert']);
});
