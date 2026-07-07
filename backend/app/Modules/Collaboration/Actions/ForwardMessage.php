<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Events\MessageSent;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Forward a message into other conversations (Messenger-style). Each forward is
 * a NEW message owned by the forwarder: body and attachment rows are copied
 * (attachment rows point at the same stored file — files are never duplicated
 * nor deleted), forwarded_from_id records provenance for the "Forwarded" tag.
 * Reply quotes and reactions do NOT travel — they belong to the source thread.
 *
 * The caller must be able to READ the source; each target must be WRITABLE by
 * the caller and not a frozen project's chat. One bad target fails the whole
 * batch (422) rather than silently forwarding to a subset.
 */
class ForwardMessage
{
    /**
     * @param  list<int>  $conversationIds
     * @return Collection<int, Message>
     */
    public function handle(Message $source, User $user, array $conversationIds): Collection
    {
        abort_if($source->isCancelled(), 422, 'A deleted message cannot be forwarded.');

        $targets = Conversation::query()->with('subject')->findMany($conversationIds);
        abort_unless($targets->count() === count(array_unique($conversationIds)), 422, 'A target conversation was not found.');

        foreach ($targets as $target) {
            abort_unless($target->isWritableBy($user), 403, 'You cannot post in one of the selected conversations.');
            abort_if(
                $target->subject instanceof ClientProject && $target->subject->isFrozen(),
                422,
                'One of the selected project chats is closed (read-only).',
            );
        }

        $source->loadMissing('attachments');

        $messages = DB::transaction(function () use ($source, $user, $targets) {
            return $targets->map(function (Conversation $target) use ($source, $user) {
                $message = $target->messages()->create([
                    'user_id' => $user->id,
                    'type' => $source->type->value,
                    'body' => $source->body,
                    'forwarded_from_id' => $source->id,
                ]);

                foreach ($source->attachments as $attachment) {
                    $message->attachments()->create([
                        'kind' => $attachment->kind->value,
                        'disk' => $attachment->disk,
                        'path' => $attachment->path,
                        'mime_type' => $attachment->mime_type,
                        'size_bytes' => $attachment->size_bytes,
                        'duration_ms' => $attachment->duration_ms,
                        'width' => $attachment->width,
                        'height' => $attachment->height,
                    ]);
                }

                // saveQuietly: bumping the inbox cursor is not an audited "update".
                $target->forceFill(['last_message_at' => now()])->saveQuietly();

                return $message;
            });
        });

        // After commit: live bubbles + bells, exactly like a fresh send.
        foreach ($messages as $message) {
            MessageSent::dispatch($message->load(['author', 'attachments']));
        }

        return $messages;
    }
}
