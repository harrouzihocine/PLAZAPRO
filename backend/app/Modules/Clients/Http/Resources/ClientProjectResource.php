<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources;

use App\Modules\Clients\Models\ClientProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientProject
 */
class ClientProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'stage' => $this->stage?->value,
            // The legal next stages — lets the UI offer only valid moves.
            'allowed_next' => collect($this->stage?->allowedNext() ?? [])->map(fn ($s) => $s->value)->all(),
            'total_price' => $this->total_price,
            'status' => $this->status?->value,
            // Where the project stands (badge): new/qualifying/office_visit/
            // in_site_visit/deal/won/lost — or desire/archived once closed.
            'step' => $this->deriveStep(),
            'closed_to_desire' => $this->closed_to_desire_at !== null,
            // Why it left active (set on archive/shift-to-desire; null when active).
            'closure_reason' => $this->when(! $this->isActive(), fn () => $this->cancellation_reason),
            // Phase-6 closure queue (set on index via withCount): liked properties
            // awaiting won/lost, and prospects still in play (any pre-closure state).
            'pending_closure_count' => $this->when(isset($this->pending_closure_count), fn () => (int) $this->pending_closure_count),
            'open_prospect_count' => $this->when(isset($this->open_prospect_count), fn () => (int) $this->open_prospect_count),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'expected_delivery_date' => $this->location->expected_delivery_date?->toDateString(),
                'gtm_priority' => $this->location->gtm_priority?->value,
            ] : null),
            // The full property card, not just the code.
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'reference' => $this->unit->reference,
                'type' => $this->unit->type?->label,
                'floor' => $this->unit->floor?->label,
                'area_sqm' => $this->unit->area_sqm,
                'price' => $this->unit->price,
                'sale_status' => $this->unit->sale_status?->value,
            ] : null),
            'active_deal' => $this->whenLoaded('activeDeal', fn () => $this->activeDeal ? [
                'id' => $this->activeDeal->id,
                'state' => $this->activeDeal->state?->value,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
