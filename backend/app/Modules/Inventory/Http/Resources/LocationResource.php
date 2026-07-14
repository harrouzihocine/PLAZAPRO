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
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type?->localizedLabel()),
            'contract_type_id' => $this->contract_type_id,
            'contract_type' => $this->whenLoaded('contractType', fn () => $this->contractType?->localizedLabel()),
            // Offered payment / financing options (project_payment_methods).
            // `_ids` drives the multi-select on the edit form; the objects render
            // the labels on read views.
            'payment_method_ids' => $this->whenLoaded(
                'paymentMethods',
                fn () => $this->paymentMethods->pluck('id')->all(),
            ),
            'payment_methods' => $this->whenLoaded(
                'paymentMethods',
                fn () => $this->paymentMethods->map(fn ($m) => ['id' => $m->id, 'label' => $m->localizedLabel()])->all(),
            ),
            'address' => $this->address,
            'description' => $this->description,
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'gtm_priority' => $this->gtm_priority?->value,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            // The cover picture id; the SPA streams it via /api/v1/media/{id}/file.
            'cover_media_id' => $this->cover_media_id,
            // Focal point (%), applied as CSS object-position on the card/hero.
            'cover_focus_x' => (int) $this->cover_focus_x,
            'cover_focus_y' => (int) $this->cover_focus_y,
            // Public-website controls (showcase visibility + marketing copy).
            'is_published' => (bool) $this->is_published,
            // Off-market veil: false = parked (hidden from selectors, greyed publicly).
            'is_available' => (bool) $this->is_available,
            'show_prices' => (bool) $this->show_prices,
            'show_availability' => (bool) $this->show_availability,
            'marketing_tagline' => $this->marketing_tagline,
            'marketing_description' => $this->marketing_description,
            'construction_progress' => $this->construction_progress,
            'status' => $this->status?->value,
            'units_count' => $this->whenCounted('units'),
            'created_at' => $this->created_at,
        ];
    }
}
