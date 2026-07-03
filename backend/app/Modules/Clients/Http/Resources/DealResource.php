<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources;

use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deal
 */
class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_project_id' => $this->client_project_id,
            'visit_id' => $this->visit_id,
            'state' => $this->state?->value,
            'total_price' => $this->total_price,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'created_at' => $this->created_at,
            // Split so the UI renders apartments/locals and their boxes apart. The
            // full property card (not just the code) rides along on each item.
            'units' => $this->whenLoaded('items', fn () => $this->items
                ->filter(fn (DealItem $i) => $i->unit_id !== null && $i->isActive())
                ->values()
                ->map(fn (DealItem $i) => [
                    'item_id' => $i->id,
                    'id' => $i->unit->id,
                    'reference' => $i->unit->reference,
                    'type' => $i->unit->type?->label,
                    'floor' => $i->unit->floor?->label,
                    'area_sqm' => $i->unit->area_sqm,
                    'price' => $i->unit->price,
                    'sale_status' => $i->unit->sale_status?->value,
                    'location' => $i->unit->location?->name,
                    'location_id' => $i->unit->location_id,
                ])),
            'boxes' => $this->whenLoaded('items', fn () => $this->items
                ->filter(fn (DealItem $i) => $i->box_id !== null && $i->isActive())
                ->values()
                ->map(fn (DealItem $i) => [
                    'item_id' => $i->id,
                    'id' => $i->box->id,
                    'reference' => $i->box->reference,
                    'type' => $i->box->type?->label,
                    'price' => $i->box->price,
                    'sale_status' => $i->box->sale_status?->value,
                    'location_id' => $i->box->location_id,
                ])),
        ];
    }
}
