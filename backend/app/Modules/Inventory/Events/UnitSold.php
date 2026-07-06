<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A unit was declared sold. Broadcasts to the public "announcements" channel so
 * every logged-in browser fires the full-screen "congratulations" celebration
 * live. Collaboration also listens (AnnounceUnitSold) to drop a durable "unit
 * sold" record in every user's bell. Carries plain scalars (no model) so the
 * payload is stable regardless of later record edits.
 */
class UnitSold implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  list<string>  $saleAgents  who did the marketing
     * @param  list<string>  $insiteAgents  who ran the site visits
     * @param  list<string>  $otherAgents  anyone else credited
     * @param  array{type?: ?string, room_number?: ?string, floor?: ?string, area_sqm?: ?string}  $details
     */
    public function __construct(
        public int $unitId,
        public string $reference,
        public ?string $locationName = null,
        public ?string $clientName = null,
        public ?string $agentName = null,
        public ?string $price = null,
        public array $saleAgents = [],
        public array $insiteAgents = [],
        public array $otherAgents = [],
        public array $details = [],
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
        return 'unit.sold';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'unit_id' => $this->unitId,
            'reference' => $this->reference,
            'location' => $this->locationName,
            'client' => $this->clientName,
            'agent' => $this->agentName,
            'price' => $this->price,
            'sale_agents' => $this->saleAgents,
            'insite_agents' => $this->insiteAgents,
            'other_agents' => $this->otherAgents,
            'type' => $this->details['type'] ?? null,
            'room_number' => $this->details['room_number'] ?? null,
            'floor' => $this->details['floor'] ?? null,
            'area_sqm' => $this->details['area_sqm'] ?? null,
            'address' => $this->details['address'] ?? null,
        ];
    }
}
