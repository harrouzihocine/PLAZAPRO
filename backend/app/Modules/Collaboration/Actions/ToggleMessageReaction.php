<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Events\MessageReactionUpdated;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\User;

/**
 * WhatsApp reaction semantics: one reaction per user per message — the same
 * emoji toggles it off, a different one replaces it. Write authorisation
 * (isWritableBy) is the caller's job; this action guards message/project state.
 */
class ToggleMessageReaction
{
    public function handle(Message $message, User $user, string $emoji): Message
    {
        abort_if($message->isCancelled(), 422, 'This message was deleted.');

        // Same freeze rule as posting: a closed project's chat is read-only.
        $subject = $message->conversation->subject;
        abort_if(
            $subject instanceof ClientProject && $subject->isFrozen(),
            422,
            'This project is closed — its chat is read-only.',
        );

        $existing = $message->reactions()->where('user_id', $user->id)->first();

        if ($existing !== null && $existing->emoji === $emoji) {
            $existing->delete();
        } elseif ($existing !== null) {
            $existing->update(['emoji' => $emoji]);
        } else {
            $message->reactions()->create(['user_id' => $user->id, 'emoji' => $emoji]);
        }

        MessageReactionUpdated::dispatch($message->load('reactions.user'));

        return $message;
    }
}
