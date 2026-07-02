<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\AdvanceClientProjectStage;
use App\Modules\Clients\Actions\ArchiveClientProject;
use App\Modules\Clients\Actions\CancelClientProject;
use App\Modules\Clients\Actions\CreateClientProject;
use App\Modules\Clients\Actions\ReactivateClientProject;
use App\Modules\Clients\Actions\UpdateClientProject;
use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Http\Requests\AdvanceClientProjectStageRequest;
use App\Modules\Clients\Http\Requests\StoreClientProjectRequest;
use App\Modules\Clients\Http\Requests\UpdateClientProjectRequest;
use App\Modules\Clients\Http\Resources\ClientProjectResource;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Deals (client_projects). Reads require clients.view; all writes require
 * clients.manage. Stage moves through /advance so transition rules are enforced.
 */
class ClientProjectController extends Controller
{
    public function index(Request $request, Client $client): AnonymousResourceCollection
    {
        // Default lists live deals; ?status=archived feeds the "reactivate" view;
        // ?status=all returns every lifecycle state (removed included).
        $status = $request->query('status');

        $projects = $client->projects()
            ->with(['location', 'unit'])
            ->when($status === 'archived', fn ($q) => $q->archived())
            ->when(! in_array($status, ['archived', 'all'], true), fn ($q) => $q->active())
            ->latest('id')
            ->get();

        return ClientProjectResource::collection($projects);
    }

    public function store(StoreClientProjectRequest $request, Client $client, CreateClientProject $action): ClientProjectResource
    {
        return new ClientProjectResource(
            $action->handle($client, $request->validated())->load(['location', 'unit']),
        );
    }

    public function update(UpdateClientProjectRequest $request, ClientProject $project, UpdateClientProject $action): ClientProjectResource
    {
        return new ClientProjectResource(
            $action->handle($project, $request->validated())->load(['location', 'unit']),
        );
    }

    public function advance(AdvanceClientProjectStageRequest $request, ClientProject $project, AdvanceClientProjectStage $action): ClientProjectResource
    {
        $target = ClientProjectStage::from($request->validated('stage'));

        return new ClientProjectResource(
            $action->handle($project, $target)->load(['location', 'unit']),
        );
    }

    public function destroy(Request $request, ClientProject $project, CancelClientProject $action): ClientProjectResource
    {
        $reason = (string) $request->input('reason', 'Deal cancelled');

        return new ClientProjectResource($action->handle($project, $reason));
    }

    /** Archive the deal + its contents (reversible; hidden until reactivated). */
    public function archive(ClientProject $project, ArchiveClientProject $action): ClientProjectResource
    {
        return new ClientProjectResource($action->handle($project)->load(['location', 'unit']));
    }

    /** Bring an archived deal (and the children archived with it) back to active. */
    public function reactivate(ClientProject $project, ReactivateClientProject $action): ClientProjectResource
    {
        return new ClientProjectResource($action->handle($project)->load(['location', 'unit']));
    }
}
