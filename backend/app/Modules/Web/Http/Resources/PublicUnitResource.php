<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Resources;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Web\Support\FormToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A unit's own public page: the same spec surface PublicProjectResource emits
 * per unit (binary `available`, never raw sale_status or hold data), plus the
 * unit's own gallery and the project breadcrumb context. Prices only when the
 * project opted in (show_prices).
 *
 * @mixin Unit
 */
class PublicUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $project = $this->location;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'rooms_id' => $this->room_number_id,
            'rooms' => $this->roomNumber?->localizedLabel(),
            'floor_id' => $this->floor_id,
            'floor' => $this->floor?->localizedLabel(),
            'area_sqm' => $this->area_sqm,
            'block' => $this->block,
            'stack_floor' => $this->stack_floor,
            'position' => $this->position,
            'available' => $this->sale_status === SaleStatus::Available,
            // Parked off the market by the promoteur — the public site keeps
            // showing it, greyed, rather than hiding it (distinct from a held/sold
            // apartment, which also reads available:false).
            'unavailable' => $this->sale_status === SaleStatus::Unavailable,
            'finishes' => array_map(fn ($f) => $f->value, $this->availableFinishes()),
            'price_semi_fini' => $project->show_prices ? $this->price_semi_fini : null,
            'price_fini' => $project->show_prices ? $this->price_fini : null,
            // The payment options actually offered here (the unit's own when it
            // overrides, else the project's) — same public shape as the project.
            'payment_methods' => $this->effectivePaymentMethods()
                ->map(fn ($m) => ['id' => $m->id, 'label' => $m->localizedLabel()])->all(),
            'media' => PublicMediaResource::collection($this->whenLoaded('media')),
            // Just enough of the parent for the breadcrumb + hero context.
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'tagline' => $project->marketing_tagline,
                'address' => $project->address,
                'wilaya' => $project->wilaya?->name,
                'commune' => $project->commune?->name,
                'show_prices' => (bool) $project->show_prices,
                'expected_delivery_date' => $project->expected_delivery_date?->toDateString(),
            ],
            'form_token' => FormToken::issue(),
        ];
    }
}
