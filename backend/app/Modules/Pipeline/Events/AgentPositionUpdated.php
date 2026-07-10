<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A fresh on-duty GPS fix (RecordAgentPosition). Broadcast on the private
 * `dispatch` channel — visits.dispatch holders only — so the live map moves
 * the agent's dot without polling. Carries the derived status and, when the
 * agent is en route, the ETA estimate to their nearest target site.
 */
class AgentPositionUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $userId,
        public float $lat,
        public float $lng,
        public string $recordedAt,
        public string $status,
        public ?int $etaMinutes = null,
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
        return 'agent.position';
    }
}
