<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Support\Collection;

/**
 * The live availability of a set of field agents, derived — never stored —
 * from the duty sessions and today's visit lifecycle stamps:
 *
 *   off_duty  no open duty session
 *   on_site   inside a site geofence right now (arrived, not departed/done)
 *   en_route  driving to a site (en route, not arrived)
 *   available on duty with neither of the above
 *
 * One object bundles status + freshest position per agent so the board, the
 * live map and the assignment ranking all read the same truth.
 */
final class AgentStatus
{
    /**
     * @param  Collection<int, string>  $statuses  user id → status
     * @param  Collection<int, AgentPosition>  $positions  user id → freshest fix
     */
    private function __construct(
        private Collection $statuses,
        private Collection $positions,
    ) {}

    /** @param  iterable<int>  $userIds */
    public static function forUsers(iterable $userIds): self
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->values();

        $onDuty = DutySession::query()->open()->whereIn('user_id', $ids)->pluck('user_id')
            ->map(fn ($id) => (int) $id)->flip();

        // Today's incomplete in-site visits carrying live stamps, newest step first.
        $liveVisits = Visit::query()->active()
            ->whereIn('agent_id', $ids)
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->where(function ($q) {
                $q->whereNotNull('arrived_at')->orWhereNotNull('en_route_at');
            })
            ->whereBetween('scheduled_at', [now()->subDay()->startOfDay(), now()->endOfDay()])
            ->get(['id', 'agent_id', 'en_route_at', 'arrived_at', 'departed_at']);

        $statuses = $ids->mapWithKeys(function (int $id) use ($onDuty, $liveVisits) {
            if (! $onDuty->has($id)) {
                return [$id => 'off_duty'];
            }
            $mine = $liveVisits->where('agent_id', $id);
            if ($mine->contains(fn (Visit $v) => $v->arrived_at !== null && $v->departed_at === null)) {
                return [$id => 'on_site'];
            }
            if ($mine->contains(fn (Visit $v) => $v->en_route_at !== null && $v->arrived_at === null)) {
                return [$id => 'en_route'];
            }

            return [$id => 'available'];
        });

        return new self($statuses, AgentPosition::latestFor($ids));
    }

    public function statusOf(int $userId): string
    {
        return $this->statuses->get($userId, 'off_duty');
    }

    public function positionOf(int $userId): ?AgentPosition
    {
        return $this->positions->get($userId);
    }

    /** The map/board payload fragment for one agent. */
    public function payloadFor(int $userId): array
    {
        $position = $this->positionOf($userId);

        return [
            'status' => $this->statusOf($userId),
            'position' => $position ? [
                'lat' => (float) $position->latitude,
                'lng' => (float) $position->longitude,
                'accuracy_m' => $position->accuracy_m,
                'at' => $position->recorded_at,
            ] : null,
        ];
    }
}
