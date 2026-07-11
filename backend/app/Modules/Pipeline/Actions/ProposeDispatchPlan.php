<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Pipeline\Support\AgentStatus;
use App\Modules\Pipeline\Support\Geo;
use App\Modules\Pipeline\Support\Router;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * The dispatcher's day optimizer: split the whole pending in-site pool across
 * the on-duty agents into geographically sensible, balanced routes.
 *
 * Greedy cheapest-insertion — at every step the globally cheapest
 * (agent, plan) pair wins, where cost = road minutes from the agent's route
 * end plus a lightness penalty per stop already on their plate (existing
 * assignments count too). Good routes, not provably optimal ones — at our
 * fleet size the difference is minutes, and the dispatcher reviews every line
 * anyway: this PROPOSES, applying records ordinary board moves, never an
 * auto-dispatch.
 */
class ProposeDispatchPlan
{
    /** Minutes of "cost" per stop already on an agent's day — the balance knob. */
    private const LOAD_PENALTY_MIN = 12;

    /** Leg estimate when an agent has no GPS fix yet (neither favoured nor buried). */
    private const NO_FIX_LEG_MIN = 20;

    /**
     * @return array{proposals: list<array<string, mixed>>, skipped: list<array<string, mixed>>, routed: bool, pool_size: int}
     */
    public function handle(): array
    {
        [$plans, $skipped] = $this->pendingPlans();
        $agents = $this->onDutyAgents();

        if ($agents->isEmpty()) {
            return [
                'proposals' => [],
                'skipped' => $skipped
                    ->concat($plans->map(fn (array $p) => $this->skippedRow($p, 'no_agents')))
                    ->values()->all(),
                'routed' => false,
                'pool_size' => $plans->count() + $skipped->count(),
            ];
        }

        $sites = $plans->flatMap(fn (array $p) => $p['sites'])->unique('id')->values();
        $legMinutes = $this->legEstimator($agents, $sites);

        // Greedy cheapest-insertion over (agent, plan) pairs.
        $routes = $agents->map(fn (array $a) => [
            'agent' => $a,
            'cursor' => $a['position'], // [lat, lng]|null — route end so far
            'stops' => [],
            'total_minutes' => 0,
            'total_km' => 0.0,
        ])->all();

        $remaining = $plans->all();

        while ($remaining !== []) {
            $bestCost = null;
            $bestAgent = null;
            $bestPlan = null;
            $bestLeg = null;

            foreach ($routes as $ri => $route) {
                $stopCount = count($route['stops']) + $route['agent']['existing_load'];
                foreach ($remaining as $pi => $plan) {
                    // A multi-site plan is priced by its nearest pinned site.
                    $leg = collect($plan['sites'])
                        ->map(fn (array $site) => $legMinutes($route['cursor'], $site) + ['site' => $site])
                        ->sortBy('minutes')
                        ->first();

                    $cost = $leg['minutes'] + self::LOAD_PENALTY_MIN * $stopCount;
                    if ($bestCost === null || $cost < $bestCost) {
                        $bestCost = $cost;
                        $bestAgent = $ri;
                        $bestPlan = $pi;
                        $bestLeg = $leg;
                    }
                }
            }

            $plan = $remaining[$bestPlan];
            unset($remaining[$bestPlan]);

            $routes[$bestAgent]['stops'][] = [
                'action_id' => $plan['action_id'],
                'client' => $plan['client'],
                'project_id' => $plan['project_id'],
                'site' => $bestLeg['site'],
                'minutes_from_prev' => $bestLeg['minutes'],
                'km_from_prev' => $bestLeg['km'],
            ];
            $routes[$bestAgent]['total_minutes'] += $bestLeg['minutes'];
            $routes[$bestAgent]['total_km'] = round($routes[$bestAgent]['total_km'] + ($bestLeg['km'] ?? 0), 1);
            $routes[$bestAgent]['cursor'] = [$bestLeg['site']['lat'], $bestLeg['site']['lng']];
        }

        return [
            'proposals' => collect($routes)
                ->map(fn (array $r) => [
                    'agent' => [
                        'id' => $r['agent']['id'],
                        'name' => $r['agent']['name'],
                        'status' => $r['agent']['status'],
                    ],
                    'existing_load' => $r['agent']['existing_load'],
                    'stops' => $r['stops'],
                    'total_minutes' => $r['total_minutes'],
                    'total_km' => $r['total_km'],
                ])
                ->sortByDesc(fn (array $r) => count($r['stops']))
                ->values()->all(),
            'skipped' => $skipped->values()->all(),
            'routed' => $this->routed,
            'pool_size' => $plans->count() + $skipped->count(),
        ];
    }

    private bool $routed = false;

    /**
     * The pending in-site pool, one row per plan with its pinned sites.
     * Unpinned plans go to `skipped` — the dispatcher's cue to drop map pins.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    private function pendingPlans(): array
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
                    ->with(['shortlistable' => fn (MorphTo $sm) => $sm->morphWith([Unit::class => ['location']])]),
            ]])])
            ->get();

        $plans = new Collection;
        $skipped = new Collection;

        foreach ($pending as $action) {
            $project = $action->subject;
            if (! $project instanceof ClientProject) {
                continue;
            }

            $items = $action->target_unit_ids
                ? $project->shortlistItems->whereIn('shortlistable_id', $action->target_unit_ids)
                : $project->shortlistItems;

            $sites = $items
                ->map(fn ($item) => $item->shortlistable?->location)
                ->filter(fn (?Location $l) => $l !== null && $l->latitude !== null && $l->longitude !== null)
                ->unique('id')
                ->map(fn (Location $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'lat' => (float) $l->latitude,
                    'lng' => (float) $l->longitude,
                ])
                ->values();

            $row = [
                'action_id' => $action->id,
                'project_id' => $project->id,
                'client' => $project->client?->full_name,
                'sites' => $sites->all(),
            ];

            $sites->isEmpty()
                ? $skipped->push($this->skippedRow($row, 'no_pin'))
                : $plans->push($row);
        }

        return [$plans, $skipped];
    }

    /** @param  array<string, mixed>  $plan */
    private function skippedRow(array $plan, string $reason): array
    {
        return [
            'action_id' => $plan['action_id'],
            'client' => $plan['client'],
            'reason' => $reason,
        ];
    }

    /**
     * On-duty agents with their freshest fix and today's open in-site count.
     *
     * @return Collection<int, array{id: int, name: string, status: string, position: array{0: float, 1: float}|null, existing_load: int}>
     */
    private function onDutyAgents(): Collection
    {
        $agents = User::query()->active()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        $live = AgentStatus::forUsers($agents->pluck('id')->map(fn ($id) => (int) $id)->all());

        $loads = Visit::query()->active()
            ->whereIn('agent_id', $agents->pluck('id'))
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('agent_id, COUNT(*) as n')
            ->groupBy('agent_id')
            ->pluck('n', 'agent_id');

        return $agents
            ->map(function (User $agent) use ($live, $loads) {
                $id = (int) $agent->id;
                $position = $live->positionOf($id);

                return [
                    'id' => $id,
                    'name' => $agent->name,
                    'status' => $live->statusOf($id),
                    'position' => $position !== null
                        ? [(float) $position->latitude, (float) $position->longitude]
                        : null,
                    'existing_load' => (int) $loads->get($id, 0),
                ];
            })
            ->filter(fn (array $a) => $a['status'] !== 'off_duty')
            ->values();
    }

    /**
     * One OSRM matrix for (agent starts + sites) × sites, closed over so leg
     * pricing is O(1) lookups; falls back to haversine estimates when the
     * engine is down.
     *
     * @param  Collection<int, array<string, mixed>>  $agents
     * @param  Collection<int, array<string, mixed>>  $sites
     * @return callable(array{0: float, 1: float}|null, array<string, mixed>): array{minutes: int, km: float|null}
     */
    private function legEstimator(Collection $agents, Collection $sites): callable
    {
        $sitePoints = $sites->map(fn (array $s) => [$s['lat'], $s['lng']])->values();
        $siteIndex = $sites->pluck('id')->flip(); // location id → matrix column

        $sourcePoints = $agents->pluck('position')->filter()->values()
            ->concat($sitePoints);
        $sourceKeys = $sourcePoints
            ->mapWithKeys(fn (array $p, int $i) => [self::pointKey($p) => $i]);

        $matrix = $sitePoints->isEmpty() ? null : Router::table($sourcePoints->all(), $sitePoints->all());
        $this->routed = $matrix !== null;

        return function (?array $from, array $site) use ($matrix, $sourceKeys, $siteIndex) {
            if ($from === null) {
                return ['minutes' => self::NO_FIX_LEG_MIN, 'km' => null];
            }

            $row = $matrix !== null ? $sourceKeys->get(self::pointKey($from)) : null;
            $col = $siteIndex->get($site['id']);
            $leg = $row !== null && $col !== null ? ($matrix[$row][$col] ?? null) : null;

            if ($leg !== null && $leg['minutes'] !== null) {
                return ['minutes' => (int) $leg['minutes'], 'km' => $leg['km']];
            }

            $km = Geo::distanceKm($from[0], $from[1], $site['lat'], $site['lng']);

            return ['minutes' => Geo::etaMinutes($km), 'km' => round($km * 1.35, 1)];
        };
    }

    /** @param  array{0: float, 1: float}  $point */
    private static function pointKey(array $point): string
    {
        return sprintf('%.7f,%.7f', $point[0], $point[1]);
    }
}
