<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Http\Resources\DesireResource;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Desire matches" board: waiting clients (a client-level desire, no deal yet)
 * whose criteria now match available inventory. Agent-scoped like the dashboard —
 * an agent sees only their own book; a non-agent sees the whole company.
 *
 * Each match ships the full property card (not just reference/price) so an agent
 * can judge fit without leaving the page, and the desire ships through
 * DesireResource (ids + labels) so the reconnect action can both show "why this
 * matches" and, if the call pivots, pre-fill the desire edit form untouched.
 */
class BuildDesireMatches
{
    /** Matches shown per client — ranked best-first by the matcher. */
    private const MAX_MATCHES = 10;

    public function __construct(private MatchDesireToInventory $matcher) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(?int $agentId): array
    {
        $desires = $this->desiresQuery($agentId)
            ->with(['client.assignedAgent', 'wilaya', 'commune', 'type', 'roomNumber', 'contractType', 'floor', 'locations'])
            ->get();

        $out = [];

        foreach ($desires as $desire) {
            $matches = $this->matcher->handle($desire);

            if ($matches->isEmpty()) {
                continue;
            }

            $shown = $matches->take(self::MAX_MATCHES)->values();

            $out[] = [
                'client' => [
                    'id' => $desire->client->id,
                    'full_name' => $desire->client->full_name,
                    'phone' => $desire->client->phone,
                    // Who owns this lead — drives the board's role split: the
                    // assignee reconnects, a manager (re)assigns.
                    'assigned_agent' => $desire->client->assignedAgent ? [
                        'id' => $desire->client->assignedAgent->id,
                        'name' => $desire->client->assignedAgent->name,
                    ] : null,
                ],
                'desire' => (new DesireResource($desire))->resolve(),
                'origin_project' => $this->originProject($desire),
                // Best-first ordering already answers "which is the closest fit" —
                // flagging the leader beats a synthetic percentage on units that
                // all already passed every hard criterion.
                'matches' => $shown->map(fn (Unit $unit, int $i) => $this->presentUnit($unit, $i === 0))->all(),
                'more_count' => max(0, $matches->count() - $shown->count()),
            ];
        }

        return $out;
    }

    /**
     * Waiting clients with at least one match — same scope as handle(), but skips
     * the presentation work (origin-project lookup, unit/resource mapping) so the
     * sidebar "Matches" badge stays cheap.
     */
    public function count(?int $agentId): int
    {
        return $this->desiresQuery($agentId)
            ->get()
            ->filter(fn (Desire $desire) => $this->matcher->exists($desire))
            ->count();
    }

    /** Waiting clients' active desires (no deal yet), scoped to the agent's own book or company-wide. */
    private function desiresQuery(?int $agentId): Builder
    {
        return Desire::query()->active()
            ->whereNull('client_project_id')
            ->whereHas('client', function ($q) use ($agentId) {
                $q->active();
                if ($agentId !== null) {
                    $q->where('assigned_agent_id', $agentId);
                }
            });
    }

    /**
     * The project this desire came from — the one "shift to desire" archived
     * (ShiftProjectToDesire stamps closed_to_desire_at right before archiving,
     * the same row EnsureActiveClientProject reactivates on reconnect). A desire
     * captured straight off a call (no prior project) has none — null is normal.
     *
     * @return array<string, mixed>|null
     */
    private function originProject(Desire $desire): ?array
    {
        $project = $desire->client->projects()
            ->archived()
            ->whereNotNull('closed_to_desire_at')
            ->with(['location', 'unit'])
            ->latest('id')
            ->first();

        if ($project === null) {
            return null;
        }

        return [
            'id' => $project->id,
            'label' => $project->unit?->reference ?? $project->location?->name,
            'closed_to_desire_at' => $project->closed_to_desire_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUnit(Unit $unit, bool $bestMatch): array
    {
        return [
            'id' => $unit->id,
            'reference' => $unit->reference,
            'price' => $unit->price,
            'area_sqm' => $unit->area_sqm,
            'sale_status' => $unit->sale_status?->value,
            'reserved_expires_at' => $unit->reserved_expires_at?->toIso8601String(),
            // A unit can override its project's push; fall back to the project's.
            'gtm_priority' => ($unit->gtm_priority ?? $unit->location?->gtm_priority)?->value,
            'floor' => $unit->floor?->label,
            'room_number' => $unit->roomNumber?->label,
            'type' => $unit->location?->type?->label,
            'contract_type' => $unit->location?->contractType?->label,
            'location' => $unit->location ? [
                'id' => $unit->location->id,
                'name' => $unit->location->name,
                'wilaya' => $unit->location->wilaya?->name,
                'commune' => $unit->location->commune?->name,
                'expected_delivery_date' => $unit->location->expected_delivery_date?->toDateString(),
            ] : null,
            'best_match' => $bestMatch,
        ];
    }
}
