<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Resources;

use App\Modules\Pipeline\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Visit
 */
class VisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client_project_id' => $this->client_project_id,
            'type' => $this->type?->value,
            'scheduled_at' => $this->scheduled_at,
            'completed_at' => $this->completed_at,
            // When the visit actually happened (agent-stated on the completion
            // form) — vs completed_at, when the log was filled.
            'visited_at' => $this->visited_at,
            'is_completed' => $this->completed_at !== null,
            'notes' => $this->notes,
            'checklist' => $this->checklist ?? [],
            'objections' => $this->objections ?? [],
            'created_at' => $this->created_at,
            'status' => $this->status?->value,
            'edited' => $this->supersedes_id !== null,
            'edit_reason' => $this->whenLoaded('supersedes', fn () => $this->supersedes?->cancellation_reason),
            'supersedes_id' => $this->supersedes_id,
            'cancellation_reason' => $this->when($this->isCancelled(), fn () => $this->cancellation_reason),
            // The deal this visit opened, if any (provenance) — the FE warns that
            // editing the log will cancel a still-waiting deal, and disables the
            // Edit button on a `locked` one (a payment or a sale on it).
            'spawned_deal' => $this->whenLoaded('deal', fn () => $this->deal ? [
                'id' => $this->deal->id,
                'state' => $this->deal->state?->value,
                'locked' => ! $this->deal->isJustWaiting(),
            ] : null),
            'agent' => $this->whenLoaded('agent', fn () => $this->agent ? [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
            ] : null),
            // The full property card (not just the code) — the deal step and the
            // timeline both show what was actually visited.
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'reference' => $this->unit->reference,
                'location_id' => $this->unit->location_id,
                'property_type' => $this->unit->location?->type?->localizedLabel(),
                'floor' => $this->unit->floor?->localizedLabel(),
                'area_sqm' => $this->unit->area_sqm,
                'price' => $this->unit->displayPrice(),
                'price_semi_fini' => $this->unit->price_semi_fini,
                'price_fini' => $this->unit->price_fini,
                // Site coordinates — lets the field agent open the in-site visit
                // in Google Maps.
                'location' => $this->unit->relationLoaded('location') && $this->unit->location ? [
                    'id' => $this->unit->location->id,
                    'name' => $this->unit->location->name,
                    'latitude' => $this->unit->location->latitude,
                    'longitude' => $this->unit->location->longitude,
                ] : null,
            ] : null),
            'outcome' => $this->whenLoaded('outcome', fn () => $this->outcome ? [
                'id' => $this->outcome->id,
                'label' => $this->outcome->localizedLabel(),
            ] : null),
        ];
    }
}
