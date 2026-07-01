<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources;

use App\Modules\Clients\Models\Desire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Desire
 */
class DesireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'floor_pref' => $this->floor_pref,
            'rooms_min' => $this->rooms_min,
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'notes' => $this->notes,
            'area' => $this->whenLoaded('area', fn () => $this->area ? [
                'id' => $this->area->id,
                'label' => $this->area->label,
            ] : null),
            'type' => $this->whenLoaded('type', fn () => $this->type ? [
                'id' => $this->type->id,
                'label' => $this->type->label,
            ] : null),
            // Raw ids too, so the edit form can pre-select without extra lookups.
            'area_id' => $this->area_id,
            'type_id' => $this->type_id,
            'updated_at' => $this->updated_at,
        ];
    }
}
