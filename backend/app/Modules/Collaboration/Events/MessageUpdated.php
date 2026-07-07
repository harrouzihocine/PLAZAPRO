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
 * A message changed in place — edited, or redacted ("deleted"). Open threads
 * patch the bubble live instead of waiting for a reload; the payload carries
 * the new body/flags plus the inbox preview so a changed LATEST message also
 * refreshes the conversation list row.
 */
class MessageUpdated implements ShouldBroadcast
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
        return 'message.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message;
        $redacted = $message->isCancelled();

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'type' => $message->type->value,
            'body' => $redacted ? null : $message->body,
            'redacted' => $redacted,
            'edited_at' => $message->edited_at?->toIso8601String(),
            'preview' => $message->previewLabel(),
        ];
    }
}
