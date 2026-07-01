<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'area_id' => $this->area_id,
            'area' => $this->whenLoaded('area', fn () => [
                'id' => $this->area->id,
                'label' => $this->area->label,
                'value' => $this->area->value,
            ]),
            'address' => $this->address,
            'description' => $this->description,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status?->value,
            'units_count' => $this->whenCounted('units'),
            'created_at' => $this->created_at,
        ];
    }
}
