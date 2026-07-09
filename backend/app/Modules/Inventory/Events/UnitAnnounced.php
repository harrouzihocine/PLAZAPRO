<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A newly-added unit, broadcast to the public "announcements" channel for the
 * live "new unit added" toast (click → the unit). The durable bell record is
 * sent alongside by the AnnounceNewUnit listener; both are triggered off the
 * existing Inventory UnitPublished domain event so Inventory stays Collaboration-
 * free.
 */
class UnitAnnounced implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Unit $unit) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('announcements')];
    }

    public function broadcastAs(): string
    {
        return 'unit.published';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'unit_id' => $this->unit->id,
            'reference' => $this->unit->reference,
            'location' => $this->unit->location?->name,
            'price' => (string) $this->unit->displayPrice(),
        ];
    }
}
