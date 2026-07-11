<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Resources;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Web\Support\FormToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published project's full public page. Units are mapped inline (not via the
 * CRM's UnitResource) so the availability flag is a plain boolean and nothing
 * about holds, reservations or sales priority can leak. Prices appear only
 * when the project opted in (show_prices).
 *
 * @mixin Location
 */
class PublicProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tagline' => $this->marketing_tagline,
            'marketing_description' => $this->marketing_description,
            'address' => $this->address,
            'wilaya' => $this->whenLoaded('wilaya', fn () => $this->wilaya?->name),
            'commune' => $this->whenLoaded('commune', fn () => $this->commune?->name),
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type?->localizedLabel()),
            'contract_type' => $this->whenLoaded('contractType', fn () => $this->contractType?->localizedLabel()),
            'payment_methods' => $this->whenLoaded(
                'paymentMethods',
                fn () => $this->paymentMethods->map(fn ($m) => ['id' => $m->id, 'label' => $m->localizedLabel()])->all(),
            ),
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'construction_progress' => $this->construction_progress,
            'cover_media_id' => $this->whenLoaded('coverMedia', fn () => $this->coverMedia?->isActive() ? $this->coverMedia->id : null),
            'cover_focus_x' => (int) $this->cover_focus_x,
            'cover_focus_y' => (int) $this->cover_focus_y,
            'show_prices' => (bool) $this->show_prices,
            'show_availability' => (bool) $this->show_availability,
            'media' => PublicMediaResource::collection($this->whenLoaded('media')),
            'units' => $this->whenLoaded('units', fn () => $this->units->map(
                fn (Unit $unit) => $this->publicUnit($unit),
            )->all()),
            // Refreshes the lead form's min-fill-time token on every page view.
            'form_token' => FormToken::issue(),
        ];
    }

    /**
     * A unit as the visitor sees it: specs + a binary `available`. `interested`
     * (backup holds live) deliberately reads unavailable — a held apartment is
     * not advertised as free. Never expose raw sale_status or reservation data.
     */
    private function publicUnit(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'reference' => $unit->reference,
            'rooms_id' => $unit->room_number_id,
            'rooms' => $unit->roomNumber?->localizedLabel(),
            'floor_id' => $unit->floor_id,
            'floor' => $unit->floor?->localizedLabel(),
            'area_sqm' => $unit->area_sqm,
            'block' => $unit->block,
            'stack_floor' => $unit->stack_floor,
            'position' => $unit->position,
            'available' => $unit->sale_status === SaleStatus::Available,
            'finishes' => array_map(fn ($f) => $f->value, $unit->availableFinishes()),
            'price_semi_fini' => $this->show_prices ? $unit->price_semi_fini : null,
            'price_fini' => $this->show_prices ? $unit->price_fini : null,
        ];
    }
}
