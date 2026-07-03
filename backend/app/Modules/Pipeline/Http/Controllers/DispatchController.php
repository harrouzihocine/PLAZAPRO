<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Actions\AssignDispatchItem;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Http\Requests\DispatchAssignRequest;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dispatch board (visits.dispatch): the pending pool of unassigned in-site
 * plans on top, and one row per field agent × the 7 days of the requested week,
 * showing their workload (all visit types + call plans for context; only
 * in-site items are draggable). Saving applies the drag-and-drop moves.
 */
class DispatchController extends Controller
{
    public function board(Request $request): JsonResponse
    {
        $start = ($request->filled('week')
            ? Carbon::parse($request->query('week'))
            : now())->startOfWeek();
        $end = $start->copy()->endOfWeek();

        // The pool: every unassigned in-site plan, oldest due first. Each carries
        // the shortlisted units + their sites — the exact properties the assigned
        // agent will visit (what GenerateInSiteVisits materializes on assignment),
        // so the dispatcher sees WHERE to send someone before assigning.
        $pending = NextAction::query()->active()->pending()
            ->where('type', NextActionType::InSiteVisit->value)
            // In-site plans always live on a project (SyncVisitFromNextAction
            // enforces it); the filter also shields the mapper from any legacy
            // client-subject row.
            ->where('subject_type', 'client_project')
            ->whereNull('assigned_to')
            // Morph-aware: only a project subject nests a client + shortlist.
            ->with(['subject' => fn (MorphTo $m) => $m->morphWith([ClientProject::class => [
                'client',
                'shortlistItems' => fn ($q) => $q->active()
                    ->where('shortlistable_type', 'unit')
                    ->whereIn('state', ['shortlisted', 'not_visited'])
                    ->with(['shortlistable' => fn (MorphTo $sm) => $sm->morphWith([Unit::class => ['location']])]),
            ]])])
            ->orderBy('due_at')
            ->get()
            ->map(fn (NextAction $a) => [
                'kind' => 'action',
                'id' => $a->id,
                'due_at' => $a->due_at,
                'client' => $a->subject?->client?->full_name,
                'client_id' => $a->subject?->client_id,
                'project_id' => $a->subject_type === 'client_project' ? $a->subject_id : null,
                'units' => $this->shortlistUnits($a->subject),
                'sites' => $this->shortlistSites($a->subject),
                'link' => $a->subject_type === 'client_project' && $a->subject
                    ? '/clients/'.$a->subject->client_id.'/projects/'.$a->subject_id
                    : null,
            ]);

        $agents = User::query()->active()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->orderBy('name')
            ->get(['id', 'name', 'role_id']);

        // The week's workload: every open/completed visit scheduled in range,
        // plus assigned call plans due in range (context only).
        $visits = Visit::query()->active()
            ->whereBetween('scheduled_at', [$start, $end])
            ->whereNotNull('agent_id')
            ->with(['client:id,first_name,last_name', 'unit.location'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Visit $v) => [
                'kind' => 'visit',
                'id' => $v->id,
                'type' => $v->type->value,
                'agent_id' => $v->agent_id,
                'at' => $v->scheduled_at,
                'day' => $v->scheduled_at->toDateString(),
                'is_completed' => $v->completed_at !== null,
                'draggable' => $v->type->value === 'in_site' && $v->completed_at === null,
                'can_unassign' => $v->type->value === 'in_site' && $v->completed_at === null && $v->next_action_id !== null,
                'client' => $v->client?->full_name,
                'unit' => $v->unit?->reference,
                'location' => $v->unit?->location?->name,
                'maps_url' => $v->unit?->location?->mapsUrl(),
                'link' => $v->client_project_id
                    ? '/clients/'.$v->client_id.'/projects/'.$v->client_project_id
                    : ($v->client_id ? '/clients/'.$v->client_id : null),
            ]);

        $callPlans = NextAction::query()->active()->pending()
            ->where('type', NextActionType::Call->value)
            ->whereNotNull('assigned_to')
            ->whereBetween('due_at', [$start, $end])
            ->with(['subject' => fn (MorphTo $m) => $m->morphWith([ClientProject::class => ['client']])])
            ->get()
            ->map(fn (NextAction $a) => [
                'kind' => 'action',
                'id' => $a->id,
                'type' => 'call',
                'agent_id' => $a->assigned_to,
                'at' => $a->due_at,
                'day' => $a->due_at->toDateString(),
                'is_completed' => false,
                'draggable' => false,
                'client' => $a->subject?->client?->full_name ?? $a->subject?->full_name,
                'link' => $a->subject_type === 'client_project' && $a->subject
                    ? '/clients/'.$a->subject->client_id.'/projects/'.$a->subject_id
                    : ($a->subject_type === 'client' ? '/clients/'.$a->subject_id : null),
            ]);

        return response()->json(['data' => [
            'week_start' => $start->toDateString(),
            'pending' => $pending->values(),
            'agents' => $agents->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'items' => $visits->concat($callPlans)->values(),
        ]]);
    }

    public function assign(DispatchAssignRequest $request, AssignDispatchItem $action): JsonResponse
    {
        // All moves land together or not at all — half-saved boards lie.
        DB::transaction(function () use ($request, $action) {
            foreach ($request->validated('changes') as $change) {
                $action->handle($change);
            }
        });

        return response()->json(['saved' => true]);
    }

    /**
     * The shortlisted properties an in-site plan will send the agent to visit.
     *
     * @return list<array{reference: ?string, site: ?string}>
     */
    private function shortlistUnits(?ClientProject $project): array
    {
        return $this->shortlistItems($project)
            ->map(fn ($item) => [
                'reference' => $item->shortlistable?->reference,
                'site' => $item->shortlistable?->location?->name,
            ])
            ->filter(fn ($u) => $u['reference'] !== null)
            ->values()
            ->all();
    }

    /**
     * The distinct sites (locations) those properties sit on, each with a Google
     * Maps link when the location has coordinates.
     *
     * @return list<array{name: string, maps_url: ?string}>
     */
    private function shortlistSites(?ClientProject $project): array
    {
        return $this->shortlistItems($project)
            ->map(fn ($item) => $item->shortlistable?->location)
            ->filter()
            ->unique('id')
            ->map(fn ($loc) => [
                'name' => $loc->name,
                'maps_url' => $loc->mapsUrl(),
            ])
            ->values()
            ->all();
    }

    /** The active unit shortlist items eager-loaded on the pending action's project. */
    private function shortlistItems(?ClientProject $project): Collection
    {
        return $project instanceof ClientProject
            ? $project->shortlistItems
            : new Collection;
    }
}
