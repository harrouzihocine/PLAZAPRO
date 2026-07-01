<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'client_project_id' => $this->client_project_id,
            'held_by' => $this->held_by,
            'held_at' => $this->held_at,
            'expires_at' => $this->expires_at,
            'hold_status' => $this->hold_status?->value,
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'created_at' => $this->created_at,
        ];
    }
}
