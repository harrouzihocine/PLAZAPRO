<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;

/**
 * Advance a participant's unread cursor to now: everything up to this moment is
 * considered read. Clears the conversation's unread badge for this user.
 */
class MarkConversationRead
{
    public function handle(Conversation $conversation, User $user): void
    {
        $conversation->participants()->updateExistingPivot($user->id, ['last_read_at' => now()]);
    }
}
