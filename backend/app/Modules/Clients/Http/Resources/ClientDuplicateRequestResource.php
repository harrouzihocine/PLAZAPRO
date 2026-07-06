<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources;

use App\Modules\Clients\Models\ClientDuplicateRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientDuplicateRequest
 */
class ClientDuplicateRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'attempted_data' => $this->attempted_data,
            'resolution' => $this->resolution,
            'share_details' => $this->share_details,
            'shared_project_id' => $this->shared_project_id,
            // The new project spawned for the finder (fork_project outcome) — lets
            // the FE deep-link to it after resolving.
            'spawned_project_id' => $this->spawned_project_id,
            'created_at' => $this->created_at,
            'resolved_at' => $this->resolved_at,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ] : null),
            // The resolver (a trusted supervisor) sees who the client is and the
            // projects they may share — name + phone + each project's step.
            'existing_client' => $this->whenLoaded('existingClient', fn () => $this->existingClient ? [
                'id' => $this->existingClient->id,
                'name' => $this->existingClient->full_name,
                'phone' => $this->existingClient->phone,
                'projects' => $this->existingClient->relationLoaded('projects')
                    ? $this->existingClient->projects->map(fn ($p) => [
                        'id' => $p->id,
                        'step' => $p->deriveStep(),
                        'location' => $p->location?->name,
                    ])->values()->all()
                    : [],
            ] : null),
        ];
    }
}
