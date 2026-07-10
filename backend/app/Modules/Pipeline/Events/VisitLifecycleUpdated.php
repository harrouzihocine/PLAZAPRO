<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use App\Modules\Pipeline\Models\Visit;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A visit moved along the dispatch lifecycle (accepted / en route / arrived /
 * departed — by tap or by geofence). Broadcast to the dispatchers' channel
 * (board + map recolor the card live) AND to the agent's own channel, so the
 * phone that auto-arrived by geofence sees its My Day stepper advance without
 * a refresh.
 */
class VisitLifecycleUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public int $visitId;

    public ?int $agentId;

    public string $status;

    public function __construct(Visit $visit, public ?int $eta = null)
    {
        $this->visitId = $visit->id;
        $this->agentId = $visit->agent_id !== null ? (int) $visit->agent_id : null;
        $this->status = $visit->dispatchStatus();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('dispatch')];
        if ($this->agentId !== null) {
            $channels[] = new PrivateChannel('users.'.$this->agentId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'visit.lifecycle';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'visit_id' => $this->visitId,
            'agent_id' => $this->agentId,
            'status' => $this->status,
            'eta_minutes' => $this->eta,
        ];
    }
}
