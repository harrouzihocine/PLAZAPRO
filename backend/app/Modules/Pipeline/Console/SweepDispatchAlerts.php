<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Console;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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
        $closed = $this->closeForgottenSessions();

        $this->info("Unaccepted nudges: {$nagged}, late flags: {$late}, sessions auto-closed: {$closed}.");

        return self::SUCCESS;
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
