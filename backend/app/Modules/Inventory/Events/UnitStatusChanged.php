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
 * A unit's sale state moved (interested / reserved / available / sold) or its
 * deposit timer changed. Broadcasts to the public "announcements" channel so
 * every open unit view repaints its badge + "Interested N" counter live, no
 * refresh.
 * Fired by the Unit model's updated hook, so it covers ALL transition sites.
 * Ephemeral UI only — durable bell records go through DomainNotification.
 */
class UnitStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * $saleStatusChanged is true when sale_status itself moved (vs. only the
     * deposit timer changing) — the durable bell notification fires only on a
     * real state move (AnnounceUnitStatusChange).
     */
    public function __construct(
        public Unit $unit,
        public bool $saleStatusChanged = true,
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
        return 'unit.status-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // SerializesModels re-hydrates the unit on the queue worker, so this runs
        // against committed state (the transaction that changed it has landed).
        return [
            'id' => $this->unit->id,
            'reference' => $this->unit->reference,
            'location_id' => $this->unit->location_id,
            'sale_status' => $this->unit->sale_status?->value,
            'interested_count' => $this->unit->interestedCount(),
            'reserved_expires_at' => $this->unit->reserved_expires_at?->toIso8601String(),
        ];
    }
}
