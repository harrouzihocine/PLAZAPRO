<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A participant advanced their read cursor. Drives the live "seen" ✓✓ ticks:
 * a message counts as seen once every OTHER participant's cursor passed its
 * created_at. Only fired for real participant rows — oversight readers without
 * a row never update a cursor, so they never broadcast (and never tick).
 */
class ConversationRead implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public string $lastReadAt,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'last_read_at' => $this->lastReadAt,
        ];
    }
}
