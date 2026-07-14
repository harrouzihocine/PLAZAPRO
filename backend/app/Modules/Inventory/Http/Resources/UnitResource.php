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
        // A sold unit's asking price is privileged (units.sold_price) — masked
        // here so EVERY consumer (tables, detail page, pickers, search) obeys.
        $pricesVisible = $this->pricesVisibleTo($request->user());

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
                'contract_type' => $this->location?->contractType?->localizedLabel(),
                // Project type (open / closed / semi-closed residence) — a project
                // attribute the unit inherits, surfaced for context.
                'type' => $this->location?->type?->localizedLabel(),
                'expected_delivery_date' => $this->location?->expected_delivery_date?->toDateString(),
                'gtm_priority' => $this->location?->gtm_priority?->value,
            ]),
            'reference' => $this->reference,
            'room_number_id' => $this->room_number_id,
            'room_number' => $this->whenLoaded('roomNumber', fn () => $this->roomNumber?->localizedLabel()),
            'floor_id' => $this->floor_id,
            'floor' => $this->whenLoaded('floor', fn () => $this->floor?->localizedLabel()),
            'area_sqm' => $this->area_sqm,
            // Finish-level prices: semi-fini and/or fini — at least one is set.
            // What the unit can be offered as is derived from which are non-null.
            // Both come back null (`prices_masked`) when the viewer may not see
            // a sold unit's price — the UI shows a lock, never a fake blank.
            'price_semi_fini' => $pricesVisible ? $this->price_semi_fini : null,
            'price_fini' => $pricesVisible ? $this->price_fini : null,
            'prices_masked' => ! $pricesVisible,
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
            // Free-text remark shown wherever the unit's details appear.
            'note' => $this->note,
            // Payment options. `payment_methods_overridden` + `payment_method_ids`
            // drive the edit form (the unit's OWN set); `payment_methods` is the
            // effective list actually offered (own when overriding, else the
            // project's) for read views. Only present when eager-loaded.
            'payment_methods_overridden' => (bool) $this->payment_methods_overridden,
            'payment_method_ids' => $this->whenLoaded(
                'paymentMethods',
                fn () => $this->paymentMethods->pluck('id')->all(),
            ),
            'payment_methods' => $this->whenLoaded(
                'paymentMethods',
                fn () => $this->effectivePaymentMethods()
                    ->map(fn ($m) => ['id' => $m->id, 'label' => $m->localizedLabel()])->all(),
            ),
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
