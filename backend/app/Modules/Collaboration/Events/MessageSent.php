<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Events;

use App\Modules\Collaboration\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was sent. Broadcasts live to the conversation's private channel (for
 * open threads) and is also listened to by NotifyParticipantsOfMessage (for the
 * bell of participants who aren't looking). The broadcast payload is a compact,
 * per-recipient-agnostic view; the authoritative, RBAC-filtered message comes
 * from the HTTP message list.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing(['author', 'attachments', 'replyTo.author']);

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'type' => $message->type->value,
            'body' => $message->body,
            'author' => [
                'id' => $message->author?->id,
                'name' => $message->author?->name,
                'avatar_url' => $message->author?->avatarUrl(),
            ],
            // Same shapes as MessageResource so a live-appended message renders
            // identically to a fetched one (grouped avatars, quotes, reactions).
            'reply_to' => $message->replyPreview(),
            'forwarded' => $message->forwarded_from_id !== null,
            'reactions' => [],
            'subject_type' => $message->subject_type,
            'subject_id' => $message->subject_id,
            'created_at' => $message->created_at?->toIso8601String(),
            'attachments' => $message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'kind' => $a->kind->value,
                'mime_type' => $a->mime_type,
                'duration_ms' => $a->duration_ms,
                'url' => '/api/v1/attachments/'.$a->id,
            ])->all(),
        ];
    }
}
