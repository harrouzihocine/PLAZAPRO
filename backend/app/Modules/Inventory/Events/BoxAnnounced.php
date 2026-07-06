<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Box;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A newly-added box, broadcast to the public "announcements" channel for the live
 * "new box added" toast. The durable bell record is sent alongside by the
 * AnnounceNewBox listener; both are triggered off the Inventory BoxPublished
 * domain event so Inventory stays Collaboration-free. Mirrors UnitAnnounced.
 */
class BoxAnnounced implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Box $box) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('announcements')];
    }

    public function broadcastAs(): string
    {
        return 'box.published';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'box_id' => $this->box->id,
            'reference' => $this->box->reference,
            'location' => $this->box->location?->name,
            'type' => $this->box->type?->label,
        ];
    }
}
