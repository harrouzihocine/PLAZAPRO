<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Events\ConversationRead;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;

/**
 * Advance a participant's unread cursor to now: everything up to this moment is
 * considered read. Clears the conversation's unread badge for this user and
 * broadcasts the new cursor (live "seen" ticks). A non-participant oversight
 * reader has no pivot row — updateExistingPivot touches nothing and no event
 * fires, so overseers never appear in read receipts.
 */
class MarkConversationRead
{
    public function handle(Conversation $conversation, User $user): void
    {
        $now = now();
        $updated = $conversation->participants()->updateExistingPivot($user->id, ['last_read_at' => $now]);

        if ($updated > 0) {
            ConversationRead::dispatch($conversation->id, $user->id, $now->toIso8601String());
        }
    }
}
