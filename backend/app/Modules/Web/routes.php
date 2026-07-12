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
use App\Modules\Web\Http\Controllers\PublicTrackController;
use App\Modules\Web\Http\Controllers\WebLeadController;
use App\Modules\Web\Http\Controllers\WebsiteSpaceController;
use App\Modules\Web\Http\Controllers\WebStatsController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->middleware('throttle:public')->group(function () {
    Route::get('/config', PublicConfigController::class);
    Route::get('/projects', [PublicProjectController::class, 'index']);
    Route::get('/projects/{id}', [PublicProjectController::class, 'show'])->whereNumber('id');
    Route::get('/projects/{id}/units/{unitId}', [PublicProjectController::class, 'unit'])
        ->whereNumber('id')->whereNumber('unitId');
});

// Media streaming gets its own, wider limiter: one project page fetches tens
// of thumbnails, and the hero slideshow adds more — 60/min would starve a
// normal browse while the JSON endpoints stay tightly capped.
Route::prefix('public')->middleware('throttle:public-media')->group(function () {
    Route::get('/media/{media}/file', [PublicMediaController::class, 'file'])->name('public.media.file');
    Route::get('/media/{media}/thumb', [PublicMediaController::class, 'thumb'])->name('public.media.thumb');
});

Route::prefix('public')->middleware('throttle:public-leads')->group(function () {
    Route::post('/leads', PublicLeadController::class);
});

// The anonymous analytics sink (batched page/project/unit views + clicks).
Route::prefix('public')->middleware('throttle:public-track')->group(function () {
    Route::post('/track', PublicTrackController::class);
});

// The staff inbox: triage + one-click convert (convert also checks
// clients.create inside the controller).
Route::middleware(['auth:sanctum', 'can:web.leads'])->group(function () {
    Route::get('/web-leads', [WebLeadController::class, 'index']);
    Route::post('/web-leads/{webLead}/handle', [WebLeadController::class, 'markHandled']);
    Route::post('/web-leads/{webLead}/spam', [WebLeadController::class, 'markSpam']);
    Route::post('/web-leads/{webLead}/convert', [WebLeadController::class, 'convert']);
});

// The website-stats board — its own grant so an owner can share traffic
// numbers without opening the leads inbox.
Route::middleware(['auth:sanctum', 'can:web.stats'])->group(function () {
    Route::get('/web-stats', WebStatsController::class);
});

// The hero library anchor for the Settings page's uploader.
Route::middleware(['auth:sanctum', 'can:settings.manage'])->group(function () {
    Route::get('/website-space', WebsiteSpaceController::class);
});
