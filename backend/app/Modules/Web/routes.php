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

use App\Modules\Web\Http\Controllers\MediaShareController;
use App\Modules\Web\Http\Controllers\PublicConfigController;
use App\Modules\Web\Http\Controllers\PublicDesireOptionsController;
use App\Modules\Web\Http\Controllers\PublicLeadController;
use App\Modules\Web\Http\Controllers\PublicMediaController;
use App\Modules\Web\Http\Controllers\PublicProjectController;
use App\Modules\Web\Http\Controllers\PublicShareController;
use App\Modules\Web\Http\Controllers\PublicTrackController;
use App\Modules\Web\Http\Controllers\WebLeadController;
use App\Modules\Web\Http\Controllers\WebsiteSpaceController;
use App\Modules\Web\Http\Controllers\WebStatsController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->middleware('throttle:public')->group(function () {
    Route::get('/config', PublicConfigController::class);
    // The desire form's option lists (official geography + generic
    // type/rooms vocabularies) — fetched once, when the form opens.
    Route::get('/desire-options', PublicDesireOptionsController::class);
    Route::get('/projects', [PublicProjectController::class, 'index']);
    Route::get('/projects/{id}', [PublicProjectController::class, 'show'])->whereNumber('id');
    Route::get('/projects/{id}/units/{unitId}', [PublicProjectController::class, 'unit'])
        ->whereNumber('id')->whereNumber('unitId');
    // The WhatsApp share page's payload — the token is the authorization.
    Route::get('/shares/{token}', [PublicShareController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40,64}');
});

// Media streaming gets its own, wider limiter: one project page fetches tens
// of thumbnails, and the hero slideshow adds more — 60/min would starve a
// normal browse while the JSON endpoints stay tightly capped.
Route::prefix('public')->middleware('throttle:public-media')->group(function () {
    Route::get('/media/{media}/file', [PublicMediaController::class, 'file'])->name('public.media.file');
    Route::get('/media/{media}/thumb', [PublicMediaController::class, 'thumb'])->name('public.media.thumb');
    // Share-scoped streaming: the same files, authorized by share membership
    // instead of the published-project gate.
    Route::get('/shares/{token}/media/{media}/file', [PublicShareController::class, 'file'])
        ->where('token', '[A-Za-z0-9]{40,64}')->name('public.share.file');
    Route::get('/shares/{token}/media/{media}/thumb', [PublicShareController::class, 'thumb'])
        ->where('token', '[A-Za-z0-9]{40,64}')->name('public.share.thumb');
});

Route::prefix('public')->middleware('throttle:public-leads')->group(function () {
    Route::post('/leads', PublicLeadController::class);
});

// The anonymous analytics sink (batched page/project/unit views + clicks).
Route::prefix('public')->middleware('throttle:public-track')->group(function () {
    Route::post('/track', PublicTrackController::class);
});

// Minting a share link: an agent picks media in a gallery and addresses one
// of their own clients (visibleTo re-checked in the controller). The wa.me
// send itself happens on the frontend, exactly like the office invite.
Route::middleware(['auth:sanctum', 'can:units.view', 'can:clients.view'])->group(function () {
    Route::post('/media-shares', [MediaShareController::class, 'store']);
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
