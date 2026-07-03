<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\AdvanceClientProjectStage;
use App\Modules\Clients\Actions\ArchiveClientProject;
use App\Modules\Clients\Actions\CancelClientProject;
use App\Modules\Clients\Actions\CreateClientProject;
use App\Modules\Clients\Actions\ReactivateClientProject;
use App\Modules\Clients\Actions\ShiftProjectToDesire;
use App\Modules\Clients\Actions\UpdateClientProject;
use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Http\Requests\AdvanceClientProjectStageRequest;
use App\Modules\Clients\Http\Requests\ArchiveClientProjectRequest;
use App\Modules\Clients\Http\Requests\ShiftProjectToDesireRequest;
use App\Modules\Clients\Http\Requests\StoreClientProjectRequest;
use App\Modules\Clients\Http\Requests\UpdateClientProjectRequest;
use App\Modules\Clients\Http\Resources\ClientProjectResource;
use App\Modules\Clients\Http\Resources\DesireResource;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Models\DynamicListItem;
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
            // projects.view_all: without it, only own-created + shared-with-me.
            ->visibleTo($request->user())
            ->with(['location', 'unit.type', 'unit.floor', 'activeDeal', 'creator'])
            // Phase-6 closure queue, derived: properties the client liked that
            // await a won/lost decision, and prospects still in play at all.
            ->withCount([
                'shortlistItems as pending_closure_count' => fn ($q) => $q->active()
                    ->where('state', 'visited_interested'),
                'shortlistItems as open_prospect_count' => fn ($q) => $q->active()
                    ->whereIn('state', ['shortlisted', 'not_visited', 'visited_interested']),
            ])
            // Feeds is_empty (remove is allowed only on never-used projects).
            ->withExists(['calls', 'visits', 'shortlistItems', 'deals', 'paymentSchedules', 'versements'])
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
        // A project with any history (calls, visits, shortlist, deals, payments)
        // is part of the client's story — archive it instead of removing it.
        abort_unless(
            $project->isEmpty(),
            422,
            'Only an empty project can be removed — this one has activity logged on it. Archive it instead.',
        );

        $reason = (string) $request->input('reason', 'Deal cancelled');

        return new ClientProjectResource($action->handle($project, $reason));
    }

    /**
     * Archive the deal (reversible; hidden until reactivated) — the "Lost / Archived"
     * outcome. A reason from the archive_reasons list is required, and archiving is
     * blocked if any payment has been recorded (ArchiveClientProject).
     */
    public function archive(ArchiveClientProjectRequest $request, ClientProject $project, ArchiveClientProject $action): ClientProjectResource
    {
        $label = DynamicListItem::query()->whereKey($request->validated('archive_reason_id'))->value('label');
        $note = $request->validated('note');
        $reason = trim($label.($note ? " — {$note}" : ''));

        return new ClientProjectResource($action->handle($project, $reason)->load(['location', 'unit']));
    }

    /** Bring an archived deal (and the children archived with it) back to active. */
    public function reactivate(ClientProject $project, ReactivateClientProject $action): ClientProjectResource
    {
        return new ClientProjectResource($action->handle($project)->load(['location', 'unit']));
    }

    /**
     * Client changed their mind: archive the deal and put the client back on the
     * Desire list with (re-captured) criteria (ShiftProjectToDesire).
     */
    public function shiftToDesire(ShiftProjectToDesireRequest $request, ClientProject $project, ShiftProjectToDesire $action): DesireResource
    {
        return new DesireResource($action->handle($project, $request->validated()));
    }
}
