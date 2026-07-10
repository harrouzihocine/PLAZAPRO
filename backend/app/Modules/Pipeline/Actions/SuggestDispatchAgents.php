<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Location;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Pipeline\Support\AgentStatus;
use App\Modules\Pipeline\Support\Geo;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Collection;

/**
 * Rank the field agents for a pending in-site plan — the dispatcher's
 * assignment assist. Industry practice, sized to our scale: distance is one
 * factor, never the deciding one. The score blends
 *
 *   0.5 × proximity   (freshest fix → nearest target site)
 *   0.3 × lightness   (fewest open in-site visits today)
 *   0.2 × familiarity (completed visits on these sites, last 90 days)
 *
 * On-duty agents with a live position rank first; on-duty without GPS follow
 * (their phone may simply lack the fix); off-duty agents trail, listed so the
 * dispatcher can still pick them deliberately. Suggest-only by design — the
 * human assigns.
 */
class SuggestDispatchAgents
{
    /**
     * @return array{sites: list<array<string, mixed>>, candidates: list<array<string, mixed>>}
     */
    public function handle(NextAction $action): array
    {
        $sites = $this->targetSites($action);
        $pinned = $sites->filter(fn (Location $l) => $l->latitude !== null && $l->longitude !== null);

        $agents = User::query()->active()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        $ids = $agents->pluck('id')->map(fn ($id) => (int) $id)->all();
        $live = AgentStatus::forUsers($ids);
        $loads = $this->todayLoads($ids);
        $familiarity = $this->familiarity($ids, $sites->pluck('id')->all());

        $candidates = $agents->map(function (User $agent) use ($live, $loads, $familiarity, $pinned) {
            $id = (int) $agent->id;
            $position = $live->positionOf($id);

            $distanceKm = null;
            if ($position !== null && $pinned->isNotEmpty()) {
                $distanceKm = $pinned->map(fn (Location $l) => Geo::distanceKm(
                    (float) $position->latitude,
                    (float) $position->longitude,
                    (float) $l->latitude,
                    (float) $l->longitude,
                ))->min();
            }

            $load = $loads->get($id, 0);
            $visited = $familiarity->get($id, 0);

            // Each factor normalized to 0..1; a missing distance contributes
            // a neutral 0.5 so GPS-less agents are neither favoured nor buried.
            $score = 0.5 * ($distanceKm === null ? 0.5 : 1 / (1 + $distanceKm / 5))
                + 0.3 * (1 / (1 + $load))
                + 0.2 * min(1, $visited / 3);

            return [
                'id' => $id,
                'name' => $agent->name,
                'status' => $live->statusOf($id),
                'distance_km' => $distanceKm !== null ? round($distanceKm, 1) : null,
                'eta_minutes' => $distanceKm !== null ? Geo::etaMinutes($distanceKm) : null,
                'position_age_minutes' => $position !== null
                    ? (int) $position->recorded_at->diffInMinutes(now())
                    : null,
                'today_load' => $load,
                'familiarity' => $visited,
                'score' => round($score, 3),
            ];
        });

        $ranked = $candidates
            ->sortBy([
                fn (array $a, array $b) => ($a['status'] === 'off_duty') <=> ($b['status'] === 'off_duty'),
                fn (array $a, array $b) => $b['score'] <=> $a['score'],
            ])
            ->values();

        return [
            'sites' => $sites->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'lat' => $l->latitude !== null ? (float) $l->latitude : null,
                'lng' => $l->longitude !== null ? (float) $l->longitude : null,
            ])->values()->all(),
            'candidates' => $ranked->all(),
        ];
    }

    /**
     * The distinct sites the plan sends its agent to — its project's active
     * unit shortlist, narrowed to the targeted apartment(s) when set.
     *
     * @return Collection<int, Location>
     */
    private function targetSites(NextAction $action): Collection
    {
        $project = $action->subject;
        if (! $project instanceof ClientProject) {
            return new Collection;
        }

        $items = $project->shortlistItems()->active()
            ->where('shortlistable_type', 'unit')
            ->whereIn('state', ['shortlisted', 'not_visited'])
            ->when($action->target_unit_ids, fn ($q) => $q->whereIn('shortlistable_id', $action->target_unit_ids))
            ->with('shortlistable.location')
            ->get();

        return $items->map(fn ($item) => $item->shortlistable?->location)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Open in-site visits today per agent — the workload-equity factor.
     *
     * @param  list<int>  $agentIds
     * @return Collection<int, int>
     */
    private function todayLoads(array $agentIds): Collection
    {
        return Visit::query()->active()
            ->whereIn('agent_id', $agentIds)
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('agent_id, COUNT(*) as n')
            ->groupBy('agent_id')
            ->pluck('n', 'agent_id')
            ->map(fn ($n) => (int) $n);
    }

    /**
     * Completed in-site visits on the target sites in the last 90 days per
     * agent — "knows the project" beats a few minutes of driving.
     *
     * @param  list<int>  $agentIds
     * @param  list<int>  $siteIds
     * @return Collection<int, int>
     */
    private function familiarity(array $agentIds, array $siteIds): Collection
    {
        if ($siteIds === []) {
            return new Collection;
        }

        return Visit::query()
            ->whereIn('agent_id', $agentIds)
            ->where('type', 'in_site')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(90))
            ->whereHas('unit', fn ($q) => $q->whereIn('location_id', $siteIds))
            ->selectRaw('agent_id, COUNT(*) as n')
            ->groupBy('agent_id')
            ->pluck('n', 'agent_id')
            ->map(fn ($n) => (int) $n);
    }
}
