<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources;

use App\Modules\Clients\Models\ClientProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientProject
 */
class ClientProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Who opened the project + its viewers list + its chat are collaborator
        // identity — shown only to the project's own members or to a user trusted
        // with the client's details, never to a name-only looker (so a colleague's
        // client cannot be quietly poached). Drives the FE's viewers panel / chat.
        $user = $request->user();
        $canSeeCollaborators = $user !== null && $this->resource->collaboratorsVisibleTo($user);

        return [
            'id' => $this->id,
            'can_view_collaborators' => $canSeeCollaborators,
            // The caller is here ONLY as the dispatched field agent (they hold an
            // in-site visit, but are no contributor / client owner): their remit
            // is that visit — the FE hides the project's other log affordances
            // (log call / plan / complete office visit), which the server rejects
            // anyway. A visit administrator (visits.assign) is never dispatch-only.
            'is_dispatch_only' => $user !== null
                && ! $user->can('visits.assign')
                && $this->resource->isDispatchOnlyAgent($user),
            'client_id' => $this->client_id,
            'stage' => $this->stage?->value,
            // The legal next stages — lets the UI offer only valid moves.
            'allowed_next' => collect($this->stage?->allowedNext() ?? [])->map(fn ($s) => $s->value)->all(),
            'total_price' => $this->total_price,
            'status' => $this->status?->value,
            // Where the project stands (badge): new/qualifying/office_visit/
            // in_site_visit/deal/won/lost — or desire/archived once closed.
            'step' => $this->deriveStep(),
            'closed_to_desire' => $this->closed_to_desire_at !== null,
            // Closed to NEW activity (calls / visits / chat) — payments still flow.
            // True when archived/cancelled OR explicitly frozen (frozen_at set).
            'frozen' => $this->isFrozen(),
            'frozen_at' => $this->frozen_at,
            // Why it left active (set on archive/shift-to-desire; null when active).
            'closure_reason' => $this->when(! $this->isActive(), fn () => $this->cancellation_reason),
            // Phase-6 closure queue (set on index via withCount): liked properties
            // awaiting won/lost, and prospects still in play (any pre-closure state).
            'pending_closure_count' => $this->when(isset($this->pending_closure_count), fn () => (int) $this->pending_closure_count),
            'open_prospect_count' => $this->when(isset($this->open_prospect_count), fn () => (int) $this->open_prospect_count),
            // True while an in-site plan waits in the dispatch pool — the field
            // agent hasn't been chosen yet (set on index via withExists). The FE
            // surfaces a note until a dispatcher assigns the in-site agent.
            'awaiting_in_site_agent' => $this->when(
                isset($this->awaiting_in_site_agent),
                fn () => (bool) $this->awaiting_in_site_agent,
            ),
            // True when nothing was ever logged on the project (set on index via
            // withExists) — only then may it be removed; otherwise archive it.
            'is_empty' => $this->when(
                isset($this->calls_exists),
                fn () => ! ($this->calls_exists || $this->visits_exists || $this->shortlist_items_exists
                    || $this->deals_exists || $this->payment_schedules_exists || $this->versements_exists),
            ),
            // Who opened the project — anchors the per-project visibility list.
            // Hidden from name-only lookers (see $canSeeCollaborators above).
            'created_by' => $this->when(
                $canSeeCollaborators,
                fn () => $this->whenLoaded('creator', fn () => $this->creator ? [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ] : null),
            ),
            // Oversight-only marker: this project is a continuation of an earlier
            // engagement (a duplicate-resolution "separate project"). Shown only to
            // projects.view_all holders — the finder must not learn the original
            // exists. Drives a "continuation" badge in the manager's project list.
            'continued_from' => $this->when(
                $user?->can('projects.view_all') && $this->continued_from_project_id !== null,
                fn () => $this->whenLoaded('continuedFrom', fn () => $this->continuedFrom ? [
                    'id' => $this->continuedFrom->id,
                    'step' => $this->continuedFrom->deriveStep(),
                ] : ['id' => $this->continued_from_project_id]),
            ),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'expected_delivery_date' => $this->location->expected_delivery_date?->toDateString(),
                'gtm_priority' => $this->location->gtm_priority?->value,
            ] : null),
            // The full property card, not just the code.
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'reference' => $this->unit->reference,
                // Project type (a project attribute the unit inherits).
                'type' => $this->unit->location?->type?->localizedLabel(),
                'floor' => $this->unit->floor?->localizedLabel(),
                'area_sqm' => $this->unit->area_sqm,
                'price' => $this->unit->displayPrice(),
                'sale_status' => $this->unit->sale_status?->value,
            ] : null),
            'active_deal' => $this->whenLoaded('activeDeal', fn () => $this->activeDeal ? [
                'id' => $this->activeDeal->id,
                'state' => $this->activeDeal->state?->value,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
