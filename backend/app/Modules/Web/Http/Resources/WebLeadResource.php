<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Resources;

use App\Modules\Web\Models\WebLead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A web lead on the staff inbox (auth side — full detail is fine here).
 *
 * @mixin WebLead
 */
class WebLeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'message' => $this->message,
            'type' => $this->type?->value,
            'lead_status' => $this->lead_status?->value,
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'reference' => $this->unit->reference,
            ] : null),
            'preferred_date' => $this->preferred_date?->toDateString(),
            'preferred_time' => $this->preferred_time,
            'locale' => $this->locale,
            'source_url' => $this->source_url,
            'converted_client_id' => $this->converted_client_id,
            'converted_client' => $this->whenLoaded('convertedClient', fn () => $this->convertedClient ? [
                'id' => $this->convertedClient->id,
                'name' => $this->convertedClient->full_name,
            ] : null),
            'handled_by' => $this->whenLoaded('handledBy', fn () => $this->handledBy?->name),
            'handled_at' => $this->handled_at,
            'created_at' => $this->created_at,
        ];
    }
}
