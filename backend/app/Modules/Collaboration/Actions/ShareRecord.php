<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Enums\MessageType;
use App\Modules\Collaboration\Events\MessageSent;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Share a record (client/deal/unit) into a conversation as a system message that
 * carries a subject reference. Access still respects RBAC — the MessageResource
 * only reveals the card to participants who may view the record. That the author
 * can view what they share is enforced by the FormRequest.
 */
class ShareRecord
{
    public function handle(Conversation $conversation, User $author, Model $subject, ?string $note = null): Message
    {
        $message = DB::transaction(function () use ($conversation, $author, $subject, $note) {
            $message = $conversation->messages()->create([
                'user_id' => $author->id,
                'type' => MessageType::System->value,
                'body' => $note,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
            ]);

            $conversation->forceFill(['last_message_at' => now()])->saveQuietly();

            return $message;
        });

        MessageSent::dispatch($message->load(['author', 'attachments', 'subject']));

        return $message;
    }
}
