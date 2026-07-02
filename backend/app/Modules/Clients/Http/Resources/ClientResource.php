<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources;

use App\Modules\Clients\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Client ownership (who it's assigned to, and who created it, when) is
        // back-office-only — gated by clients.manage, held by super-admin / admin
        // / manager. Agents (view/create only) never see it.
        $canSeeOwnership = (bool) $request->user()?->can('clients.manage');

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'source' => $this->whenLoaded('source', fn () => $this->source ? [
                'id' => $this->source->id,
                'label' => $this->source->label,
                'value' => $this->source->value,
            ] : null),
            'rating' => $this->whenLoaded('rating', fn () => $this->rating ? [
                'id' => $this->rating->id,
                'label' => $this->rating->label,
                'value' => $this->rating->value,
                'meta' => $this->rating->meta,
            ] : null),
            'assigned_agent' => $this->when($canSeeOwnership, fn () => $this->assignedAgent ? [
                'id' => $this->assignedAgent->id,
                'name' => $this->assignedAgent->name,
            ] : null),
            'created_by' => $this->when($canSeeOwnership, fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
