<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Enums\AttachmentKind;
use App\Modules\Collaboration\Enums\MessageType;
use App\Modules\Collaboration\Events\MessageSent;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Post a message to a conversation: insert the message, store its optional
 * attachment, bump the conversation's last_message_at — all in one transaction —
 * then raise MessageSent (live broadcast + notify other participants) once the
 * row is committed. Participant authorisation is the caller's job (FormRequest).
 */
class SendMessage
{
    public function __construct(private StoreAttachment $storeAttachment) {}

    public function handle(
        Conversation $conversation,
        User $author,
        ?string $body,
        ?UploadedFile $attachment = null,
        ?int $durationMs = null,
    ): Message {
        abort_if($body === null && $attachment === null, 422, 'A message needs text or an attachment.');

        $type = MessageType::Text;
        if ($attachment !== null) {
            $kind = AttachmentKind::fromMime((string) $attachment->getMimeType());
            abort_if($kind === null, 422, 'Unsupported attachment type.');
            $type = $kind->messageType();
        }

        $message = DB::transaction(function () use ($conversation, $author, $body, $type, $attachment, $durationMs) {
            $message = $conversation->messages()->create([
                'user_id' => $author->id,
                'type' => $type->value,
                'body' => $body,
            ]);

            if ($attachment !== null) {
                $this->storeAttachment->handle($message, $attachment, $durationMs);
            }

            // saveQuietly: bumping the inbox cursor is not an audited "update".
            $conversation->forceFill(['last_message_at' => now()])->saveQuietly();

            return $message;
        });

        MessageSent::dispatch($message->load(['author', 'attachments']));

        return $message;
    }
}
