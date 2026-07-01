<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Box;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Box
 */
class BoxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'reference' => $this->reference,
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type?->label),
            'price' => $this->price,
            'sale_status' => $this->sale_status?->value,
            'unit_id' => $this->unit_id,
            'status' => $this->status?->value,
            'created_at' => $this->created_at,
        ];
    }
}
