<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Resources;

use App\Modules\Inventory\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published project on the public list page. Field-by-field on purpose —
 * never reuse the CRM's LocationResource here (it carries gtm_priority, code,
 * coordinates and other internals the public must not see).
 *
 * Marketing copy ships as the full {en,fr,ar} object so a language switch
 * re-renders instantly without refetching.
 *
 * @mixin Location
 */
class PublicProjectSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tagline' => $this->marketing_tagline,
            'wilaya' => $this->whenLoaded('wilaya', fn () => $this->wilaya?->name),
            'commune' => $this->whenLoaded('commune', fn () => $this->commune?->name),
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type?->localizedLabel()),
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'construction_progress' => $this->construction_progress,
            'cover' => $this->coverThumb(),
            'show_prices' => (bool) $this->show_prices,
            'show_availability' => (bool) $this->show_availability,
            'price_from' => $this->show_prices ? $this->price_from : null,
            'available_count' => $this->show_availability ? (int) $this->available_count : null,
            'units_total' => (int) $this->units_total,
        ];
    }

    /** The cover picture via the PUBLIC thumb endpoint (+ its focal point). */
    private function coverThumb(): ?array
    {
        $cover = $this->coverMedia;

        if ($cover === null || ! $cover->isActive()) {
            return null;
        }

        $v = $cover->updated_at ? '?v='.$cover->updated_at->getTimestamp() : '';

        // Relative URLs on purpose — see PublicMediaResource.
        return [
            'thumb_url' => route('public.media.thumb', $cover->id, false).$v,
            'file_url' => route('public.media.file', $cover->id, false).$v,
            'focus_x' => (int) $this->cover_focus_x,
            'focus_y' => (int) $this->cover_focus_y,
        ];
    }
}
