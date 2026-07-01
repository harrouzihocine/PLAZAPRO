<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Models\Conversation;

/**
 * Add members to a group conversation, skipping anyone already in it. Admin-only
 * authorisation is the controller/FormRequest's job.
 */
class AddParticipants
{
    /** @param  list<int>  $userIds */
    public function handle(Conversation $conversation, array $userIds): void
    {
        $existing = $conversation->participants()->pluck('users.id')->all();
        $now = now();

        $attach = [];
        foreach (array_unique($userIds) as $id) {
            if (! in_array($id, $existing, true)) {
                $attach[$id] = ['role' => 'member', 'joined_at' => $now];
            }
        }

        if ($attach !== []) {
            $conversation->participants()->attach($attach);
        }
    }
}
