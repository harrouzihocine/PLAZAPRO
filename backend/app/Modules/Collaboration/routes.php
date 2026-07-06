<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Collaboration module routes
|--------------------------------------------------------------------------
| Required by routes/api.php inside the /api/v1 prefix group. Notifications,
| the tasks page (tasks table lives in Pipeline) and chat live here. Each route
| is guarded by auth:sanctum + a can:<permission>.
| See docs/phase-5-collaboration.md and phase-0-foundations/06-auth-and-rbac.md.
*/

use App\Modules\Collaboration\Http\Controllers\AttachmentController;
use App\Modules\Collaboration\Http\Controllers\ConversationController;
use App\Modules\Collaboration\Http\Controllers\MessageController;
use App\Modules\Collaboration\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // In-app notification feed. Each user reads only their own notifications.
    Route::middleware('can:notifications.view')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);
        Route::post('/notifications/{id}/unread', [NotificationController::class, 'unread']);
    });

    // Chat. Route entry is gated chat.use; per-conversation access is guarded by
    // participation in the controllers/requests (not via Gate, so the super-admin
    // shortcut can't widen visibility).
    Route::middleware('can:chat.use')->group(function () {
        Route::get('/chat/contacts', [ConversationController::class, 'contacts']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read']);

        // A project's dedicated chat (find-or-create; guarded by project visibility).
        Route::get('/projects/{project}/conversation', [ConversationController::class, 'forProject']);

        // One conversation for deep links — read-scoped (participants + project-chat oversight).
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);

        // Visibility & sharing.
        Route::post('/conversations/{conversation}/participants', [ConversationController::class, 'addParticipants']);
        Route::delete('/conversations/{conversation}/participants/{user}', [ConversationController::class, 'removeParticipant']);
        Route::post('/conversations/{conversation}/share', [ConversationController::class, 'share']);

        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
        Route::delete('/messages/{message}', [MessageController::class, 'destroy']);

        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);
    });
});
