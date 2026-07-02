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
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'notes' => $this->notes,
            'wilaya' => $this->whenLoaded('wilaya', fn () => $this->wilaya ? [
                'id' => $this->wilaya->id,
                'name' => $this->wilaya->name,
            ] : null),
            'commune' => $this->whenLoaded('commune', fn () => $this->commune ? [
                'id' => $this->commune->id,
                'name' => $this->commune->name,
            ] : null),
            'type' => $this->whenLoaded('type', fn () => $this->type ? [
                'id' => $this->type->id,
                'label' => $this->type->label,
            ] : null),
            // Raw ids too, so the edit form can pre-select without extra lookups.
            'wilaya_id' => $this->wilaya_id,
            'commune_id' => $this->commune_id,
            'type_id' => $this->type_id,
            'updated_at' => $this->updated_at,
        ];
    }
}
