<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Pipeline\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * The field agent's "My Day": duty state + today's own visits (in-site with
 * the full lifecycle stepper; office ones for context), each carrying its
 * site's pin so the phone can navigate. `route_order` proposes a
 * shortest-first ordering of the remaining in-site stops (greedy
 * nearest-neighbour from the agent's last fix — plenty at 2–6 stops a day).
 * Personal data only — no permission beyond being signed in.
 */
class MyDayController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $open = DutySession::openFor($userId);

        $visits = Visit::query()->active()
            ->where('agent_id', $userId)
            ->whereIn('type', ['in_site', 'office'])
            ->where(function ($q) {
                // Today's schedule + yesterday-or-older in-site work still open
                // (the overdue rail's cards belong to the agent's day too).
                $q->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
                    ->orWhere(fn ($qq) => $qq->where('type', 'in_site')
                        ->whereNull('completed_at')
                        ->where('scheduled_at', '<', now()->startOfDay()));
            })
            ->with(['client:id,first_name,last_name', 'unit.location', 'nextAction:id,state'])
            ->orderBy('scheduled_at')
            ->get();

        $items = $visits->map(function (Visit $v) {
            $location = $v->unit?->location;
            $hasPin = $location !== null && $location->latitude !== null && $location->longitude !== null;

            return [
                'id' => $v->id,
                'type' => $v->type->value,
                'scheduled_at' => $v->scheduled_at,
                'day' => $v->scheduled_at->toDateString(),
                'time' => $v->scheduled_at->hour === 0 && $v->scheduled_at->minute === 0
                    ? null
                    : $v->scheduled_at->format('H:i'),
                'is_overdue' => $v->completed_at === null && $v->scheduled_at->lt(now()->startOfDay()),
                'status' => $v->dispatchStatus(),
                'accepted_at' => $v->accepted_at,
                'en_route_at' => $v->en_route_at,
                'arrived_at' => $v->arrived_at,
                'client' => $v->client?->full_name,
                'unit' => $v->unit?->reference,
                'site' => $location ? [
                    'name' => $location->name,
                    'lat' => $hasPin ? (float) $location->latitude : null,
                    'lng' => $hasPin ? (float) $location->longitude : null,
                    'maps_url' => $location->mapsUrl(),
                ] : null,
                // Declining returns the plan to the pool — only possible while
                // the plan is still open.
                'can_decline' => $v->type->value === 'in_site'
                    && $v->completed_at === null
                    && $v->nextAction?->state?->value === 'pending',
                'link' => $v->client_project_id
                    ? '/clients/'.$v->client_id.'/projects/'.$v->client_project_id
                    : ($v->client_id ? '/clients/'.$v->client_id : null),
            ];
        });

        return response()->json(['data' => [
            'duty' => ['on' => $open !== null, 'since' => $open?->started_at],
            'visits' => $items->values(),
            'route_order' => $this->routeOrder($visits, $userId),
        ]]);
    }

    /**
     * Greedy nearest-neighbour over the remaining pinned in-site stops,
     * starting from the agent's freshest fix (or the first stop by time when
     * there is none). Returns visit ids, nearest first.
     *
     * @param  Collection<int, Visit>  $visits
     * @return list<int>
     */
    private function routeOrder(Collection $visits, int $userId): array
    {
        $stops = $visits
            ->filter(fn (Visit $v) => $v->type->value === 'in_site'
                && $v->completed_at === null
                && $v->unit?->location?->latitude !== null
                && $v->unit->location->longitude !== null)
            ->values();

        if ($stops->count() < 2) {
            return $stops->pluck('id')->all();
        }

        $position = AgentPosition::latestFor([$userId])->get($userId);
        $lat = $position ? (float) $position->latitude : (float) $stops->first()->unit->location->latitude;
        $lng = $position ? (float) $position->longitude : (float) $stops->first()->unit->location->longitude;

        $remaining = $stops->all();
        $order = [];

        while ($remaining !== []) {
            usort($remaining, fn (Visit $a, Visit $b) => Geo::distanceKm($lat, $lng, (float) $a->unit->location->latitude, (float) $a->unit->location->longitude)
                <=> Geo::distanceKm($lat, $lng, (float) $b->unit->location->latitude, (float) $b->unit->location->longitude));

            $next = array_shift($remaining);
            $order[] = $next->id;
            $lat = (float) $next->unit->location->latitude;
            $lng = (float) $next->unit->location->longitude;
        }

        return $order;
    }
}
