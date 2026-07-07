<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;

/**
 * "Delete" a conversation for ONE user, Messenger-style — nothing is removed
 * for anyone else (zero-deletion). Sets on the caller's own pivot row:
 *   hidden_at  → the thread leaves their inbox (a new message from anyone
 *                clears it and the thread resurrects — see SendMessage);
 *   cleared_at → the history up to this instant stays hidden from them even
 *                after resurrection.
 * Project chats are refused upstream (they follow the project, not the user).
 */
class DeleteConversationForUser
{
    public function handle(Conversation $conversation, User $user): void
    {
        $now = now();
        $conversation->participants()->updateExistingPivot($user->id, [
            'hidden_at' => $now,
            'cleared_at' => $now,
        ]);
    }
}
