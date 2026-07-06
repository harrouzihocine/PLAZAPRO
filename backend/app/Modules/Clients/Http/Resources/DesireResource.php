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
            'area_min' => $this->area_min,
            'area_max' => $this->area_max,
            'rooms_min' => $this->rooms_min,
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'notes' => $this->notes,
            'floor' => $this->whenLoaded('floor', fn () => $this->floor ? [
                'id' => $this->floor->id,
                'label' => $this->floor->label,
            ] : null),
            // The preferred sites (projects) the client would buy into.
            'locations' => $this->whenLoaded('locations', fn () => $this->locations
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->all()),
            'location_ids' => $this->whenLoaded('locations', fn () => $this->locations->pluck('id')->all()),
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
            'room_number' => $this->whenLoaded('roomNumber', fn () => $this->roomNumber ? [
                'id' => $this->roomNumber->id,
                'label' => $this->roomNumber->label,
            ] : null),
            'contract_type' => $this->whenLoaded('contractType', fn () => $this->contractType ? [
                'id' => $this->contractType->id,
                'label' => $this->contractType->label,
            ] : null),
            // Raw ids too, so the edit form can pre-select without extra lookups.
            'wilaya_id' => $this->wilaya_id,
            'commune_id' => $this->commune_id,
            'type_id' => $this->type_id,
            'room_number_id' => $this->room_number_id,
            'contract_type_id' => $this->contract_type_id,
            'floor_id' => $this->floor_id,
            'updated_at' => $this->updated_at,
        ];
    }
}
