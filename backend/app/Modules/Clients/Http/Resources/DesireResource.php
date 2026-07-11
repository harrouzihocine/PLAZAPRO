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
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'notes' => $this->notes,
            // Every criterion is multi-valued: labelled entries for display, plus
            // the raw id lists so the edit form can pre-select without lookups.
            'floors' => $this->whenLoaded('floors', fn () => $this->floors
                ->map(fn ($f) => ['id' => $f->id, 'label' => $f->localizedLabel()])->all()),
            'floor_ids' => $this->whenLoaded('floors', fn () => $this->floors->pluck('id')->all()),
            // The preferred sites (projects) the client would buy into.
            'locations' => $this->whenLoaded('locations', fn () => $this->locations
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->all()),
            'location_ids' => $this->whenLoaded('locations', fn () => $this->locations->pluck('id')->all()),
            'wilayas' => $this->whenLoaded('wilayas', fn () => $this->wilayas
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->all()),
            'wilaya_ids' => $this->whenLoaded('wilayas', fn () => $this->wilayas->pluck('id')->all()),
            'communes' => $this->whenLoaded('communes', fn () => $this->communes
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all()),
            'commune_ids' => $this->whenLoaded('communes', fn () => $this->communes->pluck('id')->all()),
            'types' => $this->whenLoaded('types', fn () => $this->types
                ->map(fn ($t) => ['id' => $t->id, 'label' => $t->localizedLabel()])->all()),
            'type_ids' => $this->whenLoaded('types', fn () => $this->types->pluck('id')->all()),
            'room_numbers' => $this->whenLoaded('roomNumbers', fn () => $this->roomNumbers
                ->map(fn ($r) => ['id' => $r->id, 'label' => $r->localizedLabel()])->all()),
            'room_number_ids' => $this->whenLoaded('roomNumbers', fn () => $this->roomNumbers->pluck('id')->all()),
            'contract_types' => $this->whenLoaded('contractTypes', fn () => $this->contractTypes
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->localizedLabel()])->all()),
            'contract_type_ids' => $this->whenLoaded('contractTypes', fn () => $this->contractTypes->pluck('id')->all()),
            'updated_at' => $this->updated_at,
        ];
    }
}
