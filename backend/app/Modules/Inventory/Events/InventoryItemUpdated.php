<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An inventory item (a unit or a box) was edited — broadcast to the public
 * "announcements" channel so every logged-in browser flashes a live toast, no
 * refresh. The ephemeral, everyone-sees-it twin of the durable "updated" bell
 * record (AnnounceUnitEdited / AnnounceBoxEdited fire both). Carries plain scalars
 * so the payload is stable and self-contained.
 */
class InventoryItemUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  'unit'|'box'  $type     which inventory item was edited
     * @param  string        $context  room / floor / location (unit) or type / location (box)
     * @param  string        $changed  human list of the fields that moved
     */
    public function __construct(
        public string $type,
        public int $id,
        public string $reference,
        public string $context = '',
        public string $changed = '',
        public ?string $link = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('announcements')];
    }

    public function broadcastAs(): string
    {
        return 'inventory.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'reference' => $this->reference,
            'context' => $this->context,
            'changed' => $this->changed,
            'link' => $this->link,
        ];
    }
}
