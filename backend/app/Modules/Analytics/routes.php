<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group. Analytics is a
| read side: every route here is a GET that computes over existing tables and
| never mutates domain data.
*/

use App\Modules\Analytics\Http\Controllers\AuditController;
use App\Modules\Analytics\Http\Controllers\DashboardController;
use App\Modules\Analytics\Http\Controllers\FeedbackController;
use App\Modules\Analytics\Http\Controllers\OversightController;
use App\Modules\Analytics\Http\Controllers\RecordActivityController;
use App\Modules\Analytics\Http\Controllers\ReportController;
use App\Modules\Analytics\Http\Controllers\TeamLogsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Role-aware dashboard — scoped server-side by the caller's role.
    Route::get('/dashboard', [DashboardController::class, 'show'])
        ->middleware('can:dashboard.view');

    // Manager/admin reports — gated behind an extra report permission.
    Route::middleware(['can:dashboard.view', 'can:reports.view'])->group(function () {
        Route::get('/analytics/source-roi', [ReportController::class, 'sourceRoi']);
        Route::get('/analytics/units', [ReportController::class, 'units']);

        // Voice-of-Client feedback analytics: a development's logs mined into
        // objections, demand, sentiment and next-best-action, plus a per-unit drill.
        Route::get('/analytics/locations/{location}/feedback', [FeedbackController::class, 'location']);
        Route::get('/analytics/units/{unit}/feedback', [FeedbackController::class, 'unit']);
    });

    // One record's audit trail, gated by that record's own view permission
    // (checked inside the controller via AuditableRecord).
    Route::get('/activity/{type}/{id}', [RecordActivityController::class, 'index'])
        ->whereNumber('id');

    // Team activity logs — every user's rapports + planned work. Open to any authed
    // user, but self-scoped by default: without logs.view_all a caller only ever
    // sees their OWN logs (the controller forces user_id to self). logs.view_all
    // widens it company-wide and unlocks the user selector — the manager/admin
    // "who did what" view that crosses all visibility scopes by design.
    Route::get('/team-logs', [TeamLogsController::class, 'index']);

    // Admin audit feed over the append-only activity log.
    Route::middleware('can:audit.view')->get('/audit', [AuditController::class, 'index']);
    Route::middleware('can:audit.export')->get('/audit/export', [AuditController::class, 'export']);

    // Team-oversight monitors — each gated by its own oversight.* permission.
    // The summary (sidebar badge counts) is open to any authed user; it only
    // returns counts for the monitors the caller is actually allowed to see.
    Route::get('/oversight/summary', [OversightController::class, 'summary']);
    Route::middleware('can:oversight.clients')->get('/oversight/clients', [OversightController::class, 'clients']);
    Route::middleware('can:oversight.pipeline')->get('/oversight/pipeline', [OversightController::class, 'pipeline']);
    Route::middleware('can:oversight.deals')->get('/oversight/lost-paid-deals', [OversightController::class, 'deals']);
    Route::middleware('can:oversight.drafts')->group(function () {
        Route::get('/oversight/drafts', [OversightController::class, 'drafts']);
        Route::delete('/oversight/drafts/{draft}', [OversightController::class, 'removeDraft']);
    });

    // The Archive desk — company-wide list of archived (lost/closed) projects to
    // review + reactivate. Reactivation itself reuses POST /projects/{p}/reactivate
    // (Clients module, projects.manage).
    Route::middleware('can:oversight.archive')->group(function () {
        Route::get('/oversight/archive', [OversightController::class, 'archive']);
        Route::get('/oversight/archive/export', [OversightController::class, 'exportArchive']);
    });
});
