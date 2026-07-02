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
            'wilaya_id' => $this->wilaya_id,
            'wilaya' => $this->whenLoaded('wilaya', fn () => $this->wilaya ? [
                'id' => $this->wilaya->id,
                'code' => $this->wilaya->code,
                'name' => $this->wilaya->name,
            ] : null),
            'commune_id' => $this->commune_id,
            'commune' => $this->whenLoaded('commune', fn () => $this->commune ? [
                'id' => $this->commune->id,
                'name' => $this->commune->name,
            ] : null),
            'address' => $this->address,
            'description' => $this->description,
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'gtm_priority' => $this->gtm_priority?->value,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status?->value,
            'units_count' => $this->whenCounted('units'),
            'created_at' => $this->created_at,
        ];
    }
}
