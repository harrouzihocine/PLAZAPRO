<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Events\AgentPositionUpdated;
use App\Modules\Pipeline\Events\VisitLifecycleUpdated;
use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Pipeline\Support\AgentStatus;
use App\Modules\Pipeline\Support\Geo;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\User;

/**
 * Ingest one GPS fix from an on-duty agent's device:
 *
 *  1. refuse when off duty (the privacy contract — the client stops watching);
 *  2. store the breadcrumb;
 *  3. geofence pass over the agent's open in-site visits: inside the site
 *     radius stamps arrival (a tech who shows up has de-facto accepted);
 *     leaving beyond the radius + hysteresis stamps departure;
 *  4. ETA estimate to the nearest en-route target site;
 *  5. broadcast the dot's move to the dispatchers' live map.
 */
class RecordAgentPosition
{
    /** Leaving = beyond radius × this — so GPS jitter at the fence never flaps. */
    private const DEPARTURE_HYSTERESIS = 1.5;

    /**
     * @param  array{latitude: float, longitude: float, accuracy_m?: int|null, recorded_at?: string|null}  $data
     */
    public function handle(User $agent, array $data): AgentPosition
    {
        abort_if(DutySession::openFor($agent->id) === null, 409, 'You are off duty — position not recorded.');

        $position = AgentPosition::create([
            'user_id' => $agent->id,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy_m' => $data['accuracy_m'] ?? null,
            'recorded_at' => $data['recorded_at'] ?? now(),
        ]);

        $eta = $this->applyGeofences($agent, $position);

        AgentPositionUpdated::dispatch(
            $agent->id,
            (float) $position->latitude,
            (float) $position->longitude,
            $position->recorded_at->toIso8601String(),
            AgentStatus::forUsers([$agent->id])->statusOf($agent->id),
            $eta,
        );

        return $position;
    }

    /**
     * Stamp arrivals/departures on the agent's open in-site visits and return
     * the ETA (minutes) to the nearest still-ahead en-route site, if any.
     */
    private function applyGeofences(User $agent, AgentPosition $position): ?int
    {
        $radius = AppSetting::integer('dispatch_geofence_radius_m', 200);

        // Open in-site work that has a pinned site: today's schedule plus
        // anything already en route (an overdue visit being honoured late
        // still deserves its arrival stamp).
        $visits = Visit::query()->active()
            ->where('agent_id', $agent->id)
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->where(function ($q) {
                $q->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
                    ->orWhereNotNull('en_route_at');
            })
            ->with('unit.location')
            ->get()
            ->filter(fn (Visit $v) => $v->unit?->location?->latitude !== null
                && $v->unit->location->longitude !== null);

        $eta = null;

        foreach ($visits as $visit) {
            $meters = Geo::distanceMeters(
                (float) $position->latitude,
                (float) $position->longitude,
                (float) $visit->unit->location->latitude,
                (float) $visit->unit->location->longitude,
            );

            if ($visit->arrived_at === null && $meters <= $radius) {
                $visit->update([
                    'arrived_at' => now(),
                    'accepted_at' => $visit->accepted_at ?? now(),
                    'en_route_at' => $visit->en_route_at ?? now(),
                ]);
                VisitLifecycleUpdated::dispatch($visit->fresh());

                continue;
            }

            if ($visit->arrived_at !== null && $visit->departed_at === null
                && $meters > $radius * self::DEPARTURE_HYSTERESIS) {
                $visit->update(['departed_at' => now()]);
                VisitLifecycleUpdated::dispatch($visit->fresh());

                continue;
            }

            if ($visit->en_route_at !== null && $visit->arrived_at === null) {
                $minutes = Geo::etaMinutes($meters / 1000);
                $eta = $eta === null ? $minutes : min($eta, $minutes);
            }
        }

        return $eta;
    }
}
