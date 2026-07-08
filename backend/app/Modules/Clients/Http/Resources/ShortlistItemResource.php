<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources;

use App\Modules\Clients\Models\ShortlistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShortlistItem
 */
class ShortlistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_project_id' => $this->client_project_id,
            'office_visit_id' => $this->office_visit_id,
            'call_id' => $this->call_id,
            'state' => $this->state?->value,
            'note' => $this->note,
            'shortlistable_type' => $this->shortlistable_type,
            'shortlistable_id' => $this->shortlistable_id,
            // Non-null when an open deal (open/won) or a Reserved deposit
            // pins this property to the project — the FE hides its remove button.
            'locked_reason' => $this->lockedReason(),
            // The full property card (not just the code): what it is, where it
            // sits, how big, at what price.
            'property' => $this->whenLoaded('shortlistable', fn () => $this->shortlistable ? [
                'type' => $this->shortlistable_type,
                'id' => $this->shortlistable->id,
                'reference' => $this->shortlistable->reference,
                'price' => $this->shortlistable->price,
                'sale_status' => $this->shortlistable->sale_status?->value,
                'location_id' => $this->shortlistable->location_id,
                'location' => $this->shortlistable->location?->name,
                'property_type' => $this->shortlistable_type === 'unit'
                    ? $this->shortlistable->roomNumber?->localizedLabel()
                    : $this->shortlistable->type?->localizedLabel(),
                'floor' => $this->shortlistable_type === 'unit' ? $this->shortlistable->floor?->localizedLabel() : null,
                'area_sqm' => $this->shortlistable_type === 'unit' ? $this->shortlistable->area_sqm : null,
            ] : null),
        ];
    }
}
