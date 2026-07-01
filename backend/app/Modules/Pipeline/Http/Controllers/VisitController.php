<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Pipeline\Actions\AssignVisit;
use App\Modules\Pipeline\Actions\CompleteInteraction;
use App\Modules\Pipeline\Actions\ScheduleVisit;
use App\Modules\Pipeline\Http\Requests\AssignVisitRequest;
use App\Modules\Pipeline\Http\Requests\CompleteVisitRequest;
use App\Modules\Pipeline\Http\Requests\ScheduleVisitRequest;
use App\Modules\Pipeline\Http\Resources\VisitResource;
use App\Modules\Pipeline\Models\Visit;
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
            ->active()
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
        return new VisitResource(
            $action->handle($request->validated())->load(['agent', 'unit']),
        );
    }

    public function assign(AssignVisitRequest $request, Visit $visit, AssignVisit $action): VisitResource
    {
        return new VisitResource(
            $action->handle($visit, $request->validated('agent_id'))->load(['agent', 'unit']),
        );
    }

    public function complete(CompleteVisitRequest $request, Visit $visit, CompleteInteraction $action): VisitResource
    {
        return new VisitResource(
            $action->handle($visit, $request->validated())->load(['agent', 'unit', 'outcome']),
        );
    }
}
