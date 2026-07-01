<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Models\Conversation;

/**
 * Remove a member from a group conversation (kicked by an admin, or leaving).
 * Detaching the pivot row is intentional — participation is not audited domain
 * data; the conversation and its messages are kept.
 */
class RemoveParticipant
{
    public function handle(Conversation $conversation, int $userId): void
    {
        $conversation->participants()->detach($userId);
    }
}
