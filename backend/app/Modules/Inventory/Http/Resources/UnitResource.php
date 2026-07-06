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
            // Surfaced on the unit detail page (its project name + back-link);
            // only present when the relation is loaded (UnitController::show/index).
            // wilaya/commune is the project's geographic location.
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
                'wilaya_id' => $this->location?->wilaya_id,
                'wilaya' => $this->location?->wilaya?->name,
                'commune_id' => $this->location?->commune_id,
                'commune' => $this->location?->commune?->name,
                'contract_type' => $this->location?->contractType?->label,
                // Project type (open / closed / semi-closed residence) — a project
                // attribute the unit inherits, surfaced for context.
                'type' => $this->location?->type?->label,
                'expected_delivery_date' => $this->location?->expected_delivery_date?->toDateString(),
                'gtm_priority' => $this->location?->gtm_priority?->value,
            ]),
            'reference' => $this->reference,
            'room_number_id' => $this->room_number_id,
            'room_number' => $this->whenLoaded('roomNumber', fn () => $this->roomNumber?->label),
            'floor_id' => $this->floor_id,
            'floor' => $this->whenLoaded('floor', fn () => $this->floor?->label),
            'area_sqm' => $this->area_sqm,
            'price' => $this->price,
            'sale_status' => $this->sale_status?->value,
            // How many distinct client projects hold this unit — the "Interested
            // N" counter. Reserved adds its deposit timer + holder project id.
            'interested_count' => $this->interestedCountForResource(),
            'reserved_expires_at' => $this->reserved_expires_at?->toIso8601String(),
            'reserved_project_id' => $this->reserved_project_id,
            'gtm_priority' => $this->gtm_priority?->value,
            'block' => $this->block,
            'stack_floor' => $this->stack_floor,
            'position' => $this->position,
            'status' => $this->status?->value,
            'supersedes_id' => $this->supersedes_id,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Distinct client projects with a live hold. Uses the eager-loaded
     * activeReservations collection when present (list view — no N+1), else a
     * scoped count (detail view).
     */
    private function interestedCountForResource(): int
    {
        if ($this->resource->relationLoaded('activeReservations')) {
            return $this->activeReservations
                ->pluck('client_project_id')
                ->filter()
                ->unique()
                ->count();
        }

        return $this->interestedCount();
    }
}
