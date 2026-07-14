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
use App\Modules\Collaboration\Http\Controllers\BroadcastController;
use App\Modules\Collaboration\Http\Controllers\ConversationController;
use App\Modules\Collaboration\Http\Controllers\DeviceTokenController;
use App\Modules\Collaboration\Http\Controllers\MessageController;
use App\Modules\Collaboration\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // FCM device registration (Android shell push). Any authenticated user —
    // system-tray notifications aren't gated by a permission, the content they
    // announce is (the notification pipeline already scopes recipients).
    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::post('/device-tokens/forget', [DeviceTokenController::class, 'forget']);

    // In-app notification feed. Each user reads only their own notifications.
    Route::middleware('can:notifications.view')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        // `idempotent`: read-marks queue offline; replays must not double-count.
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('idempotent');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->middleware('idempotent');
        Route::post('/notifications/{id}/unread', [NotificationController::class, 'unread']);
    });

    // Custom broadcast notifications: compose a message and send it to selected
    // users / a role / everyone, plus the company-wide history. Composing and the
    // history are gated by notifications.broadcast; the single-broadcast detail is
    // open to any recipient (authorised in the controller) so they can read the
    // message their bell notification opens.
    Route::middleware('can:notifications.broadcast')->group(function () {
        Route::get('/broadcasts', [BroadcastController::class, 'index']);
        Route::post('/broadcasts', [BroadcastController::class, 'store'])->middleware('idempotent');
    });
    Route::get('/broadcasts/{broadcast}', [BroadcastController::class, 'show']);

    // Chat. Route entry is gated chat.use; per-conversation access is guarded by
    // participation in the controllers/requests (not via Gate, so the super-admin
    // shortcut can't widen visibility).
    Route::middleware('can:chat.use')->group(function () {
        Route::get('/chat/contacts', [ConversationController::class, 'contacts']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read'])->middleware('idempotent');
        Route::post('/conversations/{conversation}/mute', [ConversationController::class, 'mute']);
        // Per-user Messenger-style delete (direct/group only — never project chats).
        Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);

        // A project's dedicated chat (find-or-create; guarded by project visibility).
        Route::get('/projects/{project}/conversation', [ConversationController::class, 'forProject']);

        // One conversation for deep links — read-scoped (participants + project-chat oversight).
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);

        // Visibility & sharing.
        Route::post('/conversations/{conversation}/participants', [ConversationController::class, 'addParticipants']);
        Route::delete('/conversations/{conversation}/participants/{user}', [ConversationController::class, 'removeParticipant']);
        Route::post('/conversations/{conversation}/share', [ConversationController::class, 'share']);

        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
        // `idempotent`: chat sends queue offline — a replayed send must return
        // the original message, never post a duplicate.
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])->middleware('idempotent');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy']);
        Route::patch('/messages/{message}', [MessageController::class, 'update']);
        Route::post('/messages/{message}/forward', [MessageController::class, 'forward']);
        Route::post('/messages/{message}/reactions', [MessageController::class, 'react']);

        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);
    });
});
