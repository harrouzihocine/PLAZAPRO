<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Resources;

use App\Modules\Pipeline\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Call
 */
class CallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client_project_id' => $this->client_project_id,
            'direction' => $this->direction?->value,
            'notes' => $this->notes,
            'topics' => $this->topics ?? [],
            'objections' => $this->objections ?? [],
            'called_at' => $this->called_at,
            'created_at' => $this->created_at,
            'status' => $this->status?->value,
            // A superseded (edited) version links back via supersedes_id; the reason
            // lives on the cancelled original. The FE shows an "edited" chip and
            // nests cancelled versions under their replacement.
            'edited' => $this->supersedes_id !== null,
            'edit_reason' => $this->whenLoaded('supersedes', fn () => $this->supersedes?->cancellation_reason),
            'supersedes_id' => $this->supersedes_id,
            'cancellation_reason' => $this->when($this->isCancelled(), fn () => $this->cancellation_reason),
            'agent' => $this->whenLoaded('agent', fn () => $this->agent ? [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
            ] : null),
            'outcome' => $this->whenLoaded('outcome', fn () => $this->outcome ? [
                'id' => $this->outcome->id,
                'label' => $this->outcome->label,
            ] : null),
        ];
    }
}
