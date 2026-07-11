<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Actions\LocateOnDutyAgents;
use App\Modules\Pipeline\Actions\ProposeDispatchPlan;
use App\Modules\Pipeline\Actions\SuggestDispatchAgents;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\AgentMileageDay;
use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Pipeline\Support\AgentStatus;
use App\Modules\Pipeline\Support\Geo;
use App\Modules\Pipeline\Support\Mileage;
use App\Modules\Pipeline\Support\Router;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
            // Live en-route legs: the road each driving agent is on, drawn
            // from their freshest fix to the target site — "where is he going".
            'legs' => $this->enRouteLegs($visits, $live),
            'pending_sites' => $pendingSites,
            // Sites referenced today with no pin — the dispatcher's cue to go
            // drop the marker on the location form.
            'unpinned_site_names' => $visits
                ->map(fn (Visit $v) => $v->unit?->location)
                ->filter(fn ($l) => $l !== null && ($l->latitude === null || $l->longitude === null))
                ->unique('id')->pluck('name')->values(),
        ]]);
    }

    /**
     * "Go on duty, please": the dispatcher's nudge to one agent whose dot is
     * grey — bell + tray push with the deep link to the My Day switch.
     * Throttled like locate, so a nervous dispatcher can't spam a phone.
     */
    public function nudge(Request $request): JsonResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'integer', 'exists:users,id']]);

        if (! \Illuminate\Support\Facades\Cache::add("duty-nudge:{$data['agent_id']}", 1, 60)) {
            return response()->json(['data' => ['sent' => false]]);
        }

        User::query()->findOrFail($data['agent_id'])->notify(
            new \App\Modules\Collaboration\Notifications\DomainNotification(
                kind: 'duty_nudge',
                key: 'duty_nudge',
                params: ['dispatcher' => $request->user()->name],
                link: '/my-day',
            ),
        );

        return response()->json(['data' => ['sent' => true]]);
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
            // Optional hour window: "where was he between 09:00 and 12:00"
            // without scrubbing the whole day.
            'from' => ['sometimes', 'date_format:H:i'],
            'until' => ['sometimes', 'date_format:H:i', 'after:from'],
        ]);

        $day = Carbon::parse($data['date']);
        $windowStart = isset($data['from'])
            ? $day->copy()->setTimeFromTimeString($data['from'])
            : $day->copy()->startOfDay();
        $windowEnd = isset($data['until'])
            ? $day->copy()->setTimeFromTimeString($data['until'])
            : $day->copy()->endOfDay();

        $positions = AgentPosition::query()
            ->where('user_id', $data['agent_id'])
            ->whereBetween('recorded_at', [$windowStart, $windowEnd])
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

        $sessions = DutySession::query()
            ->where('user_id', $data['agent_id'])
            ->where('started_at', '<=', $day->copy()->endOfDay())
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $day->copy()->startOfDay()))
            ->orderBy('started_at')
            ->get(['started_at', 'ended_at']);

        return response()->json(['data' => [
            'positions' => $positions->map(fn (AgentPosition $p) => [
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'at' => $p->recorded_at,
            ])->values(),
            'visits' => $visits->values(),
            // Driven km over the returned window (glitch-filtered) + the duty
            // stretches — the replay header's context line.
            'distance_km' => Mileage::pathKm($positions),
            'sessions' => $sessions->map(fn (DutySession $s) => [
                'started_at' => $s->started_at,
                'ended_at' => $s->ended_at,
            ])->values(),
        ]]);
    }

    /**
     * "Who is closest to THIS site, right now" — the map pin's assist panel.
     * On-duty agents ranked by road minutes (OSRM; haversine estimate when the
     * engine is down). Looking pings the on-duty phones like the map open.
     */
    public function nearest(Request $request, LocateOnDutyAgents $locate): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer', 'exists:locations,id']]);

        $location = Location::query()->findOrFail($data['location_id']);
        abort_if($location->latitude === null || $location->longitude === null, 422, 'This site has no map pin yet.');

        $locate->handle();

        $agents = User::query()->active()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        $ids = $agents->pluck('id')->map(fn ($id) => (int) $id)->all();
        $live = AgentStatus::forUsers($ids);

        $loads = Visit::query()->active()
            ->whereIn('agent_id', $ids)
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('agent_id, COUNT(*) as n')
            ->groupBy('agent_id')
            ->pluck('n', 'agent_id');

        $positioned = $agents
            ->map(fn (User $u) => ['id' => (int) $u->id, 'position' => $live->positionOf((int) $u->id)])
            ->filter(fn (array $row) => $row['position'] !== null && $live->statusOf($row['id']) !== 'off_duty')
            ->values();

        $matrix = $positioned->isEmpty() ? null : Router::table(
            $positioned->map(fn (array $row) => [
                (float) $row['position']->latitude,
                (float) $row['position']->longitude,
            ])->all(),
            [[(float) $location->latitude, (float) $location->longitude]],
        );
        $legs = $positioned->mapWithKeys(fn (array $row, int $i) => [
            $row['id'] => $matrix[$i][0] ?? null,
        ]);

        $ranked = $agents
            ->map(function (User $agent) use ($live, $loads, $legs, $location) {
                $id = (int) $agent->id;
                $status = $live->statusOf($id);
                if ($status === 'off_duty') {
                    return null;
                }

                $position = $live->positionOf($id);
                $leg = $legs->get($id);
                $km = $leg['km'] ?? null;
                $minutes = $leg['minutes'] ?? null;
                if ($km === null && $position !== null) {
                    $km = round(Geo::distanceKm(
                        (float) $position->latitude,
                        (float) $position->longitude,
                        (float) $location->latitude,
                        (float) $location->longitude,
                    ), 1);
                    $minutes = Geo::etaMinutes($km);
                }

                return [
                    'id' => $id,
                    'name' => $agent->name,
                    'status' => $status,
                    'distance_km' => $km,
                    'eta_minutes' => $minutes,
                    'routed' => $leg !== null && ($leg['minutes'] ?? null) !== null,
                    'position_age_minutes' => $position !== null
                        ? (int) $position->recorded_at->diffInMinutes(now())
                        : null,
                    'today_load' => (int) $loads->get($id, 0),
                ];
            })
            ->filter()
            ->sortBy(fn (array $a) => $a['eta_minutes'] ?? PHP_INT_MAX)
            ->values();

        return response()->json(['data' => [
            'site' => [
                'id' => $location->id,
                'name' => $location->name,
                'lat' => (float) $location->latitude,
                'lng' => (float) $location->longitude,
            ],
            'agents' => $ranked,
        ]]);
    }

    /** The day optimizer's proposal — review-and-apply, never auto-dispatch. */
    public function planPreview(ProposeDispatchPlan $action): JsonResponse
    {
        return response()->json(['data' => $action->handle()]);
    }

    /**
     * Km driven per agent per day — fuel/allowance visibility. History reads
     * the nightly aggregates; today is computed live from the breadcrumbs.
     */
    public function mileage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $until = Carbon::parse($data['until'])->endOfDay();
        abort_if($from->diffInDays($until) > 92, 422, 'Pick a window of three months or less.');

        $agents = User::query()->active()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        $rows = AgentMileageDay::query()
            ->whereIn('user_id', $agents->pluck('id'))
            ->whereBetween('day', [$from->toDateString(), $until->toDateString()])
            ->get()
            ->groupBy('user_id');

        // Today isn't aggregated yet — fold it in live when the window covers it.
        $today = now()->startOfDay();
        $includeToday = $today->betweenIncluded($from, $until);

        return response()->json(['data' => [
            'agents' => $agents->map(function (User $agent) use ($rows, $includeToday, $today) {
                $days = $rows->get($agent->id, collect())
                    ->map(fn (AgentMileageDay $d) => [
                        'day' => $d->day->toDateString(),
                        'km' => $d->km,
                        'duty_minutes' => $d->duty_minutes,
                    ]);

                if ($includeToday) {
                    $liveDay = Mileage::forDay((int) $agent->id, $today);
                    if ($liveDay['fixes'] > 0 || $liveDay['duty_minutes'] > 0) {
                        $days->push([
                            'day' => $today->toDateString(),
                            'km' => $liveDay['km'],
                            'duty_minutes' => $liveDay['duty_minutes'],
                        ]);
                    }
                }

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'days' => $days->sortBy('day')->values(),
                    'total_km' => round($days->sum('km'), 1),
                    'total_duty_minutes' => (int) $days->sum('duty_minutes'),
                ];
            })->values(),
        ]]);
    }

    /**
     * The live legs: for every en-route, not-yet-arrived visit whose agent has
     * a fix, the road to the site (the off-route corridor doubles as the
     * drawing — computed here when a leg went en route without one, e.g. the
     * motion auto-stamp). Engine down = a straight dashed line, flagged.
     *
     * @param  \Illuminate\Support\Collection<int, Visit>  $visits
     * @return list<array<string, mixed>>
     */
    private function enRouteLegs($visits, AgentStatus $live): array
    {
        return $visits
            ->filter(fn (Visit $v) => $v->en_route_at !== null && $v->arrived_at === null
                && $v->completed_at === null
                && $v->unit?->location?->latitude !== null && $v->unit->location->longitude !== null)
            ->map(function (Visit $v) use ($live) {
                $position = $live->positionOf((int) $v->agent_id);
                if ($position === null) {
                    return null;
                }

                $from = [(float) $position->latitude, (float) $position->longitude];
                $to = [(float) $v->unit->location->latitude, (float) $v->unit->location->longitude];

                $corridorKey = "dispatch:corridor:{$v->id}";
                $polyline = Cache::get($corridorKey);
                if ($polyline === null) {
                    $route = Router::route($from, $to);
                    if ($route !== null) {
                        $polyline = $route['polyline'];
                        Cache::put($corridorKey, $polyline, now()->addHours(6));
                    }
                }

                $routed = $polyline !== null;
                $polyline ??= [$from, $to];

                return [
                    'visit_id' => $v->id,
                    'agent_id' => (int) $v->agent_id,
                    'agent' => $v->agent?->name,
                    'client' => $v->client?->full_name,
                    'site' => [
                        'id' => $v->unit->location->id,
                        'name' => $v->unit->location->name,
                        'lat' => $to[0],
                        'lng' => $to[1],
                    ],
                    'eta_minutes' => Geo::etaMinutes(Geo::distanceKm($from[0], $from[1], $to[0], $to[1])),
                    'routed' => $routed,
                    'polyline' => $this->decimate($polyline),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Cap a polyline at ~150 points (ends kept) — plenty for drawing.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    private function decimate(array $points): array
    {
        $count = count($points);
        if ($count <= 150) {
            return $points;
        }

        $step = (int) ceil($count / 150);
        $kept = [];
        for ($i = 0; $i < $count; $i += $step) {
            $kept[] = $points[$i];
        }
        if (end($kept) !== $points[$count - 1]) {
            $kept[] = $points[$count - 1];
        }

        return $kept;
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
                'client:id,first_name,last_name',
                'shortlistItems' => fn ($q) => $q->active()
                    ->where('shortlistable_type', 'unit')
                    ->whereIn('state', ['shortlisted', 'not_visited'])
                    ->with(['shortlistable' => fn ($sm) => $sm->morphWith([Unit::class => ['location']])]),
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

                return $items
                    ->map(fn ($item) => $item->shortlistable?->location)
                    ->filter()
                    ->unique('id')
                    ->map(fn ($location) => [
                        'location' => $location,
                        // The plan behind the hollow pin — lets the nearest-
                        // agents panel assign it without a trip to the board.
                        'plan' => ['action_id' => $a->id, 'client' => $project->client?->full_name],
                    ]);
            })
            ->filter(fn (array $row) => $row['location']->latitude !== null && $row['location']->longitude !== null)
            ->groupBy(fn (array $row) => $row['location']->id)
            ->map(fn ($group) => [
                'id' => $group->first()['location']->id,
                'name' => $group->first()['location']->name,
                'lat' => (float) $group->first()['location']->latitude,
                'lng' => (float) $group->first()['location']->longitude,
                'plans' => $group->pluck('plan')->unique('action_id')->values(),
            ])
            ->values()
            ->all();
    }
}
