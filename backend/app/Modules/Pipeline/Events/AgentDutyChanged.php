<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agent toggled duty (or their status changed shape). The dispatcher's
 * board/map recolors the agent's dot live.
 */
class AgentDutyChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $userId,
        public string $status,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('dispatch')];
    }

    public function broadcastAs(): string
    {
        return 'agent.duty';
    }
}
