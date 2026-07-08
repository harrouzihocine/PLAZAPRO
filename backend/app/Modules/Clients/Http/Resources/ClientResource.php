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
        // clients.view_details: without it a user sees only who the client IS
        // (name + status) — no phone, email, notes or profile fields. A per-client
        // detail grant (from a supervised duplicate-share) also unlocks details.
        $user = $request->user();
        $canSeeDetails = $user !== null && $this->resource->isDetailVisibleTo($user);

        // Client ownership (who it's assigned to, and who created it, when) is
        // back-office-only — gated by clients.manage, held by super-admin / admin
        // / manager. Agents (view/create only) never see it.
        $canSeeOwnership = (bool) $request->user()?->can('clients.manage');

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'status' => $this->status?->value,
            'phone' => $this->when($canSeeDetails, $this->phone),
            'email' => $this->when($canSeeDetails, $this->email),
            'notes' => $this->when($canSeeDetails, $this->notes),
            // Who told the client about the project (source = referral).
            'referrer_name' => $this->when($canSeeDetails, $this->referrer_name),
            'referrer_phone' => $this->when($canSeeDetails, $this->referrer_phone),
            // Identity / contract details captured for closing a deal. A client
            // may hold several ID documents, each with its own issue date/place.
            'id_documents' => $this->when($canSeeDetails, fn () => $this->id_documents ?? []),
            'id_number' => $this->when($canSeeDetails, $this->id_number),
            'birth_date' => $this->when($canSeeDetails, $this->birth_date?->toDateString()),
            'birth_place' => $this->when($canSeeDetails, $this->birth_place),
            'address' => $this->when($canSeeDetails, $this->address),
            // Workflow gate: a client's first entity is a call — until one exists the
            // FE hides deals/desire/visits behind a "log the first call" CTA.
            'has_calls' => $this->when(isset($this->calls_exists), fn () => (bool) $this->calls_exists),
            'source' => $this->when($canSeeDetails, fn () => $this->whenLoaded('source', fn () => $this->source ? [
                'id' => $this->source->id,
                'label' => $this->source->localizedLabel(),
                'value' => $this->source->value,
                'icon' => $this->source->meta['icon'] ?? null,
            ] : null)),
            'rating' => $this->when($canSeeDetails, fn () => $this->whenLoaded('rating', fn () => $this->rating ? [
                'id' => $this->rating->id,
                'label' => $this->rating->localizedLabel(),
                'value' => $this->rating->value,
                'meta' => $this->rating->meta,
            ] : null)),
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
