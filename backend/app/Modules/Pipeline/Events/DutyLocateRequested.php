<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A dispatcher asked for a fresh fix from one on-duty agent (map open /
 * roster click). Broadcast on the agent's own channel: an open page grabs one
 * geolocation fix and posts it. The phone-in-pocket path rides a silent FCM
 * data message instead (LocateOnDutyAgents) — this event is the cheap half.
 *
 * Battery contract: idle tracking is coarse and slow; THIS is what turns GPS
 * on, briefly, when someone actually looks.
 */
class DutyLocateRequested implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $userId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'duty.locate';
    }
}
