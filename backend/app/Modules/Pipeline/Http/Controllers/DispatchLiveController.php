<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Actions\LocateOnDutyAgents;
use App\Modules\Pipeline\Actions\SuggestDispatchAgents;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Pipeline\Support\AgentStatus;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * The dispatcher's live layer (visits.dispatch, like the board):
 *
 *  - map:     every field agent's status + freshest fix, and today's target
 *             sites with their visits — the Onfleet-style ops view;
 *  - suggest: ranked agents for one pending plan (SuggestDispatchAgents);
 *  - replay:  one agent's breadcrumb trail + visits for a day — dispute
 *             resolution, not surveillance: retention is short and access is
 *             dispatcher-only.
 */
class DispatchLiveController extends Controller
{
    public function map(LocateOnDutyAgents $locate): JsonResponse
    {
        // Opening (or refreshing) the map is exactly "a dispatcher looking":
        // ping the on-duty phones for fresh fixes — the answers stream in over
        // the dispatch channel moments later. Throttled per agent inside.
        $locate->handle();

        $agents = User::query()->active()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        $live = AgentStatus::forUsers($agents->pluck('id')->all());

        // Today's in-site visits, grouped under their site pin.
        $visits = Visit::query()->active()
            ->where('type', 'in_site')
            ->whereNotNull('agent_id')
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
            ->with(['client:id,first_name,last_name', 'unit.location', 'agent:id,name'])
            ->orderBy('scheduled_at')
            ->get();

        $sites = $visits
            ->filter(fn (Visit $v) => $v->unit?->location !== null)
            ->groupBy(fn (Visit $v) => $v->unit->location->id)
            ->map(function ($group) {
                $location = $group->first()->unit->location;

                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'lat' => $location->latitude !== null ? (float) $location->latitude : null,
                    'lng' => $location->longitude !== null ? (float) $location->longitude : null,
                    'maps_url' => $location->mapsUrl(),
                    'visits' => $group->map(fn (Visit $v) => [
                        'id' => $v->id,
                        'client' => $v->client?->full_name,
                        'unit' => $v->unit?->reference,
                        'time' => $v->scheduled_at->hour === 0 && $v->scheduled_at->minute === 0
                            ? null : $v->scheduled_at->format('H:i'),
                        'status' => $v->dispatchStatus(),
                        'agent_id' => $v->agent_id,
                        'agent' => $v->agent?->name,
                    ])->values(),
                ];
            })
            ->values();

        // Sites a PENDING pool plan points at (nothing assigned yet) — shown
        // hollow on the map so the dispatcher sees tomorrow's ground too.
        $pendingSites = $this->pendingSites();

        return response()->json(['data' => [
            'agents' => $agents->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                ...$live->payloadFor((int) $u->id),
            ])->values(),
            'sites' => $sites,
            'pending_sites' => $pendingSites,
            // Sites referenced today with no pin — the dispatcher's cue to go
            // drop the marker on the location form.
            'unpinned_site_names' => $visits
                ->map(fn (Visit $v) => $v->unit?->location)
                ->filter(fn ($l) => $l !== null && ($l->latitude === null || $l->longitude === null))
                ->unique('id')->pluck('name')->values(),
        ]]);
    }

    /** A targeted ping (roster click) — same throttle as the map-open burst. */
    public function locate(Request $request, LocateOnDutyAgents $locate): JsonResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'integer', 'exists:users,id']]);

        return response()->json(['data' => ['pinged' => $locate->handle([(int) $data['agent_id']])]]);
    }

    public function suggest(Request $request, SuggestDispatchAgents $action): JsonResponse
    {
        $data = $request->validate(['action_id' => ['required', 'integer']]);

        $nextAction = NextAction::query()->active()->pending()
            ->where('type', NextActionType::InSiteVisit->value)
            ->findOrFail($data['action_id']);

        return response()->json(['data' => $action->handle($nextAction)]);
    }

    public function replay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $day = Carbon::parse($data['date']);

        $positions = AgentPosition::query()
            ->where('user_id', $data['agent_id'])
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->limit(2000)
            ->get(['latitude', 'longitude', 'accuracy_m', 'recorded_at']);

        $visits = Visit::query()
            ->where('agent_id', $data['agent_id'])
            ->where('type', 'in_site')
            ->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->with(['client:id,first_name,last_name', 'unit.location'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Visit $v) => [
                'id' => $v->id,
                'client' => $v->client?->full_name,
                'unit' => $v->unit?->reference,
                'site' => $v->unit?->location?->name,
                'lat' => $v->unit?->location?->latitude !== null ? (float) $v->unit->location->latitude : null,
                'lng' => $v->unit?->location?->longitude !== null ? (float) $v->unit->location->longitude : null,
                'status' => $v->dispatchStatus(),
                'scheduled_at' => $v->scheduled_at,
                'arrived_at' => $v->arrived_at,
                'departed_at' => $v->departed_at,
            ]);

        return response()->json(['data' => [
            'positions' => $positions->map(fn (AgentPosition $p) => [
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'at' => $p->recorded_at,
            ])->values(),
            'visits' => $visits->values(),
        ]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingSites(): array
    {
        $pending = NextAction::query()->active()->pending()
            ->where('type', NextActionType::InSiteVisit->value)
            ->where('subject_type', 'client_project')
            ->whereNull('assigned_to')
            ->with(['subject' => fn (MorphTo $m) => $m->morphWith([ClientProject::class => [
                'shortlistItems' => fn ($q) => $q->active()
                    ->where('shortlistable_type', 'unit')
                    ->whereIn('state', ['shortlisted', 'not_visited'])
                    ->with(['shortlistable' => fn (MorphTo $sm) => $sm->morphWith([Unit::class => ['location']])]),
            ]])])
            ->get();

        return $pending
            ->flatMap(function (NextAction $a) {
                $project = $a->subject;
                if (! $project instanceof ClientProject) {
                    return [];
                }
                $items = $a->target_unit_ids
                    ? $project->shortlistItems->whereIn('shortlistable_id', $a->target_unit_ids)
                    : $project->shortlistItems;

                return $items->map(fn ($item) => $item->shortlistable?->location)->filter();
            })
            ->unique('id')
            ->filter(fn ($l) => $l->latitude !== null && $l->longitude !== null)
            ->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'lat' => (float) $l->latitude,
                'lng' => (float) $l->longitude,
            ])
            ->values()
            ->all();
    }
}
