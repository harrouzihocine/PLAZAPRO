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
            'reference' => $this->reference,
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type?->label),
            'floor_id' => $this->floor_id,
            'floor' => $this->whenLoaded('floor', fn () => $this->floor?->label),
            'area_sqm' => $this->area_sqm,
            'rooms' => $this->rooms,
            'price' => $this->price,
            'sale_status' => $this->sale_status?->value,
            'block' => $this->block,
            'stack_floor' => $this->stack_floor,
            'position' => $this->position,
            'status' => $this->status?->value,
            'supersedes_id' => $this->supersedes_id,
            'created_at' => $this->created_at,
        ];
    }
}
