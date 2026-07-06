<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\ResolveDuplicateRequest as ResolveDuplicateAction;
use App\Modules\Clients\Http\Requests\ResolveDuplicateRequest;
use App\Modules\Clients\Http\Resources\ClientDuplicateRequestResource;
use App\Modules\Clients\Models\ClientDuplicateRequest;
use App\Modules\Clients\Models\ClientProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The supervised duplicate-client queue (clients.duplicates.resolve). Lists the
 * pending requests and resolves each — deny, or share a project (± details).
 */
class DuplicateRequestController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $requests = ClientDuplicateRequest::query()
            ->where('status', 'pending')
            ->with(['requester', 'existingClient.projects.location'])
            ->latest()
            ->get();

        return ClientDuplicateRequestResource::collection($requests);
    }

    public function resolve(
        ResolveDuplicateRequest $request,
        ClientDuplicateRequest $duplicateRequest,
        ResolveDuplicateAction $action,
    ): ClientDuplicateRequestResource {
        $resolved = $action->handle(
            $duplicateRequest,
            $request->validated('action'),
            $request->user(),
            $request->validated(),
        );

        return new ClientDuplicateRequestResource(
            $resolved->load(['requester', 'existingClient.projects.location', 'sharedProject', 'spawnedProject']),
        );
    }

    /**
     * A read-only detail of ONE of the existing client's projects, so the resolver
     * can decide from real content (activity volume, stage, the client's brief)
     * rather than a bare step badge. Scoped to the request's client — an id from a
     * different client reads as absent. The resolver holds duplicates.resolve; the
     * route already gates that. A compact, purpose-built payload (not the full
     * project resource) — enough to judge, nothing more.
     */
    public function previewProject(ClientDuplicateRequest $duplicateRequest, ClientProject $project): JsonResponse
    {
        abort_unless((int) $project->client_id === (int) $duplicateRequest->existing_client_id, 404);

        $project->load(['location', 'unit', 'activeDeal', 'creator'])
            ->loadCount(['calls', 'visits', 'shortlistItems', 'deals']);

        $desire = $duplicateRequest->existingClient?->desire()
            ->with(['type', 'roomNumber', 'wilaya', 'commune'])
            ->first();

        return response()->json(['data' => [
            'id' => $project->id,
            'step' => $project->deriveStep(),
            'stage' => $project->stage?->value,
            'opened_by' => $project->creator?->name,
            'opened_at' => $project->created_at,
            'location' => $project->location?->name,
            'unit' => $project->unit?->reference,
            'active_deal_state' => $project->activeDeal?->state?->value,
            'contributor_count' => $project->contributorIds()->count(),
            'counts' => [
                'calls' => $project->calls_count,
                'visits' => $project->visits_count,
                'shortlist' => $project->shortlist_items_count,
                'deals' => $project->deals_count,
            ],
            'desire' => $desire ? [
                'type' => $desire->type?->label,
                'rooms' => $desire->roomNumber?->label,
                'wilaya' => $desire->wilaya?->name,
                'commune' => $desire->commune?->name,
                'budget_min' => $desire->budget_min,
                'budget_max' => $desire->budget_max,
                'area_min' => $desire->area_min,
                'area_max' => $desire->area_max,
                'notes' => $desire->notes,
            ] : null,
        ]]);
    }
}
