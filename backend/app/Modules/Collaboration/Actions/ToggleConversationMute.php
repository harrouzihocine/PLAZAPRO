<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;

/**
 * Flip the caller's own mute flag on a conversation. A muted participant keeps
 * full access but stops receiving chat_message notifications (the existing
 * NotifyParticipantsOfMessage listener already skips muted pivots) — and the
 * frontend suppresses the chime. Returns the new state.
 */
class ToggleConversationMute
{
    public function handle(Conversation $conversation, User $user): bool
    {
        $row = $conversation->participants()->where('users.id', $user->id)->first();
        abort_if($row === null, 403, 'Only participants can mute a conversation.');

        $muted = ! (bool) $row->pivot->muted;
        $conversation->participants()->updateExistingPivot($user->id, ['muted' => $muted]);

        return $muted;
    }
}
