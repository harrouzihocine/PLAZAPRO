<?php

declare(strict_types=1);

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (Reverb)
|--------------------------------------------------------------------------
| Authorisation callbacks for private/presence channels. Loaded via
| ->withBroadcasting() in bootstrap/app.php. See docs/phase-5-collaboration.md.
|
| NOTE: channel auth callbacks are NOT subject to Gate::before, so the
| super-admin shortcut does not silently widen channel access here — each
| callback must return true only for users who genuinely belong on the channel.
*/

// A user's private notification channel. The User model overrides
// receivesBroadcastNotificationsOn() to publish here (users.{id}) rather than
// on the namespaced class path.
Broadcast::channel('users.{userId}', function (User $user, int $userId) {
    return $user->id === $userId;
});

// A conversation's live message stream. Same read rule as the HTTP endpoints:
// participants, plus project-chat oversight (chat.view_project_chats).
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::query()->find($conversationId);

    return $conversation !== null && $conversation->isReadableBy($user);
});
