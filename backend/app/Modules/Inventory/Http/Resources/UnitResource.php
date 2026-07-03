<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unit
 */
class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            // Surfaced on the unit detail page (its project name + back-link);
            // only present when the relation is loaded (UnitController::show/index).
            // wilaya/commune is the project's geographic location.
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
                'wilaya_id' => $this->location?->wilaya_id,
                'wilaya' => $this->location?->wilaya?->name,
                'commune_id' => $this->location?->commune_id,
                'commune' => $this->location?->commune?->name,
                'contract_type' => $this->location?->contractType?->label,
                'expected_delivery_date' => $this->location?->expected_delivery_date?->toDateString(),
                'gtm_priority' => $this->location?->gtm_priority?->value,
            ]),
            'reference' => $this->reference,
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type?->label),
            'floor_id' => $this->floor_id,
            'floor' => $this->whenLoaded('floor', fn () => $this->floor?->label),
            'area_sqm' => $this->area_sqm,
            'price' => $this->price,
            'sale_status' => $this->sale_status?->value,
            'gtm_priority' => $this->gtm_priority?->value,
            'block' => $this->block,
            'stack_floor' => $this->stack_floor,
            'position' => $this->position,
            'status' => $this->status?->value,
            'supersedes_id' => $this->supersedes_id,
            'created_at' => $this->created_at,
        ];
    }
}
