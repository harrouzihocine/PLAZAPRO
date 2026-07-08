<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A click-to-call request was answered (call logged, or "not now") on one of
 * the user's devices. Broadcast to the user's own channel so every OTHER open
 * session (the PC while the phone answered, or vice versa) closes its
 * still-open "log this call?" prompt instead of asking twice.
 */
class CallRequestClosed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $userId,
        public int $callRequestId,
        public string $status,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'call-request.closed';
    }
}
