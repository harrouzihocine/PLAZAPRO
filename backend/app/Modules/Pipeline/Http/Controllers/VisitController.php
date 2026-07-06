<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Actions\AssignVisit;
use App\Modules\Pipeline\Actions\CompleteInteraction;
use App\Modules\Pipeline\Actions\CorrectVisit;
use App\Modules\Pipeline\Actions\ProposeInSiteVisit;
use App\Modules\Pipeline\Actions\ScheduleVisit;
use App\Modules\Pipeline\Http\Requests\AssignVisitRequest;
use App\Modules\Pipeline\Http\Requests\CompleteVisitRequest;
use App\Modules\Pipeline\Http\Requests\CorrectVisitRequest;
use App\Modules\Pipeline\Http\Requests\ProposeInSiteVisitRequest;
use App\Modules\Pipeline\Http\Requests\ScheduleVisitRequest;
use App\Modules\Pipeline\Http\Resources\VisitResource;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Visits. Reads need clients.view; scheduling/assigning need visits.assign (and
 * the agent-only rule); completing needs visits.conduct and leaves a next action.
 */
class VisitController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $visits = Visit::query()
            ->with(['agent', 'unit', 'outcome'])
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('agent_id'), fn ($q) => $q->where('agent_id', $request->integer('agent_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->query('completed') === '0', fn ($q) => $q->whereNull('completed_at'))
            ->when($request->query('completed') === '1', fn ($q) => $q->whereNotNull('completed_at'))
            ->orderByDesc('scheduled_at')
            ->get();

        return VisitResource::collection($visits);
    }

    public function store(ScheduleVisitRequest $request, ScheduleVisit $action): VisitResource
    {
        $data = $request->validated();

        // A closed or frozen project takes no new visits — same rule as calls
        // and plans (an explicit unfreeze / reactivate reopens it).
        if (! empty($data['client_project_id'])) {
            $project = ClientProject::findOrFail((int) $data['client_project_id']);
            abort_unless($project->isActive(), 422, 'This project is closed — reactivate it before scheduling on it.');
            abort_if($project->isFrozen(), 422, 'This project is frozen — unfreeze it before scheduling on it.');
        }

        return new VisitResource(
            $action->handle($data)->load(['agent', 'unit']),
        );
    }

    public function assign(AssignVisitRequest $request, Visit $visit, AssignVisit $action): VisitResource
    {
        return new VisitResource(
            $action->handle($visit, $request->validated('agent_id'))->load(['agent', 'unit']),
        );
    }

    /**
     * Add apartment(s) to visit on a project — the standalone twin of the
     * "another apartment" step inside visit completion, freed from the "only on
     * the last open visit" gate. Only a dispatcher (visits.dispatch) may pre-pick
     * the field agent; everyone else's addition lands in the dispatch pool.
     */
    public function proposeInSite(
        ProposeInSiteVisitRequest $request,
        ClientProject $project,
        ProposeInSiteVisit $action,
    ): AnonymousResourceCollection {
        $agentId = $request->user()->can('visits.dispatch')
            ? $request->validated('assigned_to')
            : null;

        $visits = $action->handle(
            $project,
            array_map('intval', $request->validated('unit_ids')),
            $request->validated('due_date'),
            $request->validated('due_time'),
            $agentId !== null ? (int) $agentId : null,
            $request->user(),
        );

        // The action returns a plain collection of fresh models (empty when the
        // addition was pooled) — hydrate an Eloquent collection so their agent /
        // unit can be eager-loaded for the resource.
        return VisitResource::collection(
            (new EloquentCollection($visits->all()))->load(['agent', 'unit']),
        );
    }

    public function complete(CompleteVisitRequest $request, Visit $visit, CompleteInteraction $action): VisitResource
    {
        return new VisitResource(
            $action->handle($visit, $request->validated(), $request->user())->load(['agent', 'unit', 'outcome']),
        );
    }

    /**
     * Correct a visit's details with a reason: cancels the original and returns the
     * new version, keeping both in history (CorrectVisit → supersedeWith).
     */
    public function correct(CorrectVisitRequest $request, Visit $visit, CorrectVisit $action): VisitResource
    {
        return new VisitResource(
            $action->handle($visit, $request->safe()->except('reason'), $request->validated('reason'))
                ->load(['agent', 'unit', 'outcome']),
        );
    }
}
