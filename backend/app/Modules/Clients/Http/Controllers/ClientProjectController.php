<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\AdvanceClientProjectStage;
use App\Modules\Clients\Actions\ArchiveClientProject;
use App\Modules\Clients\Actions\CancelClientProject;
use App\Modules\Clients\Actions\CreateClientProject;
use App\Modules\Clients\Actions\ReactivateProjectWithHandoff;
use App\Modules\Clients\Actions\ShiftProjectToDesire;
use App\Modules\Clients\Actions\UpdateClientProject;
use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Http\Requests\AdvanceClientProjectStageRequest;
use App\Modules\Clients\Http\Requests\ArchiveClientProjectRequest;
use App\Modules\Clients\Http\Requests\ReactivateClientProjectRequest;
use App\Modules\Clients\Http\Requests\ShiftProjectToDesireRequest;
use App\Modules\Clients\Http\Requests\StoreClientProjectRequest;
use App\Modules\Clients\Http\Requests\UpdateClientProjectRequest;
use App\Modules\Clients\Http\Resources\ClientProjectResource;
use App\Modules\Clients\Http\Resources\DesireResource;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Deals (client_projects). Reads require clients.view; lifecycle writes (edit /
 * archive / reactivate / shift / remove) require projects.manage, opening one
 * requires projects.create, and stage moves through /advance (projects.advance)
 * so transition rules are enforced.
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
            // client: feeds collaboratorsVisibleTo (detail-visibility gate) on the
            // resource without an N+1; creator: the "opened by" identity behind it.
            ->with(['client', 'location', 'unit.floor', 'unit.location.type', 'activeDeal', 'creator', 'continuedFrom'])
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
            // Feeds awaiting_in_site_agent: an in-site plan sits in the dispatch
            // pool (no agent chosen yet) — the FE shows a "waiting for dispatcher"
            // note until a visits.dispatch holder assigns the field agent.
            ->withExists(['nextActions as awaiting_in_site_agent' => fn ($q) => $q->active()->pending()
                ->where('type', 'in_site_visit')->whereNull('assigned_to')])
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
        $reasonId = (int) $request->validated('archive_reason_id');
        $label = DynamicListItem::query()->whereKey($reasonId)->value('label');
        $note = $request->validated('note');
        $reason = trim($label.($note ? " — {$note}" : ''));

        return new ClientProjectResource(
            $action->handle($project, $reason, archiveReasonId: $reasonId)->load(['location', 'unit']),
        );
    }

    /**
     * Bring an archived deal back to active — optionally handing it to a chosen
     * team. With no body it is a plain reactivate; with handler_ids it becomes a
     * hand-off (as-is or a separate new project — ReactivateProjectWithHandoff).
     * In `separate` mode the returned resource is the NEW project.
     */
    public function reactivate(ReactivateClientProjectRequest $request, ClientProject $project, ReactivateProjectWithHandoff $action): ClientProjectResource
    {
        return new ClientProjectResource(
            $action->handle($project, $request->user(), $request->validated())->load(['location', 'unit']),
        );
    }

    /**
     * Who to show before handing a project over on reactivate: who opened it, its
     * current contributors, and the field agents who worked its in-site visits.
     * A compact, purpose-built payload, like DuplicateRequestController::previewProject.
     */
    public function handoffPreview(ClientProject $project): JsonResponse
    {
        $project->load([
            'creator:id,name',
            'viewers' => fn ($q) => $q->wherePivotNull('hidden_at')->orderBy('name'),
        ]);

        $contributors = collect();
        if ($project->creator !== null) {
            $contributors->push(['id' => $project->creator->id, 'name' => $project->creator->name, 'is_creator' => true]);
        }
        foreach ($project->viewers as $viewer) {
            if ($viewer->id === $project->created_by) {
                continue; // covered by the creator row
            }
            $contributors->push(['id' => $viewer->id, 'name' => $viewer->name, 'is_creator' => false]);
        }

        $inSiteAgents = $project->visits()->active()
            ->where('type', VisitType::InSite->value)
            ->whereNotNull('agent_id')
            ->with('agent:id,name')
            ->get()
            ->pluck('agent')
            ->filter()
            ->unique('id')
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
            ->values();

        return response()->json(['data' => [
            'opened_by' => $project->creator !== null
                ? ['id' => $project->creator->id, 'name' => $project->creator->name]
                : null,
            'contributors' => $contributors->values(),
            'in_site_agents' => $inSiteAgents,
        ]]);
    }

    /**
     * Client changed their mind: archive the deal and put the client back on the
     * Desire list with (re-captured) criteria (ShiftProjectToDesire).
     */
    public function shiftToDesire(ShiftProjectToDesireRequest $request, ClientProject $project, ShiftProjectToDesire $action): DesireResource
    {
        return new DesireResource($action->handle($project, $request->validated()));
    }

    /**
     * Freeze the project — a deliberate close-down to NEW activity (calls,
     * plans, visits, deals, chat); payments and documents still flow. The
     * project stays fully visible. Gated by projects.freeze.
     */
    public function freeze(Request $request, ClientProject $project): ClientProjectResource
    {
        abort_unless($project->isActive(), 422, 'Only an active project can be frozen.');
        abort_if($project->frozen_at !== null, 422, 'This project is already frozen.');

        $project->update(['frozen_at' => now(), 'frozen_by' => $request->user()->id]);

        return new ClientProjectResource($project->fresh()->load(['location', 'unit', 'activeDeal']));
    }

    /** Reopen a frozen project to new activity. Gated by projects.freeze. */
    public function unfreeze(ClientProject $project): ClientProjectResource
    {
        abort_if($project->frozen_at === null, 422, 'This project is not frozen.');

        $project->update(['frozen_at' => null, 'frozen_by' => null]);

        return new ClientProjectResource($project->fresh()->load(['location', 'unit', 'activeDeal']));
    }
}
