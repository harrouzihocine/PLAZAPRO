<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Web\Actions\ConvertWebLead;
use App\Modules\Web\Enums\WebLeadStatus;
use App\Modules\Web\Http\Resources\WebLeadResource;
use App\Modules\Web\Models\WebLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The staff inbox for website leads (route-gated can:web.leads). Convert
 * additionally requires clients.create — the inbox may be visible to a desk
 * that triages (handled/spam) without client-creation rights.
 */
class WebLeadController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $leads = WebLead::query()->active()
            ->with(['location:id,name', 'unit:id,location_id,reference', 'convertedClient:id,first_name,last_name', 'handledBy:id,name'])
            ->when($request->filled('lead_status'), fn ($q) => $q->where('lead_status', $request->query('lead_status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->latest()
            ->paginate(50);

        return WebLeadResource::collection($leads);
    }

    public function markHandled(Request $request, WebLead $webLead): WebLeadResource
    {
        return $this->setStatus($request, $webLead, WebLeadStatus::Handled);
    }

    public function markSpam(Request $request, WebLead $webLead): WebLeadResource
    {
        return $this->setStatus($request, $webLead, WebLeadStatus::Spam);
    }

    public function convert(Request $request, WebLead $webLead, ConvertWebLead $action): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('clients.create'), 403);

        $options = $request->validate([
            'existing_client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'create_project' => ['sometimes', 'boolean'],
        ]);

        $result = $action->handle($webLead, $options, $request->user());

        if (isset($result['converted'])) {
            return response()->json([
                'data' => new WebLeadResource(
                    $webLead->fresh()->load(['location:id,name', 'unit:id,location_id,reference', 'convertedClient:id,first_name,last_name', 'handledBy:id,name']),
                ),
                'client_id' => $result['converted']->id,
            ]);
        }

        // Same shape as ClientController::store's duplicate answer: the client
        // id only when the converter may see that client.
        return response()->json([
            'duplicate' => true,
            'client_id' => $result['duplicate']?->id,
            'visible' => $result['visible'],
            'message' => $result['visible'] ? __('app.duplicate_own') : __('app.duplicate_other'),
        ], 409);
    }

    private function setStatus(Request $request, WebLead $webLead, WebLeadStatus $status): WebLeadResource
    {
        abort_if($webLead->lead_status === WebLeadStatus::Converted, 422, 'This lead was already converted.');

        $webLead->update([
            'lead_status' => $status->value,
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
        ]);

        return new WebLeadResource($webLead->load(['location:id,name', 'unit:id,location_id,reference', 'handledBy:id,name']));
    }
}
