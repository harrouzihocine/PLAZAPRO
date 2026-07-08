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

    /** Waiting clients per page — the board lazy-loads page by page. */
    public const PER_PAGE = 15;

    public function __construct(private MatchDesireToInventory $matcher) {}

    /**
     * One page of the board. The "has a match" test runs as a single set-based
     * EXISTS (whereHasMatch) so only the page's desires pay the per-desire
     * ranking/hydration cost — the unpaginated form ran the matcher for every
     * waiting client and collapsed past a couple thousand cases.
     *
     * @param  array{page?: int|string|null, per_page?: int|string|null, search?: ?string, unassigned?: bool|int|string|null}  $filters
     * @return array{items: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function handle(?int $agentId, array $filters = []): array
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? self::PER_PAGE)));

        $desires = $this->matchableDesiresQuery($agentId, $filters)
            ->with(['client.assignedAgent', 'wilaya', 'commune', 'type', 'roomNumber', 'contractType', 'floor', 'locations'])
            // Waiting-longest first — the order the unpaginated board showed, now
            // explicit because pagination needs a stable sort.
            ->orderBy('desires.id')
            ->paginate($perPage, ['desires.*'], 'page', max(1, (int) ($filters['page'] ?? 1)));

        $out = [];

        foreach ($desires->items() as $desire) {
            // Ranked in SQL, hydrating only the shown units — a loose desire
            // matches most of the inventory, and this runs once per page row.
            ['units' => $shown, 'total' => $matchTotal] = $this->matcher->handleTop($desire, self::MAX_MATCHES);

            // whereHasMatch guarantees a hit; a unit sold between the two queries
            // can still empty a row — skip it rather than show a matchless client.
            if ($shown->isEmpty()) {
                continue;
            }

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
                'matches' => $shown->values()->map(fn (Unit $unit, int $i) => $this->presentUnit($unit, $i === 0))->all(),
                'more_count' => max(0, $matchTotal - $shown->count()),
            ];
        }

        return [
            'items' => $out,
            'meta' => [
                'current_page' => $desires->currentPage(),
                'last_page' => $desires->lastPage(),
                'per_page' => $desires->perPage(),
                'total' => $desires->total(),
            ],
        ];
    }

    /**
     * Waiting clients with at least one match — same scope as handle(), one
     * COUNT query (the sidebar "Matches" badge rides on every app load, so the
     * old load-all-then-exists()-per-desire form was a per-navigation stampede).
     */
    public function count(?int $agentId): int
    {
        return $this->matchableDesiresQuery($agentId)->count();
    }

    /** The board's base set: waiting desires that have ≥1 match, plus the list filters. */
    private function matchableDesiresQuery(?int $agentId, array $filters = []): Builder
    {
        $q = $this->matcher->whereHasMatch($this->desiresQuery($agentId));

        if (! empty($filters['unassigned'])) {
            $q->whereHas('client', fn ($c) => $c->whereNull('assigned_agent_id'));
        }

        if (! empty($filters['search'])) {
            $this->applySearch($q, (string) $filters['search']);
        }

        // Waiting-since window on the desire's capture date — at thousands of
        // cases the manager triages a slice, not the whole history.
        if (! empty($filters['from'])) {
            $q->whereDate('desires.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('desires.created_at', '<=', $filters['to']);
        }

        return $q;
    }

    /**
     * Search on the waiting client — name or phone. Mirrors ClientController@index
     * (and BuildArchive): phone matches digits-only, trunk 0 stripped, so a
     * fragment matches however the number was written.
     */
    private function applySearch(Builder $q, string $term): void
    {
        $term = trim($term);
        $digits = ltrim(preg_replace('/\D/', '', $term), '0');

        $q->whereHas('client', function (Builder $c) use ($term, $digits) {
            $c->where(function (Builder $sub) use ($term, $digits) {
                $sub->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%");
                if ($digits !== '') {
                    $sub->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$digits}%"]);
                } else {
                    $sub->orWhere('phone', 'like', "%{$term}%");
                }
            });
        });
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
