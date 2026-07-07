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
| NOTE: the participant checks below are plain pivot queries, immune to the
| Gate::before super-admin shortcut. The conversation channel additionally
| delegates to Conversation::isReadableBy, whose PROJECT-chat oversight branch
| uses $user->can(...) — which Gate::before DOES satisfy for super-admins.
| That widening is intentional and limited to project chats; direct/group
| threads remain strictly participant-only for everyone.
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

// Who is online right now (presence). Any authenticated user may join; the
// member payload is intentionally minimal (id + name). Powers the green
// "Active now" dots in the Android app's chat — web users still JOIN (so
// they count as online to app users) even though the web UI shows no dots.
Broadcast::channel('online', function (User $user) {
    return ['id' => $user->id, 'name' => $user->name];
});
