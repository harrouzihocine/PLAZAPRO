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
 * A message's reaction set changed (added / replaced / removed). Broadcasts the
 * FULL set for that message so clients replace rather than merge — no add/remove
 * deltas to get out of sync over a flaky connection.
 */
class MessageReactionUpdated implements ShouldBroadcast
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
        return 'message.reaction';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing('reactions.user');

        return [
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'reactions' => $message->reactions->map(fn ($r) => [
                'emoji' => $r->emoji,
                'user_id' => $r->user_id,
                'user_name' => $r->user?->name,
            ])->values()->all(),
        ];
    }
}
