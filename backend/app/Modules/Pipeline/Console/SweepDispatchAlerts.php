<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Console;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Pipeline\Support\AgentStatus;
use App\Modules\Pipeline\Support\Geo;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The dispatcher's watchdog (every 5 minutes, like the hold sweepers):
 *
 *  - an assigned in-site visit still unaccepted past the accept-SLA nudges
 *    the dispatchers once (Onfleet's "intervene before it's a miss");
 *  - a timed in-site visit with no arrival past scheduled + grace flags the
 *    agent as late to the dispatchers, once;
 *  - a duty session someone forgot to close auto-ends after 20 hours, so
 *    nobody is "on duty" (or trackable) around the clock by accident.
 *
 * The one-shot columns (`acceptance_alerted_at`, `late_alerted_at`) reset with
 * the lifecycle on reassignment — a new agent gets a fresh clock.
 */
class SweepDispatchAlerts extends Command
{
    protected $signature = 'dispatch:sweep';

    protected $description = 'Alert dispatchers about unaccepted assignments and late arrivals; close forgotten duty sessions';

    public function handle(): int
    {
        $dispatchers = $this->dispatchers();

        $nagged = $this->sweepUnaccepted($dispatchers);
        $late = $this->sweepLateArrivals($dispatchers);
        $idle = $this->sweepIdleStops($dispatchers);
        $closed = $this->closeForgottenSessions();

        $this->info("Unaccepted nudges: {$nagged}, late flags: {$late}, idle flags: {$idle}, sessions auto-closed: {$closed}.");

        return self::SUCCESS;
    }

    /**
     * An AVAILABLE on-duty agent parked somewhere for longer than the idle
     * threshold — not at a visit site (that's on-site, skipped by status) —
     * gets flagged to the dispatchers once per stop. The 5-minute duty
     * snapshots supply the evidence; the stop's first still fix identifies it,
     * so one stop never nags twice however long it lasts.
     *
     * @param  Collection<int, User>  $dispatchers
     */
    private function sweepIdleStops(Collection $dispatchers): int
    {
        $threshold = AppSetting::integer('dispatch_idle_alert_minutes', 45);
        if ($threshold <= 0) {
            return 0;
        }

        $onDuty = DutySession::query()->open()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if ($onDuty === []) {
            return 0;
        }

        $live = AgentStatus::forUsers($onDuty);
        $flagged = 0;

        foreach ($onDuty as $userId) {
            // En route / on site = working; only a supposedly-roaming agent
            // sitting still is worth a look.
            if ($live->statusOf($userId) !== 'available') {
                continue;
            }

            // The whole day's trail (~150 rows at 5-min cadence), so the
            // stop's first still fix is a STABLE identity — a sliding window
            // would rename long stops each sweep and nag again.
            $positions = AgentPosition::query()
                ->where('user_id', $userId)
                ->where('recorded_at', '>=', now()->startOfDay())
                ->orderByDesc('recorded_at')
                ->get(['latitude', 'longitude', 'recorded_at']);

            if ($positions->count() < 3 || $positions->first()->recorded_at->lt(now()->subMinutes(15))) {
                continue; // no fresh evidence — snapshots off or phone dark
            }

            $anchor = $positions->first();
            $still = $positions->takeWhile(fn (AgentPosition $p) => Geo::distanceMeters(
                (float) $anchor->latitude, (float) $anchor->longitude,
                (float) $p->latitude, (float) $p->longitude,
            ) <= 150);

            $stopStart = $still->last()->recorded_at;
            if ($still->count() < 3 || $stopStart->gt(now()->subMinutes($threshold))) {
                continue; // moving, or not parked long enough yet
            }

            // One alert per stop, ever — keyed by the stop's first still fix.
            if (! Cache::add("dispatch:idle-alert:{$userId}:{$stopStart->timestamp}", 1, now()->addDay())) {
                continue;
            }

            $agent = User::query()->find($userId);
            if ($agent === null) {
                continue;
            }

            $notification = new DomainNotification(
                kind: 'agent_idle',
                key: 'agent_idle',
                params: [
                    'agent' => $agent->name,
                    'minutes' => (string) (int) $stopStart->diffInMinutes(now()),
                ],
                link: '/dispatch?tab=map',
            );
            foreach ($dispatchers as $dispatcher) {
                $dispatcher->notify($notification);
            }
            $flagged++;
        }

        return $flagged;
    }

    /** @param  Collection<int, User>  $dispatchers */
    private function sweepUnaccepted(Collection $dispatchers): int
    {
        $sla = AppSetting::integer('dispatch_accept_sla_minutes', 15);

        $visits = Visit::query()->active()
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->whereNotNull('agent_id')
            ->whereNull('accepted_at')
            ->whereNull('acceptance_alerted_at')
            ->whereRaw('COALESCE(assigned_at, created_at) <= ?', [now()->subMinutes($sla)])
            ->with(['agent:id,name', 'client:id,first_name,last_name'])
            ->get();

        foreach ($visits as $visit) {
            $this->notify($dispatchers, 'visit_unaccepted', $visit);
            $visit->update(['acceptance_alerted_at' => now()]);
        }

        return $visits->count();
    }

    /** @param  Collection<int, User>  $dispatchers */
    private function sweepLateArrivals(Collection $dispatchers): int
    {
        $grace = AppSetting::integer('dispatch_arrival_grace_minutes', 15);

        $visits = Visit::query()->active()
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->whereNotNull('agent_id')
            ->whereNull('arrived_at')
            ->whereNull('late_alerted_at')
            // Today's timed slots only: the midnight sentinel means "no time
            // chosen" — nothing to be late for.
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereRaw("TIME(scheduled_at) <> '00:00:00'")
            ->where('scheduled_at', '<=', now()->subMinutes($grace))
            ->with(['agent:id,name', 'client:id,first_name,last_name'])
            ->get();

        foreach ($visits as $visit) {
            $this->notify($dispatchers, 'visit_late', $visit);
            $visit->update(['late_alerted_at' => now()]);
        }

        return $visits->count();
    }

    private function closeForgottenSessions(): int
    {
        return DutySession::query()->open()
            ->where('started_at', '<=', now()->subHours(20))
            ->update(['ended_at' => now()]);
    }

    /** @param  Collection<int, User>  $dispatchers */
    private function notify(Collection $dispatchers, string $kind, Visit $visit): void
    {
        $when = $visit->scheduled_at->hour === 0 && $visit->scheduled_at->minute === 0
            ? $visit->scheduled_at->format('D d M')
            : $visit->scheduled_at->format('D d M, H:i');

        $notification = new DomainNotification(
            kind: $kind,
            key: $kind,
            params: [
                'agent' => $visit->agent?->name ?? '—',
                'client' => $visit->client?->full_name ?? 'a client',
                'when' => $when,
            ],
            link: '/dispatch',
            subjectType: 'visit',
            subjectId: $visit->id,
        );

        foreach ($dispatchers as $dispatcher) {
            $dispatcher->notify($notification);
        }
    }

    /** @return Collection<int, User> */
    private function dispatchers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'visits.dispatch'))
            ->get();
    }
}
